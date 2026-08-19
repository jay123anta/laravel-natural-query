<?php

namespace Jayanta\NaturalQuery\Tests;

use Jayanta\NaturalQuery\NaturalQueryServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [NaturalQueryServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('naturalquery.llm.driver', 'gemini');
        $app['config']->set('naturalquery.llm.providers.gemini.api_key', 'test-key');
        $app['config']->set('naturalquery.cache.enabled', false);
        $app['config']->set('naturalquery.verification.enabled', false);
        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/Stubs/schemas');
    }
}
