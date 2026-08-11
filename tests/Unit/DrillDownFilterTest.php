<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Engine\NextStepSuggester;
use Jayanta\NaturalQuery\Engine\SqlBuilder;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Filtering on one column while grouping by another.
 *
 * Found in the browser, on a suggestion this package generated itself. After
 * "quantity by product category", the drill-down offered "Break Grocery down by
 * customer name" — which asked for "quantity by customer_name for Grocery".
 *
 * group_value was only ever matched against the GROUP column, so that looked
 * for a customer called Grocery, found none, and the unmatched-name retry
 * dropped the filter and listed every customer. A confident, well formatted
 * answer to a question nobody asked — offered by a button the package drew.
 *
 * The contract now separates the two: group_by is what the rows are, and
 * filter_column says which column group_value belongs to.
 */
class DrillDownFilterTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
    }

    private function build(array $intent): array
    {
        return $this->app->make(SqlBuilder::class)->buildQuery($intent + [
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
        ]);
    }

    #[Test]
    public function a_filter_on_another_column_narrows_without_regrouping()
    {
        $result = $this->build([
            'group_by' => 'customer_name',
            'group_value' => 'West',
            'filter_column' => 'region',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('customer_name', $result['group_column'], 'rows are still customers');
        $this->assertSame('region', $result['filter_column']);
        $this->assertStringContainsString('GROUP BY customer_name', $result['sql']);
        $this->assertStringContainsString('LOWER(region)', $result['sql']);
        $this->assertStringNotContainsString('LOWER(customer_name) =', $result['sql']);
    }

    #[Test]
    public function it_stays_a_ranking_not_a_single_row()
    {
        // "Quantity by customer for Grocery" wants every customer in that
        // category. The old path treated any group_value as a detail view and
        // capped it at one row.
        $result = $this->build([
            'group_by' => 'customer_name',
            'group_value' => 'West',
            'filter_column' => 'region',
        ]);

        $this->assertSame('ranking', $result['query_type']);
        // Anchored: "LIMIT 10" contains "LIMIT 1".
        $this->assertDoesNotMatchRegularExpression('/LIMIT 1$/', $result['sql']);
    }

    #[Test]
    public function the_filter_value_is_bound_never_interpolated()
    {
        $result = $this->build([
            'group_by' => 'customer_name',
            'group_value' => "West'; DROP TABLE gb_sales--",
            'filter_column' => 'region',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('DROP TABLE', $result['sql']);
        $this->assertContains("West'; DROP TABLE gb_sales--", $result['bindings']);
    }

    #[Test]
    public function a_period_survives_alongside_the_filter()
    {
        config(['naturalquery.schema.config_path' => __DIR__ . '/../Stubs/time-schemas']);
        $this->app->forgetInstance(\Jayanta\NaturalQuery\Schema\SchemaRegistry::class);
        $this->app->forgetInstance(SqlBuilder::class);

        $result = $this->app->make(SqlBuilder::class)->buildQuery([
            'dataset' => 'tf_sales',
            'metric' => 'revenue',
            'group_by' => 'region',
            'group_value' => 'West',
            'filter_column' => 'region',
            'date_from' => '2026-07-01',
        ]);

        // Same column here, so it is an ordinary detail view — but the period
        // must still apply.
        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertStringContainsString('order_date >= ?', $result['sql']);
    }

    #[Test]
    public function an_unusable_filter_column_is_refused_rather_than_ignored()
    {
        $result = $this->build([
            'group_by' => 'customer_name',
            'group_value' => 'West',
            'filter_column' => 'shoe_size',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('shoe_size', $result['error']);
    }

    #[Test]
    public function filtering_on_the_grouping_column_is_still_a_detail_view()
    {
        // The regression guard: "revenue for West" grouped by region has not
        // changed and must not become a filtered ranking.
        $result = $this->build(['group_value' => 'West']);

        $this->assertSame('group_detail', $result['query_type']);
    }

    /**
     * The suggestion and the engine have to agree. A button that produces a
     * question the builder cannot answer is worse than no button.
     */
    #[Test]
    public function the_drill_down_suggestion_names_the_column_it_filters_on()
    {
        $suggestions = $this->app->make(NextStepSuggester::class)->suggest([
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_column' => 'region',
            'query_type' => 'ranking',
            'order' => 'DESC',
            'limit' => 5,
        ], [['region' => 'West', 'revenue' => 100]]);

        $drill = array_values(array_filter(
            $suggestions,
            fn ($s) => str_contains($s['label'], 'West')
        ));

        $this->assertNotEmpty($drill, 'expected a drill-down into the top row');
        $this->assertStringContainsString('where region is West', $drill[0]['query']);
        $this->assertStringNotContainsString('for West', $drill[0]['query']);
    }
}
