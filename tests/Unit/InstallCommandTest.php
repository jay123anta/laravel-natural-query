<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The installer's closing advice is the first thing anyone reads, and most of
 * them will copy it verbatim. It is documentation that happens to be code, so
 * Rule 1 applies to it as much as to the README.
 */
class InstallCommandTest extends TestCase
{
    private ?string $sandbox = null;

    /**
     * Run the installer somewhere disposable.
     *
     * It creates directories and writes the example schema for real, and the
     * suite's schema path points at tests/Stubs/schemas — so the first version
     * of this test dropped an example.php into the fixtures, which then made a
     * later case count three datasets where it expected two. A test that
     * changes the inputs of other tests is worse than no test.
     */
    private function install(): string
    {
        $this->sandbox = sys_get_temp_dir() . '/nq-install-' . getmypid();
        config(['naturalquery.schema.config_path' => $this->sandbox]);

        Artisan::call('naturalquery:install');

        return Artisan::output();
    }

    protected function tearDown(): void
    {
        if ($this->sandbox && is_dir($this->sandbox)) {
            foreach (glob($this->sandbox . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->sandbox);
        }

        parent::tearDown();
    }

    /**
     * A key name that does not match the config sends the new user to set an
     * environment variable nothing reads, and the failure that follows —
     * "API key is empty" — points at the variable they just set. The installer
     * said CLAUDE_API_KEY while the config read ANTHROPIC_API_KEY.
     */
    #[Test]
    public function every_api_key_it_names_is_one_the_config_actually_reads()
    {
        preg_match_all('/\b([A-Z][A-Z0-9_]*_API_KEY)\b/', $this->install(), $matches);

        $named = array_unique($matches[1]);
        $this->assertNotEmpty($named, 'no key names found — the installer text changed shape');

        $config = (string) file_get_contents(__DIR__ . '/../../config/naturalquery.php');

        foreach ($named as $envVar) {
            $this->assertStringContainsString(
                "env('{$envVar}')",
                $config,
                "the installer tells people to set {$envVar}, which config/naturalquery.php never reads"
            );
        }
    }

    /**
     * Running a model yourself must be presented as an ordinary choice.
     *
     * Step 1 used to name GEMINI_API_KEY and nothing else, which made one
     * hosted vendor the default for every new install — an accident of where
     * this package was extracted from, not a decision anyone made. Plenty of
     * adopters run Ollama or a self-hosted server, and some cannot send data
     * to a third party at all.
     */
    #[Test]
    public function it_offers_a_local_model_alongside_the_hosted_ones()
    {
        $output = $this->install();

        $this->assertStringContainsString('ollama', $output, 'no local option offered');
        $this->assertStringContainsString('no API key', $output, 'the keyless path is not mentioned');
        $this->assertStringContainsString('OpenAI-compatible', $output, 'self-hosted servers are not mentioned');
    }

    #[Test]
    public function it_points_at_doctor_for_whatever_goes_wrong_next()
    {
        $this->assertStringContainsString('naturalquery:doctor', $this->install());
    }
}
