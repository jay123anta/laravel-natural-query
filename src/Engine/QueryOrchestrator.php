<?php

namespace Jayanta\NaturalQuery\Engine;

use Jayanta\NaturalQuery\Cache\TwoTierQueryCache;
use Jayanta\NaturalQuery\Conversation\QueryState;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Contracts\QueryCacheInterface;
use Jayanta\NaturalQuery\Contracts\ScopesCacheByDataset;
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

    // NQ-001-REDUCE: DatasetSeeder is load-bearing on its own for NQ-003 (see
    // resolveAskingDataset() below) as well as dataset detection in
    // processWithSqlGeneration(); PromptBudget is the bare `prompts.max_chars`
    // size bound the G1-round-3 ruling kept. Both optional and nullable, same
    // pattern as the four above — a hand-built orchestrator (existing unit
    // tests construct one directly) keeps working exactly as today, with the
    // bound simply never engaging.
    protected ?DatasetSeeder $seeder;
    protected ?PromptBudget $budget;

    /**
     * True while the steps of a decomposed question are being answered.
     *
     * Each step re-enters query(), and a step must never be decomposed again:
     * "compare A and B" would plan into "A" and "B", and if "A" were planned in
     * turn the recursion has no floor.
     */
    protected bool $inStepExecution = false;

    /** Memoised reflection: see cacheOverridesFindOnly(). Null until first asked. */
    private ?bool $cacheFindIsOverridden = null;

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
        ?IntentCoverage $coverage = null,
        ?DatasetSeeder $seeder = null,
        ?PromptBudget $budget = null
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

        $this->seeder = $seeder;
        $this->budget = $budget;
    }

    /**
     * Process a natural language query end-to-end.
     *
     * @param string $naturalLanguageQuery The user's question
     * @param string|null $datasetHint Optional dataset key hint
     * @return array Complete response with data, or clarification/error
     */
    public function query(string $naturalLanguageQuery, ?string $datasetHint = null, array $context = []): array
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
                    $datasetHint,
                    $queryMode,
                    $context['session_id'] ?? null
                ));
            }

            // Apply default dataset if configured and no hint provided
            if (!$datasetHint) {
                $defaultDataset = config('naturalquery.default_dataset');
                if ($defaultDataset && $this->registry->has($defaultDataset)) {
                    $datasetHint = $defaultDataset;
                    $metadata['default_dataset_applied'] = true;
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
                    return $this->runSteps($naturalLanguageQuery, $plan, $datasetHint, $metadata, $startTime, $context);
                }

                // A plan that could not be built is not a reason to stop — the
                // question is still answerable the ordinary way, and falling
                // through is the whole point of planning being an enhancement.
                //
                // Unless the provider asked us to stop. On a 429 the fall-through
                // makes a second call to a service that has just reported it is
                // over quota, which extends the very window it complained about
                // and is the one thing docs/TROUBLESHOOTING.md promises the
                // package does not do. The failure was reported and then
                // discarded unread, so nothing here could tell the difference.
                if (($plan['refused_before_sending'] ?? false)) {
                    // Same treatment as the 429 branch below it, which this
                    // was added one line away from and did not copy: strip the
                    // internal flag providerFailure() sets for the retry logic,
                    // and announce, because this exit skips query()'s tail
                    // where both normally happen.
                    $refused = $this->providerFailure($plan, $metadata);
                    unset($refused['_unretriable'], $refused['_rate_limited'], $refused['_fallback_eligible']);

                    // Unconditional: the planner branch this sits in already
                    // required !inStepExecution, so re-checking only looks
                    // like it is guarding something.
                    $this->announceOutcome($naturalLanguageQuery, $refused, false, $startTime);

                    return $refused;
                }

                if (($plan['status'] ?? null) === 429) {
                    // No `_rate_limited` flag. It exists to stop the refined
                    // retry, and this returns from above that block, so here it
                    // would only leak an internal name into the JSON body —
                    // which query()'s tail strips and this exit skips.
                    $limited = $this->formatter->formatError(
                        self::RATE_LIMIT_MESSAGE,
                        $metadata,
                        ErrorCode::RATE_LIMITED
                    );

                    // Unconditional: the planner branch this sits in already
                    // required !inStepExecution, so re-checking only looks
                    // like it is guarding something.
                    $this->announceOutcome($naturalLanguageQuery, $limited, false, $startTime);

                    return $limited;
                }

                if (!empty($plan['error'])) {
                    Log::info('[NaturalQuery] Planning failed; answering as a single query', [
                        'error' => $plan['error'],
                    ]);
                }
            }

            // Check cache first (works for all modes).
            //
            // The dataset THIS question resolves to, at zero API cost, so the
            // cache knows what a candidate row would have to match (NQ-003) —
            // see resolveAskingDataset() and TwoTierQueryCache::findForDataset().
            $cacheHit = false;
            $askingDataset = $this->resolveAskingDataset($naturalLanguageQuery, $datasetHint, $context);

            // Not looked up at all mid-conversation. Both readers refuse a row
            // when conversation state is present, so the lookup was pure cost
            // — and worse than free: TwoTierQueryCache counts a hit the moment
            // it returns a row, so every follow-up incremented hit_count on a
            // row it was never going to use, and naturalquery:cache-stats
            // reported reuse that had not happened.
            $cachedResult = empty($context['state'])
                ? $this->findInCache($naturalLanguageQuery, $askingDataset)
                : null;

            // An entry belonging to a different dataset is not a hit, and it is
            // discarded HERE — at the one place a cached result enters — rather
            // than downstream where the mismatch is finally acted on.
            //
            // Discovering it downstream was too late. `cache_hit` is set the
            // moment the cache returns anything, so a question that went on to
            // call the provider still reported itself as cached; and
            // verification.skip_on_cache_hit, which defaults to true, reads that
            // flag and skipped QueryVerifier on the SQL that had just been
            // generated. The one path where the self-check matters most was the
            // one path that turned it off.
            //
            // Null counts as a mismatch on EITHER side. The first version of
            // this guard read `$askingDataset !== null && ...`, which skipped
            // the check entirely whenever the asking dataset could not be
            // placed — and resolveAskingDataset() returns null routinely, any
            // time more than one dataset is registered and neither a hint, the
            // conversation state, nor a keyword names one. So a row cached from
            // a dataset-scoped page was served verbatim to the same wording
            // asked from the general one. That is the widget's ordinary shape,
            // and it is the exact failure this guard was added to close.
            //
            // Both null is the one case that still serves: neither ask carried
            // dataset context, so there is nothing to disagree about.
            // Compared against the scope the CACHED question was asked under,
            // not against the dataset its answer turned out to be about. Those
            // are different values, and the first version of this guard used
            // the second one — which is the only value the row had.
            //
            // The consequence was that a question naming no dataset never
            // matched its own row: the asking side resolved to null, the row
            // carried whatever dataset the model had chosen, and they could
            // never agree. On a multi-dataset install that is most questions,
            // so the cache was off for precisely the ones people repeat.
            // Comparing scope to scope keeps the cross-dataset hit closed —
            // an explicitly scoped row is still refused to an unscoped ask —
            // without charging for it on every ordinary repeat.
            // FAILS CLOSED. A missing scope is UNKNOWN, not "unscoped".
            //
            // `?? null` read both the same way, and the difference matters
            // because `_asking_scope` rides inside an opaque blob that a
            // third-party QueryCacheInterface was never asked to round-trip.
            // An implementation that drops keys it does not recognise — which
            // the 2.0.0 contract permitted — handed back rows with no scope,
            // every one of which then looked eligible for any unscoped
            // question. The cross-dataset hit this release exists to close,
            // reopened for exactly the adopters who wrote their own cache.
            //
            // The same test also catches a blob that is not an array at all.
            // Those used to reach normalizeIntent(array $intent) and throw; the
            // \Throwable catch added earlier turned the crash into an error
            // response, which was still wrong — Tier 2 rows never expire, so
            // that question stayed permanently unanswerable with no remedy in
            // the message. An unreadable row is a MISS. The question is
            // answered, the row is rewritten on the way past, and it costs one
            // API call.
            $blob = $cachedResult['intent'] ?? null;
            $scopeIsKnown = is_array($blob) && array_key_exists('_asking_scope', $blob);
            $cachedScope = $scopeIsKnown ? $blob['_asking_scope'] : null;

            if ($cachedResult && (!$scopeIsKnown || $cachedScope !== $askingDataset)) {
                Log::debug('[NaturalQuery:Cache] Discarded: unusable or asked under another scope', [
                    'asking' => $askingDataset,
                    'cached' => $scopeIsKnown ? $cachedScope : '(no scope recorded)',
                    'answers' => $cachedResult['dataset'] ?? null,
                ]);
                $cachedResult = null;
            }

            // Deliberately NOT flagging a hit here.
            //
            // Finding a row is not using one. Every reader below has its own
            // reasons to refuse the row it was handed — mid-conversation,
            // wrong dataset, wrong shape — and a flag set at the entry cannot
            // know about any of them. It used to be set here, and so a
            // conversation turn that both readers declined still reported
            // itself as cached: the provider generated the SQL, the audit log
            // recorded a cached answer, the QuestionAnswered event carried the
            // wrong figure, and verification.skip_on_cache_hit read the flag
            // and skipped QueryVerifier on brand-new SQL.
            //
            // markCacheHit() is now the only writer, and every caller of it is
            // a line that has just committed to using the row.

            // Route to appropriate mode
            if ($queryMode === 'sql_generation') {
                $result = $this->processWithSqlGeneration($naturalLanguageQuery, $datasetHint, $cachedResult, $metadata, $context);
            } elseif ($queryMode === 'intent') {
                $result = $this->processWithIntent($naturalLanguageQuery, $datasetHint, $cachedResult, $metadata, $context);
            } else {
                // AUTO mode: intent first — unless the question plainly needs
                // SQL the intent contract cannot express.
                //
                // Falling back on ERROR is not enough, and that was the whole
                // problem: intent mode did not fail on these questions, it
                // succeeded at a narrower one. "Customers with more than 10
                // orders" quietly became "customers", ranked. Deciding up front
                // costs nothing — both modes are a single API call.
                // A cached SQL recipe settles it before the coverage check
                // does. Only processWithSqlGeneration can replay one; intent
                // mode declines it, pays for a parseIntent, and — because both
                // readers store under the same key — then OVERWRITES the recipe
                // with an intent row. The next repeat finds an intent row,
                // clarifies, falls back, regenerates, and overwrites the intent
                // row with a recipe again. The cache oscillated between one and
                // three provider calls per repeat of the same question, forever,
                // where zero is available.
                //
                // The row's own shape says which reader wrote it, so use it.
                $beyond = isset($cachedResult['intent']['_sql_result'])
                    ? 'a cached SQL result'
                    : ($this->coverage ? $this->coverage->exceeds($naturalLanguageQuery) : null);

                if ($beyond) {
                    Log::info('[NaturalQuery] Question needs SQL beyond the intent contract', [
                        'component' => $beyond,
                    ]);
                    $metadata['query_mode'] = 'auto→sql_generation';
                    $metadata['escalated_for'] = $beyond;
                    $result = $this->processWithSqlGeneration($naturalLanguageQuery, $datasetHint, $cachedResult, $metadata, $context);
                } else {
                    $result = $this->processWithIntent($naturalLanguageQuery, $datasetHint, $cachedResult, $metadata, $context);

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
                        // Intent mode may have used the cached row and then
                        // failed downstream. Whatever it marked describes an
                        // answer that is being thrown away.
                        $metadata['cache_hit'] = false;
                        $generated = $this->processWithSqlGeneration($naturalLanguageQuery, $datasetHint, $cachedResult, $metadata, $context);

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
            // Never retry a request the provider refused before sending
            // anything (NQ-002): Strategy 1 below retries with a SMALLER,
            // single-dataset prompt, which is exactly the wrong move for a
            // refusal caused by prompt SIZE — the smaller prompt can clear
            // the same guard and be answered for real, but as a narrower
            // question than the one that was refused. isProviderFailure()
            // already recognises this class of result; it just used to be
            // consulted too late, after Strategy 1 had already fired.
            if (($result['status'] ?? '') === 'error'
                && config('naturalquery.errors.retry_on_failure', true)
                && !($result['_retried'] ?? false)
                && !($result['_rate_limited'] ?? false)
                && !($result['_unretriable'] ?? false)
            ) {
                // The failure so far is passed in, so the retry can decline to
                // overwrite a provider fault with a claim about the question.
                $metadata['cache_hit'] = false;
                $result = $this->retryWithRefinedPrompt($naturalLanguageQuery, $datasetHint, $metadata, $result);
            }

            // Read AFTER the readers have run, so it reflects what was used
            // rather than what was available. This is the value the audit log,
            // the QuestionAnswered event and verification.skip_on_cache_hit
            // all consume.
            $cacheHit = $metadata['cache_hit'] ?? false;

            // Remove internal flags
            unset($result['_fallback_eligible'], $result['_rate_limited'], $result['_unretriable']);

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

        // \Throwable, not \Exception. A TypeError extends \Error and so slipped
        // straight past this, taking the whole error envelope with it: the
        // caller got a framework 500 and a stack trace instead of a
        // NaturalQuery error response, and the HTTP layer's error_code,
        // retryable and Retry-After never happened.
        //
        // Reachable from ordinary data rather than from a bug in the caller —
        // a cache row whose `intent` column is present but not an array
        // reaches normalizeIntent(array $intent) and throws. Tier 2 rows have
        // no expiry, so once one exists that question is a hard 500 forever.
        } catch (\Throwable $e) {
            Log::error('[NaturalQuery] Orchestrator error', [
                'error' => $e->getMessage(),
                'type' => get_class($e),
            ]);

            return $this->formatter->formatError('An error occurred processing your query.', $metadata, ErrorCode::INTERNAL);
        }
    }

    // =========================================================================
    // INTENT MODE
    // =========================================================================

    /**
     * Process query using intent parsing → local SQL builder.
     *
     * Flow: AI extracts (dataset, metric, order, limit, district) → SqlBuilder constructs SQL
     */
    protected function processWithIntent(string $query, ?string $datasetHint, ?array $cached, array &$metadata, array $context = []): array
    {
        $metadata['query_mode_used'] = 'intent';

        // A follow-up is meaningless on its own: "only in West" means one thing
        // after a revenue question and another after an order count. So a turn
        // carrying conversation state is never answered from cache and never
        // written to it — the words are the same and the question is not.
        $inConversation = !empty($context['state']);

        $intent = null;
        // A SQL-generation recipe is not an intent, whatever the column it
        // shares. processWithSqlGeneration() caches those rows with the
        // finished SQL in `_sql_result`, and it replays that SQL verbatim.
        // Handed the same row, this reader instead passes it to normalizeIntent()
        // and SqlBuilder — and the intent contract has no slot for a WHERE
        // predicate, so a cached "revenue for pending orders" came back as the
        // revenue for every row: right shape, wrong number, status success,
        // no provider call, and no TTL on tier-2 rows to make it stop.
        //
        // The row exists BECAUSE the question needed something this contract
        // cannot express. Rebuilding it through this contract must lose exactly
        // that. Leaving $intent null makes it an honest miss and costs one call.
        //
        // Eligibility itself is not re-checked here. query() already discarded
        // any row asked under a different scope, and the check that used to sit
        // on this line compared the asking scope against the row's ANSWER
        // dataset — the same conflation, one level down, rejecting a row that
        // had just been cleared on the correct grounds. NQ-003's original
        // version of it was worse still: it reconciled the mismatch by
        // overwriting $intent['dataset'], so an identical repeat could be
        // answered from a table the model never chose. One authoritative guard,
        // at the entry.
        if ($cached && !$inConversation && !isset($cached['intent']['_sql_result'])) {
            $intent = $this->normalizeIntent($cached['intent']);
            $this->markCacheHit($metadata, $cached);
        }

        if ($intent === null) {
            $datasetList = $this->registry->getDatasetListForLlm();
            $intent = $this->normalizeIntent(
                $this->llmProvider->parseIntent($this->withState($query, $context), $datasetList)
            );

            // Never overwrite a SQL recipe with an intent. Both readers store
            // under the same key, and the recipe is the row that can actually
            // answer this question — it exists because the intent contract
            // could not. Clobbering it throws away the better answer and
            // guarantees the next repeat pays to rebuild it.
            $wouldClobberARecipe = isset($cached['intent']['_sql_result']);

            if (!$inConversation
                && !$wouldClobberARecipe
                && ($intent['success'] ?? false)
                && !($intent['needs_clarification'] ?? false)
            ) {
                $this->rememberIntent(
                    $query,
                    $intent,
                    $this->resolveAskingDataset($query, $datasetHint, $context)
                );
            }
        }

        // A total is one number. Applied after the cache too, since a stored
        // intent can carry the same invented breakdown.
        $intent = $this->dropUnaskedBreakdown($intent, $query);

        // Apply dataset hint
        if ($datasetHint && empty($intent['dataset'])) {
            if ($this->registry->has($datasetHint)) {
                $intent['dataset'] = $datasetHint;
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

            // A refusal that never reached the wire is NOT fallback-eligible.
            //
            // Falling back means trying SQL generation, and on a schema with
            // no linked datasets that builds the single-dataset prompt — which
            // is SMALLER, clears the very guard that just refused, and gets
            // answered. The user asked a question the context could not hold
            // and receives a confident number computed from one table.
            //
            // That is the same conversion NQ-002 stopped in retryWithRefinedPrompt,
            // reappearing on the auto-mode fallback because the flag was read
            // in providerFailure() and this path builds its error inline.
            // Marked unretriable too, so handle() does not then retry it.
            if ($intent['refused_before_sending'] ?? false) {
                return array_merge(
                    $this->formatter->formatError($intent['error'] ?? 'The AI service could not be reached.', $metadata, ErrorCode::PROVIDER_ERROR),
                    ['_unretriable' => true]
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
        $availableDatasets = $this->registry->getAvailableDatasets();
        $hasGroupValue = !empty($intent['group_value']);

        // With exactly one dataset there is nothing to choose between, so a
        // model that says "which dataset?" is really saying "I could not tell
        // what you meant" — about the metric, usually.
        if (empty($intent['dataset']) && count($availableDatasets) === 1) {
            $intent['dataset'] = $availableDatasets[0]['key'];
        }

        $hasDataset = !empty($intent['dataset']);

        // Asking which dataset is only meaningful when the dataset is genuinely
        // unresolved AND there is more than one to pick from. Asking it once
        // the dataset is known produced a card whose only button re-sent the
        // same question and redrew the same card — indistinguishable, from the
        // outside, from the widget being broken.
        if (!$hasDataset && count($availableDatasets) > 1) {
            return $this->formatter->formatClarification($intent, $availableDatasets);
        }

        if ($hasDataset && $hasGroupValue && empty($intent['metric'])) {
            // District detail — SqlBuilder handles this
        } elseif (($intent['needs_clarification'] ?? false) || !$hasDataset) {
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
                $availableDatasets,
                $hasDataset ? $this->registry->getDatasetMetrics($intent['dataset']) : []
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
        $response = $this->validateAndExecute($queryResult, $intent['dataset'], $metadata);

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
        ?string $datasetHint,
        array $metadata,
        float $startTime,
        array $context = []
    ): array {
        $steps = [];
        $rateLimited = false;

        $this->inStepExecution = true;

        try {
            foreach ($plan['steps'] as $i => $question) {
                // $context, not nothing. Re-entering without it dropped the
                // conversation at the door: the steps could not see the metric,
                // period or filters established in earlier turns, and — because
                // the cache guard is keyed on !empty($context['state']) — each
                // step looked like a standalone question and WROTE itself to
                // the shared, session-less cache. Another session asking those
                // words then read this conversation's rows back, which is
                // exactly what docs/CONVERSATIONS.md promises cannot happen.
                $result = $this->query($question, $datasetHint, $context);
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

                // A rate limit ends the run. Each step is a full query() and
                // guards its own 429 correctly, but the loop then carried on
                // and asked again — so one rate limit authorised N more calls
                // against a provider that had just said stop, which is the
                // one response that makes a quota problem worse.
                //
                // error_code survives query()'s tail (only the underscore
                // flags are stripped), so the loop can see it; it simply never
                // looked.
                if (($result['error_code'] ?? null) === ErrorCode::RATE_LIMITED) {
                    $rateLimited = true;
                    break;
                }
            }
        } finally {
            // Restored even if a step throws, or the next ordinary question
            // silently loses the ability to be decomposed.
            $this->inStepExecution = false;
        }

        $synthesis = $this->synthesizer->synthesize($originalQuery, $steps, $plan['comparison'] ?? false);
        $successful = array_values(array_filter($steps, fn ($s) => $s['status'] === 'success'));

        // A rate limit is reported as a rate limit, decomposed question or not.
        //
        // This envelope carried no error_code at all, and the controller reads
        // `$result['error_code'] ?? null` — ErrorCode::httpStatus(null) is 500
        // and isRetryable(null) is false. So the identical fault that returns
        // 429 + Retry-After + retryable:true for a one-part question returned
        // 500 + retryable:false for a two-part one: an explicit instruction to
        // every SDK and queue worker NOT to back off, on the one fault where
        // backing off is the entire remedy. docs/API.md tells clients to branch
        // on error_code, and there was nothing to branch on.
        //
        // The bundled widget makes it worse still: it renders `data.error`,
        // which this envelope does not set either, so the user saw "The query
        // could not be processed" and no mention of a rate limit anywhere.
        if ($rateLimited) {
            // Steps kept so the caller can see how far it got. No
            // `_rate_limited` flag: this result is returned straight out of
            // query() without passing the tail that strips internal names.
            $limited = array_merge(
                $this->formatter->formatError(self::RATE_LIMIT_MESSAGE, $metadata, ErrorCode::RATE_LIMITED),
                ['steps' => $steps]
            );

            $this->announceOutcome($originalQuery, $limited, false, $startTime);

            return $limited;
        }

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
                'dataset' => $datasetHint,
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

        // The fields query()'s tail adds to every other answer, which this
        // path returns straight past. A decomposed answer was arriving with no
        // `provider`, no `cache_hit` and no `usage` — and docs/API.md documents
        // usage as accumulating specifically across "the steps of a decomposed
        // question", which is the one shape where it was absent.
        //
        // cache_hit is false by construction: each step consults the cache on
        // its own, and this envelope is assembled fresh every time.
        $response['metadata'] = array_merge($response['metadata'], [
            'cache_hit' => false,
            'provider' => $this->llmProvider->getName(),
        ]);

        if ($usage = $this->usageForThisQuestion()) {
            $response['metadata']['usage'] = $usage;
        }

        // And the event. A listener counting cost or logging answers saw every
        // single-part question and no decomposed one.
        $this->announceOutcome($originalQuery, $response, false, $startTime);

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

        $fallback = $this->validateAndExecute($rebuilt, $unfiltered['dataset'] ?? null, $metadata);

        // Only prefer the fallback if it actually found something.
        if (($fallback['status'] ?? '') !== 'success' || ($fallback['type'] ?? null) === 'no_data') {
            return $response;
        }

        Log::info('[NaturalQuery] Name filter matched nothing; answered without it', [
            'unmatched_filter' => $filter,
            'dataset' => $unfiltered['dataset'] ?? null,
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
    protected function processWithSqlGeneration(string $query, ?string $datasetHint, ?array $cached, array &$metadata, array $context = []): array
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

        // Check if we have a cached SQL result.
        //
        // NQ-003-FIX: the cached SQL names whatever dataset the ORIGINAL
        // question resolved to, which is not necessarily this one — replaying
        // it verbatim would silently answer the wrong table. NQ-003 handled a
        // disagreement by retargeting the cached recipe (metric, query_type,
        // group_value, limit, order) through SqlBuilder for the dataset THIS
        // question resolves to, at zero API cost. That recipe is exactly the
        // fields the INTENT contract can express — this cached result exists
        // in the first place because the question needed SQL generation, i.e.
        // something beyond that contract, most often a WHERE predicate with
        // no slot to carry it. Retargeting silently dropped it and reported
        // success on the unfiltered query. So a mismatch is a cache MISS, not
        // a retarget: a fresh generation below costs one API call and cannot
        // drop anything, because it starts from the question, not the recipe.
        // A conversation turn is never served from this cache, matching what
        // processWithIntent has always done and what docs/CONVERSATIONS.md
        // promises in as many words. The key is the question's TEXT and carries
        // no session, so "and the total there" is the same key for every
        // conversation in the application — a follow-up only means anything
        // relative to the turns before it, and those are not in the key.
        $inConversation = !empty($context['state']);

        // Eligibility was settled in query(), against the scope the cached
        // question was asked under. The comparison that used to sit here
        // measured the asking scope against the row's ANSWER dataset instead,
        // and threw away rows that had just been cleared on the correct
        // grounds — the same conflation as in processWithIntent, in the other
        // reader. What is left is a shape check: this reader replays finished
        // SQL, so it wants the rows that carry some.
        if ($cached && !$inConversation && isset($cached['intent']['_sql_result'])) {
            $sqlResult = $cached['intent']['_sql_result'];
            $this->markCacheHit($metadata, $cached);

            return $this->validateAndExecute($sqlResult, $sqlResult['dataset'] ?? null, $metadata);
        }

        // Step 1: Identify the dataset (priority: hint → routing → keywords → LLM intent)
        $dataset = $datasetHint;
        if (!$dataset || !$this->registry->has($dataset)) {
            // Try keyword/routing detection first (fast, no API call)
            $dataset = $this->seeder?->detect($query);
        }

        if (!$dataset || !$this->registry->has($dataset)) {
            // Fall back to LLM intent parsing (slower, requires API call)
            $datasetList = $this->registry->getDatasetListForLlm();
            $intent = $this->llmProvider->parseIntent($query, $datasetList);

            // The response was read only for ['dataset'], and a failure has no
            // dataset — so a 429 here read as "could not place the question"
            // and generateSql was called a line later, against a provider that
            // had just reported it was over quota. Same fall-through as the
            // planner's, on the call beside it.
            //
            // Only the failures that must not be followed by another call.
            // Widening this to every failure broke the fallback that makes
            // this method worth reaching: a model that could not place the
            // question is still perfectly able to answer the multi-dataset
            // prompt below, and RateLimitHandlingTest exists to say so.
            $mustStop = ($intent['status'] ?? null) === 429
                || ($intent['refused_before_sending'] ?? false);

            if (!($intent['success'] ?? true) && $mustStop) {
                return $this->providerFailure($intent, $metadata);
            }

            $dataset = $intent['dataset'] ?? null;

            if (!$dataset) {
                $dataset = $this->registry->findByAlias($query);
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
        // the last sentence of it. Dataset detection above deliberately still
        // uses the bare question — the state block would match every dataset
        // name it mentions.
        if ($dataset && $this->registry->has($dataset) && !$this->registry->hasLinkedSchemas()) {
            $prompt = $this->promptBuilder->buildSqlPrompt($dataset, $stated);
            $datasetsRendered = 1;
        } else {
            $prompt = $this->promptBuilder->buildMultiDatasetPrompt($stated);
            $datasetsRendered = count($this->registry->all());
        }

        // R4: over budget refuses BEFORE any provider call — never a smaller
        // prompt answering a narrower question. _unretriable per R5: the only
        // retry strategy this package has (retryWithRefinedPrompt) sends a
        // SMALLER, single-dataset prompt, which is exactly the wrong move for
        // a refusal caused by size.
        $refusal = $this->budget?->check($prompt, $datasetsRendered);
        if ($refusal !== null) {
            return array_merge(
                $this->formatter->formatError($refusal, $metadata, ErrorCode::CANNOT_ANSWER),
                ['_unretriable' => true]
            );
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
                'dataset' => null,
                'metric' => null,
                'group_value' => null,
                'confidence' => 0,
                'needs_clarification' => $data['needs_clarification'] ?? true,
                'clarification_type' => $data['clarification_type'] ?? 'ambiguous',
            ];

            return $this->formatter->formatClarification($intent, $this->registry->getAvailableDatasets());
        }

        // AI generated SQL
        $sql = $data['sql'] ?? null;
        $dataset = $data['dataset'] ?? $datasetHint;

        if (!$sql) {
            return $this->formatter->formatError('AI did not generate a SQL query', $metadata, ErrorCode::PROVIDER_ERROR);
        }

        // Replace computed metric names if AI used them as column names
        if ($dataset && $this->registry->has($dataset)) {
            $sql = $this->replaceComputedMetrics($sql, $dataset);
        }

        // A required_filter is a RULE, not a hint, and this route was treating
        // it as a hint.
        //
        // PromptBuilder writes "REQUIRED FILTER (always include in WHERE)" into
        // the prompt and hopes; SqlBuilder, on the intent route, appends it to
        // the SQL so the model cannot omit it. Same setting, guaranteed on one
        // path and requested on the other — and the whole reason someone writes
        // one is that the answer is WRONG without it. A model that skipped the
        // line returned every cancelled order in the total, reported success,
        // and nothing downstream re-checked.
        $schemaData = $dataset ? $this->registry->get($dataset) : null;

        // _unretriable, because the retry regenerates from the same prompt with
        // the same REQUIRED FILTER line the model has just ignored. Without
        // this the refusal simply bounced into retryWithRefinedPrompt, which
        // produced the same unfiltered SQL and returned it as a success — the
        // first version of this fix did exactly that and the test stayed red.
        if ($refusal = $this->requiredFilterMissing($sql, $schemaData)) {
            return array_merge(
                $this->formatter->formatError($refusal, $metadata, ErrorCode::CANNOT_ANSWER),
                ['_unretriable' => true]
            );
        }

        // Build query result
        $queryResult = [
            'success' => true,
            'sql' => $sql,
            'dataset' => $dataset,
            'dataset_name' => $schemaData['name'] ?? $dataset,
            'metric' => $data['metric'] ?? null,
            'metric_description' => $data['explanation'] ?? ($data['metric'] ?? 'data'),
            'metric_unit' => '',
            'metric_type' => 'neutral',
            'group_value' => $data['group_value'] ?? null,
            'limit' => $data['limit'] ?? config('naturalquery.sql.default_limit', 100),
            'order' => $data['order'] ?? 'DESC',
            'query_type' => $data['query_type'] ?? 'ranking',
            // Reported so a generated query says which dates it covered.
            // Intent mode derives this from date_from/date_to; SQL
            // generation writes the WHERE itself, so it had nothing to
            // report and every step of a decomposed question came back
            // with a blank period — while the README promises each one
            // states the range it used. The model knows; it just was not
            // being asked.
            'time_filter' => $data['period'] ?? null,
            'group_column' => $dataset ? $this->registry->getGroupColumn($dataset) : 'name',
        ];

        // Resolve metric unit/type from schema if possible
        if ($dataset && $queryResult['metric']) {
            $metricData = $this->resolveMetricData($dataset, $queryResult['metric']);
            if ($metricData) {
                $queryResult['metric_description'] = $metricData['description'] ?? $queryResult['metric_description'];
                $queryResult['metric_unit'] = $metricData['unit'] ?? '';
                $queryResult['metric_type'] = $metricData['type'] ?? $metricData['metric_type'] ?? 'neutral';
            }
        }

        // Self-verification: AI checks its own SQL before execution
        if ($this->shouldVerify($metadata)) {
            $verification = $this->verifier->verify($query, $queryResult['sql'], $dataset);

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

        // Cache the VERIFIED SQL result for future identical queries — but
        // never a conversation turn. Writing one poisons the shared, text-keyed
        // store for every other session that asks the same follow-up words,
        // and it is the write, not the read, that does the damage: the row
        // outlives the conversation that created it.
        if (!$inConversation) {
            $rememberIfItWorks = fn () => $this->rememberIntent($query, [
                'dataset' => $dataset,
                'metric' => $queryResult['metric'],
                'group_value' => $queryResult['group_value'],
                'limit' => $queryResult['limit'],
                'order' => $queryResult['order'],
                'query_type' => $queryResult['query_type'],
                '_sql_result' => $queryResult,
            ], $this->resolveAskingDataset($query, $datasetHint, $context));
        }

        // Validate and execute — THEN cache, and only what worked.
        //
        // The store used to run first, so SQL that SqlValidator rejected, or
        // that the database refused, was written to a cache with no expiry and
        // replayed on every later ask of that wording. The provider was never
        // consulted again, so the one bad generation became permanent: the
        // user was told their question could not be understood, forever, and
        // rewording it slightly was the only escape.
        //
        // Identical in shape to the recipe defect this release opened with —
        // a row cached in a state nothing downstream re-checks.
        $result = $this->validateAndExecute($queryResult, $dataset, $metadata);

        if (isset($rememberIfItWorks) && ($result['status'] ?? '') === 'success') {
            $rememberIfItWorks();
        }

        return $result;
    }

    /**
     * The single dataset THIS question resolves to, at zero API cost
     * (NQ-003): an explicit hint, conversation state, then keyword/alias
     * detection on the question's own text via DatasetSeeder — the same
     * priority `processWithSqlGeneration()`'s own dataset-identification
     * step already uses, minus its final LLM fallback, which costs a call
     * and is not needed just to sanity-check a cache hit.
     *
     * A cache row is written for whatever dataset the ORIGINAL question
     * resolved to. Replaying it for a DIFFERENT dataset than the one THIS
     * question resolves to answers the wrong table with no error, no
     * latency, and no log entry to suggest anything happened — the
     * confidently-wrong-number failure mode §0 exists to rule out. Both
     * cached-result branches (processWithIntent(), processWithSqlGeneration())
     * compare this against the cached row's own dataset before reusing it.
     *
     * Null when none of the free signals resolve one AND more than one
     * dataset is registered — the caller then trusts the cached row's own
     * dataset as-is: an exact-hash hit is the identical question asked
     * before, and a fuzzy hit already refused to reach this point without
     * one of these same signals (TwoTierQueryCache::find()). With exactly
     * one dataset registered there is nothing for a cached row to cross
     * INTO, so that one is resolved unconditionally — the same reasoning
     * `processWithIntent()` already applies when a parsed intent names no
     * dataset and there is only one to choose from.
     */
    /**
     * Look a question up in the cache.
     *
     * Which method gets called is about capability, not about safety. A cache
     * implementing ScopesCacheByDataset is told what the asking question
     * resolves to and can narrow its own fuzzy search; one that does not is
     * called through find(). Either way the row comes back to query(), which
     * decides eligibility against the scope recorded on it.
     */
    protected function findInCache(string $query, ?string $askingDataset): ?array
    {
        // findForDataset() is an OPTIMISATION, not the safety mechanism. It
        // lets the bundled cache filter the fuzzy tier in SQL; eligibility
        // itself is decided in query() against `_asking_scope`, which rides
        // inside the intent blob that every implementation stores and returns.
        // So a cache that cannot scope is safe to read, and the bypass that
        // used to sit here — returning null whenever a dataset was known —
        // was not protecting anything.
        //
        // It was, however, silently disabling every custom cache on the most
        // common install shape: resolveAskingDataset() returns the sole key
        // unconditionally when one dataset is registered, so the bypass fired
        // on every question. store() still ran, so the adopter's table filled
        // up and never returned a row.
        //
        // A subclass that overrides find() is the other half. Overriding
        // find() is the obvious way to bolt a tenant or permission gate onto
        // the bundled cache, and it inherits ScopesCacheByDataset, so calling
        // findForDataset() would route around the gate without a word — a
        // cross-tenant read that looks like a cache hit. Where the override
        // exists and the scoped method has not been overridden with it, the
        // override wins: one fuzzy tier is worth one API call, and a gate that
        // does not run is worth considerably more.
        if ($this->cache instanceof ScopesCacheByDataset && !$this->cacheOverridesFindOnly()) {
            return $this->cache->findForDataset($query, $askingDataset);
        }

        return $this->cache->find($query);
    }

    /**
     * Whether the injected cache overrides find() but inherits findForDataset().
     *
     * Reflection once per instance, memoised — the answer cannot change for a
     * given object, and this runs on every question.
     */
    private function cacheOverridesFindOnly(): bool
    {
        if ($this->cacheFindIsOverridden !== null) {
            return $this->cacheFindIsOverridden;
        }

        // Only meaningful for subclasses of the bundled cache. The question
        // this answers — "did the author gate find() and not realise
        // findForDataset() bypasses it?" — presupposes inheriting both from
        // TwoTierQueryCache.
        //
        // Applied to any implementation, the comparison misfires: a cache that
        // declares ScopesCacheByDataset on a concrete class while inheriting
        // find() from its OWN abstract base has find() declared somewhere that
        // is neither TwoTierQueryCache nor the class declaring findForDataset,
        // so it was read as a find()-only gate and its findForDataset() was
        // never called. It had implemented the capability interface precisely
        // to be asked.
        if (!$this->cache instanceof TwoTierQueryCache) {
            return $this->cacheFindIsOverridden = false;
        }

        $declaring = fn (string $method) => (new \ReflectionMethod($this->cache, $method))
            ->getDeclaringClass()
            ->getName();

        $find = $declaring('find');
        $scoped = $declaring('findForDataset');

        // Both redeclared together means the author knew about both, so their
        // scoped version is the one to call.
        $overridesFindOnly = $find !== TwoTierQueryCache::class && $find !== $scoped;

        if ($overridesFindOnly) {
            // Warning, not debug. This choice keeps the adopter's gate running
            // and costs them EVERY cache hit, not just the fuzzy tier: find()
            // looks up with no scope, while rows are stored under the scope
            // their question was asked with, so the exact tier cannot match
            // either. That is the right way round — a 0% hit rate is a
            // performance loss and a skipped tenant gate is a cross-tenant
            // read — but it is far too expensive to discover from a debug log.
            Log::warning(
                '[NaturalQuery:Cache] ' . get_class($this->cache) . ' overrides find() but not '
                . 'findForDataset(). find() is being called so the override still runs, which means NO '
                . 'cache hits at all: lookups carry no dataset scope while stored rows do. Override '
                . 'findForDataset() as well (see docs/CACHING.md) to keep both the gate and the cache.',
                ['cache' => get_class($this->cache), 'declares_find' => $find]
            );
        }

        return $this->cacheFindIsOverridden = $overridesFindOnly;
    }

    /**
     * Record that this answer came from a cached row.
     *
     * Call it where a row is USED, never where one is found. Four things read
     * this flag — the response metadata, auditLog(), the QuestionAnswered
     * event, and verification.skip_on_cache_hit — and the last of those turns
     * QueryVerifier off, so a false positive disables the self-check on exactly
     * the SQL that was generated a moment earlier.
     *
     * It was previously set in query() the moment the cache returned anything,
     * which is the fifth guard in this class to have been attached to the entry
     * rather than to the thing it describes. Both readers refuse a row
     * mid-conversation and neither told the caller, so the flag was true for
     * answers the provider had just generated and billed for.
     */
    /**
     * Write an intent to the cache, stamped with the scope it was asked under.
     *
     * The scope is a required parameter rather than something read from state,
     * so a new store site cannot be added without deciding what it is. Every
     * previous guard in this class was optional at the call site, and every
     * one of them was then missed at least once.
     *
     * `_asking_scope` rides inside the intent blob deliberately. Passing it as
     * an argument would mean widening QueryCacheInterface::store(), and adding
     * a parameter to an interface method — even an optional one — is a fatal
     * error at class load for every third-party implementation that already
     * exists. That mistake was made once already on find(); the capability
     * became ScopesCacheByDataset instead. A reserved key inside a payload the
     * interface already carries costs nothing and breaks nobody, and
     * normalizeIntent() drops unknown keys, so it never reaches SqlBuilder.
     */
    /**
     * Why generated SQL cannot be trusted for this dataset, or null.
     *
     * REFUSES rather than injects, deliberately. SqlBuilder can splice the
     * filter into its own SQL because it wrote that SQL and knows its shape.
     * Model-generated SQL is arbitrary — a derived table, a CTE, a subquery in
     * the FROM clause — and the splice is a regex on the first WHERE, which on
     * `SELECT … FROM (SELECT … WHERE x) sub` lands inside the subquery and
     * narrows the wrong thing. Silently. That is a worse failure than the one
     * being fixed, and "reconcile rather than refuse" is the exact habit this
     * release spent four review rounds removing.
     *
     * The comparison is deliberately literal — whitespace collapsed and `<>`
     * folded to `!=`, nothing more. A model writing an EQUIVALENT filter in
     * different words (`status NOT IN ('cancelled')`) is refused even though
     * its SQL was correct. That is a false refusal, it costs an error message
     * on a good answer, and it is still the right trade: the alternative is
     * accepting text that merely mentions the column, which passes
     * `GROUP BY status` and hands back the unfiltered total.
     */
    private function requiredFilterMissing(string $sql, ?array $schemaData): ?string
    {
        $required = $schemaData['tables']['primary']['required_filter'] ?? null;

        if (!$required) {
            return null;
        }

        $normalise = static fn (string $s) => strtolower(preg_replace(
            ['/\s+/', '/<>/'],
            [' ', '!='],
            trim($s)
        ) ?? '');

        if (str_contains($normalise($sql), $normalise($required))) {
            return null;
        }

        Log::warning('[NaturalQuery] Generated SQL omitted a required filter', [
            'dataset' => $schemaData['name'] ?? null,
            'required_filter' => $required,
        ]);

        return sprintf(
            'This dataset has a required filter (%s) that the generated query did not apply, so the '
            . 'answer would have counted rows your schema says must never be counted. Ask again, or use '
            . 'query_mode "intent" for this question — that route applies the filter itself instead of '
            . 'asking the model to.',
            $required
        );
    }

    private function rememberIntent(string $query, array $intent, ?string $askingScope): void
    {
        $intent['_asking_scope'] = $askingScope;
        $this->cache->store($query, $intent);
    }

    private function markCacheHit(array &$metadata, array $cached): void
    {
        $metadata['cache_hit'] = true;
        $metadata['cache_match_type'] = $cached['cache_match_type'] ?? null;
    }

    protected function resolveAskingDataset(string $query, ?string $datasetHint, array $context = []): ?string
    {
        if ($datasetHint && $this->registry->has($datasetHint)) {
            return $datasetHint;
        }

        $stateDataset = $context['state']['dataset'] ?? null;
        if (is_string($stateDataset) && $stateDataset !== '' && $this->registry->has($stateDataset)) {
            return $stateDataset;
        }

        $detected = $this->seeder?->detect($query);
        if ($detected && $this->registry->has($detected)) {
            return $detected;
        }

        $keys = $this->registry->keys();
        return count($keys) === 1 ? $keys[0] : null;
    }

    // =========================================================================
    // RETRY LOGIC
    // =========================================================================

    /**
     * Retry a failed query with a refined prompt.
     *
     * Strategy:
     * 1. Try to detect dataset from query keywords/aliases
     * 2. If found, retry with single-dataset SQL prompt (much more accurate)
     * 3. If still no dataset, retry with explicit instruction to generate SQL
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

        $error = $this->formatter->formatError(
            $response['error'] ?? 'AI failed to generate SQL',
            $metadata,
            ErrorCode::PROVIDER_ERROR
        );

        // A provider can refuse BEFORE sending anything — OllamaProvider's
        // context-window guard does, because Ollama does not reject an
        // oversized prompt, it silently truncates the schema and answers
        // anyway. That refusal is tied to prompt SIZE, not to what the model
        // thinks of the question, so it is not the "model answered badly"
        // case retryWithRefinedPrompt() exists for. Flagged here, at the one
        // place every such response passes through, so the caller can decide
        // not to retry at all rather than send a smaller prompt that clears
        // the guard and answers a narrower question than was asked.
        if ($response['refused_before_sending'] ?? false) {
            $error['_unretriable'] = true;
        }

        return $error;
    }

    protected function retryWithRefinedPrompt(string $query, ?string $datasetHint, array $metadata, array $previous = []): array
    {
        $metadata['_retried'] = true;
        $metadata['retry'] = true;
        Log::info('[NaturalQuery] Retrying with refined prompt', ['query' => $query]);

        // Strategy 1: Try keyword-based dataset detection from all aliases
        $dataset = $datasetHint;
        if (!$dataset) {
            $dataset = $this->seeder?->detect($query);
        }

        if ($dataset && $this->registry->has($dataset)) {
            Log::info('[NaturalQuery] Retry: detected dataset from keywords', ['dataset' => $dataset]);
            // Use single-dataset SQL prompt — much more reliable
            $prompt = $this->promptBuilder->buildSqlPrompt($dataset, $query);

            // Measured like any other. This is the same builder the bounded
            // path uses, and it was the one prompt in the package that went
            // out unchecked — so an install that set prompts.max_chars
            // precisely to stop oversized prompts being sent still sent one
            // here, on the retry, where nobody was looking.
            if ($refusal = $this->budget?->check($prompt, 1)) {
                return array_merge(
                    $this->formatter->formatError($refusal, $metadata, ErrorCode::CANNOT_ANSWER),
                    ['_unretriable' => true]
                );
            }

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
                $name = $this->registry->get($dataset)['name'] ?? $dataset;

                return $this->formatter->formatError(
                    "That could not be answered from {$name}. Try naming the measure you want, or rephrasing the breakdown.",
                    $metadata,
                    ErrorCode::CANNOT_ANSWER
                );
            }

            $data = $response['data'];
            $schemaData = $this->registry->get($dataset);

            // The second place generated SQL enters the engine, and therefore
            // the second place a required_filter can be dropped. Guarding only
            // the first one would have left the rule unenforced here — which is
            // precisely how this defect existed in the first place, and how the
            // first attempt at fixing it still failed: the refusal below sent
            // the question into this retry, which re-generated the same
            // unfiltered SQL and returned it as a success.
            if ($refusal = $this->requiredFilterMissing($data['sql'], $schemaData)) {
                return array_merge(
                    $this->formatter->formatError($refusal, $metadata, ErrorCode::CANNOT_ANSWER),
                    ['_unretriable' => true]
                );
            }

            $queryResult = [
                'success' => true,
                'sql' => $data['sql'],
                'dataset' => $dataset,
                'dataset_name' => $schemaData['name'] ?? $dataset,
                'metric' => $data['metric'] ?? null,
                'metric_description' => $data['explanation'] ?? '',
                'metric_unit' => '',
                'metric_type' => 'neutral',
                'group_value' => $data['group_value'] ?? null,
                'limit' => $data['limit'] ?? config('naturalquery.sql.default_limit', 100),
                'order' => $data['order'] ?? 'DESC',
                'query_type' => $data['query_type'] ?? 'ranking',
            // Reported so a generated query says which dates it covered.
            // Intent mode derives this from date_from/date_to; SQL
            // generation writes the WHERE itself, so it had nothing to
            // report and every step of a decomposed question came back
            // with a blank period — while the README promises each one
            // states the range it used. The model knows; it just was not
            // being asked.
            'time_filter' => $data['period'] ?? null,
                'group_column' => $this->registry->getGroupColumn($dataset),
            ];

            $result = $this->validateAndExecute($queryResult, $dataset, $metadata);
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
        // up overwritten here whenever no dataset could be guessed from the
        // words — which is exactly what happens when the provider never
        // answered and there is no intent to guess from.
        //
        // Caught by pointing a real install at a provider with no API key: the
        // transcription was perfect, and the answer was "Try mentioning a
        // dataset name."
        if ($this->isProviderFailure($previous)) {
            return array_merge($previous, ['_retried' => true]);
        }

        $datasets = array_map(fn($s) => $s['name'] . ' (' . $s['key'] . ')', $this->registry->getAvailableDatasets());
        $datasetList = implode(', ', array_slice($datasets, 0, 10));

        return $this->formatter->formatError(
            "Could not understand the query. Try mentioning a dataset name. Available: {$datasetList}",
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

    // =========================================================================
    // SHARED EXECUTION
    // =========================================================================

    /**
     * Validate SQL, execute it, and format the response.
     */
    protected function validateAndExecute(array $queryResult, ?string $dataset, array $metadata): array
    {
        $sql = $queryResult['sql'];

        // Remembered for the QuestionAnswered event, which is server-side.
        // The SQL is deliberately kept OUT of the HTTP response — a browser has
        // no use for it and it describes the shape of the database — but it is
        // the single most useful field a listener can log.
        $this->lastSql = $sql;

        // Validate SQL security
        $allowedTables = $this->registry->getAllowedTables();
        $maxLimit = $dataset ? $this->registry->getMaxLimit($dataset) : config('naturalquery.sql.max_limit');
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
        $connection = $dataset ? $this->registry->getConnection($dataset) : config('naturalquery.sql.database_connection');
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
            'dataset_name' => $queryResult['dataset_name'] ?? null,
            'metric_unit' => $queryResult['metric_unit'] ?? null,
        ]);

        // How the question was understood, in one readable line:
        //   "Orders · revenue · by region · status is pending"
        //
        // `parsed_query` has carried all of this since 1.0.0, as structure a
        // client has to assemble itself — and the bundled widget did not, so
        // the one thing that makes a misreading visible was visible only to
        // people writing their own front end. A conversation turn got a
        // summary from ConversationManager; an ordinary question, which is the
        // first thing anyone asks, got nothing.
        //
        // Roughly one question in five is misread on an uncurated schema.
        // Rendering the interpretation next to the number is the cheapest
        // defence there is against the failure §0 names as the worst: not that
        // the package is sometimes wrong, but that it is wrong without saying
        // so. The same builder the conversation path uses, so the two lines
        // cannot drift apart.
        $summary = QueryState::fromIntent($queryResult)->summary($this->registry);

        if ($summary !== '') {
            $response['parsed_summary'] = $summary;
        }

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
    protected function replaceComputedMetrics(string $sql, string $dataset): string
    {
        $computed = $this->registry->getComputedMetrics($dataset);
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
    protected function resolveMetricData(string $dataset, string $metric): ?array
    {
        $metrics = $this->registry->getMetrics($dataset);
        if (isset($metrics[$metric])) {
            return $metrics[$metric];
        }

        $computed = $this->registry->getComputedMetrics($dataset);
        if (isset($computed[$metric])) {
            return $computed[$metric];
        }

        // Try alias resolution
        $resolved = $this->registry->resolveMetric($dataset, $metric);
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
            'dataset' => $result['parsed_query']['dataset'] ?? null,
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

    public function getDatasets(): array
    {
        return $this->registry->getAvailableDatasets();
    }

    /**
     * The schema registry this orchestrator resolves against.
     *
     * Exposed so callers describing what can be asked — the /datasets endpoint,
     * a custom front end — read the same registry the engine answers from,
     * rather than a copy that can disagree with it.
     */
    public function registry(): SchemaRegistry
    {
        return $this->registry;
    }

    public function getDatasetMetrics(string $datasetKey): array
    {
        return $this->registry->getDatasetMetrics($datasetKey);
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

    public function clearCache(?string $dataset = null, int $olderThanDays = 0, int $minHits = 0): int
    {
        return $this->cache->clear($dataset, $olderThanDays, $minHits);
    }
}
