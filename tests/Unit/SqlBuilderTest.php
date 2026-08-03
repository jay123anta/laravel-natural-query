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
            'group_value' => null,
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
            'group_value' => null,
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
            'group_value' => null,
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
            'group_value' => null,
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
            'group_value' => 'Sharma Traders',
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
    public function it_builds_a_group_detail_query_with_parameterized_bindings()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 1,
            'order' => 'desc',
            'group_value' => 'Kamrup',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('group_detail', $result['query_type']);
        // Should use parameterized queries (? placeholders)
        $this->assertStringContainsString('?', $result['sql']);
        $this->assertNotEmpty($result['bindings']);
        $this->assertCount(3, $result['bindings']); // exact, LIKE, CASE
        $this->assertEquals('Kamrup', $result['bindings'][0]);
    }

    /**
     * `district` was the pre-1.0 name for this field. A cached intent, a
     * custom prompt override or a third-party provider can still send it, and
     * dropping it silently would turn a filtered question into an unfiltered
     * one — the wrong answer, confidently.
     */
    #[Test]
    public function the_legacy_district_key_is_still_honoured()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 1,
            'order' => 'desc',
            'district' => 'Kamrup',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('group_detail', $result['query_type']);
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
            'group_value' => null,
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
            'group_value' => null,
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
            'group_value' => null,
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
            'group_value' => null,
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
            'group_value' => "Kamrup'; DROP TABLE--",
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
            'group_value' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('DESC', $result['order']); // falls back to DESC
    }

    /**
     * ILIKE is PostgreSQL-only. The named-record lookup used it unconditionally,
     * so on MySQL and MariaDB — both officially supported — every "details for
     * X" query died with a syntax error. Nothing caught it because the builder
     * never consulted the dialect and MySQL had no integration coverage.
     */
    #[Test]
    public function the_named_record_lookup_uses_portable_sql_not_postgres_only_ilike()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => 'Acme',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsStringIgnoringCase(
            'ILIKE',
            $result['sql'],
            'ILIKE is a hard syntax error on MySQL/MariaDB'
        );
        $this->assertStringContainsString('LOWER(', $result['sql']);
        $this->assertStringContainsString('LIKE', $result['sql']);
    }

    /** The value must stay a binding — never interpolated into the SQL. */
    #[Test]
    public function the_named_record_lookup_parameterises_the_value()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => 'Acme Traders',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('Acme Traders', $result['sql']);
        $this->assertContains('Acme Traders', $result['bindings']);
        $this->assertContains('%Acme Traders%', $result['bindings']);
    }

    /**
     * Belt and braces on top of the bindings: quotes, semicolons, backslashes
     * and LIKE wildcards are stripped from the value before it is ever used,
     * and anything that stops looking like a name is dropped entirely.
     */
    #[Test]
    public function a_dangerous_looking_name_is_sanitised_away_rather_than_bound()
    {
        $result = $this->builder->buildQuery([
            'scheme' => 'test_orders',
            'metric' => 'amount',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => "Acme'; DROP TABLE orders; --",
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsStringIgnoringCase('DROP', $result['sql']);
        foreach ($result['bindings'] as $binding) {
            $this->assertStringNotContainsString(';', (string) $binding);
            $this->assertStringNotContainsString("'", (string) $binding);
        }
    }
}
