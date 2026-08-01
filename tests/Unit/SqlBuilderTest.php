<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Engine\SqlBuilder;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SqlBuilderTest extends TestCase
{
    private SqlBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $registry = new SchemaRegistry(__DIR__ . '/../Stubs/schemas');
        $this->builder = new SqlBuilder($registry);
    }

    #[Test]
    public function it_builds_ranking_query()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 10,
            'order' => 'desc',
            'district' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('SELECT', $result['sql']);
        $this->assertStringContainsString('public.orders', $result['sql']);
        $this->assertStringContainsString('amount', $result['sql']);
        $this->assertStringContainsString('ORDER BY', $result['sql']);
        $this->assertStringContainsString('DESC', $result['sql']);
        $this->assertStringContainsString('LIMIT 10', $result['sql']);
        $this->assertEquals('ranking', $result['query_type']);
        $this->assertEmpty($result['bindings']);
    }

    #[Test]
    public function it_aggregates_ranking_on_transactional_tables()
    {
        // Regression: 'amount' is aggregatable → one row per order must be
        // summed per customer, not ranked as raw order lines (which produced
        // duplicate customers in the top-N).
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 10,
            'order' => 'desc',
            'district' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('SUM(amount)', $result['sql']);
        $this->assertStringContainsString('GROUP BY customer_name', $result['sql']);
    }

    #[Test]
    public function it_groups_aggregate_computed_metrics_without_nesting()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'avg_amount',
            'limit' => 5,
            'order' => 'desc',
            'district' => null,
        ]);

        $this->assertTrue($result['success']);
        // Expression already aggregates — must be grouped, never SUM-wrapped
        $this->assertStringContainsString('GROUP BY customer_name', $result['sql']);
        $this->assertStringContainsString('ROUND(AVG(amount), 2)', $result['sql']);
        $this->assertStringNotContainsString('SUM(ROUND', $result['sql']);
    }

    #[Test]
    public function it_does_not_aggregate_preaggregated_tables()
    {
        // Backward compatibility: no aggregatable columns (one row per
        // district) → read rows as-is, no GROUP BY, no SUM
        $result = $this->builder->buildQuery([
            'scheme' => 'test_districts',
            'metric' => 'total_households',
            'limit' => 10,
            'order' => 'desc',
            'district' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('GROUP BY', $result['sql']);
        $this->assertStringNotContainsString('SUM(', $result['sql']);
    }

    #[Test]
    public function it_aggregates_group_value_detail_on_transactional_tables()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 1,
            'order' => 'desc',
            'district' => 'Sharma Traders',
        ]);

        $this->assertTrue($result['success']);
        // Detail view must sum the customer's orders, not pick one row
        $this->assertStringContainsString('SUM(amount) AS amount', $result['sql']);
        $this->assertStringContainsString('GROUP BY customer_name', $result['sql']);
    }

    #[Test]
    public function aggregation_query_does_not_nest_aggregate_expressions()
    {
        $plain = $this->builder->buildAggregationQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
        ]);
        $this->assertTrue($plain['success']);
        $this->assertStringContainsString('SUM(amount)', $plain['sql']);

        $computed = $this->builder->buildAggregationQuery([
            'scheme' => 'test_orders',
            'metric' => 'avg_amount',
        ]);
        $this->assertTrue($computed['success']);
        $this->assertStringNotContainsString('SUM(ROUND', $computed['sql']);
        $this->assertStringContainsString('ROUND(AVG(amount), 2)', $computed['sql']);
    }

    #[Test]
    public function it_builds_district_query_with_parameterized_bindings()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 1,
            'order' => 'desc',
            'district' => 'Kamrup',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('district', $result['query_type']);
        // Should use parameterized queries (? placeholders)
        $this->assertStringContainsString('?', $result['sql']);
        $this->assertNotEmpty($result['bindings']);
        $this->assertCount(3, $result['bindings']); // exact, ILIKE, CASE
        $this->assertEquals('Kamrup', $result['bindings'][0]);
    }

    #[Test]
    public function it_resolves_metric_aliases()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'revenue', // alias for 'amount'
            'limit' => 5,
            'order' => 'desc',
            'district' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('amount', $result['metric']);
    }

    #[Test]
    public function it_uses_default_metric_when_none_specified()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => null,
            'limit' => 5,
            'order' => 'desc',
            'district' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('amount', $result['metric']); // default_metric
    }

    #[Test]
    public function it_fails_for_unknown_scheme()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'nonexistent',
            'metric' => 'amount',
            'limit' => 5,
            'order' => 'desc',
            'district' => null,
        ]);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function it_fails_for_null_scheme()
    {
        $result = $this->builder->buildQuery([
            'scheme' => null,
            'metric' => 'amount',
        ]);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function it_caps_limit_to_max()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 500, // max is 100
            'order' => 'desc',
            'district' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(100, $result['limit']);
    }

    #[Test]
    public function it_sanitizes_district_with_dangerous_chars()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 1,
            'order' => 'desc',
            'district' => "Kamrup'; DROP TABLE--",
        ]);

        // Should either sanitize to safe value or reject
        if ($result['success']) {
            $this->assertStringNotContainsString('DROP', $result['sql']);
            $this->assertStringNotContainsString("'", $result['bindings'][0] ?? '');
        }
    }

    #[Test]
    public function it_normalizes_order_direction()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 5,
            'order' => 'invalid',
            'district' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('DESC', $result['order']); // falls back to DESC
    }
}
