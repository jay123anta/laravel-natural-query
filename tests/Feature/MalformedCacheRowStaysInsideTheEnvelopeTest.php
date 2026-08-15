<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Engine\ErrorCode;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Tests\Support\RecordingProvider;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A bad row in the cache must not become a framework 500.
 *
 * query() wrapped everything in `catch (\Exception)`. A TypeError extends
 * \Error, not \Exception, so it went straight past — taking the whole error
 * envelope with it. The caller got a stack trace instead of a NaturalQuery
 * response: no `error_code` to branch on, no `retryable`, no Retry-After, and
 * the QuestionFailed event never fired.
 *
 * Reaching it needs no bug in the caller, only a row whose `intent` column is
 * present and is not an array — a truncated write, a hand-edited row, a
 * restored backup from a different schema, a third-party cache returning the
 * decoded blob in a different shape. normalizeIntent(array $intent) then
 * throws. Tier 2 rows have no expiry, so that question stays a hard 500
 * forever, for everyone, with nothing in the response to say why.
 */
class MalformedCacheRowStaysInsideTheEnvelopeTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('naturalquery.cache.enabled', true);
        $app['config']->set('naturalquery.query_mode', 'intent');
    }

    #[Test]
    public function a_cached_intent_of_the_wrong_shape_returns_an_error_response_not_a_crash()
    {
        $this->artisan('migrate', ['--force' => true])->run();

        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_orders')->insert(['revenue' => 100]);

        $provider = new RecordingProvider();
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
            'confidence' => 0.95,
            'needs_clarification' => false,
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $question = 'total revenue';

        // Asked with NO dataset hint, against two registered datasets, so
        // resolveAskingDataset() cannot place it and the asking scope is null.
        // That is what makes the bad row reachable: the entry guard reads
        // `$cached['intent']['_asking_scope'] ?? null`, which on a string
        // intent yields null, matches a null asking scope, and passes the row
        // straight to normalizeIntent(array $intent). With a hint the guard
        // discards it first and the crash never happens — which is why this
        // has to be the unhinted route.
        //
        // Warm the cache the ordinary way, then corrupt the stored blob so the
        // row is present, current, and the wrong shape.
        $orchestrator->query($question);

        $table = config('naturalquery.cache.table_name', 'naturalquery_cache');
        DB::table($table)->update(['intent' => json_encode('not an array')]);

        // Tier 1 would serve the good copy from memory and hide it.
        $this->app->make(\Illuminate\Contracts\Cache\Factory::class)->store()->flush();

        $result = $orchestrator->query($question);

        $this->assertIsArray($result);
        $this->assertSame('error', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            ErrorCode::INTERNAL,
            $result['error_code'] ?? null,
            'a malformed cache row escaped the error envelope as an uncaught TypeError, so the caller got '
                . 'a framework 500 with no error_code, no retryable flag and no event: ' . json_encode($result)
        );
    }
}
