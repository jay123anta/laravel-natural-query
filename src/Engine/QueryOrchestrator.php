<?php

namespace Jayanta\NaturalQuery\Engine;

use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Contracts\QueryCacheInterface;
use Jayanta\NaturalQuery\Contracts\ReportsUsage;
use Jayanta\NaturalQuery\Contracts\SqlValidatorInterface;
use Jayanta\NaturalQuery\Events\QuestionAnswered;
use Jayanta\NaturalQuery\Events\QuestionAsked;
use Jayanta\NaturalQuery\Events\QuestionFailed;
use Jayanta\NaturalQuery\Events\UnsafeSqlRejected;
use Illuminate\Support\Facades\Event;
use Jayanta\NaturalQuery\Security\InputGuard;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Query Orchestrator - Main Engine
 *
 * Orchestrates the full natural language → SQL → results pipeline.
 *
 * Supports three query modes:
 *
 * 1. INTENT MODE: AI extracts intent → local SqlBuilder constructs SQL.
 *    Safest, but limited to predefined metrics in schema config.
 *
 * 2. SQL GENERATION MODE: AI receives full table structure and generates
 *    SQL directly. More flexible — works with any query. SQL is validated
 *    before execution.
 *
 * 3. AUTO MODE (default): Tries intent mode first. If intent parsing
 *    fails or needs clarification, falls back to SQL generation mode.
 *    This gives the best of both worlds.
 *
 * This is the primary entry point exposed via the NaturalQuery facade.
 */
class QueryOrchestrator
{
    /**
     * Shown when the LLM provider answers 429. Kept honest and actionable —
     * a rate limit must never surface as "could not understand the query".
     */
    public const RATE_LIMIT_MESSAGE =
        'The AI service is receiving too many requests right now (rate limit). '
        . 'Please wait a minute and try again.';

    protected LlmProviderInterface $llmProvider;
    protected QueryCacheInterface $cache;
    protected SqlValidatorInterface $validator;
    protected SchemaRegistry $registry;
    protected SqlBuilder $sqlBuilder;
    protected PromptBuilder $promptBuilder;
    protected ResponseFormatter $formatter;

    protected InputGuard $inputGuard;
    protected QueryVerifier $verifier;

    protected ?QueryPlanner $planner;
    protected ?StepSynthesizer $synthesizer;
    protected ?NextStepSuggester $suggester;
    protected ?IntentCoverage $coverage;

    /**
     * True while the steps of a decomposed question are being answered.
     *
     * Each step re-enters query(), and a step must never be decomposed again:
     * "compare A and B" would plan into "A" and "B", and if "A" were planned in
     * turn the recursion has no floor.
     */
    protected bool $inStepExecution = false;

    /** SQL from the most recent execution, for the QuestionAnswered event. */
    protected ?string $lastSql = null;

    public function __construct(
        LlmProviderInterface $llmProvider,
        QueryCacheInterface $cache,
        SqlValidatorInterface $validator,
        SchemaRegistry $registry,
        SqlBuilder $sqlBuilder,
        PromptBuilder $promptBuilder,
        ResponseFormatter $formatter,
        InputGuard $inputGuard,
        QueryVerifier $verifier,
        ?QueryPlanner $planner = null,
        ?StepSynthesizer $synthesizer = null,
        ?NextStepSuggester $suggester = null,
        ?IntentCoverage $coverage = null
    ) {
        $this->llmProvider = $llmProvider;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->registry = $registry;
        $this->sqlBuilder = $sqlBuilder;
        $this->promptBuilder = $promptBuilder;
        $this->formatter = $formatter;
        $this->inputGuard = $inputGuard;
        $this->verifier = $verifier;

        // Optional so an orchestrator built by hand — in a test, or in code
        // written against the previous constructor — keeps working, simply
        // without chat features.
        $this->planner = $planner;
        $this->synthesizer = $synthesizer;
        $this->suggester = $suggester;
        $this->coverage = $coverage;
    }

    /**
     * Process a natural language query end-to-end.
     *
     * @param string $naturalLanguageQuery The user's question
     * @param string|null $schemeHint Optional scheme key hint
     * @return array Complete response with data, or clarification/error
     */
    public function query(string $naturalLanguageQuery, ?string $schemeHint = null, array $context = []): array
    {
        $startTime = microtime(true);
        $queryMode = config('naturalquery.query_mode', 'auto');
        $metadata = ['processing_mode' => $this->llmProvider->getName(), 'query_mode' => $queryMode];

        // Token counts are per-question, so the running total starts here. The
        // provider is a singleton for the request; without this, the second
        // question of a conversation reports the first one's tokens too.
        //
        // Not reset for the steps of a decomposed question — those are one
        // question to the user and should be billed as one.
        if (!$this->inStepExecution) {
            $this->lastSql = null;

            if ($this->llmProvider instanceof ReportsUsage) {
                $this->llmProvider->resetUsage();
            }
        }

        try {
            // Security: Validate and sanitize input BEFORE it reaches the AI
            $guardResult = $this->inputGuard->validate($naturalLanguageQuery);
            if (!$guardResult['safe']) {
                Log::warning('[NaturalQuery] Input blocked by guard', [
                    'reason' => $guardResult['blocked_reason'],
                    'query' => substr($naturalLanguageQuery, 0, 100),
                ]);
                return $this->formatter->formatError(
                    $guardResult['blocked_reason'] ?? 'Query blocked for security reasons.',
                    $metadata,
                    ErrorCode::BLOCKED
                );
            }
            $naturalLanguageQuery = $guardResult['query']; // Use sanitized version

            // Announced after the guard and before any spending, so a listener
            // can attribute cost, enforce a quota the package knows nothing
            // about, or record who asked what. Steps of a decomposed question
            // stay silent — the user asked one question.
            if (!$this->inStepExecution) {
                Event::dispatch(new QuestionAsked(
                    $naturalLanguageQuery,
                    $schemeHint,
                    $queryMode,
                    $context['session_id'] ?? null
                ));
            }

            // Apply default scheme if configured and no hint provided
            if (!$schemeHint) {
                $defaultScheme = config('naturalquery.default_scheme');
                if ($defaultScheme && $this->registry->has($defaultScheme)) {
                    $schemeHint = $defaultScheme;
                    $metadata['default_scheme_applied'] = true;
                }
            }

            // A question about two things needs two queries. Gated by a local
            // pattern check, so an ordinary question costs exactly what it
            // costs today and takes exactly the path it takes today.
            if (!$this->inStepExecution
                && $this->planner
                && $this->synthesizer
                && $this->planner->looksMultiStep($naturalLanguageQuery)) {
                $plan = $this->planner->plan($naturalLanguageQuery);

                if ($plan['success']) {
                    return $this->runSteps($naturalLanguageQuery, $plan, $schemeHint, $metadata, $startTime);
                }
            }

            // Check cache first (works for all modes)
            $cacheHit = false;
            $cachedResult = $this->cache->find($naturalLanguageQuery);
            if ($cachedResult && isset($cachedResult['intent'])) {
                $cacheHit = true;
                $metadata['cache_hit'] = true;
                $metadata['cache_match_type'] = $cachedResult['cache_match_type'];
            }

            // Route to appropriate mode
            if ($queryMode === 'sql_generation') {
                $result = $this->processWithSqlGeneration($naturalLanguageQuery, $schemeHint, $cachedResult, $metadata, $context);
            } elseif ($queryMode === 'intent') {
                $result = $this->processWithIntent($naturalLanguageQuery, $schemeHint, $cachedResult, $metadata, $context);
            } else {
                // AUTO mode: intent first — unless the question plainly needs
                // SQL the intent contract cannot express.
                //
                // Falling back on ERROR is not enough, and that was the whole
                // problem: intent mode did not fail on these questions, it
                // succeeded at a narrower one. "Customers with more than 10
                // orders" quietly became "customers", ranked. Deciding up front
                // costs nothing — both modes are a single API call.
                $beyond = $this->coverage ? $this->coverage->exceeds($naturalLanguageQuery) : null;

                if ($beyond) {
                    Log::info('[NaturalQuery] Question needs SQL beyond the intent contract', [
                        'component' => $beyond,
                    ]);
                    $metadata['query_mode'] = 'auto→sql_generation';
                    $metadata['escalated_for'] = $beyond;
                    $result = $this->processWithSqlGeneration($naturalLanguageQuery, $schemeHint, $cachedResult, $metadata, $context);
                } else {
                    $result = $this->processWithIntent($naturalLanguageQuery, $schemeHint, $cachedResult, $metadata, $context);

                    // Fall back when intent mode could not answer — whether it
                    // failed outright, or asked a question it should not have
                    // needed to ask. A clarification is only offered here when
                    // the dataset chosen cannot express the breakdown but a
                    // related table can, so trying is strictly better than
                    // handing the user a menu they cannot usefully answer.
                    $couldNotAnswer = in_array($result['status'] ?? '', ['error', 'clarification_needed'], true);

                    if ($couldNotAnswer && ($result['_fallback_eligible'] ?? false)) {
                        Log::info('[NaturalQuery] Auto mode: falling back to sql_generation', [
                            'after' => $result['status'] ?? '?',
                        ]);
                        $metadata['query_mode'] = 'auto→sql_generation';
                        $generated = $this->processWithSqlGeneration($naturalLanguageQuery, $schemeHint, $cachedResult, $metadata, $context);

                        // Keep the clarification if generation did no better —
                        // a usable question beats a bare failure.
                        if (($generated['status'] ?? '') === 'success'
                            || ($result['status'] ?? '') === 'error') {
                            $result = $generated;
                        }
                    }
                }
            }

            // Retry with refined prompt on failure (if enabled).
            // Never retry a rate-limited request — it would only add load.
            if (($result['status'] ?? '') === 'error'
                && config('naturalquery.errors.retry_on_failure', true)
                && !($result['_retried'] ?? false)
                && !($result['_rate_limited'] ?? false)
            ) {
                // The failure so far is passed in, so the retry can decline to
                // overwrite a provider fault with a claim about the question.
                $result = $this->retryWithRefinedPrompt($naturalLanguageQuery, $schemeHint, $metadata, $result);
            }

            // Remove internal flags
            unset($result['_fallback_eligible'], $result['_rate_limited']);

            // Add timing
            $result['metadata'] = array_merge($result['metadata'] ?? [], [
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'cache_hit' => $cacheHit,
                'provider' => $this->llmProvider->getName(),
            ]);

            // What this answer cost. Absent for a cache hit, which is the
            // point of the cache, and absent for providers that report no
            // usage — an omitted figure is honest, a zero is not.
            if ($usage = $this->usageForThisQuestion()) {
                $result['metadata']['usage'] = $usage;
            }

            // Audit logging
            if (config('naturalquery.privacy.audit_queries', true) && ($result['status'] ?? '') === 'success') {
                $this->auditLog($result, $cacheHit, $startTime);
            }

            if (!$this->inStepExecution) {
                $this->announceOutcome($naturalLanguageQuery, $result, $cacheHit, $startTime);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('[NaturalQuery] Orchestrator error', ['error' => $e->getMessage()]);
            return $this->formatter->formatError('An error occurred processing your query.', $metadata, ErrorCode::INTERNAL);
        }
    }

    // =========================================================================
    // INTENT MODE
    // =========================================================================

    /**
     * Process query using intent parsing → local SQL builder.
     *
     * Flow: AI extracts (scheme, metric, order, limit, district) → SqlBuilder constructs SQL
     */
    protected function processWithIntent(string $query, ?string $schemeHint, ?array $cached, array &$metadata, array $context = []): array
    {
        $metadata['query_mode_used'] = 'intent';

        // A follow-up is meaningless on its own: "only in West" means one thing
        // after a revenue question and another after an order count. So a turn
        // carrying conversation state is never answered from cache and never
        // written to it — the words are the same and the question is not.
        $inConversation = !empty($context['state']);

        $intent = null;
        if ($cached && !$inConversation) {
            $intent = $this->normalizeIntent($cached['intent']);
        } else {
            $schemeList = $this->registry->getSchemeListForLlm();
            $intent = $this->normalizeIntent(
                $this->llmProvider->parseIntent($this->withState($query, $context), $schemeList)
            );

            if (!$inConversation && ($intent['success'] ?? false) && !($intent['needs_clarification'] ?? false)) {
                $this->cache->store($query, $intent);
            }
        }

        // A total is one number. Applied after the cache too, since a stored
        // intent can carry the same invented breakdown.
        $intent = $this->dropUnaskedBreakdown($intent, $query);

        // Apply scheme hint
        if ($schemeHint && empty($intent['scheme'])) {
            if ($this->registry->has($schemeHint)) {
                $intent['scheme'] = $schemeHint;
            }
        }

        // Fold the conversation's state into what actually runs.
        //
        // The state was previously used only as prompt context: the SQL was
        // built from THIS turn's intent alone, so "and what about Electronics?"
        // executed with the Electronics filter and without the West one, while
        // the state — and the line shown to the user — claimed both. A summary
        // that promises a narrowing the query does not apply is worse than no
        // summary at all.
        //
        // The same QueryState::merge the conversation uses, so what runs and
        // what is displayed cannot disagree.
        if (!empty($context['state'])) {
            $intent = \Jayanta\NaturalQuery\Conversation\QueryState::fromArray(['slots' => $context['state']])
                ->merge($intent, 0, $query)
                ->toIntent() + $intent;

            $intent = $this->narrowingRatherThanDetail($intent);
        }

        // Handle parse failure.
        //
        // success:false from a provider never means "the question was unclear"
        // — an unclear question comes back as a successful call carrying a
        // clarification. It means the call itself failed: no route to the host,
        // a rejected key, a reply that was not JSON. Reporting that as
        // not_understood sends the user off rewording a perfectly good question
        // while the real fault goes unmentioned.
        if (!($intent['success'] ?? true)) {
            // Rate limiting especially: falling back to sql_generation or
            // retrying would fire MORE calls at a provider already refusing
            // them.
            if (($intent['status'] ?? null) === 429) {
                return array_merge(
                    $this->formatter->formatError(self::RATE_LIMIT_MESSAGE, $metadata, ErrorCode::RATE_LIMITED),
                    ['_rate_limited' => true]
                );
            }

            // Still fallback-eligible: in auto mode a garbled intent response
            // is worth one attempt at SQL generation, which asks for something
            // simpler. If that fails too, its error is the one reported.
            return array_merge(
                $this->formatter->formatError($intent['error'] ?? 'The AI service could not be reached.', $metadata, ErrorCode::PROVIDER_ERROR),
                ['_fallback_eligible' => true]
            );
        }

        // Handle clarification
        $availableSchemes = $this->registry->getAvailableSchemes();
        $hasGroupValue = !empty($intent['group_value']);

        // With exactly one dataset there is nothing to choose between, so a
        // model that says "which dataset?" is really saying "I could not tell
        // what you meant" — about the metric, usually.
        if (empty($intent['scheme']) && count($availableSchemes) === 1) {
            $intent['scheme'] = $availableSchemes[0]['key'];
        }

        $hasScheme = !empty($intent['scheme']);

        // Asking which dataset is only meaningful when the dataset is genuinely
        // unresolved AND there is more than one to pick from. Asking it once
        // the scheme is known produced a card whose only button re-sent the
        // same question and redrew the same card — indistinguishable, from the
        // outside, from the widget being broken.
        if (!$hasScheme && count($availableSchemes) > 1) {
            return $this->formatter->formatClarification($intent, $availableSchemes);
        }

        if ($hasScheme && $hasGroupValue && empty($intent['metric'])) {
            // District detail — SqlBuilder handles this
        } elseif (($intent['needs_clarification'] ?? false) || !$hasScheme) {
            // Why the model asked, before it is rewritten below. The prompt
            // tells it to answer 'ambiguous' when the requested breakdown is
            // not available on the dataset it chose — which, across related
            // tables, usually means the answer needs a JOIN rather than a
            // question. "Revenue by region" is line_total in one table and
            // region in another: perfectly answerable, just not by a builder
            // that works within a single dataset.
            $askedBecause = $intent['clarification_type'] ?? null;

            // The dataset is settled; whatever is still unclear is a metric.
            $intent['clarification_type'] = 'metric';

            $clarification = $this->formatter->formatClarification(
                $intent,
                $availableSchemes,
                $hasScheme ? $this->registry->getSchemeMetrics($intent['scheme']) : []
            );

            // Asking the user to choose a metric they already named is a dead
            // end. Let auto mode try SQL generation, which can join across the
            // related tables and answer it outright.
            //
            // 'ambiguous' means the breakdown does not exist on the chosen
            // dataset. But a question that states its own measure — "how many
            // continents are there", "average horsepower" — is not ambiguous
            // whatever the model labelled it, and on a schema of mostly
            // dimension tables it labelled almost everything 'metric' and
            // asked. A whole Spider database scored zero that way.
            //
            // Retrying is safe: the clarification is kept unless generation
            // actually succeeds, so a genuinely open question ("which is the
            // best?") still gets asked rather than guessed at.
            $selfEvident = $this->statesItsOwnMeasure($query);

            if (($askedBecause === 'ambiguous' || $selfEvident) && $this->registry->hasLinkedSchemas()) {
                $clarification['_fallback_eligible'] = true;
            }

            return $clarification;
        }

        // Build SQL locally
        $queryResult = $this->sqlBuilder->buildQuery($intent);
        if (!$queryResult['success']) {
            return array_merge(
                $this->formatter->formatError($queryResult['error'] ?? 'Failed to build query', $metadata, ErrorCode::CANNOT_ANSWER),
                ['_fallback_eligible' => true]
            );
        }

        // Validate and execute
        $response = $this->validateAndExecute($queryResult, $intent['scheme'], $metadata);

        return $this->retryWithoutUnmatchedNameFilter($response, $intent, $metadata);
    }

    /**
     * Answer each step of a decomposed question, then combine them.
     *
     * Every step goes back through query() unchanged, so each one is intent
     * parsed, validated against the table whitelist and executed exactly like
     * a question typed on its own. Decomposition adds a planning call; it does
     * not add a second way into the database.
     *
     * A step that fails does not fail the whole answer — three of four numbers
     * is more useful than none, provided the response says so, which it does.
     */
    protected function runSteps(
        string $originalQuery,
        array $plan,
        ?string $schemeHint,
        array $metadata,
        float $startTime
    ): array {
        $steps = [];

        $this->inStepExecution = true;

        try {
            foreach ($plan['steps'] as $i => $question) {
                $result = $this->query($question, $schemeHint);
                $succeeded = ($result['status'] ?? '') === 'success';

                $steps[] = [
                    'n' => $i + 1,
                    'question' => $question,
                    'status' => $succeeded ? 'success' : 'error',
                    'answer' => $result['answer'] ?? ($result['error'] ?? null),
                    'rows' => $result['rows'] ?? [],
                    'metric' => $result['parsed_query']['metric'] ?? null,
                    'group_by' => $result['parsed_query']['group_by'] ?? null,
                    // The period this step actually used. "Last year" is read
                    // as calendar 2025 by some models and as a trailing twelve
                    // months by others; the two differ by millions and the
                    // number alone shows nothing. A step that states its own
                    // period lets the reader see which was meant.
                    'period' => $result['parsed_query']['period'] ?? null,
                    'insights' => $result['insights'] ?? null,
                    'next_steps' => $result['next_steps'] ?? [],
                ];
            }
        } finally {
            // Restored even if a step throws, or the next ordinary question
            // silently loses the ability to be decomposed.
            $this->inStepExecution = false;
        }

        $synthesis = $this->synthesizer->synthesize($originalQuery, $steps, $plan['comparison'] ?? false);
        $successful = array_values(array_filter($steps, fn ($s) => $s['status'] === 'success'));

        $response = [
            'status' => empty($successful) ? 'error' : 'success',
            'type' => 'multi_step',
            'answer' => $synthesis['answer'],
            'steps' => $steps,
            'comparison' => $synthesis['comparison'],
            // Kept for clients that render a single result table: the last
            // step's rows are the ones a follow-up would build on.
            'rows' => empty($successful) ? [] : end($successful)['rows'],
            'visualization' => 'steps',
            'parsed_query' => [
                'scheme' => $schemeHint,
                'multi_step' => true,
                'step_count' => count($steps),
            ],
            'metadata' => array_merge($metadata, [
                'multi_step' => true,
                'steps_planned' => count($plan['steps']),
                'steps_succeeded' => count($successful),
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]),
        ];

        // The conversation continues from where it ended, so the follow-ups
        // offered are the last successful step's.
        if (!empty($successful)) {
            $last = end($successful);

            if (!empty($last['next_steps'])) {
                $response['next_steps'] = $last['next_steps'];
            }
        }

        if (config('naturalquery.response.include_speech_text', true)) {
            $response['speech_text'] = $synthesis['answer'];
        }

        return $response;
    }

    /**
     * Put the conversation's state in front of the utterance.
     *
     * A structured summary, not a transcript. The model is asked to resolve ONE
     * instruction against a handful of named slots, rather than to re-read four
     * turns of dialogue and work out for itself what still applies — which is
     * both more to get wrong and more that changes when any earlier turn is
     * worded differently.
     */
    protected function withState(string $query, array $context): string
    {
        if (empty($context['state'])) {
            return $query;
        }

        $slots = [];

        foreach ($context['state'] as $slot => $value) {
            if ($value !== null && $value !== '' && $slot !== 'query_type') {
                $slots[] = $slot . '=' . (is_scalar($value) ? $value : json_encode($value));
            }
        }

        if (!$slots) {
            return $query;
        }

        // The narrowing rule is spelled out because leaving it implicit lost
        // it. "Only in Guwahati" after "total amount by city" came back from
        // one provider with no filter at all — every city returned, the
        // instruction silently discarded — and from two others as a request for
        // one record's detail rows. Naming the slot removes the guess.
        return "CURRENT QUERY STATE (carry these forward unless the instruction changes them):\n"
            . '  ' . implode('; ', $slots) . "\n"
            . "NEW INSTRUCTION: \"{$query}\"\n"
            . "A narrowing — \"only in X\", \"just for X\", \"in X\" — goes in filters as "
            . "{\"column\":\"<the column X belongs to>\",\"value\":\"X\"}, and the existing "
            . "group_by STAYS. Do not put it in group_value: that means one named record "
            . "and returns its detail rows instead of the narrowed answer.\n"
            . 'Return the FULL intent after applying the instruction to that state.';
    }

    /**
     * Does the question already say what to measure?
     *
     * "How many continents are there" names its own measure — every dataset
     * can be counted — so a request to choose a metric is not a real question,
     * it is a dead end. "Which is the best?" names nothing and deserves to be
     * asked about.
     */
    protected function statesItsOwnMeasure(string $query): bool
    {
        return (bool) preg_match(
            '/\b(?:how\s+many|how\s+much|number\s+of|count\s+of|total|sum|average|mean|median|minimum|maximum|min|max|highest|lowest|largest|smallest)\b/i',
            $query
        );
    }

    /**
     * Bring an intent onto the current contract.
     *
     * The field naming a single record to filter to used to be called
     * `district` — vocabulary from the project this package came out of, which
     * meant nothing on anyone else's database and which models mis-filled on
     * other domains. It is `group_value` now, matching the builder's own
     * group_column terminology.
     *
     * The old key is still read, because it can still arrive from three
     * places: a cache row written before the rename, a custom prompt override
     * in `prompts.intent_parsing`, or a third-party provider written against
     * the old contract.
     *
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    /**
     * "How many invoices are pending" is one number, not a league table.
     *
     * Both Gemini and DeepSeek answered that question with
     * query_type=ranking and group_by=client, producing "Rekha Stores: 1
     * records" where the answer is "1". Two providers agreeing means the
     * prompt is not carrying it, so this is decided locally instead — the same
     * reasoning as every other guard here: a rule that must hold is cheaper to
     * enforce than to ask for.
     *
     * The count was right, which is what makes it worth fixing. A wrong number
     * gets questioned; a right number wearing the wrong shape gets read as
     * "only Rekha Stores has pending invoices", which is a different claim and
     * one nobody checked.
     *
     * Only fires when the sentence asks for a total AND names no breakdown. A
     * breakdown that was asked for is never touched, and neither is a question
     * that did not ask for a total — "top clients by amount" has no total
     * wording and keeps its grouping.
     *
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    protected function dropUnaskedBreakdown(array $intent, string $query): array
    {
        // Deliberately NOT skipped when group_by is empty. The breakdown comes
        // from two places: the model can name one, and SqlBuilder falls back to
        // the schema's default group column when it does not. An early return
        // on an empty group_by fixed DeepSeek, which names one, and left Gemini
        // exactly as it was, because Gemini names none and the default supplies
        // it downstream. Saying "aggregation" is what stops that fallback.

        // "by region", "per customer", "for each status", "breakdown by" — any
        // of these and the grouping was requested.
        if (preg_match('/\b(?:by|per|each|breakdown|split|grouped)\b/i', $query)) {
            return $intent;
        }

        // Asks for a single figure over the whole set.
        $wantsOneNumber = preg_match(
            '/\b(?:how\s+many|how\s+much|total|sum|count|average|mean|number\s+of)\b/i',
            $query
        );

        if (!$wantsOneNumber) {
            return $intent;
        }

        if (($intent['query_type'] ?? null) === 'aggregation' && empty($intent['group_by'])) {
            return $intent; // already right; nothing to say
        }

        Log::info('[NaturalQuery] Answering as a total, not a breakdown', [
            'group_by' => $intent['group_by'] ?? '(schema default)',
            'query' => $query,
        ]);

        $intent['group_by'] = null;
        $intent['query_type'] = 'aggregation';

        return $intent;
    }

    /**
     * "Only in Guwahati" narrows the answer; it does not ask for a file card.
     *
     * A bare `group_value` means "one named record" and routes to the detail
     * view — every column of the matching rows. That is a reasonable reading of
     * "revenue for Guwahati" asked cold. It is the wrong reading of "only in
     * Guwahati" said straight after "total amount by city", where the user is
     * plainly narrowing the answer they are looking at.
     *
     * Three providers demonstrated three different wrong answers to exactly
     * that pair. Claude and DeepSeek set group_value and got a raw dump of
     * invoice rows with the breakdown silently changed; Gemini set nothing at
     * all and returned every city, the narrowing quietly discarded.
     *
     * So during a conversation a bare group_value is re-read as a filter on the
     * column already being grouped by, which is what "only in X" means. The
     * grouping survives, so the answer is the one row asked for rather than a
     * table with the question changed underneath it.
     *
     * Only inside a conversation. A one-shot "revenue for Guwahati" has no
     * established breakdown to narrow and keeps the detail reading.
     *
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    protected function narrowingRatherThanDetail(array $intent): array
    {
        $value = $intent['group_value'] ?? null;
        $groupBy = $intent['group_by'] ?? null;

        if ($value === null || $value === '' || empty($groupBy)) {
            return $intent;
        }

        // Already expressed as a filter, on any column: leave it alone.
        foreach (($intent['filters'] ?? []) as $filter) {
            if (is_array($filter) && isset($filter['value'])
                && strcasecmp(trim((string) $filter['value']), trim((string) $value)) === 0) {
                $intent['group_value'] = null;

                return $intent;
            }
        }

        Log::info('[NaturalQuery] Reading a follow-up as a narrowing, not a detail view', [
            'value' => $value,
            'column' => $groupBy,
        ]);

        $intent['filters'] = array_merge(
            is_array($intent['filters'] ?? null) ? $intent['filters'] : [],
            [['column' => $groupBy, 'value' => $value]]
        );
        $intent['group_value'] = null;

        return $intent;
    }

    protected function normalizeIntent(array $intent): array
    {
        if (!array_key_exists('group_value', $intent) && array_key_exists('district', $intent)) {
            $intent['group_value'] = $intent['district'];
        }

        unset($intent['district']);

        return $this->dropDuplicatedGroupValue($intent);
    }

    /**
     * One constraint, expressed twice.
     *
     * Models sometimes put the same value in `group_value` AND in `filters`.
     * "How many invoices are pending" came back with filters=[status:pending]
     * and group_value="pending" — the same narrowing said two ways.
     *
     * That is not harmless. `group_value` matches against the GROUP column, so
     * the copy asks for a *client* named "pending"; and its mere presence
     * disqualifies the query from being a total, which is how a question with
     * a plain numeric answer came back as a one-row league table.
     *
     * `filters` is the better of the two — it names the column — so the bare
     * copy goes. Compared case-insensitively, since the two rarely agree on
     * capitalisation.
     *
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    protected function dropDuplicatedGroupValue(array $intent): array
    {
        $value = $intent['group_value'] ?? null;

        if ($value === null || $value === '' || empty($intent['filters']) || !is_array($intent['filters'])) {
            return $intent;
        }

        foreach ($intent['filters'] as $filter) {
            if (!is_array($filter) || !isset($filter['value'])) {
                continue;
            }

            if (strcasecmp(trim((string) $filter['value']), trim((string) $value)) === 0) {
                Log::info('[NaturalQuery] Same filter given twice; keeping the one that names its column', [
                    'group_value' => $value,
                    'column' => $filter['column'] ?? null,
                ]);

                $intent['group_value'] = null;
                break;
            }
        }

        return $intent;
    }

    /**
     * Recover when a name filter matched nothing.
     *
     * The intent contract lets the model name a single record to filter by.
     * It sometimes fills that in with a word that is really the grouping
     * dimension — "top 5 customers by revenue" occasionally comes back with
     * the filter set to "customers" — and the resulting WHERE clause matches
     * no rows. The user then gets "No data found for customers", which is a
     * dead end and simply wrong: drop the filter and the question answers
     * perfectly.
     *
     * So when a filtered query finds nothing, run it again without the filter.
     * This costs one local query and no API call, and the answer says plainly
     * that the name did not match rather than quietly pretending it was never
     * asked for.
     */
    protected function retryWithoutUnmatchedNameFilter(array $response, array $intent, array $metadata): array
    {
        $filter = $intent['group_value'] ?? null;

        if (($response['type'] ?? null) !== 'no_data' || empty($filter)) {
            return $response;
        }

        $unfiltered = $intent;
        $unfiltered['group_value'] = null;

        $rebuilt = $this->sqlBuilder->buildQuery($unfiltered);
        if (!($rebuilt['success'] ?? false)) {
            return $response;
        }

        $fallback = $this->validateAndExecute($rebuilt, $unfiltered['scheme'] ?? null, $metadata);

        // Only prefer the fallback if it actually found something.
        if (($fallback['status'] ?? '') !== 'success' || ($fallback['type'] ?? null) === 'no_data') {
            return $response;
        }

        Log::info('[NaturalQuery] Name filter matched nothing; answered without it', [
            'unmatched_filter' => $filter,
            'scheme' => $unfiltered['scheme'] ?? null,
        ]);

        $fallback['answer'] = "No match for \"{$filter}\", so this covers everything. "
            . ($fallback['answer'] ?? '');
        $fallback['metadata'] = array_merge($fallback['metadata'] ?? [], [
            'unmatched_filter' => $filter,
            'filter_dropped' => true,
        ]);

        return $fallback;
    }

    // =========================================================================
    // SQL GENERATION MODE
    // =========================================================================

    /**
     * Process query using AI-generated SQL.
     *
     * Flow: AI receives full schema → generates SQL → validate → execute
     * The AI sees every table, column, type, description, alias, and JOIN.
     */
    protected function processWithSqlGeneration(string $query, ?string $schemeHint, ?array $cached, array &$metadata, array $context = []): array
    {
        $metadata['query_mode_used'] = 'sql_generation';

        // The conversation, carried into the SQL prompt.
        //
        // This method did not take $context at all, so every follow-up that
        // escalated here lost the whole accumulated state. "Total amount by
        // city" → "only in Guwahati" → "breakdown by client" came back with
        // all three clients: the Guwahati filter, established two turns
        // earlier and displayed in the state summary the user was reading,
        // simply gone from the SQL. A complete answer to a question nobody
        // asked, which is the failure this package exists to prevent.
        //
        // Intent mode had carried state since conversations were built. This
        // path never did, and only showed it when a follow-up happened to
        // escalate — which depends on the provider, so it hid behind whichever
        // one was being tested.
        $stated = $this->withState($query, $context);

        // Check if we have a cached SQL result
        if ($cached && isset($cached['intent']['_sql_result'])) {
            $sqlResult = $cached['intent']['_sql_result'];
            return $this->validateAndExecute($sqlResult, $sqlResult['scheme'] ?? null, $metadata);
        }

        // Step 1: Identify the scheme (priority: hint → routing → keywords → LLM intent)
        $scheme = $schemeHint;
        if (!$scheme || !$this->registry->has($scheme)) {
            // Try keyword/routing detection first (fast, no API call)
            $scheme = $this->detectSchemeFromKeywords($query);
        }

        if (!$scheme || !$this->registry->has($scheme)) {
            // Fall back to LLM intent parsing (slower, requires API call)
            $schemeList = $this->registry->getSchemeListForLlm();
            $intent = $this->llmProvider->parseIntent($query, $schemeList);
            $scheme = $intent['scheme'] ?? null;

            if (!$scheme) {
                $scheme = $this->registry->findByAlias($query);
            }
        }

        // Step 2: Build the prompt.
        //
        // A single-table prompt is sharper when it is the right table. But on a
        // normalised schema the routing above matches the table NAMED in the
        // question, which is often a dimension table rather than the one
        // holding the numbers: "top customers by revenue" routes to
        // `customers`, whose prompt has no revenue in it, and the model
        // correctly replies that it does not know which metric is meant.
        //
        // So when the tables are linked by foreign keys, any question may
        // legitimately span them and the multi-table prompt — which lists every
        // table, their relationships, and permission to join — is the only one
        // that can answer. Fall back to the focused prompt when there is one
        // dataset, or when nothing is related and a join is impossible anyway.
        // $stated, not $query: the SQL must reflect the conversation, not just
        // the last sentence of it. Scheme detection above deliberately still
        // uses the bare question — the state block would match every dataset
        // name it mentions.
        if ($scheme && $this->registry->has($scheme) && !$this->registry->hasLinkedSchemas()) {
            $prompt = $this->promptBuilder->buildSqlPrompt($scheme, $stated);
        } else {
            $prompt = $this->promptBuilder->buildMultiSchemePrompt($stated);
        }

        // Ask AI to generate SQL
        $response = $this->llmProvider->generateSql($prompt);

        if (!$response['success']) {
            return $this->providerFailure($response, $metadata);
        }

        $data = $response['data'];

        // AI returned an error / needs clarification
        if (isset($data['error'])) {
            $intent = [
                'scheme' => null,
                'metric' => null,
                'group_value' => null,
                'confidence' => 0,
                'needs_clarification' => $data['needs_clarification'] ?? true,
                'clarification_type' => $data['clarification_type'] ?? 'ambiguous',
            ];

            return $this->formatter->formatClarification($intent, $this->registry->getAvailableSchemes());
        }

        // AI generated SQL
        $sql = $data['sql'] ?? null;
        $scheme = $data['scheme'] ?? $schemeHint;

        if (!$sql) {
            return $this->formatter->formatError('AI did not generate a SQL query', $metadata, ErrorCode::PROVIDER_ERROR);
        }

        // Replace computed metric names if AI used them as column names
        if ($scheme && $this->registry->has($scheme)) {
            $sql = $this->replaceComputedMetrics($sql, $scheme);
        }

        // Build query result
        $schemaData = $scheme ? $this->registry->get($scheme) : null;
        $queryResult = [
            'success' => true,
            'sql' => $sql,
            'scheme' => $scheme,
            'scheme_name' => $schemaData['name'] ?? $scheme,
            'metric' => $data['metric'] ?? null,
            'metric_description' => $data['explanation'] ?? ($data['metric'] ?? 'data'),
            'metric_unit' => '',
            'metric_type' => 'neutral',
            'group_value' => $data['group_value'] ?? null,
            'limit' => $data['limit'] ?? config('naturalquery.sql.default_limit', 100),
            'order' => $data['order'] ?? 'DESC',
            'query_type' => $data['query_type'] ?? 'ranking',
            'group_column' => $scheme ? $this->registry->getGroupColumn($scheme) : 'name',
        ];

        // Resolve metric unit/type from schema if possible
        if ($scheme && $queryResult['metric']) {
            $metricData = $this->resolveMetricData($scheme, $queryResult['metric']);
            if ($metricData) {
                $queryResult['metric_description'] = $metricData['description'] ?? $queryResult['metric_description'];
                $queryResult['metric_unit'] = $metricData['unit'] ?? '';
                $queryResult['metric_type'] = $metricData['type'] ?? $metricData['metric_type'] ?? 'neutral';
            }
        }

        // Self-verification: AI checks its own SQL before execution
        if ($this->shouldVerify($metadata)) {
            $verification = $this->verifier->verify($query, $queryResult['sql'], $scheme);

            $metadata['verification'] = [
                'confidence' => $verification['confidence'],
                'passed' => $verification['passed'],
                'attempts' => $verification['attempt'],
            ];

            // If verification provided a fixed SQL, use it
            if ($verification['fixed_sql']) {
                $queryResult['sql'] = $verification['fixed_sql'];
                $metadata['verification']['sql_corrected'] = true;
                Log::info('[NaturalQuery:Verifier] SQL corrected', [
                    'issue' => $verification['issues'],
                ]);
            }
        }

        // Cache the VERIFIED SQL result for future identical queries
        $this->cache->store($query, [
            'scheme' => $scheme,
            'metric' => $queryResult['metric'],
            'group_value' => $queryResult['group_value'],
            'limit' => $queryResult['limit'],
            'order' => $queryResult['order'],
            'query_type' => $queryResult['query_type'],
            '_sql_result' => $queryResult,
        ]);

        // Validate and execute
        return $this->validateAndExecute($queryResult, $scheme, $metadata);
    }

    // =========================================================================
    // RETRY LOGIC
    // =========================================================================

    /**
     * Retry a failed query with a refined prompt.
     *
     * Strategy:
     * 1. Try to detect scheme from query keywords/aliases
     * 2. If found, retry with single-scheme SQL prompt (much more accurate)
     * 3. If still no scheme, retry with explicit instruction to generate SQL
     */
    /**
     * Say what actually went wrong with a provider call.
     *
     * A failed request is not a failure to understand, and reporting it as one
     * sends the user off rewording a perfectly good question while the real
     * fault — an expired key, a rate limit, no route to the host — goes
     * unmentioned. The benchmark suite lost a CA bundle and every single case
     * came back "Could not understand the query. Try mentioning a dataset
     * name", which is a lie that costs an afternoon.
     *
     * @param array<string, mixed> $response Provider response with success:false
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    protected function providerFailure(array $response, array $metadata): array
    {
        if (($response['status'] ?? null) === 429) {
            return array_merge(
                $this->formatter->formatError(self::RATE_LIMIT_MESSAGE, $metadata, ErrorCode::RATE_LIMITED),
                ['_rate_limited' => true]
            );
        }

        return $this->formatter->formatError(
            $response['error'] ?? 'AI failed to generate SQL',
            $metadata,
            ErrorCode::PROVIDER_ERROR
        );
    }

    protected function retryWithRefinedPrompt(string $query, ?string $schemeHint, array $metadata, array $previous = []): array
    {
        $metadata['_retried'] = true;
        $metadata['retry'] = true;
        Log::info('[NaturalQuery] Retrying with refined prompt', ['query' => $query]);

        // Strategy 1: Try keyword-based scheme detection from all aliases
        $scheme = $schemeHint;
        if (!$scheme) {
            $scheme = $this->detectSchemeFromKeywords($query);
        }

        if ($scheme && $this->registry->has($scheme)) {
            Log::info('[NaturalQuery] Retry: detected scheme from keywords', ['scheme' => $scheme]);
            // Use single-scheme SQL prompt — much more reliable
            $prompt = $this->promptBuilder->buildSqlPrompt($scheme, $query);
            $response = $this->llmProvider->generateSql($prompt);

            // The retry is the last thing that runs before the "could not
            // understand" message, so a provider fault swallowed here is a
            // provider fault reported as a bad question.
            if (!($response['success'] ?? false)) {
                return $this->providerFailure($response, $metadata);
            }

            if (!isset($response['data']['sql'])) {
                // The dataset was identified and the provider answered without
                // SQL. Telling the user to name a dataset would send them off
                // supplying the one thing that was never missing.
                $name = $this->registry->get($scheme)['name'] ?? $scheme;

                return $this->formatter->formatError(
                    "That could not be answered from {$name}. Try naming the measure you want, or rephrasing the breakdown.",
                    $metadata,
                    ErrorCode::CANNOT_ANSWER
                );
            }

            $data = $response['data'];
            $schemaData = $this->registry->get($scheme);

            $queryResult = [
                'success' => true,
                'sql' => $data['sql'],
                'scheme' => $scheme,
                'scheme_name' => $schemaData['name'] ?? $scheme,
                'metric' => $data['metric'] ?? null,
                'metric_description' => $data['explanation'] ?? '',
                'metric_unit' => '',
                'metric_type' => 'neutral',
                'group_value' => $data['group_value'] ?? null,
                'limit' => $data['limit'] ?? config('naturalquery.sql.default_limit', 100),
                'order' => $data['order'] ?? 'DESC',
                'query_type' => $data['query_type'] ?? 'ranking',
                'group_column' => $scheme ? $this->registry->getGroupColumn($scheme) : 'name',
            ];

            $result = $this->validateAndExecute($queryResult, $scheme, $metadata);
            $result['_retried'] = true;

            return $result;
        }

        // Strategy 2: say we could not read the question — but only when that
        // is what happened.
        //
        // Everything upstream funnels here, so this message was the last word
        // on failures that had nothing to do with the wording: an expired key,
        // no route to the host, a provider returning nonsense. Phase 9 fixed
        // the two places that mislabelled such failures, and they still ended
        // up overwritten here whenever no scheme could be guessed from the
        // words — which is exactly what happens when the provider never
        // answered and there is no intent to guess from.
        //
        // Caught by pointing a real install at a provider with no API key: the
        // transcription was perfect, and the answer was "Try mentioning a
        // dataset name."
        if ($this->isProviderFailure($previous)) {
            return array_merge($previous, ['_retried' => true]);
        }

        $schemes = array_map(fn($s) => $s['name'] . ' (' . $s['key'] . ')', $this->registry->getAvailableSchemes());
        $schemeList = implode(', ', array_slice($schemes, 0, 10));

        return $this->formatter->formatError(
            "Could not understand the query. Try mentioning a dataset name. Available: {$schemeList}",
            $metadata,
            ErrorCode::NOT_UNDERSTOOD
        );
    }

    /**
     * Did this failure come from the provider rather than from the question?
     *
     * @param array<string, mixed> $result
     */
    protected function isProviderFailure(array $result): bool
    {
        return in_array(
            $result['error_code'] ?? null,
            [ErrorCode::PROVIDER_ERROR, ErrorCode::RATE_LIMITED],
            true
        );
    }

    /**
     * Detect scheme from query keywords.
     *
     * Priority:
     * 1. User-defined query_routing rules (most specific, highest priority)
     * 2. Schema aliases (from schema config files)
     * 3. Column aliases (last resort)
     */
    protected function detectSchemeFromKeywords(string $query): ?string
    {
        $queryLower = strtolower($query);

        // Priority 1: User-defined routing rules (longest match first)
        $routing = config('naturalquery.query_routing', []);
        if (!empty($routing)) {
            // Sort by key length DESC — longer phrases matched first
            uksort($routing, fn($a, $b) => strlen($b) - strlen($a));

            foreach ($routing as $keyword => $schemeKey) {
                if (str_contains($queryLower, strtolower($keyword))) {
                    if ($this->registry->has($schemeKey)) {
                        Log::debug('[NaturalQuery] Route matched', ['keyword' => $keyword, 'scheme' => $schemeKey]);
                        return $schemeKey;
                    }
                }
            }
        }

        // Priority 2: Schema aliases (longest first for best match)
        foreach ($this->registry->all() as $key => $schema) {
            if (str_contains($queryLower, strtolower($key))) {
                return $key;
            }

            $aliases = $schema['aliases'] ?? [];
            usort($aliases, fn($a, $b) => strlen($b) - strlen($a));

            foreach ($aliases as $alias) {
                if (str_contains($queryLower, strtolower($alias))) {
                    return $key;
                }
            }
        }

        // Priority 3: Column aliases
        foreach ($this->registry->all() as $key => $schema) {
            $columns = $schema['tables']['primary']['columns'] ?? [];
            foreach ($columns as $colName => $colDef) {
                foreach ($colDef['aliases'] ?? [] as $alias) {
                    if (str_contains($queryLower, strtolower($alias))) {
                        return $key;
                    }
                }
            }
        }

        return null;
    }

    // =========================================================================
    // SHARED EXECUTION
    // =========================================================================

    /**
     * Validate SQL, execute it, and format the response.
     */
    protected function validateAndExecute(array $queryResult, ?string $scheme, array $metadata): array
    {
        $sql = $queryResult['sql'];

        // Remembered for the QuestionAnswered event, which is server-side.
        // The SQL is deliberately kept OUT of the HTTP response — a browser has
        // no use for it and it describes the shape of the database — but it is
        // the single most useful field a listener can log.
        $this->lastSql = $sql;

        // Validate SQL security
        $allowedTables = $this->registry->getAllowedTables();
        $maxLimit = $scheme ? $this->registry->getMaxLimit($scheme) : config('naturalquery.sql.max_limit');
        $requireLimit = $maxLimit !== null;

        $validation = $this->validator->validate($sql, $allowedTables, [
            'max_limit' => $maxLimit,
            'require_limit' => $requireLimit,
        ]);

        if (!$validation['valid']) {
            Log::warning('[NaturalQuery] SQL validation failed', ['sql' => $sql, 'reason' => $validation['reason']]);

            // A security signal, so it gets its own event rather than being
            // one flavour of failure. Under normal use it should almost never
            // fire; a burst from one user is worth looking at.
            Event::dispatch(new UnsafeSqlRejected(
                question: (string) ($metadata['original_query'] ?? $queryResult['question'] ?? ''),
                sql: $sql,
                reason: (string) $validation['reason'],
                provider: $this->llmProvider->getName(),
            ));

            return $this->formatter->formatError('Query validation failed: ' . $validation['reason'], $metadata, ErrorCode::UNSAFE_SQL);
        }

        // Execute SQL (with parameterized bindings when available)
        $connection = $scheme ? $this->registry->getConnection($scheme) : config('naturalquery.sql.database_connection');
        $bindings = $queryResult['bindings'] ?? [];

        try {
            $rows = $connection
                ? DB::connection($connection)->select($sql, $bindings)
                : DB::select($sql, $bindings);
        } catch (\Exception $e) {
            Log::error('[NaturalQuery] SQL execution failed', ['sql' => $sql, 'error' => $e->getMessage()]);
            return $this->formatter->formatError('Database query failed: ' . $this->sanitizeDbError($e->getMessage()), $metadata, ErrorCode::DATABASE_ERROR);
        }

        // Empty results
        if (empty($rows)) {
            $response = $this->formatter->formatNoData($queryResult);
            $response['metadata'] = $metadata;
            return $response;
        }

        // Format response
        $response = $this->formatter->format($queryResult, $rows);
        $response['metadata'] = array_merge($metadata, [
            'scheme_name' => $queryResult['scheme_name'] ?? null,
            'metric_unit' => $queryResult['metric_unit'] ?? null,
        ]);

        // What to ask next. Derived from the schema, so it costs no API call.
        if ($this->suggester) {
            $nextSteps = $this->suggester->suggest($queryResult, $rows);

            if ($nextSteps) {
                $response['next_steps'] = $nextSteps;
            }
        }

        return $response;
    }

    /**
     * Replace computed metric names with their SQL expressions.
     * Safety net if AI uses a computed metric name as a column name.
     */
    protected function replaceComputedMetrics(string $sql, string $scheme): string
    {
        $computed = $this->registry->getComputedMetrics($scheme);
        if (empty($computed)) {
            return $sql;
        }

        foreach ($computed as $metricName => $metricData) {
            $expression = $metricData['expression'];

            // Replace in ORDER BY
            $sql = preg_replace(
                '/\bORDER\s+BY\s+' . preg_quote($metricName, '/') . '\b/i',
                'ORDER BY ' . $expression,
                $sql
            );

            // Replace in SELECT (if used as column name without AS)
            if (preg_match('/\bSELECT\b.*\b' . preg_quote($metricName, '/') . '\b.*\bFROM\b/is', $sql)) {
                if (!preg_match('/AS\s+' . preg_quote($metricName, '/') . '\b/i', $sql)) {
                    $sql = preg_replace(
                        '/(\bSELECT\s+(?:.*?,\s*)?)' . preg_quote($metricName, '/') . '(\s*(?:,|\s+FROM\b))/i',
                        '$1' . $expression . ' AS ' . $metricName . '$2',
                        $sql
                    );
                }
            }
        }

        return $sql;
    }

    /**
     * Resolve metric metadata from schema config.
     */
    protected function resolveMetricData(string $scheme, string $metric): ?array
    {
        $metrics = $this->registry->getMetrics($scheme);
        if (isset($metrics[$metric])) {
            return $metrics[$metric];
        }

        $computed = $this->registry->getComputedMetrics($scheme);
        if (isset($computed[$metric])) {
            return $computed[$metric];
        }

        // Try alias resolution
        $resolved = $this->registry->resolveMetric($scheme, $metric);
        if ($resolved) {
            return $metrics[$resolved] ?? $computed[$resolved] ?? null;
        }

        return null;
    }

    /**
     * Should we verify the AI-generated SQL?
     */
    protected function shouldVerify(array $metadata): bool
    {
        if (!config('naturalquery.verification.enabled', true)) {
            return false;
        }
        if (config('naturalquery.verification.skip_on_cache_hit', true) && ($metadata['cache_hit'] ?? false)) {
            return false;
        }
        if ($metadata['_retried'] ?? false) {
            return false;
        }
        return true;
    }

    /**
     * Sanitize database error messages for user display.
     */
    protected function sanitizeDbError(string $message): string
    {
        // Remove SQL details, keep only the human-readable part
        if (preg_match('/ERROR:\s*(.+?)(?:\(|$)/i', $message, $m)) {
            return trim($m[1]);
        }
        return 'An error occurred while querying the database.';
    }

    /**
     * Audit log for successful queries.
     */
    /**
     * Tell the application how the question ended.
     *
     * A clarification is neither: being asked which measure you meant is the
     * system working, and firing a failure event for it would fill an alerting
     * channel with successes.
     *
     * @param array<string, mixed> $result
     */
    protected function announceOutcome(string $question, array $result, bool $cacheHit, float $startTime): void
    {
        $status = $result['status'] ?? null;
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $metadata = $result['metadata'] ?? [];

        if ($status === 'success') {
            Event::dispatch(new QuestionAnswered(
                question: $question,
                parsedQuery: $result['parsed_query'] ?? [],
                sql: $result['sql'] ?? $this->lastSql,
                rowCount: count($result['rows'] ?? []),
                modeUsed: $metadata['query_mode_used'] ?? null,
                cacheHit: $cacheHit,
                durationMs: $durationMs,
                provider: $this->llmProvider->getName(),
                usage: $metadata['usage'] ?? [],
            ));

            return;
        }

        if ($status === 'error') {
            Event::dispatch(new QuestionFailed(
                question: $question,
                errorCode: $result['error_code'] ?? ErrorCode::INTERNAL,
                message: (string) ($result['error'] ?? ''),
                provider: $this->llmProvider->getName(),
                durationMs: $durationMs,
            ));
        }
    }

    /**
     * Tokens spent answering the question in flight, if the provider says.
     *
     * @return array<string, int>
     */
    protected function usageForThisQuestion(): array
    {
        return $this->llmProvider instanceof ReportsUsage
            ? $this->llmProvider->lastUsage()
            : [];
    }

    protected function auditLog(array $result, bool $cacheHit, float $startTime): void
    {
        $channel = config('naturalquery.privacy.audit_channel');
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();
        $logger->info('[NaturalQuery] Query executed', [
            'scheme' => $result['parsed_query']['scheme'] ?? null,
            'query_type' => $result['parsed_query']['query_type'] ?? null,
            'metric' => $result['parsed_query']['metric'] ?? null,
            'rows' => count($result['rows'] ?? []),
            'cache_hit' => $cacheHit,
            'mode' => $result['metadata']['query_mode_used'] ?? 'unknown',
            'processing_ms' => round((microtime(true) - $startTime) * 1000, 2),
            // In the audit line as well as the response, so cost is answerable
            // from the logs without every caller having to store it.
            'usage' => $result['metadata']['usage'] ?? null,
        ]);
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function getSchemes(): array
    {
        return $this->registry->getAvailableSchemes();
    }

    /**
     * The schema registry this orchestrator resolves against.
     *
     * Exposed so callers describing what can be asked — the /schemes endpoint,
     * a custom front end — read the same registry the engine answers from,
     * rather than a copy that can disagree with it.
     */
    public function registry(): SchemaRegistry
    {
        return $this->registry;
    }

    public function getSchemeMetrics(string $schemeKey): array
    {
        return $this->registry->getSchemeMetrics($schemeKey);
    }

    public function healthCheck(): array
    {
        $providerHealth = $this->llmProvider->healthCheck();
        $cacheStats = $this->cache->getStatistics();

        return [
            'status' => $providerHealth['status'] === 'ok' ? 'healthy' : 'degraded',
            'provider' => [
                'name' => $this->llmProvider->getName(),
                'status' => $providerHealth['status'],
                'model' => $providerHealth['model'] ?? null,
            ],
            'cache' => [
                'enabled' => ($cacheStats['enabled'] ?? false),
                'total_entries' => $cacheStats['total_entries'] ?? 0,
                'total_hits' => $cacheStats['total_hits'] ?? 0,
            ],
            'schemas' => [
                'loaded' => count($this->registry->all()),
                'keys' => $this->registry->keys(),
            ],
            'query_mode' => config('naturalquery.query_mode', 'auto'),
            'security' => [
                'input_guard' => true,
                'sql_validator' => true,
                'ai_guard' => $this->inputGuard->hasAiGuard(),
                'rate_limiting' => str_contains(implode(',', config('naturalquery.routes.middleware', [])), 'throttle'),
            ],
            'verification' => [
                'enabled' => config('naturalquery.verification.enabled', true),
                'confidence_threshold' => config('naturalquery.verification.confidence_threshold', 0.7),
            ],
        ];
    }

    public function getCacheStats(): array
    {
        return $this->cache->getStatistics();
    }

    public function clearCache(?string $scheme = null, int $olderThanDays = 0, int $minHits = 0): int
    {
        return $this->cache->clear($scheme, $olderThanDays, $minHits);
    }
}
