<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Engine\SchemaShortlister;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-001-v2, RED for G2.
 *
 * `SchemaShortlister::resolve(array $seedKeys): PromptScope` does not exist
 * yet, so every test below fails now with "class not found" — the correct
 * RED reason for a brand-new collaborator. What each test PINS, once the
 * class exists, is one of two things: a property the design names
 * explicitly (1, 2, 11), or one of the two named defects from the reverted
 * attempt (commit cc9b07b) that must not reappear:
 *
 *   defect 1 — seeds were normalised through registry->has() only, never
 *   registry->findByAlias(), so a model answering with an alias (the commit
 *   message's own example: "SL Warehouses") had that dataset silently
 *   dropped instead of resolved.
 *
 *   defect 2 — relatedDatasets() read tables.primary.relationships only and
 *   was blind to required_join, while hasLinkedSchemas() four hundred lines
 *   earlier already counts a required_join as a link ("a hand-written join
 *   counts too") — so the registry disagreed with itself.
 */
class SchemaShortlisterTest extends TestCase
{
    private function registry(string $path): SchemaRegistry
    {
        return new SchemaRegistry($path);
    }

    /**
     * Property 1: relevance beats filename order. zzz_orders sorts LAST of
     * the four fixture files (glob() is alphabetical), and aaa_customers /
     * bbb_regions sort BEFORE it and are unrelated. If scope were still
     * influenced by file order — the old budget loop "pops the last key off
     * a sorted glob" — either the seed itself could be dropped, or the
     * earlier, irrelevant files could be pulled in ahead of it.
     */
    #[Test]
    public function the_seeded_dataset_survives_regardless_of_its_position_in_a_sorted_glob()
    {
        $shortlister = new SchemaShortlister($this->registry(__DIR__ . '/../Stubs/shortlist-schemas'));

        $scope = $shortlister->resolve(['zzz_orders']);

        $this->assertContains('zzz_orders', $scope->keys());
        $this->assertNotContains('aaa_customers', $scope->keys());
        $this->assertNotContains('bbb_regions', $scope->keys());
    }

    /**
     * Property 2: no action at a distance. A registry that additionally
     * knows about an unrelated dataset (ccc_products, added to a SANDBOXED
     * copy of the fixture directory — never written into the checked-in
     * tests/Stubs tree, cleaned up in tearDown per I8) must resolve the
     * SAME seed to the SAME scope as the registry without it.
     */
    #[Test]
    public function adding_an_unrelated_dataset_does_not_change_the_scope_for_the_same_seed()
    {
        $baseline = (new SchemaShortlister($this->registry(__DIR__ . '/../Stubs/shortlist-schemas')))
            ->resolve(['zzz_orders']);

        $sandbox = sys_get_temp_dir() . '/nq-shortlist-extra-' . getmypid();
        if (!is_dir($sandbox)) {
            mkdir($sandbox, 0777, true);
        }

        try {
            foreach (glob(__DIR__ . '/../Stubs/shortlist-schemas/*.php') as $file) {
                copy($file, $sandbox . '/' . basename($file));
            }

            file_put_contents($sandbox . '/ccc_products.php', <<<'PHP'
<?php
return [
    'name' => 'Unrelated Products',
    'description' => 'Sorts between bbb_regions and zzz_customers, related to nothing',
    'aliases' => ['unrelated-products'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'ccc_products',
            'description' => 'Unrelated products',
            'group_column' => 'name',
            'columns' => [
                'name' => ['type' => 'varchar', 'description' => 'Name', 'groupable' => true],
            ],
            'relationships' => [],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
PHP
            );

            $withExtra = (new SchemaShortlister($this->registry($sandbox)))->resolve(['zzz_orders']);

            $baselineKeys = $baseline->keys();
            $withExtraKeys = $withExtra->keys();
            sort($baselineKeys);
            sort($withExtraKeys);

            $this->assertSame($baselineKeys, $withExtraKeys);
            $this->assertNotContains('ccc_products', $withExtraKeys);
        } finally {
            foreach (glob($sandbox . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($sandbox)) {
                rmdir($sandbox);
            }
        }
    }

    /** Defect 1 regression: an alias, not the exact key, must still resolve. */
    #[Test]
    public function a_seed_that_is_an_alias_rather_than_the_exact_key_still_resolves()
    {
        $shortlister = new SchemaShortlister($this->registry(__DIR__ . '/../Stubs/shortlist-schemas'));

        $scope = $shortlister->resolve(['SL Warehouses']);

        $this->assertContains(
            'zzz_orders',
            $scope->keys(),
            'a seed matching a dataset ALIAS, not its exact key, was dropped instead of resolved'
        );
    }

    /** A foreign key one hop away is pulled into scope, per R3's "seeds, then FK expansion, then done". */
    #[Test]
    public function a_foreign_key_one_hop_away_is_pulled_into_scope()
    {
        $shortlister = new SchemaShortlister($this->registry(__DIR__ . '/../Stubs/shortlist-schemas'));

        $scope = $shortlister->resolve(['zzz_orders']);

        $this->assertContains('zzz_customers', $scope->keys(), 'zzz_orders.customer_id points at zzz_customers');
    }

    /** Defect 2 regression: required_join is an edge too, not only relationships. */
    #[Test]
    public function a_required_join_target_with_no_relationships_entry_is_pulled_into_scope()
    {
        $shortlister = new SchemaShortlister($this->registry(__DIR__ . '/../Stubs/shortlist-join-schemas'));

        $scope = $shortlister->resolve(['jn_orders']);

        $this->assertContains(
            'jn_districts',
            $scope->keys(),
            'jn_orders has no relationships[] entry, only required_join naming jn_districts — '
                . 'hasLinkedSchemas() already treats that as a link, and expand() must agree'
        );
    }

    /**
     * Property 11: every seed fictional falls open to ALL datasets, with the
     * reason recorded — never an empty, unanswerable scope.
     */
    #[Test]
    public function every_seed_being_fictional_falls_open_to_the_full_schema()
    {
        $shortlister = new SchemaShortlister($this->registry(__DIR__ . '/../Stubs/shortlist-schemas'));
        $registry = $this->registry(__DIR__ . '/../Stubs/shortlist-schemas');

        $scope = $shortlister->resolve(['completely_invented_dataset_name']);

        $expected = $registry->keys();
        sort($expected);
        $actual = $scope->keys();
        sort($actual);

        $this->assertSame($expected, $actual, 'a wholly fictional seed must fall open to every dataset');
        $this->assertSame('all', $scope->source());
        $this->assertNotSame('', trim($scope->reason()), 'the fall-open reason must be recorded, not silent');
    }
}
