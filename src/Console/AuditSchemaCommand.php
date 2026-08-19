<?php

namespace Jayanta\NaturalQuery\Console;

use Illuminate\Console\Command;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;

/**
 * What the model cannot work out for itself, and you can.
 *
 * Roughly one question in five is misread on an UNCURATED schema, and the same
 * questions land near-perfectly once a handful of sentences are written. The
 * gap between those two numbers is the whole product — and until now an
 * adopter had no way to see which sentences were missing. "It gets things
 * wrong sometimes" is not actionable. "These four columns have no description
 * and two of them could both be revenue" is.
 *
 * Everything reported here is something introspection genuinely cannot
 * recover: a column called `amount` in two tables is identical in name, type
 * and nullability, and only you know which one the business calls revenue.
 * `naturalquery:discover` writes the structure; this says what to add on top.
 *
 * Read-only. Contacts nothing.
 */
class AuditSchemaCommand extends Command
{
    protected $signature = 'naturalquery:audit-schema
                            {--dataset= : Audit a single dataset}
                            {--json : Machine-readable output}';

    protected $description = 'Find the schema descriptions the AI needs and does not have';

    /**
     * Table names that answer no business question.
     *
     * Every one of these in the registry is noise in the prompt: more tables
     * for the model to pick a wrong column from, and on a large schema, real
     * pressure against prompts.max_chars.
     */
    private const INFRASTRUCTURE = [
        'migrations', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache',
        'cache_locks', 'password_resets', 'password_reset_tokens', 'personal_access_tokens',
        'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring',
        'notifications', 'audit_log', 'audit_logs', 'activity_log', 'settings',
    ];

    /** @var array<int, array{dataset: string, kind: string, detail: string, fix: string}> */
    private array $findings = [];

    public function handle(SchemaRegistry $registry): int
    {
        $only = $this->option('dataset');
        $datasets = $registry->all();

        if ($only !== null) {
            if (!isset($datasets[$only])) {
                $this->error("No such dataset: {$only}");

                return self::FAILURE;
            }

            $datasets = [$only => $datasets[$only]];
        }

        if (!$datasets) {
            $this->warn('No schemas registered. Run `php artisan naturalquery:discover` first.');

            return self::SUCCESS;
        }

        foreach ($datasets as $key => $schema) {
            $this->auditDataset((string) $key, $schema);
        }

        // Only meaningful across the whole registry, so it is skipped when the
        // audit is narrowed to one dataset.
        if ($only === null) {
            $this->auditCompetingMeasures($datasets);
        }

        return $this->option('json') ? $this->emitJson() : $this->emitReport(count($datasets));
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function auditDataset(string $key, array $schema): void
    {
        $table = (string) ($schema['tables']['primary']['name'] ?? '');

        // The shipped template names a placeholder table. Auditing it produces
        // findings about a file that queries nothing.
        if (SchemaRegistry::isUntouchedTemplate(['tables' => ['primary' => ['name' => $table]]])) {
            return;
        }

        if ($this->isInfrastructure($table)) {
            $this->add($key, 'infrastructure', "`{$table}` looks like an infrastructure table",
                'It answers no business question, and every table in the registry is another set of '
                . 'columns the model can pick a wrong one from. Delete its schema file, and add it to '
                . 'naturalquery.schema.discover_exclude so the next discover run does not recreate it.');
        }

        if (trim((string) ($schema['description'] ?? '')) === '') {
            $this->add($key, 'dataset-description', 'The dataset has no description',
                'One sentence saying what a row IS — "one row per despatched order line" — stops the '
                . 'model inferring it from the table name.');
        }

        if (empty($schema['aliases'])) {
            $this->add($key, 'aliases', 'No aliases',
                "Users will not type \"{$key}\". Add the words they actually say — sales, orders, "
                . 'invoices — so a question routes here without an API call.');
        }

        $columns = $schema['tables']['primary']['columns'] ?? [];
        $undescribed = [];
        $unitless = [];

        foreach ($columns as $name => $column) {
            if (trim((string) ($column['description'] ?? '')) === '') {
                $undescribed[] = (string) $name;
            }

            // A number with no unit is rendered bare, so 1500 could be rupees,
            // kilograms or a count, and nothing in the answer says which.
            if (!empty($column['aggregatable']) && trim((string) ($column['unit'] ?? '')) === '') {
                $unitless[] = (string) $name;
            }
        }

        if ($undescribed) {
            $this->add($key, 'column-descriptions',
                count($undescribed) . ' of ' . count($columns) . ' columns have no description: '
                    . implode(', ', array_slice($undescribed, 0, 8))
                    . (count($undescribed) > 8 ? ' …' : ''),
                'The model sees only the name. A column called `status_cd` or `amt2` is a guess; a '
                . 'described one is not.');
        }

        // A rule the schema cannot imply, and the only kind of gap here whose
        // absence produces a WRONG NUMBER rather than a vague answer.
        //
        // A `status` column holding "cancelled" is the classic case: nothing in
        // the type, the name or the foreign keys says whether those rows count
        // towards a total, so the model includes them, and the total is wrong
        // in a way nobody notices. Only a person knows. This cannot detect the
        // rule — it detects the SHAPE of a column that usually needs one.
        $noRule = trim((string) ($schema['tables']['primary']['required_filter'] ?? '')) === '';

        foreach ($noRule ? $columns : [] as $name => $column) {
            $excluding = $this->exclusionValues($column);

            if (!$excluding) {
                continue;
            }

            $this->add($key, 'required-filter',
                "`{$name}` holds " . implode('/', $excluding) . ' and this dataset has no required_filter',
                'Should those rows count towards totals? If not, say so once and every query obeys: '
                . "'required_filter' => \"{$name} != '{$excluding[0]}'\" in the table block. Intent mode "
                . 'appends it to the SQL; SQL generation refuses an answer that omits it. Without a rule '
                . 'the model decides, and a total that quietly includes cancelled rows looks exactly like '
                . 'a correct one.');

            break; // One prompt per dataset is enough; the point is made.
        }

        if ($unitless) {
            $this->add($key, 'units', 'Measures with no unit: ' . implode(', ', $unitless),
                'Answers render the number bare, so 1500 could be currency, kilograms or a count. '
                . "Add 'unit' => '₹' or 'kg' and the answer says so.");
        }
    }

    /**
     * Words that would route to more than one dataset.
     *
     * This is the ambiguity introspection can never resolve, and the one that
     * produces a plausible wrong number rather than an error: ask for
     * "revenue" when two datasets both offer it and the model picks, silently.
     *
     * @param  array<string, mixed>  $datasets
     */
    private function auditCompetingMeasures(array $datasets): void
    {
        $claims = [];

        foreach ($datasets as $key => $schema) {
            $table = (string) ($schema['tables']['primary']['name'] ?? '');

            if (SchemaRegistry::isUntouchedTemplate(['tables' => ['primary' => ['name' => $table]]])) {
                continue;
            }

            foreach ($schema['tables']['primary']['columns'] ?? [] as $name => $column) {
                if (empty($column['aggregatable'])) {
                    continue;
                }

                // The column's own name and every word a user might use for
                // it: those are the terms this dataset lays claim to.
                foreach (array_merge([(string) $name], (array) ($column['aliases'] ?? [])) as $term) {
                    $claims[strtolower(trim((string) $term))][$key] = "{$key}.{$name}";
                }
            }
        }

        $settled = trim((string) config('naturalquery.system_instructions', '')) !== '';

        foreach ($claims as $term => $owners) {
            if (count($owners) < 2) {
                continue;
            }

            $this->add('(across datasets)', 'competing-measure',
                "\"{$term}\" could mean any of: " . implode(', ', $owners),
                $settled
                    ? 'system_instructions is set — check it names this term explicitly, because the '
                    . 'model chooses whenever it does not.'
                    : 'Nothing says which is authoritative, so the model picks and a wrong pick returns '
                    . 'a plausible number for a different question. Write it in '
                    . "'system_instructions': \"{$term} means SUM(" . reset($owners) . '), never the '
                    . 'others." This is the single highest-value sentence you can add.');
        }
    }

    /**
     * Values in a column that usually mean "do not count this row".
     *
     * Read from the schema file's own `values` list, not guessed from the
     * database — this command contacts nothing and reads no data, and a column
     * whose permitted values someone has already written down is the only
     * place this can be known from structure alone.
     *
     * @param  array<string, mixed>  $column
     * @return array<int, string>
     */
    private function exclusionValues(array $column): array
    {
        static $suspect = [
            'cancelled', 'canceled', 'void', 'voided', 'deleted', 'draft',
            'refunded', 'reversed', 'rejected', 'archived', 'test',
        ];

        $found = [];

        foreach ((array) ($column['values'] ?? []) as $value) {
            if (in_array(strtolower(trim((string) $value)), $suspect, true)) {
                $found[] = (string) $value;
            }
        }

        return $found;
    }

    private function isInfrastructure(string $table): bool
    {
        $bare = strtolower((string) preg_replace('/^.*\./', '', $table));

        return in_array($bare, self::INFRASTRUCTURE, true);
    }

    private function add(string $dataset, string $kind, string $detail, string $fix): void
    {
        $this->findings[] = compact('dataset', 'kind', 'detail', 'fix');
    }

    private function emitJson(): int
    {
        $this->line((string) json_encode([
            'findings' => $this->findings,
            'total' => count($this->findings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function emitReport(int $datasetCount): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>NaturalQuery Schema Audit</>');
        $this->line("  <fg=gray>{$datasetCount} dataset(s). Nothing was sent anywhere.</>");
        $this->newLine();

        if (!$this->findings) {
            $this->line('  <fg=green;options=bold>Nothing to add.</> Every column is described, every '
                . 'dataset is named, and no term is ambiguous.');
            $this->newLine();

            return self::SUCCESS;
        }

        $byDataset = [];

        foreach ($this->findings as $f) {
            $byDataset[$f['dataset']][] = $f;
        }

        foreach ($byDataset as $dataset => $items) {
            $this->line("  <options=bold>{$dataset}</>");

            foreach ($items as $item) {
                $this->line("    <fg=yellow>!</> {$item['detail']}");
                $this->line("      <fg=gray>{$item['fix']}</>");
            }

            $this->newLine();
        }

        $competing = count(array_filter($this->findings, fn ($f) => $f['kind'] === 'competing-measure'));

        $this->line('  ' . count($this->findings) . ' thing(s) the model currently has to guess.');

        if ($competing > 0) {
            $this->line('  <fg=yellow>Start with the ambiguous terms</> — those are the ones that return a '
                . 'wrong number rather than a poor one.');
        }

        $this->newLine();

        // Exit 0 deliberately: an unaudited schema is not a broken install,
        // and a non-zero code here would fail CI for every adopter who has not
        // finished writing descriptions.
        return self::SUCCESS;
    }
}
