<?php

namespace Jayanta\NaturalQuery\Tests\Benchmark;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Schema\SchemaRegistry;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package against REAL questions from the Spider dev set.
 *
 * Everything measured so far used questions written here, against a schema
 * written here. That measures whether the package does what its author
 * expected, which is worth something and is not the same as accuracy. Spider
 * is the standard benchmark for this task: 200 databases, human-written
 * questions, human-written gold SQL, and nobody involved has seen this package.
 *
 * WHAT IS REAL: the schema (Spider's published concert_singer definition, four
 * tables with its real foreign keys), the 45 dev questions, and the gold SQL.
 * The package is given no curation of any kind — no system_instructions, no
 * aliases, no llm_instructions. Discovery runs and that is all it gets. This is
 * the hardest and most honest test available: an unfamiliar schema, phrased by
 * strangers.
 *
 * WHAT IS NOT REAL, and what that costs:
 *
 * Spider does not distribute its SQLite files through the channel available
 * here, so the tables are created from the published schema and filled with
 * synthetic rows. Execution accuracy compares result sets, and both queries run
 * against the same synthetic data, so the COMPARISON stays valid — but the
 * resulting number is NOT comparable to a published Spider score, which uses
 * the real data.
 *
 * Synthetic data also creates a specific way to cheat: if gold returns nothing,
 * a generated query returning nothing "matches" while having answered nothing.
 * Spider's own test-suite accuracy exists to close that hole. The mitigation
 * here is cruder and stated plainly: questions whose gold SQL returns no rows
 * on this data are EXCLUDED from the score rather than counted as passes.
 */
class SpiderSampleTest extends TestCase
{
    private string $schemaPath;

    #[Test]
    public function it_answers_real_spider_questions()
    {
        if (env('NATURALQUERY_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set NATURALQUERY_BENCHMARK=1 to run the benchmark (uses live API calls).');
        }

        if (!env('NATURALQUERY_BENCHMARK_KEY')) {
            $this->markTestSkipped('Set NATURALQUERY_BENCHMARK_KEY to the provider API key.');
        }

        $this->prepare();

        try {
            $results = $this->runQuestions();
        } finally {
            $this->cleanUp();
        }

        $this->report($results);

        $scored = array_values(array_filter($results, fn ($r) => !$r['skipped']));
        $correct = count(array_filter($scored, fn ($r) => $r['correct']));

        $this->assertNotEmpty($scored, 'no question produced a gold result to compare against');

        // Deliberately loose. This is an unfamiliar schema with no curation and
        // questions phrased by strangers; the number it prints is the point,
        // and the assertion only catches a collapse.
        $this->assertGreaterThan(
            0,
            $correct,
            'not one real Spider question was answered correctly'
        );
    }

    private function prepare(): void
    {
        $driver = env('NATURALQUERY_LLM_DRIVER', 'gemini');

        config([
            'naturalquery.llm.driver' => $driver,
            "naturalquery.llm.providers.{$driver}.api_key" => env('NATURALQUERY_BENCHMARK_KEY'),
            'naturalquery.cache.enabled' => false,
            'naturalquery.feedback.enabled' => false,
            // No curation whatsoever. Whatever discovery produces is what the
            // model is told, which is the situation on day one of an install.
            'naturalquery.system_instructions' => '',
        ]);

        if ($caBundle = env('NATURALQUERY_SSL_VERIFY')) {
            config(['naturalquery.ssl_verify' => $caBundle]);
        }

        $this->schemaPath = sys_get_temp_dir() . '/nq-spider-' . getmypid();

        if (!is_dir($this->schemaPath)) {
            mkdir($this->schemaPath, 0777, true);
        }

        config(['naturalquery.schema.config_path' => $this->schemaPath]);

        $this->buildSpiderDatabase();

        Artisan::call('naturalquery:discover', [
            '--output' => $this->schemaPath,
            '--no-verify' => true,
            '--force' => true,
        ]);

        $this->app->make(SchemaRegistry::class)->flush();
    }

    private function cleanUp(): void
    {
        foreach (glob(($this->schemaPath ?? '') . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        if (!empty($this->schemaPath) && is_dir($this->schemaPath)) {
            rmdir($this->schemaPath);
        }
    }

    /**
     * Spider's published concert_singer schema, with its real foreign keys.
     * Column names and casing are Spider's, not tidied — half the difficulty of
     * an unfamiliar schema is that it is not named the way you would name it.
     */
    private function buildSpiderDatabase(): void
    {
        DB::statement('CREATE TABLE stadium (
            Stadium_ID INTEGER PRIMARY KEY, Location TEXT, Name TEXT,
            Capacity INTEGER, Highest INTEGER, Lowest INTEGER, Average INTEGER)');

        DB::statement('CREATE TABLE singer (
            Singer_ID INTEGER PRIMARY KEY, Name TEXT, Country TEXT, Song_Name TEXT,
            Song_release_year TEXT, Age INTEGER, Is_male TEXT)');

        DB::statement('CREATE TABLE concert (
            concert_ID INTEGER PRIMARY KEY, concert_Name TEXT, Theme TEXT,
            Stadium_ID INTEGER, Year TEXT,
            FOREIGN KEY (Stadium_ID) REFERENCES stadium(Stadium_ID))');

        DB::statement('CREATE TABLE singer_in_concert (
            concert_ID INTEGER, Singer_ID INTEGER,
            FOREIGN KEY (concert_ID) REFERENCES concert(concert_ID),
            FOREIGN KEY (Singer_ID) REFERENCES singer(Singer_ID))');

        DB::table('stadium')->insert([
            ['Stadium_ID' => 1, 'Location' => 'Raith Rovers', 'Name' => 'Stark\'s Park', 'Capacity' => 10104, 'Highest' => 4812, 'Lowest' => 1294, 'Average' => 2106],
            ['Stadium_ID' => 2, 'Location' => 'Ayr United', 'Name' => 'Somerset Park', 'Capacity' => 11998, 'Highest' => 2363, 'Lowest' => 1057, 'Average' => 1477],
            ['Stadium_ID' => 3, 'Location' => 'East Fife', 'Name' => 'Bayview Stadium', 'Capacity' => 2000, 'Highest' => 1980, 'Lowest' => 533, 'Average' => 864],
            ['Stadium_ID' => 4, 'Location' => 'Queens Park', 'Name' => 'Hampden Park', 'Capacity' => 52500, 'Highest' => 1763, 'Lowest' => 466, 'Average' => 730],
            ['Stadium_ID' => 5, 'Location' => 'Stirling Albion', 'Name' => 'Forthbank Stadium', 'Capacity' => 3808, 'Highest' => 1125, 'Lowest' => 404, 'Average' => 642],
        ]);

        DB::table('singer')->insert([
            ['Singer_ID' => 1, 'Name' => 'Joe Sharp', 'Country' => 'Netherlands', 'Song_Name' => 'You', 'Song_release_year' => '1992', 'Age' => 52, 'Is_male' => 'F'],
            ['Singer_ID' => 2, 'Name' => 'Timbaland', 'Country' => 'United States', 'Song_Name' => 'Dangerous', 'Song_release_year' => '2008', 'Age' => 32, 'Is_male' => 'T'],
            ['Singer_ID' => 3, 'Name' => 'Justin Brown', 'Country' => 'France', 'Song_Name' => 'Hey Oh', 'Song_release_year' => '2013', 'Age' => 29, 'Is_male' => 'T'],
            ['Singer_ID' => 4, 'Name' => 'Rose White', 'Country' => 'France', 'Song_Name' => 'Sun', 'Song_release_year' => '2003', 'Age' => 41, 'Is_male' => 'F'],
            ['Singer_ID' => 5, 'Name' => 'John Nizinik', 'Country' => 'France', 'Song_Name' => 'Gentleman', 'Song_release_year' => '2014', 'Age' => 43, 'Is_male' => 'T'],
            ['Singer_ID' => 6, 'Name' => 'Tribal King', 'Country' => 'France', 'Song_Name' => 'Love', 'Song_release_year' => '2016', 'Age' => 25, 'Is_male' => 'T'],
        ]);

        DB::table('concert')->insert([
            ['concert_ID' => 1, 'concert_Name' => 'Audition Anthem', 'Theme' => 'Free choice', 'Stadium_ID' => 1, 'Year' => '2014'],
            ['concert_ID' => 2, 'concert_Name' => 'Super bootcamp', 'Theme' => 'Free choice 2', 'Stadium_ID' => 2, 'Year' => '2014'],
            ['concert_ID' => 3, 'concert_Name' => 'Home Visits', 'Theme' => 'Bleeding Love', 'Stadium_ID' => 2, 'Year' => '2015'],
            ['concert_ID' => 4, 'concert_Name' => 'Week 1', 'Theme' => 'Wide Awake', 'Stadium_ID' => 10, 'Year' => '2014'],
            ['concert_ID' => 5, 'concert_Name' => 'Week 1', 'Theme' => 'Happy Tonight', 'Stadium_ID' => 9, 'Year' => '2015'],
            ['concert_ID' => 6, 'concert_Name' => 'Week 2', 'Theme' => 'Party All Night', 'Stadium_ID' => 7, 'Year' => '2015'],
        ]);

        DB::table('singer_in_concert')->insert([
            ['concert_ID' => 1, 'Singer_ID' => 2], ['concert_ID' => 1, 'Singer_ID' => 3],
            ['concert_ID' => 1, 'Singer_ID' => 5], ['concert_ID' => 2, 'Singer_ID' => 3],
            ['concert_ID' => 2, 'Singer_ID' => 6], ['concert_ID' => 3, 'Singer_ID' => 5],
            ['concert_ID' => 4, 'Singer_ID' => 4], ['concert_ID' => 5, 'Singer_ID' => 6],
            ['concert_ID' => 5, 'Singer_ID' => 3], ['concert_ID' => 6, 'Singer_ID' => 1],
        ]);
    }

    /** @return array<int, array> */
    private function runQuestions(): array
    {
        $all = require __DIR__ . '/spider/concert_singer_questions.php';

        // Bounded because each question is a live API call on a free tier. The
        // cap is reported, never silent.
        $limit = (int) (env('NATURALQUERY_BENCHMARK_LIMIT') ?: 20);
        $questions = array_slice($all, 0, $limit);

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $results = [];

        foreach ($questions as $i => $case) {
            if ($i > 0) {
                sleep(5);
            }

            $results[] = $this->score($orchestrator, $case) + ['total_available' => count($all)];
        }

        return $results;
    }

    private function score(QueryOrchestrator $orchestrator, array $case): array
    {
        try {
            $goldRows = DB::select($case['gold']);
        } catch (\Throwable $e) {
            return $case + ['correct' => false, 'skipped' => true, 'note' => 'gold SQL failed here', 'mode' => '-'];
        }

        // Both-empty would "match" while answering nothing. Excluded, not passed.
        if (empty($goldRows)) {
            return $case + ['correct' => false, 'skipped' => true, 'note' => 'gold empty on synthetic data', 'mode' => '-'];
        }

        $gold = $this->normalize($goldRows);

        try {
            $response = $orchestrator->query($case['question']);
        } catch (\Throwable $e) {
            return $case + ['correct' => false, 'skipped' => false, 'note' => 'exception', 'mode' => '-'];
        }

        $mode = $response['metadata']['query_mode_used'] ?? '-';

        if (($response['status'] ?? '') !== 'success') {
            return $case + [
                'correct' => false,
                'skipped' => false,
                'mode' => $mode,
                'note' => substr((string) ($response['error'] ?? $response['message'] ?? 'no answer'), 0, 48),
            ];
        }

        $actual = $this->normalize($response['rows'] ?? []);

        return $case + [
            'correct' => $actual === $gold,
            'skipped' => false,
            'mode' => $mode,
            'note' => $actual === $gold ? '' : 'got ' . $this->preview($actual) . ' want ' . $this->preview($gold),
        ];
    }

    /** Values only, order-insensitive: aliases and column order do not matter. */
    private function normalize(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $values = [];

            foreach ((array) $row as $value) {
                if ($value === null) {
                    continue;
                }
                $values[] = is_numeric($value)
                    ? (string) round((float) $value, 2)
                    : strtolower(trim((string) $value));
            }

            sort($values);
            $out[] = implode('|', $values);
        }

        sort($out);

        return $out;
    }

    private function preview(array $n): string
    {
        return '[' . implode(' ; ', array_slice($n, 0, 2)) . (count($n) > 2 ? ' …' : '') . ']';
    }

    private function report(array $results): void
    {
        $lines = ["\n  SPIDER dev — concert_singer — no curation, synthetic data\n"];

        foreach ($results as $r) {
            $lines[] = sprintf(
                '  %-5s %-52s %-15s %s',
                $r['skipped'] ? 'skip' : ($r['correct'] ? ' ok ' : 'FAIL'),
                substr($r['question'], 0, 52),
                $r['mode'],
                $r['note']
            );
        }

        $scored = array_values(array_filter($results, fn ($r) => !$r['skipped']));
        $correct = count(array_filter($scored, fn ($r) => $r['correct']));
        $skipped = count($results) - count($scored);

        $lines[] = '';
        $lines[] = sprintf(
            "  asked %d of %d available; %d excluded (gold empty on synthetic data)",
            count($results),
            $results[0]['total_available'] ?? count($results),
            $skipped
        );
        $lines[] = sprintf(
            "  SCORED  %d/%d (%.0f%%)\n",
            $correct,
            count($scored),
            count($scored) ? 100 * $correct / count($scored) : 0
        );

        fwrite(STDERR, implode("\n", $lines) . "\n");
    }
}
