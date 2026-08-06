<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Engine\IntentCoverage;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Four wrong-number defects in three days shared one shape: intent mode could
 * not represent part of the question, dropped it, and answered the remainder
 * without saying so. Each was fixed by adding a field — dimension, metric,
 * clarification target, period.
 *
 * Adding fields one at a time never closes the gap, because there is always
 * another SQL clause. This closes it from the other side: if the wording shows
 * the question needs more than the contract holds, use the mode that can
 * express it. Both modes cost one API call, so escalating is close to free —
 * a false positive loses determinism, a false negative is a confident lie.
 */
class IntentCoverageTest extends TestCase
{
    private function coverage(): IntentCoverage
    {
        return $this->app->make(IntentCoverage::class);
    }

    #[Test]
    public function questions_the_contract_can_express_stay_in_intent_mode()
    {
        // Intent mode is deterministic and cannot hallucinate a column. It
        // should keep every question it can genuinely answer.
        foreach ([
            'top 5 customers by revenue',
            'revenue by region',
            'how many orders by status',
            'total revenue',
            'show me Talukdar & Co',
            'revenue last month',
            'bottom 10 regions by revenue',
            'orders per status',
        ] as $query) {
            $this->assertNull(
                $this->coverage()->exceeds($query),
                "'{$query}' fits the intent contract and should not escalate"
            );
        }
    }

    #[Test]
    public function filtering_groups_by_an_aggregate_escalates()
    {
        // There is no HAVING in the contract. "Customers with more than 10
        // orders" quietly became "customers", ranked.
        $this->assertSame('having', $this->coverage()->exceeds('customers with more than 10 orders'));
        $this->assertSame('having', $this->coverage()->exceeds('regions having at least 50 orders'));
    }

    #[Test]
    public function a_numeric_filter_escalates()
    {
        // The contract filters by name and by period. Nothing else.
        $this->assertSame('numeric_filter', $this->coverage()->exceeds('orders over 5000'));
        $this->assertSame('numeric_filter', $this->coverage()->exceeds('sales above £1000'));
        $this->assertSame('numeric_filter', $this->coverage()->exceeds('orders between 100 and 500'));
    }

    #[Test]
    public function an_exclusion_escalates()
    {
        // No NOT anywhere in the contract, so "excluding cancelled" was simply
        // dropped and cancelled orders were counted in the total.
        $this->assertSame('exclusion', $this->coverage()->exceeds('revenue excluding cancelled orders'));
        $this->assertSame('exclusion', $this->coverage()->exceeds('all regions except West'));
        $this->assertSame('exclusion', $this->coverage()->exceeds('orders not including refunds'));
    }

    #[Test]
    public function distinct_counting_escalates()
    {
        // record_count is COUNT(*). "How many different customers" is not that.
        $this->assertSame('distinct', $this->coverage()->exceeds('how many different customers'));
        $this->assertSame('distinct', $this->coverage()->exceeds('count distinct regions'));
    }

    #[Test]
    public function ratios_and_shares_escalate()
    {
        $this->assertSame('ratio', $this->coverage()->exceeds('what percentage of orders were cancelled'));
        $this->assertSame('ratio', $this->coverage()->exceeds('revenue per customer'));
        $this->assertSame('ratio', $this->coverage()->exceeds('share of revenue by region'));
    }

    #[Test]
    public function a_per_group_superlative_escalates()
    {
        // Needs a window function or a correlated subquery — the contract has
        // one ORDER BY and one LIMIT for the whole result, not per group.
        $this->assertSame('per_group_top', $this->coverage()->exceeds('top 2 customers in each region'));
        $this->assertSame('per_group_top', $this->coverage()->exceeds('for each region the highest revenue customer'));
    }

    /**
     * Found by running real Spider dev questions rather than questions written
     * here. Every failure in that run was intent mode and every SQL-generation
     * run passed — the contract carries ONE metric and ONE label, while people
     * routinely ask for several of each.
     */
    #[Test]
    public function asking_for_several_aggregates_at_once_escalates()
    {
        // Answered as a single number, with the other two silently absent.
        $this->assertSame(
            'multi_aggregate',
            $this->coverage()->exceeds('What is the average, minimum, and maximum age of all singers?')
        );
        $this->assertSame(
            'multi_aggregate',
            $this->coverage()->exceeds('What is the average and maximum capacities for all stadiums?')
        );
    }

    #[Test]
    public function asking_for_a_list_of_columns_escalates()
    {
        $this->assertSame(
            'multi_column',
            $this->coverage()->exceeds('Show name, country, age for all singers ordered by age')
        );
        $this->assertSame(
            'multi_column',
            $this->coverage()->exceeds('What are the names, countries, and ages for every singer?')
        );
    }

    #[Test]
    public function a_single_measure_by_a_single_dimension_still_stays_in_intent_mode()
    {
        // The new patterns must not swallow the questions intent mode handles
        // well — it is cheaper, deterministic, and cannot invent a column.
        foreach ([
            'revenue by region',
            'top 5 customers by revenue',
            'how many singers are from each country',
            'total revenue last month',
            'average order value',
        ] as $query) {
            $this->assertNull(
                $this->coverage()->exceeds($query),
                "'{$query}' should stay in intent mode"
            );
        }
    }

    /**
     * Caught by a Spider question that had been passing by luck: "the model of
     * the car whose weight is below the average" needs
     * WHERE x < (SELECT AVG(x)). The digit-anchored numeric patterns miss it
     * precisely because the sentence contains no number.
     */
    #[Test]
    public function a_comparison_against_an_aggregate_escalates()
    {
        foreach ([
            'find the model of the car whose weight is below the average',
            'customers with revenue above the average',
            'products priced under the median',
        ] as $query) {
            $this->assertSame(
                'numeric_filter',
                $this->coverage()->exceeds($query),
                "'{$query}' needs a subquery"
            );
        }
    }

    #[Test]
    public function escalation_can_be_switched_off()
    {
        config(['naturalquery.sql.escalate_beyond_intent' => false]);

        $this->assertNull($this->coverage()->exceeds('customers with more than 10 orders'));
    }
}
