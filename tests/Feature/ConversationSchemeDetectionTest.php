<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Jayanta\NaturalQuery\Conversation\ConversationManager;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package has to work against any application's database, so nothing in it
 * may assume a particular domain.
 *
 * `looksLikeScheme()` decides whether a short follow-up names a different
 * dataset or is a value to look up inside the current one. It used to match a
 * hardcoded list of scheme names from the project this package was extracted
 * from. On every other application that list matched nothing, so short
 * follow-ups were always treated as record lookups — and, worse, an unrelated
 * app that happened to mention one of those words was told it had switched
 * dataset.
 *
 * These cases run against the test schemas (test_orders, test_districts),
 * which share no vocabulary with that list.
 */
class ConversationSchemeDetectionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Resolving ConversationManager pulls in the whole engine, which
        // refuses an unsupported driver. The suite runs on SQLite.
        $app['config']->set(
            'naturalquery.sql.introspectors.sqlite',
            \Jayanta\NaturalQuery\Tests\Support\SqliteTestIntrospector::class
        );
    }

    private function manager(): ConversationManager
    {
        return $this->app->make(ConversationManager::class);
    }

    private function looksLikeScheme(string $text): bool
    {
        $m = $this->manager();
        $method = new \ReflectionMethod($m, 'looksLikeScheme');
        $method->setAccessible(true);

        return $method->invoke($m, $text);
    }

    #[Test]
    public function it_recognises_a_dataset_registered_by_this_application()
    {
        // 'test_orders' declares aliases: orders, sales, revenue.
        $this->assertTrue($this->looksLikeScheme('orders'));
        $this->assertTrue($this->looksLikeScheme('sales'));
        $this->assertTrue($this->looksLikeScheme('what about orders'));
    }

    #[Test]
    public function it_does_not_recognise_vocabulary_from_some_other_project()
    {
        // The old hardcoded list. None of these are datasets here, so treating
        // them as such was wrong on every application but one.
        foreach (['basundhara', 'nrega', 'pmay', 'nfsa', 'rti', 'housing', 'waste'] as $foreign) {
            $this->assertFalse(
                $this->looksLikeScheme($foreign),
                "'{$foreign}' is not a dataset in this application and must not be treated as one"
            );
        }
    }

    #[Test]
    public function a_customer_name_is_not_mistaken_for_a_dataset()
    {
        // The whole point of the check: these are values to look up, not
        // datasets to switch to.
        $this->assertFalse($this->looksLikeScheme('Kalita Stores'));
        $this->assertFalse($this->looksLikeScheme('North'));
    }

    #[Test]
    public function a_dataset_name_inside_a_longer_word_does_not_count()
    {
        // Substring matching would fire on "reorder" for a dataset called
        // "order", which is how a value gets mistaken for a dataset.
        $this->assertFalse($this->looksLikeScheme('reordering'));
        $this->assertFalse($this->looksLikeScheme('salesforce integration'));
    }

    #[Test]
    public function it_handles_an_application_with_no_schemas_at_all()
    {
        config(['naturalquery.schema.config_path' => __DIR__ . '/../Stubs/does-not-exist']);
        $this->app->forgetInstance(\Jayanta\NaturalQuery\Schema\SchemaRegistry::class);

        $this->assertFalse($this->looksLikeScheme('anything'));
    }
}
