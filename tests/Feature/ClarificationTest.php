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
        $app['config']->set(
            'naturalquery.sql.introspectors.sqlite',
            \Jayanta\NaturalQuery\Tests\Support\SqliteTestIntrospector::class
        );
    }

    /** Answer every intent parse with the given payload. */
    private function provider(array $intent): void
    {
        $provider = new RecordingProvider();
        $provider->intentResponse = $intent + [
            'success' => true,
            'confidence' => 0.5,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);
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
