<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Tests\Support\RecordingProvider;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Asking "which is the best?" of a single-dataset app produced "Which dataset
 * would you like to query?" with one button on it. Clicking that button re-sent
 * the same question, got back the same response, and redrew the same card —
 * so the widget looked broken while behaving exactly as written.
 *
 * The dataset was never in doubt. The model says clarification_type=scheme
 * when it cannot tell what "best" measures, and that was taken literally.
 */
class ClarificationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
        $app['config']->set('naturalquery.query_mode', 'intent');
    }

    /** Answer every intent parse with the given payload. */
    private function provider(array $intent): RecordingProvider
    {
        $provider = new RecordingProvider();
        $provider->intentResponse = $intent + [
            'success' => true,
            'confidence' => 0.5,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);
        $this->app->forgetInstance(\Jayanta\NaturalQuery\Engine\QueryPlanner::class);

        return $provider;
    }

    #[Test]
    public function a_single_dataset_app_is_never_asked_which_dataset()
    {
        $this->provider([
            'scheme' => null,
            'metric' => null,
            'needs_clarification' => true,
            'clarification_type' => 'scheme',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('which is the best?');

        $this->assertSame('clarification_needed', $result['status']);
        $this->assertSame(
            'metric_clarification',
            $result['type'],
            'there is only one dataset, so the open question is what to measure'
        );
    }

    #[Test]
    public function a_metric_clarification_actually_offers_metrics()
    {
        // An empty options list is what made the card a dead end.
        $this->provider([
            'scheme' => 'gb_sales',
            'metric' => null,
            'needs_clarification' => true,
            'clarification_type' => 'scheme',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('which is the best?');

        $this->assertNotEmpty($result['available_metrics']);

        $keys = array_column($result['available_metrics'], 'key');
        $this->assertContains('revenue', $keys);
        $this->assertContains('record_count', $keys);
    }

    /**
     * "Revenue by region" is line_total in one table and region in another.
     * The model correctly reports it cannot group the dataset it chose by a
     * column that dataset does not have — and the prompt tells it to say
     * 'ambiguous' in exactly that case.
     *
     * Turning that into "which metric did you mean?" is a dead end: the user
     * already named the metric, and no button on the card can fix a missing
     * JOIN. Auto mode should try SQL generation, which can write the join.
     */
    #[Test]
    public function an_unavailable_breakdown_across_related_tables_is_retried_as_sql()
    {
        config(['naturalquery.schema.config_path' => __DIR__ . '/../Stubs/related-schemas']);
        config(['naturalquery.query_mode' => 'auto']);
        $this->app->forgetInstance(\Jayanta\NaturalQuery\Schema\SchemaRegistry::class);

        $provider = $this->provider([
            'scheme' => 'rel_orders',
            'metric' => 'quantity',
            'needs_clarification' => true,
            'clarification_type' => 'ambiguous',
        ]);

        $this->app->make(QueryOrchestrator::class)->query('quantity by customer name');

        // The attempt is the behaviour. Whether the generated SQL then answers
        // depends on the model, but stopping at a menu without trying is the
        // bug: no button on that card can supply a missing JOIN.
        $this->assertContains(
            'generateSql',
            $provider->methodsCalled(),
            'a joinable question must be retried as SQL, not answered with a menu'
        );
    }

    #[Test]
    public function a_genuine_metric_question_is_still_asked_not_guessed()
    {
        // Only 'ambiguous' means "wrong dataset for this breakdown". A real
        // metric ambiguity must still be put to the user rather than resolved
        // by letting the model write whatever SQL it likes.
        $this->provider([
            'scheme' => 'gb_sales',
            'metric' => null,
            'needs_clarification' => true,
            'clarification_type' => 'metric',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('which is the best?');

        $this->assertSame('clarification_needed', $result['status']);
    }

    #[Test]
    public function the_resolved_dataset_is_reported_back()
    {
        $this->provider([
            'scheme' => null,
            'metric' => null,
            'needs_clarification' => true,
            'clarification_type' => 'scheme',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('which is the best?');

        $this->assertSame('gb_sales', $result['parsed_query']['scheme']);
    }
}
