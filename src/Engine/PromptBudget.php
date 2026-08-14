<?php

namespace Jayanta\NaturalQuery\Engine;

/**
 * Prompt Budget
 *
 * The BOUND half of NQ-001-v2 (SchemaShortlister is the SCOPE half). A pure
 * function of a finished prompt string and the PromptScope it was built
 * from — no loop, no shrinking, no provider knowledge. It answers exactly
 * one question: does this prompt fit inside `prompts.max_chars`?
 *
 * R4: the bound may only REFUSE. There is no second, smaller attempt in
 * here or anywhere downstream of it — a refusal from `check()` is always
 * `_unretriable` at the call site, because the only retry strategy this
 * package has sends a SMALLER, single-dataset prompt, which is exactly the
 * wrong move for a refusal caused by size rather than by what the model
 * made of the question.
 */
class PromptBudget
{
    /** Null means unbounded — today's behaviour, byte for byte (C1). */
    protected ?int $maxChars;

    public function __construct(?int $maxChars)
    {
        $this->maxChars = $maxChars;
    }

    /**
     * @return string|null null when the prompt fits (or the budget is
     *         unbounded); otherwise a refusal message naming bytes needed,
     *         bytes allowed, and what a person can do about it.
     */
    public function check(string $prompt, PromptScope $scope): ?string
    {
        if ($this->maxChars === null) {
            return null;
        }

        $needed = strlen($prompt);
        if ($needed <= $this->maxChars) {
            return null;
        }

        $keys = $scope->keys();

        // Property 8: a single dataset alone over budget has nothing left to
        // scope down TO — say so plainly rather than offering a lever that
        // cannot help.
        if (count($keys) <= 1) {
            $only = $keys[0] ?? 'the requested dataset';

            return "This question needs {$needed} bytes of schema context for '{$only}' alone, "
                . "but naturalquery.prompts.max_chars allows only {$this->maxChars} — "
                . 'no scoping configuration can help; raise prompts.max_chars, or the provider\'s '
                . 'own context window, to answer this question.';
        }

        return "This question needs {$needed} bytes of schema context across " . count($keys)
            . ' datasets in scope (' . implode(', ', $keys) . "), but naturalquery.prompts.max_chars "
            . "allows only {$this->maxChars}. Narrow the question to fewer datasets, add a more "
            . 'specific naturalquery.query_routing rule, or raise prompts.max_chars.';
    }
}
