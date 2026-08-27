<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Tests\Support\RecordingProvider;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A rule the schema cannot imply must hold on BOTH routes.
 *
 * `required_filter` is how an adopter states something introspection can never
 * recover: cancelled orders never count towards a total. Nothing in the column
 * types, names or foreign keys says so.
 *
 * It was enforced on one route and requested on the other. Intent mode appends
 * it to the SQL in SqlBuilder, so the model cannot omit it. SQL generation put
 * a line in the prompt -  "REQUIRED FILTER (always include in WHERE)" -  and
 * hoped. A model that ignored it produced a total including every cancelled
 * order, reported success, and nothing downstream re-checked.
 *
 * That is the shape of every defect this release has fixed: a guarantee that
 * holds on the path it was written for and not the one beside it. And it is
 * worse here than most, because the whole point of the setting is that the
 * answer is WRONG without it -  a user who writes the rule has been told the
 * package will apply it.
 *
 * Fixture: 100 paid + 200 paid + 500 cancelled. The rule makes the answer 300;
 * without it, 800.
 */
class RequiredFilterIsEnforcedTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/required-filter-schemas');
        $app['config']->set('naturalquery.cache.enabled', false);
        $app['config']->set('naturalquery.verification.enabled', false);
    }

    private function seedOrders(): void
    {
        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->string('status');
            $t->decimal('revenue', 12, 2);
        });

        foreach ([['paid', 100], ['paid', 200], ['cancelled', 500]] as [$s, $r]) {
            DB::table('nq_orders')->insert(['status' => $s, 'revenue' => $r]);
        }
    }

    /** A model that ignores the prompt line must not produce an unfiltered total. */
    private function disobedientProvider(string $sql): RecordingProvider
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => $sql,
                'dataset' => 'nq_orders',
                'metric' => 'revenue',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $provider;
    }

    private function total(array $r): float
    {
        $row = $r['rows'][0] ?? null;

        return (float) (is_array($row) ? ($row['revenue'] ?? 0) : ($row->revenue ?? 0));
    }

    /**
     * THE DEFECT. sql_generation mode, model omits the filter it was told to
     * include. 800 is every order; 300 is the answer the rule defines.
     */
    #[Test]
    public function sql_generation_does_not_return_a_total_the_rule_forbids()
    {
        config(['naturalquery.query_mode' => 'sql_generation']);
        $this->seedOrders();
        $this->disobedientProvider('SELECT SUM(revenue) AS revenue FROM nq_orders');

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertNotEquals(
            800.0,
            $this->total($result),
            'the model ignored the required filter and the engine served the unfiltered total -  a rule '
                . 'the adopter wrote specifically to make this answer correct was treated as a suggestion'
        );
    }

    /** And when it obeys, the answer is unchanged and not double-filtered. */
    #[Test]
    public function an_obedient_model_is_left_alone()
    {
        config(['naturalquery.query_mode' => 'sql_generation']);
        $this->seedOrders();
        $this->disobedientProvider("SELECT SUM(revenue) AS revenue FROM nq_orders WHERE status != 'cancelled'");

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertEquals(300.0, $this->total($result), 'the correctly filtered answer was altered');
    }

    /** Intent mode already enforced it; this pins that it still does. */
    #[Test]
    public function intent_mode_still_enforces_the_rule()
    {
        config(['naturalquery.query_mode' => 'intent']);
        $this->seedOrders();

        $provider = new RecordingProvider;
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
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertEquals(300.0, $this->total($result), 'intent mode stopped applying the required filter');
    }
}
