<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Cache\TwoTierQueryCache;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

/**
 * The fuzzy tier decides on the DIFFERENCE, not the similarity.
 *
 * The score it used to threshold was anti-correlated with safety. Measured on
 * the shipped scorer, at the 0.85 the package actually shipped:
 *
 *   grade a / grade b, stated precisely       0.883   REUSED  <- wrong answer
 *   genuine typo, "summry" / "summary"        0.818   missed  <- its whole job
 *   genuine paraphrase                        0.447   missed
 *
 * So at its own default the tier served the one pair that must never match and
 * refused both pairs it exists for. No threshold separates those sets, because
 * the dangerous pair scores HIGHER: normalizeQuery() has already folded away
 * everything two questions can innocently differ by, so the score is dominated
 * by the tokens they SHARE and climbs as a question gets longer and more
 * specific.
 *
 * These pairs are written already normalised -  lowercased, filler dropped,
 * de-duplicated, sorted -  which is the form the gate actually sees.
 */
class TheFuzzyTierMatchesOnlyTyposTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function pairs(): array
    {
        return [
            // ---- must never match: one token carries the whole meaning ----

            // Single letters are VALUES in real schemas - grade A, block B,
            // zone 1 - and "a" to "b" is one edit, the same distance as a typo.
            'a value swapped, short question' => ['a for grade revenue', 'b for grade revenue', false],

            // The case that made the old scorer dangerous: same swap, stated
            // precisely, scored 0.883 and was reused.
            'a value swapped, precise question' => [
                'a by for grade in orders pending region revenue total',
                'b by for grade in orders pending region revenue total',
                false,
            ],

            'opposite ranking' => ['10 customers top', '10 bottom customers', false],
            'a different month' => ['last month revenue', 'month revenue this', false],
            'two months one edit apart' => ['in july revenue', 'in june revenue', false],
            'two regions' => ['east for revenue', 'for revenue west', false],

            // Digits are never typos. This is also what keeps the tier from
            // undoing the dated-question guard by the back door.
            'a different year' => ['2025 in revenue', '2026 in revenue', false],

            // An extra token is a different question, not a misspelling.
            'an extra token' => ['by region revenue', 'by region revenue total', false],

            // Two unrelated words in the same slot. Indistinguishable from a
            // value swap, so it has to go - see the cost recorded in
            // FuzzyCacheDatasetIsolationTest.
            'a paraphrase' => ['channel orders revenue summary', 'channel orders overview revenue', false],

            // Nothing differs at all: that is an exact hit, not this tier.
            'identical' => ['by region revenue', 'by region revenue', false],

            // Two typos compound rather than cancel.
            'two tokens misspelled' => ['by custmers revenue totl', 'by customers revenue total', false],

            // ---- must match: the only thing this tier is for ----

            'one letter dropped' => ['by custmers revenue total', 'by customers revenue total', true],
            'one letter dropped, single token' => ['custmers', 'customers', true],
            'a plural' => ['by region sales', 'by regions sales', true],
            'adjacent letters transposed' => ['by orders pendign revenue', 'by orders pending revenue', true],
        ];
    }

    private function gate(): ReflectionMethod
    {
        $method = new ReflectionMethod(TwoTierQueryCache::class, 'differsOnlyByATypo');
        $method->setAccessible(true);

        return $method;
    }

    #[DataProvider('pairs')]
    #[Test]
    public function only_a_misspelling_counts_as_the_same_question(string $a, string $b, bool $expected)
    {
        $cache = (new ReflectionClass(TwoTierQueryCache::class))->newInstanceWithoutConstructor();

        $this->assertSame(
            $expected,
            $this->gate()->invoke($cache, $a, $b),
            $expected
                ? "a genuine misspelling was refused, which is the only thing this tier is for:\n  {$a}\n  {$b}"
                : "two different questions were treated as the same one:\n  {$a}\n  {$b}"
        );
    }

    /** The gate must not care which question was asked first. */
    #[DataProvider('pairs')]
    #[Test]
    public function the_verdict_is_the_same_in_both_directions(string $a, string $b, bool $expected)
    {
        $cache = (new ReflectionClass(TwoTierQueryCache::class))->newInstanceWithoutConstructor();

        $this->assertSame(
            $this->gate()->invoke($cache, $a, $b),
            $this->gate()->invoke($cache, $b, $a),
            "the gate answered differently depending on argument order:\n  {$a}\n  {$b}"
        );
    }
}
