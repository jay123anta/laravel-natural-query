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
 * NQ-001-v3, RED for G2 — defect 1 end to end ("winner-takes-all cascade").
 *
 * `DatasetSeeder::seeds()` (src/Engine/DatasetSeeder.php:129) reads:
 *
 *     $seeds = $this->routingSeeds($q) ?: $this->schemaAliasSeeds($q) ?: $this->columnAliasSeeds($q);
 *
 * The moment ANY `query_routing` keyword matches, every schema-alias match
 * is discarded — even one naming a totally different dataset by its own
 * alias, in plain English, in the same question.
 *
 * Fixture: tests/Stubs/cascade-merge-schemas. Three datasets — cm_orders
 * (alias "orders"), cm_customers (FK target of cm_orders.customer_id, not
 * otherwise relevant here), cm_products (alias "products", NO relationship
 * or required_join to either of the other two, so no amount of FK/join
 * expansion can ever rescue it into scope). `query_routing` maps "revenue"
 * to cm_orders — exactly the docs/PROVIDERS.md-recommended shape. Rows,
 * hand-checkable:
 *
 *   cm_products.revenue: 7, 11   -> correct answer to "which products
 *                                    bring in the most revenue" sums to 18
 *   cm_orders.revenue:  100, 200 -> the WRONG table's total is 300
 *
 * The question below contains BOTH "revenue" (routing tier -> cm_orders)
 * and "products" (cm_products' own alias, named directly). A question that
 * literally says "products" must be answered from the products data, not
 * silently answered from orders because a routing keyword happened to
 * match too.
 *
 * The stand-in provider below behaves the way a real model plausibly would:
 * if cm_products was actually rendered into the prompt (i.e. scope
 * genuinely included it) it answers from cm_products, correctly; if
 * cm_products never reached the prompt at all — today's bug — the only
 * revenue-bearing table it was shown is cm_orders, so it answers from
 * there instead. Nothing forces that outcome except what SchemaShortlister
 * actually put in scope, which is exactly what this test is pinning.
 *
 * WHAT THIS TEST CATCHES: with prompts.max_chars set generously (50000 —
 * no size pressure at all, so this cannot be mistaken for a budget
 * refusal), a question that names "products" outright gets `datasets_in_scope
 * = [cm_customers, cm_orders]`, the string "cm_products" never reaches the
 * prompt, and the orchestrator returns `status=success` with 300 — the
 * ORDERS total — for a question that asked about products. That is Rule
 * 0's worst failure mode: a confidently wrong number, not even a refusal.
 */
class PromptScopeSeedCascadeDropsNamedDatasetTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/cascade-merge-schemas');
        $app['config']->set('naturalquery.query_mode', 'sql_generation');
        $app['config']->set('naturalquery.cache.enabled', false);

        // Generous on purpose: this test is about SCOPE (relevance), never
        // about SIZE (R3). Nothing here should be able to trip a budget
        // refusal.
        $app['config']->set('naturalquery.prompts.max_chars', 50000);

        // Exactly docs/PROVIDERS.md's recommended shape: one routing keyword
        // pointing "revenue" at the orders dataset.
        $app['config']->set('naturalquery.query_routing', ['revenue' => 'cm_orders']);
        $app['config']->set('naturalquery.default_dataset', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('cm_orders');
        Schema::create('cm_orders', function ($t) {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->decimal('revenue', 12, 2);
        });
        DB::table('cm_orders')->insert([
            ['customer_id' => 1, 'revenue' => 100],
            ['customer_id' => 1, 'revenue' => 200],
        ]);

        Schema::dropIfExists('cm_customers');
        Schema::create('cm_customers', function ($t) {
            $t->id();
            $t->string('name');
        });
        DB::table('cm_customers')->insert([
            ['name' => 'Acme'],
        ]);

        Schema::dropIfExists('cm_products');
        Schema::create('cm_products', function ($t) {
            $t->id();
            $t->string('name');
            $t->decimal('revenue', 12, 2);
        });
        DB::table('cm_products')->insert([
            ['name' => 'Gizmo', 'revenue' => 7],
            ['name' => 'Widget', 'revenue' => 11],
        ]);
    }

    private function wiredProvider(): RecordingProvider
    {
        $provider = new RecordingProvider();

        // What a real model would plausibly write: it can only name a table
        // it was actually shown. Whether it was shown cm_products at all is
        // exactly what SchemaShortlister's scope decides — this callable
        // does not decide the outcome, it only reports which table the
        // rendered prompt made visible.
        $provider->sqlResponse = function (string $prompt) {
            $sawProducts = str_contains(strtolower($prompt), 'cm_products');

            return [
                'success' => true,
                'data' => [
                    'sql' => $sawProducts
                        ? 'SELECT SUM(revenue) AS total FROM cm_products'
                        : 'SELECT SUM(revenue) AS total FROM cm_orders',
                    'dataset' => $sawProducts ? 'cm_products' : 'cm_orders',
                    'metric' => 'revenue',
                    'query_type' => 'aggregation',
                    'group_value' => null,
                    'order' => 'DESC',
                    'limit' => 100,
                    'period' => null,
                    'explanation' => $sawProducts ? 'Total revenue by product' : 'Total revenue',
                ],
            ];
        };

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $provider;
    }

    #[Test]
    public function a_question_naming_products_by_alias_is_not_silently_answered_from_orders()
    {
        $provider = $this->wiredProvider();

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('which products bring in the most revenue');

        // Sanity: this is the SILENT WRONG NUMBER failure mode, not a
        // refusal. If this were 'error' the test would be measuring R6's
        // gate instead of the seeding cascade this test exists to pin.
        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'expected a (possibly wrong) answer, not a refusal: ' . json_encode($result)
        );

        // THE RED ASSERTION — hand-checked arithmetic, the assertion that
        // matters most in this whole reproduction: cm_products.revenue is
        // 7 + 11 = 18. Today the question is answered from cm_orders
        // instead (100 + 200 = 300) because cm_products never reached the
        // prompt at all. Checked BEFORE the datasets_in_scope assertion
        // below on purpose, so its failure is observed independently rather
        // than being hidden behind an earlier failing assertion.
        $this->assertEquals(
            18.0,
            (float) array_values((array) $result['rows'][0])[0],
            'hand-checked: cm_products.revenue 7 + 11 = 18 is the correct answer to a question about '
                . 'products; got the cm_orders total instead. Full result: ' . json_encode($result)
        );

        // Supporting assertion, same underlying cause: cm_products was
        // named by its own alias in the question text and must be a seed
        // candidate regardless of the "revenue" routing match.
        $this->assertContains(
            'cm_products',
            $result['metadata']['datasets_in_scope'] ?? [],
            'cm_products was named by alias in the question but is missing from datasets_in_scope. '
                . 'Got: ' . json_encode($result['metadata']['datasets_in_scope'] ?? null)
        );

        // Forward guard, so a later change cannot "fix" this by including
        // cm_products in the prompt without actually letting the SQL use it.
        $this->assertSame(1, count($provider->methodsCalled()), json_encode($provider->calls));
    }
}
