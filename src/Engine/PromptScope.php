<?php

namespace Jayanta\NaturalQuery\Engine;

/**
 * Prompt Scope
 *
 * Immutable record of which datasets a question's prompt was built from —
 * the output of `SchemaShortlister::resolve()` and the one value threaded
 * through prompt building (`PromptBuilder::buildMultiDatasetPrompt()`), the
 * size guard (`PromptBudget::check()`), the SQL security gate
 * (`QueryOrchestrator::validateAndExecute()`, R6) and the response's
 * `metadata.datasets_in_scope` / `metadata.datasets_omitted` fields.
 *
 * A plain value object on purpose: no registry, no config, no I/O. Nothing
 * downstream may compute a NEW scope from it — only read the one
 * SchemaShortlister already decided. `reason` is for logs and metadata only;
 * it is schema/config-derived text and never reaches a provider.
 */
final class PromptScope
{
    /** @var array<int, string> dataset keys in scope, registry order */
    private array $keys;

    /** @var array<int, string> dataset keys NOT rendered into the prompt */
    private array $omitted;

    /** @var array<int, string> physical primary-table names of the keys in scope */
    private array $scopedTables;

    /** 'seeded' (relevance-derived) or 'all' (fall-open — see SchemaShortlister) */
    private string $source;

    private string $reason;

    public function __construct(
        array $keys,
        array $omitted,
        array $scopedTables,
        string $source,
        string $reason
    ) {
        $this->keys = $keys;
        $this->omitted = $omitted;
        $this->scopedTables = $scopedTables;
        $this->source = $source;
        $this->reason = $reason;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return $this->keys;
    }

    /** @return array<int, string> */
    public function omitted(): array
    {
        return $this->omitted;
    }

    /** @return array<int, string> */
    public function scopedTables(): array
    {
        return $this->scopedTables;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function isDataset(string $key): bool
    {
        return in_array($key, $this->keys, true);
    }
}
