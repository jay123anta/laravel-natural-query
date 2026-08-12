<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Engine\SchemaShortlister;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-001 — index-first prompting, RED gate.
 *
 * `SchemaShortlister` does not exist yet. This is the class the approved
 * design (G1) names as the thing that expands a model-named seed list over
 * the foreign-key graph BEFORE stage two renders full detail — mechanically,
 * not by trusting the model to have named every table a question touches.
 *
 * Fixture (tests/Stubs/shortlist-schemas), five datasets, hand-checkable FK
 * graph:
 *   sl_customers            (no relationships of its own)
 *   sl_orders     -> sl_customers
 *   sl_order_items -> sl_orders, -> sl_products (sl_products has NO schema file)
 *   sl_regions              (unrelated to everything)
 *   sl_warehouses            (unrelated to everything)
 *
 * What wrong behaviour each test catches:
 *
 *  - one_seed_expands_to_exactly_its_neighbours: without mechanical FK
 *    expansion, a model that names only "customers" never sees "orders", so
 *    "top customers by revenue" is answered from the wrong table or refused.
 *    The design's own example. sl_orders references sl_customers, NOT the
 *    other way round — so this also pins that expansion walks the FK edge in
 *    reverse, not just forward.
 *
 *  - a_hallucinated_key_is_dropped_not_expanded: an 8B model naming a table
 *    that does not exist must not silently poison the shortlist (e.g. by
 *    crashing, or by being treated as a real seed with no neighbours).
 *
 *  - an_all_hallucinated_reply_falls_open_to_every_dataset: binding condition
 *    5 — stage one must fail OPEN, never proceed on a guess. If every named
 *    seed is fictional, the only safe behaviour is the full dataset list, not
 *    an empty, confidently-wrong shortlist.
 *
 *  - expansion_refuses_a_table_outside_allows_table: mirrors the existing
 *    joinsFor() guard in PromptBuilder — a foreign key pointing at a table
 *    with no schema file must never be added to the shortlist, or the model
 *    is invited to write SQL that SqlValidator will then reject.
 */
class SchemaShortlistTest extends TestCase
{
    private SchemaRegistry $registry;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/shortlist-schemas');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = $this->app->make(SchemaRegistry::class);
    }

    #[Test]
    public function precondition_five_datasets_with_a_known_fk_graph()
    {
        $this->assertEqualsCanonicalizing(
            ['sl_customers', 'sl_orders', 'sl_order_items', 'sl_regions', 'sl_warehouses'],
            $this->registry->keys()
        );

        $this->assertFalse(
            $this->registry->allowsTable('sl_products'),
            'precondition: sl_products must be outside the whitelist to exercise the allowsTable() refusal'
        );
    }

    #[Test]
    public function one_seed_expands_to_exactly_its_neighbours()
    {
        $shortlister = new SchemaShortlister($this->registry);

        $result = $shortlister->shortlist(['sl_customers']);

        // sl_customers carries no relationship of its own. sl_orders is the
        // ONLY dataset in the fixture whose relationship points AT
        // sl_customers (orders.customer_id -> customers.id). Everything else
        // is two hops away or unrelated and must be omitted, not expanded.
        $this->assertEqualsCanonicalizing(['sl_customers', 'sl_orders'], $result['keys']);
        $this->assertEqualsCanonicalizing(
            ['sl_order_items', 'sl_regions', 'sl_warehouses'],
            $result['omitted']
        );
        $this->assertSame('seeded', $result['source']);
    }

    #[Test]
    public function a_hallucinated_key_is_dropped_not_expanded()
    {
        $shortlister = new SchemaShortlister($this->registry);

        $result = $shortlister->shortlist(['sl_customers', 'made_up_table']);

        $this->assertEqualsCanonicalizing(['sl_customers', 'sl_orders'], $result['keys']);
        $this->assertNotContains('made_up_table', $result['keys']);
        $this->assertNotContains('made_up_table', $result['omitted']);
    }

    #[Test]
    public function an_all_hallucinated_reply_falls_open_to_every_dataset()
    {
        $shortlister = new SchemaShortlister($this->registry);

        $result = $shortlister->shortlist(['made_up_1', 'made_up_2']);

        $this->assertEqualsCanonicalizing($this->registry->keys(), $result['keys']);
        $this->assertSame([], $result['omitted']);
        $this->assertSame('all', $result['source']);
        $this->assertNotEmpty($result['reason'], 'a fall-open decision must be auditable, not silent');
    }

    #[Test]
    public function expansion_refuses_a_table_outside_allows_table()
    {
        $shortlister = new SchemaShortlister($this->registry);

        $result = $shortlister->shortlist(['sl_order_items']);

        // sl_order_items has TWO foreign keys: one to sl_orders (whitelisted)
        // and one to sl_products (no schema file, not whitelisted). Only the
        // first may be added — the second is exactly the join
        // PromptBuilder::joinsFor() already refuses to advertise, because
        // SqlValidator would reject the resulting SQL.
        $this->assertContains('sl_orders', $result['keys']);
        $this->assertNotContains('sl_products', $result['keys']);
    }
}
