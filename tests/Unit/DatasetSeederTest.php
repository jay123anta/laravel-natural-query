<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Engine\DatasetSeeder;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-001-v2, RED for G2.
 *
 * `Jayanta\NaturalQuery\Engine\DatasetSeeder` does not exist yet, so every
 * test here fails now with "class not found". What it will own once built:
 * `detect()` is `QueryOrchestrator::detectDatasetFromKeywords()` moved
 * verbatim (single best guess, existing behaviour); `seeds()` is new —
 * EVERY matching signal, not just the first — which is what makes property 3
 * (routing is supreme, every match counts) and property 13 (I10: no signal
 * outside the permitted list) testable independently of the rest of the
 * design.
 */
class DatasetSeederTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/shortlist-schemas');
    }

    private function seeder(): DatasetSeeder
    {
        return new DatasetSeeder($this->app->make(SchemaRegistry::class));
    }

    /** Property 3, part A: default_dataset seeds a question naming nothing at all. */
    #[Test]
    public function default_dataset_is_seeded_for_a_question_naming_nothing()
    {
        config([
            'naturalquery.query_routing' => [],
            'naturalquery.default_dataset' => 'bbb_regions',
        ]);

        $seeds = $this->seeder()->seeds('please help me with something');

        $this->assertContains('bbb_regions', $seeds);
    }

    /**
     * Property 3, part B: EVERY matching query_routing keyword puts its
     * target in scope — not merely the first, which is what detect() (the
     * verbatim-moved single-guess method) is allowed to do.
     */
    #[Test]
    public function every_matching_routing_keyword_seeds_its_own_target()
    {
        config([
            'naturalquery.query_routing' => [
                'gizmo' => 'aaa_customers',
                'widget' => 'zzz_orders',
            ],
            'naturalquery.default_dataset' => null,
        ]);

        $seeds = $this->seeder()->seeds('report on gizmo and widget sales');

        $this->assertContains('aaa_customers', $seeds, 'the "gizmo" routing rule was not honoured');
        $this->assertContains('zzz_orders', $seeds, 'the "widget" routing rule was not honoured');
    }

    /**
     * Contrast: detect() is the existing single-best-guess method (moved
     * verbatim so both call sites in QueryOrchestrator stay byte-identical).
     * On the SAME two-keyword question it returns only one dataset — the
     * whole reason seeds() had to be written new rather than reused.
     */
    #[Test]
    public function detect_returns_only_the_first_match_where_seeds_returns_all_of_them()
    {
        config([
            'naturalquery.query_routing' => [
                'gizmo' => 'aaa_customers',
                'widget' => 'zzz_orders',
            ],
        ]);

        $seeder = $this->seeder();

        $this->assertIsString($seeder->detect('report on gizmo and widget sales'));
        $this->assertCount(
            1,
            array_filter([$seeder->detect('report on gizmo and widget sales')]),
            'detect() is documented as a single best guess'
        );
        $this->assertGreaterThan(
            1,
            count($seeder->seeds('report on gizmo and widget sales')),
            'seeds() must return every matching rule, not collapse to one'
        );
    }

    /**
     * Property 13 / I10: with no routing rule and no alias match available,
     * two questions differing only in a domain noun the schema has never
     * heard of must produce the SAME seed set. A synonym table or a
     * column-name heuristic would make them differ; I10 forbids both.
     */
    #[Test]
    public function two_unmatched_domain_nouns_produce_the_same_seeds()
    {
        config([
            'naturalquery.query_routing' => [],
            'naturalquery.default_dataset' => null,
        ]);

        $seeder = $this->seeder();

        $walrus = $seeder->seeds('tell me about the walruses');
        $giraffe = $seeder->seeds('tell me about the giraffes');

        $this->assertSame($walrus, $giraffe);
    }
}
