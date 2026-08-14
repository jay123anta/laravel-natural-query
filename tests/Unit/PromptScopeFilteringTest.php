<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Engine\PromptBuilder;
use Jayanta\NaturalQuery\Engine\PromptScope;
use Jayanta\NaturalQuery\Feedback\FeedbackStore;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-001-v2, RED for G2 — properties 4 and 5.
 *
 * The PREVIOUS attempt at this feature (reverted in cc9b07b) asserted only
 * prompt SIZE and a "were not expanded" sentence, never whether the SURVIVING
 * datasets kept what makes them answerable. That is exactly how the old
 * budget loop shipped a demoted dataset that still LOOKED queryable but had
 * silently lost its required_filter, required_join, select_override and
 * computed metrics — an "orders" table with no cancelled-row exclusion left
 * in the prompt at all.
 *
 * These tests pin the fix at the one class the architect named as
 * responsible for it: `PromptBuilder::buildMultiDatasetPrompt(string, ?PromptScope)`.
 * `PromptScope` is hand-built here, deliberately bypassing `SchemaShortlister`,
 * so a failure can only mean PromptBuilder itself renders a dataset in
 * degraded form or fails to omit one — not that scope was computed wrong
 * upstream (that is `SchemaShortlisterTest`'s job).
 *
 * WHAT THIS TEST CATCHES: neither `PromptScope` nor the second parameter of
 * `buildMultiDatasetPrompt()` exist yet, so this fails now on a fatal
 * "class not found" / "too many arguments" error — the correct RED reason
 * for a capability that has not been built. Once it exists, these
 * assertions are what stop a future change from reintroducing the same
 * degrade-in-place defect under a different disguise.
 */
class PromptScopeFilteringTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/scope-prompt-schemas');
        $app['config']->set('naturalquery.query_routing', [
            'purchase' => 'ps_orders',
            'gadget' => 'ps_widgets',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->run();

        $this->app->make(FeedbackStore::class)->recordCorrection(
            'total revenue last month',
            'ps_orders',
            'SELECT SUM(revenue) FROM ps_orders',
            'Cancelled orders must be excluded from revenue',
            "SELECT SUM(revenue) FROM ps_orders WHERE status != 'cancelled'",
            'wrong_filter'
        );

        $this->app->make(FeedbackStore::class)->recordCorrection(
            'widget count last month',
            'ps_widgets',
            'SELECT COUNT(*) FROM ps_widgets_table',
            'Should have grouped by serial prefix',
            null,
            'other'
        );
    }

    /** ps_orders in scope with its FK target ps_districts; ps_widgets omitted. */
    private function inScopeOutOfScope(): PromptScope
    {
        return new PromptScope(
            keys: ['ps_orders', 'ps_districts'],
            omitted: ['ps_widgets'],
            scopedTables: ['ps_orders', 'ps_districts'],
            source: 'seeded',
            reason: 'seeded from the question, ps_districts pulled in by the required_join'
        );
    }

    #[Test]
    public function every_dataset_that_appears_keeps_its_required_filter_required_join_select_override_and_computed_metrics()
    {
        $registry = $this->app->make(SchemaRegistry::class);
        $scope = $this->inScopeOutOfScope();

        $prompt = $this->app->make(PromptBuilder::class)
            ->buildMultiDatasetPrompt('total revenue by district', $scope);

        // Property over the fixture, not one hand-picked field: whichever
        // in-scope dataset declares each feature, the prompt must carry it.
        foreach ($scope->keys() as $key) {
            $schema = $registry->get($key);
            $primary = $schema['tables']['primary'] ?? [];

            if (!empty($primary['required_filter'])) {
                $this->assertStringContainsString(
                    $primary['required_filter'],
                    $prompt,
                    "{$key} appears in the prompt but its required_filter is missing"
                );
            }

            if (!empty($primary['required_join'])) {
                $this->assertStringContainsString(
                    $primary['required_join'],
                    $prompt,
                    "{$key} appears in the prompt but its required_join is missing"
                );
            }

            if (!empty($primary['select_override'])) {
                $this->assertStringContainsString(
                    $primary['select_override'],
                    $prompt,
                    "{$key} appears in the prompt but its select_override is missing"
                );
            }

            foreach ($schema['computed_metrics'] ?? [] as $metric) {
                $this->assertStringContainsString(
                    $metric['expression'],
                    $prompt,
                    "{$key} appears in the prompt but a computed metric's SQL expression is missing"
                );
            }
        }

        // Sanity: the fixture actually exercises all four, or the loop above
        // proves nothing.
        $orders = $registry->get('ps_orders')['tables']['primary'];
        $this->assertNotEmpty($orders['required_filter']);
        $this->assertNotEmpty($orders['required_join']);
        $this->assertNotEmpty($orders['select_override']);
        $this->assertNotEmpty($registry->get('ps_orders')['computed_metrics']);
    }

    #[Test]
    public function an_omitted_dataset_is_totally_absent_from_every_scope_filtered_section()
    {
        $prompt = $this->app->make(PromptBuilder::class)
            ->buildMultiDatasetPrompt('total revenue by district', $this->inScopeOutOfScope());

        // buildAllSchemasFullInfo() / joinsFor(): neither the dataset key, its
        // physical table, nor its column may appear — including as an
        // out-of-scope neighbour's RELATED join target.
        $this->assertStringNotContainsString('ps_widgets_table', $prompt);
        $this->assertStringNotContainsString('ps_widget_serial', $prompt);

        // buildRoutingHints(): a routing rule pointing at an omitted dataset
        // must not be advertised — it names a table the model has no other
        // information about.
        $this->assertStringNotContainsString('ps_widgets', $prompt);

        // buildAllCorrections(): a past correction for an omitted dataset is
        // schema knowledge about a table that is not in the prompt at all.
        $this->assertStringNotContainsString('widget count last month', $prompt);
        $this->assertStringNotContainsString('Should have grouped by serial prefix', $prompt);

        // No "were not expanded" (or equivalent) sentence naming what was cut —
        // R2: omitted datasets are not mentioned to the model AT ALL, not even
        // to say they exist and were left out.
        $this->assertStringNotContainsStringIgnoringCase('not expanded', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('were omitted', $prompt);

        // Positive controls: the in-scope dataset's own routing hint and
        // correction ARE present, so the assertions above are proving
        // absence, not a broken prompt.
        $this->assertStringContainsString('ps_orders', $prompt);
        $this->assertStringContainsString('total revenue last month', $prompt);
        $this->assertStringContainsString('Cancelled orders must be excluded from revenue', $prompt);
    }

    // NOTE (honesty, not coverage): a third test asserting
    // `buildMultiDatasetPrompt($query, null)` is byte-identical to
    // `buildMultiDatasetPrompt($query)` (property 10 / C1) was written and
    // then removed from this RED submission. PHP silently ignores an extra
    // positional argument to today's one-parameter method, so that
    // assertion already passes now for a trivial reason — the argument is
    // discarded, not honoured — which is exactly the "measuring the wrong
    // thing" trap AGENTS.md warns about. It belongs back once
    // buildMultiDatasetPrompt() actually declares the second parameter.
}
