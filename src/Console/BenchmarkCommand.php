<?php

namespace Jayanta\NaturalQuery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Jayanta\NaturalQuery\Benchmark\ResultComparator;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;

/**
 * How accurate is this package on YOUR database?
 *
 * The package quotes its own figure — roughly one question in five wrong on an
 * uncurated schema, near-perfect once curated — and nobody should take that on
 * trust for their own data. This grades the same way that figure was produced:
 * run the generated query and a reference query you wrote by hand against the
 * same database, and compare the RESULT SETS. A query is right when its answer
 * is right, however differently it is written.
 *
 * Its real use is the before-and-after. Run it, run `naturalquery:audit-schema`,
 * write the descriptions it asks for, run this again. The difference is what
 * curation bought you, in a number you produced yourself.
 *
 * This DOES call the provider — one or more calls per question — so it costs
 * real money and is never run implicitly.
 */
class BenchmarkCommand extends Command
{
    protected $signature = 'naturalquery:benchmark
                            {--file= : PHP file returning the question set (default: config/naturalquery-benchmark.php)}
                            {--dataset= : Only questions tagged with this dataset}
                            {--min= : Exit non-zero below this percentage, for CI}
                            {--json : Machine-readable output}';

    protected $description = 'Measure answer accuracy on your own schema against reference SQL you supply';

    public function handle(QueryOrchestrator $orchestrator, ResultComparator $comparator): int
    {
        $questions = $this->loadQuestions();

        if ($questions === null) {
            return self::FAILURE;
        }

        if ($dataset = $this->option('dataset')) {
            $questions = array_values(array_filter(
                $questions,
                fn ($q) => ($q['dataset'] ?? null) === $dataset
            ));
        }

        if (!$questions) {
            $this->warn('No questions to run.');

            return self::SUCCESS;
        }

        $count = count($questions);

        // Never bill someone by surprise. --no-interaction skips the prompt,
        // which is what CI passes.
        if (!$this->confirm("Run {$count} question(s)? This calls your AI provider and costs money.", true)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $results = [];

        foreach ($questions as $i => $q) {
            $results[] = $this->runQuestion($orchestrator, $comparator, $q, $i + 1, $count);
        }

        return $this->option('json')
            ? $this->emitJson($results)
            : $this->emitReport($results);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function loadQuestions(): ?array
    {
        $path = $this->option('file')
            ?: (function_exists('config_path') ? config_path('naturalquery-benchmark.php') : null);

        if (!$path || !is_file($path)) {
            $this->error('No question set found' . ($path ? " at {$path}" : '') . '.');
            $this->newLine();
            $this->line('  Create one: a PHP file returning an array of questions with the SQL you');
            $this->line('  would have written by hand. There is a commented example in the package at');
            $this->line('  <fg=cyan>stubs/benchmark-example.php</>.');
            $this->newLine();
            $this->line('  <fg=gray>Write the reference SQL from the QUESTION, never from what the</>');
            $this->line('  <fg=gray>package produced — otherwise you are marking its own homework.</>');

            return null;
        }

        $questions = require $path;

        if (!is_array($questions) || !$questions) {
            $this->error("{$path} did not return a non-empty array of questions.");

            return null;
        }

        foreach ($questions as $i => $q) {
            if (empty($q['question']) || empty($q['gold'])) {
                $this->error('Question #' . ($i + 1) . " needs both 'question' and 'gold'.");

                return null;
            }

            // The reference SQL runs unvalidated against the adopter's own
            // database. It is their SQL, but a benchmark file is the kind of
            // thing that gets copied between projects, and a stray UPDATE in
            // one would be executed without a word. Reads only.
            if (!preg_match('/^\s*(SELECT|WITH)\b/i', (string) $q['gold'])) {
                $this->error('Question #' . ($i + 1) . "'s reference SQL is not a SELECT. Refusing to run it.");

                return null;
            }
        }

        return array_values($questions);
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    private function runQuestion(QueryOrchestrator $orchestrator, ResultComparator $comparator, array $q, int $n, int $of): array
    {
        $question = (string) $q['question'];
        $ordered = (bool) ($q['ordered'] ?? false);

        $quiet = (bool) $this->option('json');
        $tick = function (string $verdict) use ($quiet) {
            if (!$quiet) {
                $this->line($verdict);
            }
        };

        if (!$quiet) {
            $this->output->write(sprintf('  [%d/%d] %s … ', $n, $of, mb_strimwidth($question, 0, 48, '…')));
        }

        $row = [
            'question' => $question,
            'hardness' => $q['hardness'] ?? null,
            'status' => 'failed',
            'reason' => null,
        ];

        try {
            $expected = DB::select((string) $q['gold']);
        } catch (\Throwable $e) {
            $tick('<fg=yellow>reference SQL failed</>');

            return array_merge($row, ['reason' => 'the reference SQL did not run: ' . $e->getMessage()]);
        }

        try {
            $answer = $orchestrator->query($question, $q['dataset'] ?? null);
        } catch (\Throwable $e) {
            $tick('<fg=red>error</>');

            return array_merge($row, ['reason' => 'the engine threw: ' . $e->getMessage()]);
        }

        if (($answer['status'] ?? null) !== 'success') {
            $tick('<fg=red>no answer</>');

            return array_merge($row, ['reason' => $answer['error'] ?? 'no answer', 'error_code' => $answer['error_code'] ?? null]);
        }

        if ($comparator->matches($expected, $answer['rows'] ?? [], $ordered)) {
            $tick('<fg=green>correct</>');

            return array_merge($row, ['status' => 'correct', 'understood_as' => $answer['parsed_summary'] ?? null]);
        }

        $tick('<fg=red>wrong</>');

        return array_merge($row, [
            'reason' => 'different result',
            'expected' => $comparator->preview($comparator->normalize($expected, $ordered)),
            'got' => $comparator->preview($comparator->normalize($answer['rows'] ?? [], $ordered)),
            // The most useful thing on a wrong answer: what it thought it was
            // being asked. Nine times in ten the misreading is right there.
            'understood_as' => $answer['parsed_summary'] ?? null,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function emitJson(array $results): int
    {
        $correct = count(array_filter($results, fn ($r) => $r['status'] === 'correct'));

        $this->line((string) json_encode([
            'total' => count($results),
            'correct' => $correct,
            'accuracy' => count($results) ? round($correct / count($results) * 100, 1) : 0.0,
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $this->exitCode($results);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function emitReport(array $results): int
    {
        $total = count($results);
        $correct = count(array_filter($results, fn ($r) => $r['status'] === 'correct'));
        $wrong = array_filter($results, fn ($r) => $r['status'] !== 'correct');

        $this->newLine();

        if ($wrong) {
            $this->line('  <options=bold>Not correct</>');
            $this->newLine();

            foreach ($wrong as $r) {
                $this->line("    <fg=red>✗</> {$r['question']}");
                $this->line("      <fg=gray>{$r['reason']}</>");

                if (!empty($r['understood_as'])) {
                    $this->line("      <fg=gray>read as: {$r['understood_as']}</>");
                }

                if (isset($r['expected'])) {
                    $this->line("      <fg=gray>expected {$r['expected']}</>");
                    $this->line("      <fg=gray>got      {$r['got']}</>");
                }

                $this->newLine();
            }
        }

        $pct = $total ? round($correct / $total * 100, 1) : 0.0;
        $this->line("  <options=bold>{$correct}/{$total} correct ({$pct}%)</>");

        // By hardness, when the set says so. A package that answers every easy
        // question and no hard one is a different proposition from one that is
        // uniformly patchy, and the average hides which you have.
        $tiers = array_filter(array_unique(array_column($results, 'hardness')));

        if ($tiers) {
            sort($tiers);

            foreach ($tiers as $tier) {
                $inTier = array_filter($results, fn ($r) => ($r['hardness'] ?? null) === $tier);
                $ok = count(array_filter($inTier, fn ($r) => $r['status'] === 'correct'));
                $this->line(sprintf('    %-8s %d/%d', $tier, $ok, count($inTier)));
            }
        }

        if ($wrong) {
            $this->newLine();
            $this->line('  <fg=gray>Run `php artisan naturalquery:audit-schema` — most wrong answers are a</>');
            $this->line('  <fg=gray>missing description or an ambiguous term, not a model failure. Fix</>');
            $this->line('  <fg=gray>those, run this again, and compare.</>');
        }

        $this->newLine();

        return $this->exitCode($results);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function exitCode(array $results): int
    {
        $min = $this->option('min');

        if ($min === null) {
            return self::SUCCESS;
        }

        $total = count($results);
        $correct = count(array_filter($results, fn ($r) => $r['status'] === 'correct'));
        $pct = $total ? $correct / $total * 100 : 0.0;

        return $pct + 1e-9 >= (float) $min ? self::SUCCESS : self::FAILURE;
    }
}
