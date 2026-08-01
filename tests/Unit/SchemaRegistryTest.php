<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SchemaRegistryTest extends TestCase
{
    private SchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SchemaRegistry(__DIR__ . '/../Stubs/schemas');
    }

    #[Test]
    public function it_loads_schema_files()
    {
        $all = $this->registry->all();
        $this->assertNotEmpty($all);
        $this->assertArrayHasKey('test_orders', $all);
    }

    #[Test]
    public function it_gets_schema_by_key()
    {
        $schema = $this->registry->get('test_orders');
        $this->assertNotNull($schema);
        $this->assertEquals('Test Orders', $schema['name']);
    }

    #[Test]
    public function it_returns_null_for_unknown_key()
    {
        $this->assertNull($this->registry->get('nonexistent'));
    }

    #[Test]
    public function it_checks_existence()
    {
        $this->assertTrue($this->registry->has('test_orders'));
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    #[Test]
    public function it_gets_table_name()
    {
        $this->assertEquals('public.orders', $this->registry->getTableName('test_orders'));
    }

    #[Test]
    public function it_gets_group_column()
    {
        $this->assertEquals('customer_name', $this->registry->getGroupColumn('test_orders'));
    }

    #[Test]
    public function it_gets_metrics()
    {
        $metrics = $this->registry->getMetrics('test_orders');
        $this->assertArrayHasKey('amount', $metrics);
        $this->assertTrue($metrics['amount']['aggregatable']);
    }

    #[Test]
    public function it_gets_computed_metrics()
    {
        $computed = $this->registry->getComputedMetrics('test_orders');
        $this->assertArrayHasKey('avg_amount', $computed);
        $this->assertEquals('ROUND(AVG(amount), 2)', $computed['avg_amount']['expression']);
    }

    #[Test]
    public function it_resolves_metric_by_alias()
    {
        $this->assertEquals('amount', $this->registry->resolveMetric('test_orders', 'revenue'));
        $this->assertEquals('amount', $this->registry->resolveMetric('test_orders', 'sales'));
        $this->assertEquals('avg_amount', $this->registry->resolveMetric('test_orders', 'average'));
    }

    #[Test]
    public function it_returns_null_for_unknown_metric()
    {
        $this->assertNull($this->registry->resolveMetric('test_orders', 'nonexistent'));
    }

    #[Test]
    public function it_finds_by_alias()
    {
        $this->assertEquals('test_orders', $this->registry->findByAlias('orders'));
        $this->assertEquals('test_orders', $this->registry->findByAlias('sales'));
        $this->assertNull($this->registry->findByAlias('nonexistent'));
    }

    #[Test]
    public function it_gets_allowed_tables()
    {
        $tables = $this->registry->getAllowedTables();
        $this->assertContains('public.orders', $tables);
    }

    #[Test]
    public function it_gets_scheme_list_for_llm()
    {
        $list = $this->registry->getSchemeListForLlm();
        $this->assertNotEmpty($list);
        $keys = array_column($list, 'key');
        $this->assertContains('test_orders', $keys);
        $this->assertArrayHasKey('metrics', $list[0]);
    }

    #[Test]
    public function it_flushes_cache()
    {
        $this->registry->all(); // load
        $this->registry->flush();
        // After flush, re-loading should work
        $all = $this->registry->all();
        $this->assertNotEmpty($all);
    }
}
