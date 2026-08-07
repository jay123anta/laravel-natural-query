<?php

namespace Jayanta\NaturalQuery\Engine;

use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Contracts\QueryCacheInterface;
use Jayanta\NaturalQuery\Contracts\SqlValidatorInterface;
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
                    $metadata
                );
            }
            $naturalLanguageQuery = $guardResult['query']; // Use sanitized version

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
                $result = $this->processWithSqlGeneration($naturalLanguageQuery, $schemeHint, $cachedResult, $metadata);
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
                    $result = $this->processWithSqlGeneration($naturalLanguageQuery, $schemeHint, $cachedResult, $metadata);
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
                        $generated = $this->processWithSqlGeneration($naturalLanguageQuery, $schemeHint, $cachedResult, $metadata);

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
                $result = $this->retryWithRefinedPrompt($naturalLanguageQuery, $schemeHint, $metadata);
            }

            // Remove internal flags
            unset($result['_fallback_eligible'], $result['_rate_limited']);

            // Add timing
            $result['metadata'] = array_merge($result['metadata'] ?? [], [
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'cache_hit' => $cacheHit,
                'provider' => $this->llmProvider->getName(),
            ]);

            // Audit logging
            if (config('naturalquery.privacy.audit_queries', true) && ($result['status'] ?? '') === 'success') {
                $this->auditLog($result, $cacheHit, $startTime);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('[NaturalQuery] Orchestrator error', ['error' => $e->getMessage()]);
            return $this->formatter->formatError('An error occurred processing your query.', $metadata);
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
                ->merge($intent, 0)
                ->toIntent() + $intent;
        }

        // Handle parse failure
        if (!($intent['success'] ?? true)) {
            // Rate limiting is NOT a comprehension failure: falling back to
            // sql_generation / retrying would fire MORE calls at a provider
            // that is already refusing them. Tell the user the truth instead.
            if (($intent['status'] ?? null) === 429) {
                return array_merge(
                    $this->formatter->formatError(self::RATE_LIMIT_MESSAGE, $metadata),
                    ['_rate_limited' => true]
                );
            }

            return array_merge(
                $this->formatter->formatError($intent['error'] ?? 'Failed to understand query', $metadata),
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
                $this->formatter->formatError($queryResult['error'] ?? 'Failed to build query', $metadata),
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

        return "CURRENT QUERY STATE (carry these forward unless the instruction changes them):\n"
            . '  ' . implode('; ', $slots) . "\n"
            . "NEW INSTRUCTION: \"{$query}\"\n"
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
    protected function normalizeIntent(array $intent): array
    {
        if (!array_key_exists('group_value', $intent) && array_key_exists('district', $intent)) {
            $intent['group_value'] = $intent['district'];
        }

        unset($intent['district']);

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
    protected function processWithSqlGeneration(string $query, ?string $schemeHint, ?array $cached, array &$metadata): array
    {
        $metadata['query_mode_used'] = 'sql_generation';

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
        if ($scheme && $this->registry->has($scheme) && !$this->registry->hasLinkedSchemas()) {
            $prompt = $this->promptBuilder->buildSqlPrompt($scheme, $query);
        } else {
            $prompt = $this->promptBuilder->buildMultiSchemePrompt($query);
        }

        // Ask AI to generate SQL
        $response = $this->llmProvider->generateSql($prompt);

        if (!$response['success']) {
            if (($response['status'] ?? null) === 429) {
                return array_merge(
                    $this->formatter->formatError(self::RATE_LIMIT_MESSAGE, $metadata),
                    ['_rate_limited' => true]
                );
            }

            return $this->formatter->formatError($response['error'] ?? 'AI failed to generate SQL', $metadata);
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
            return $this->formatter->formatError('AI did not generate a SQL query', $metadata);
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
    protected function retryWithRefinedPrompt(string $query, ?string $schemeHint, array $metadata): array
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

            if ($response['success'] && isset($response['data']['sql'])) {
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
        }

        // Strategy 2: Return a helpful error with available schemes
        $schemes = array_map(fn($s) => $s['name'] . ' (' . $s['key'] . ')', $this->registry->getAvailableSchemes());
        $schemeList = implode(', ', array_slice($schemes, 0, 10));

        return $this->formatter->formatError(
            "Could not understand the query. Try mentioning a dataset name. Available: {$schemeList}",
            $metadata
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
            return $this->formatter->formatError('Query validation failed: ' . $validation['reason'], $metadata);
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
            return $this->formatter->formatError('Database query failed: ' . $this->sanitizeDbError($e->getMessage()), $metadata);
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
        ]);
    }

    // =========================================================================
    // VOICE QUERIES
    // =========================================================================

    /**
     * Process a voice query (audio input).
     */
    public function voiceQuery(string $audioBase64, string $mimeType = 'audio/webm', ?string $schemeHint = null): array
    {
        if (!$this->llmProvider->supportsVoice()) {
            return $this->formatter->formatError(
                "Voice input is not supported by the '{$this->llmProvider->getName()}' provider. Use text input instead."
            );
        }

        try {
            $schemeList = $this->registry->getSchemeListForLlm();
            $intent = $this->llmProvider->parseVoiceQuery($audioBase64, $mimeType, $schemeList);

            $transcribedText = $intent['transcribed_text'] ?? null;
            if ($transcribedText) {
                return $this->query($transcribedText, $schemeHint);
            }

            return $this->formatter->formatError('Could not transcribe audio. Please try again or use text input.');
        } catch (\Exception $e) {
            Log::error('[NaturalQuery] Voice query error', ['error' => $e->getMessage()]);
            return $this->formatter->formatError('Voice processing failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function getSchemes(): array
    {
        return $this->registry->getAvailableSchemes();
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
                'supports_voice' => $this->llmProvider->supportsVoice(),
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
