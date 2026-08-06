<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The widget is advertised as drop-in and zero-publish: add
 * `<x-naturalquery::widget />` to a Blade view and the browser fetches
 * {prefix}/widget.js straight from the package.
 *
 * Regression: that route was handled by NaturalQueryController, whose
 * constructor pulls in the whole engine — orchestrator, prompt builder, schema
 * introspector. On any database driver the package cannot introspect, simply
 * resolving the controller threw, so a static JavaScript file returned 500 and
 * every page embedding the widget broke. Laravel 11 and 12 default to SQLite,
 * so this was the out-of-the-box experience for a lot of new apps.
 *
 * Serving a file must not depend on the database at all.
 */
class WidgetRouteTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // A driver the package explicitly cannot introspect.
        $app['config']->set('database.default', 'unsupported');
        $app['config']->set('database.connections.unsupported', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Public so the test exercises the asset, not the auth stack.
        $app['config']->set('naturalquery.routes.middleware', []);
    }

    #[Test]
    public function the_widget_asset_is_served_without_a_supported_database_driver()
    {
        $response = $this->get('/naturalquery/widget.js');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('NaturalQuery', $response->getContent());
    }

    #[Test]
    public function the_widget_asset_is_cacheable()
    {
        $this->get('/naturalquery/widget.js')->assertHeader('cache-control', 'max-age=3600, public');
    }

    #[Test]
    public function the_demo_page_does_not_need_a_supported_database_driver_either()
    {
        config(['naturalquery.widget.demo_page' => true]);

        // Rendering may still fail on a missing view, but it must not fail
        // while resolving the controller's dependencies.
        $response = $this->get('/naturalquery/demo');

        $this->assertNotSame(500, $response->getStatusCode(), $response->getContent());
    }

    #[Test]
    public function the_engine_still_refuses_an_unsupported_driver_with_an_actionable_message()
    {
        // The fix must not silently make an unsupported database look workable.
        // SQLite is supported now, so disable it to reach the refusal path.
        config(['naturalquery.sql.introspectors' => ['sqlite' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("cannot introspect the 'sqlite' database driver");

        $this->app->make(\Jayanta\NaturalQuery\Engine\QueryOrchestrator::class);
    }
}
