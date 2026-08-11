<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Engine\NextStepSuggester;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The hard part of asking your data questions is not phrasing one — it is
 * knowing what can be asked at all. In a chat box there is nothing on screen
 * to tell you, so every answer carries a few concrete follow-ups.
 *
 * They are built from the schema rather than from the model: free, instant,
 * and incapable of proposing a breakdown the validator would then refuse.
 */
class NextStepSuggesterTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
    }

    private function suggest(array $overrides = [], array $rows = []): array
    {
        return $this->app->make(NextStepSuggester::class)->suggest($overrides + [
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_column' => 'region',
            'query_type' => 'ranking',
            'order' => 'DESC',
            'limit' => 5,
        ], $rows);
    }

    #[Test]
    public function it_offers_the_other_breakdowns_the_schema_allows()
    {
        $queries = array_column($this->suggest(), 'query');

        $this->assertNotEmpty($queries);

        // Re-offering the question just answered is the one useless suggestion.
        // ("how many by region" is fine — same slice, different measure.)
        $this->assertNotContains('revenue by region', $queries);

        $joined = implode(' | ', $queries);
        $this->assertTrue(
            str_contains($joined, 'customer_name') || str_contains($joined, 'status'),
            'expected another groupable column to be offered, got: ' . $joined
        );
    }

    #[Test]
    public function it_never_suggests_a_breakdown_the_validator_would_refuse()
    {
        // revenue is a measure, not a dimension. Grouping by it is a question
        // the SqlBuilder is required to reject, so it must never be offered.
        // Only the breakdown position matters — "... by revenue" as the METRIC
        // is exactly right.
        foreach ($this->suggest() as $s) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bby revenue\b(?!\s*$)/',
                $s['query'],
                'revenue may only appear as the measure, never as the grouping'
            );
        }
    }

    #[Test]
    public function it_offers_to_drill_into_the_top_result()
    {
        $suggestions = $this->suggest([], [['region' => 'West', 'revenue' => 5515736]]);

        $labels = array_column($suggestions, 'label');
        $matching = array_values(array_filter($labels, fn ($l) => str_contains($l, 'West')));

        $this->assertNotEmpty($matching, 'expected a drill-down into the top row: ' . implode(' | ', $labels));
    }

    #[Test]
    public function drilling_into_values_can_be_switched_off()
    {
        // For deployments that would rather no value from the data appear in a
        // suggestion, even though the user is the one who would send it.
        config(['naturalquery.chat.suggest_drilldown_values' => false]);

        $labels = array_column($this->suggest([], [['region' => 'West', 'revenue' => 1]]), 'label');

        foreach ($labels as $label) {
            $this->assertStringNotContainsString('West', $label);
        }
    }

    #[Test]
    public function it_offers_counting_after_a_money_question()
    {
        // "How many" is the question people reach for right after seeing money.
        $queries = implode(' | ', array_column($this->suggest(), 'query'));

        $this->assertStringContainsString('how many', $queries);
    }

    #[Test]
    public function it_offers_the_opposite_end_of_a_ranking()
    {
        $labels = implode(' | ', array_column($this->suggest(['order' => 'DESC']), 'label'));

        $this->assertStringContainsString('Bottom', $labels);
    }

    #[Test]
    public function a_detail_view_is_not_drilled_into_again()
    {
        $suggestions = $this->suggest(
            ['group_value' => 'West', 'query_type' => 'group_detail'],
            [['region' => 'West', 'revenue' => 1]]
        );

        foreach ($suggestions as $s) {
            $this->assertStringNotContainsString('Break West', $s['label']);
        }
    }

    #[Test]
    public function suggestions_are_capped_and_never_duplicated()
    {
        config(['naturalquery.chat.max_next_steps' => 3]);

        $suggestions = $this->suggest([], [['region' => 'West', 'revenue' => 1]]);
        $queries = array_column($suggestions, 'query');

        $this->assertLessThanOrEqual(3, count($suggestions));
        $this->assertSame($queries, array_values(array_unique($queries)));
    }

    #[Test]
    public function it_stays_silent_when_switched_off_or_for_an_unknown_dataset()
    {
        config(['naturalquery.chat.suggest_next_steps' => false]);
        $this->assertSame([], $this->suggest());

        config(['naturalquery.chat.suggest_next_steps' => true]);
        $this->assertSame([], $this->suggest(['dataset' => 'no_such_dataset']));
    }
}
