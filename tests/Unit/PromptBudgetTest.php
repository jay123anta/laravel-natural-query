<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Engine\PromptBudget;
use Jayanta\NaturalQuery\Engine\PromptScope;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-001-v2, RED for G2 — properties 7 and 8.
 *
 * `Jayanta\NaturalQuery\Engine\PromptBudget` does not exist yet (nor does
 * `PromptScope`), so every test fails now with "class not found". Per the
 * design it is a PURE function of (prompt string, PromptScope) — no loop,
 * no shrinking, no provider knowledge: it only ever answers "does this fit"
 * (null) or "here is why not, and what would help" (a refusal string).
 */
class PromptBudgetTest extends TestCase
{
    /**
     * Property 7: the budget BOUNDS. Never assert merely "the prompt got
     * smaller" — assert the disjunction the design states explicitly:
     * either the prompt fits, or the caller was refused. Parameterised over
     * dataset counts standing in for 5, 24, 150 and 400 discovered tables —
     * PromptBudget itself does not know how many tables produced the
     * string, only how long it is, which is exactly why this disjunction
     * must hold at every size rather than just the ones spot-checked.
     */
    #[DataProvider('datasetCounts')]
    #[Test]
    public function the_prompt_either_fits_the_budget_or_the_call_is_refused(int $datasetCount)
    {
        $maxChars = 2000;

        // One dataset "costs" 100 bytes in this synthetic prompt — standing
        // in for a real buildMultiDatasetPrompt() section without needing a
        // live SchemaRegistry at 400 tables for a unit test of PromptBudget
        // alone.
        $prompt = str_repeat('x', $datasetCount * 100);
        $keys = array_map(fn ($i) => "dataset_{$i}", range(1, $datasetCount));

        $scope = new PromptScope(
            keys: $keys,
            omitted: [],
            scopedTables: $keys,
            source: 'seeded',
            reason: 'synthetic'
        );

        $budget = new PromptBudget($maxChars);
        $refusal = $budget->check($prompt, $scope);

        $fits = strlen($prompt) <= $maxChars;
        $refused = $refusal !== null;

        $this->assertTrue(
            $fits || $refused,
            "at {$datasetCount} datasets (" . strlen($prompt) . " bytes) the prompt neither fit nor was refused"
        );

        // The other half of the disjunction must also hold: a refusal is
        // only ever issued when it is genuinely needed.
        if ($fits) {
            $this->assertNull($refusal, "a {$datasetCount}-dataset prompt that fits was refused anyway");
        }
    }

    public static function datasetCounts(): array
    {
        return [
            '5 datasets, well under budget' => [5],
            '24 datasets, at the edge' => [24],
            '150 datasets, over budget' => [150],
            '400 datasets, far over budget' => [400],
        ];
    }

    /**
     * Property 8: floor refusal is actionable. A SINGLE dataset alone over
     * budget must refuse, naming that key, bytes needed, bytes allowed, and
     * — because there is nothing left to scope down to — a sentence stating
     * that no scoping configuration can help.
     */
    #[Test]
    public function a_single_oversized_dataset_refuses_by_name_with_no_scoping_lever_offered()
    {
        $prompt = str_repeat('x', 5000);
        $scope = new PromptScope(
            keys: ['solo_dataset'],
            omitted: [],
            scopedTables: ['solo_dataset'],
            source: 'seeded',
            reason: 'only dataset the question named'
        );

        $budget = new PromptBudget(100);
        $refusal = $budget->check($prompt, $scope);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('solo_dataset', $refusal);
        $this->assertStringContainsString('5000', $refusal, 'bytes needed must be stated');
        $this->assertStringContainsString('100', $refusal, 'bytes allowed must be stated');
        $this->assertStringContainsString(
            'no scoping configuration can help',
            $refusal,
            'a single-dataset floor has nothing left to scope down to, and must say so'
        );
    }

    /** Unbounded (max_chars null) never refuses, regardless of size — C1. */
    #[Test]
    public function a_null_budget_never_refuses()
    {
        $prompt = str_repeat('x', 1_000_000);
        $scope = new PromptScope(
            keys: ['a', 'b'],
            omitted: [],
            scopedTables: ['a', 'b'],
            source: 'all',
            reason: 'no seed matched'
        );

        $budget = new PromptBudget(null);

        $this->assertNull($budget->check($prompt, $scope));
    }
}
