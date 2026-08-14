<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Engine\DatasetSeeder;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-001-v3, RED for G2 — defect 1 ("winner-takes-all cascade").
 *
 * `DatasetSeeder::seeds()` currently reads:
 *
 *     $seeds = $this->routingSeeds($q) ?: $this->schemaAliasSeeds($q) ?: $this->columnAliasSeeds($q);
 *
 * `?:` short-circuits the moment the first tier returns anything non-empty.
 * So the instant ANY `query_routing` keyword matches, EVERY schema-alias
 * match is discarded outright — even one naming a totally different,
 * unrelated dataset by its own alias, in the same sentence, in plain words.
 *
 * WHAT THIS TEST CATCHES: fixture `tests/Stubs/cascade-merge-schemas` has
 * three datasets — cm_orders (alias "orders"), cm_customers (FK target of
 * cm_orders.customer_id, not otherwise seedable from this question's text),
 * and cm_products (alias "products", carrying NO relationship or
 * required_join to either of the other two — nothing about FK/join
 * expansion can ever pull it into scope). `query_routing` maps the word
 * "revenue" to cm_orders, mirroring the docs/PROVIDERS.md-recommended
 * pattern. The question below contains BOTH "revenue" (routing tier) and
 * "products" (schema-alias tier, cm_products' own alias, named in plain
 * English). A question that literally says "products" must seed the
 * products dataset. Today it does not: the routing tier matching anything
 * at all — regardless of whether it has any relation to "products" —
 * silently deletes the alias match before SchemaShortlister ever sees it,
 * so a dataset the question NAMES BY ALIAS is never even offered as a seed
 * candidate, and R2 then omits it from the prompt entirely.
 *
 * The fix (not made by this test) is to MERGE every tier's matches rather
 * than cascade through them — never lose a signal, because a scope that is
 * too NARROW produces a confidently wrong number silently, while a scope
 * that is too WIDE at worst costs a loud, actionable budget refusal (§0).
 */
class DatasetSeederCascadeMergeTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/cascade-merge-schemas');
    }

    private function seeder(): DatasetSeeder
    {
        return new DatasetSeeder($this->app->make(SchemaRegistry::class));
    }

    #[Test]
    public function a_routing_match_does_not_suppress_a_dataset_the_question_names_by_its_own_alias()
    {
        config([
            'naturalquery.query_routing' => ['revenue' => 'cm_orders'],
            'naturalquery.default_dataset' => null,
        ]);

        $seeds = $this->seeder()->seeds('which products bring in the most revenue');

        $this->assertContains(
            'cm_orders',
            $seeds,
            'sanity: the routing tier itself must still fire — "revenue" routes to cm_orders'
        );

        $this->assertContains(
            'cm_products',
            $seeds,
            'the question literally says "products" (cm_products\' own alias) but the routing tier '
                . 'matching "revenue" cascaded over the alias tier and dropped it. '
                . 'Got seeds: ' . json_encode($seeds)
        );
    }
}
