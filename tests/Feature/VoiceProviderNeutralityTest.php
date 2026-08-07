<?php

namespace Jayanta\NaturalQuery\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Contracts\TranscriberInterface;
use Jayanta\NaturalQuery\Engine\ErrorCode;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Tests\Support\RecordingProvider;
use Jayanta\NaturalQuery\Tests\TestCase;
use Jayanta\NaturalQuery\Transcription\NullTranscriber;
use Jayanta\NaturalQuery\Transcription\OpenAiCompatibleTranscriber;
use Jayanta\NaturalQuery\Transcription\ProviderTranscriber;
use PHPUnit\Framework\Attributes\Test;

/**
 * Voice must not depend on which LLM you chose.
 *
 * The package came out of a project that used Gemini, and server-side voice
 * was built the way Gemini makes easy: audio inline in the same call that
 * returns intent JSON. That shape then became the definition of the feature,
 * so every other provider reported "does not support voice" — which was true
 * of the provider and false of what was possible. An app on Ollama, or Claude,
 * or a self-hosted model had no route to voice at all, and self-hosted is the
 * case with the best reason to want it, because the audio can stay inside the
 * network.
 *
 * Transcription is its own capability now. These cases hold that line: the
 * provider must be irrelevant to whether voice works.
 */
class VoiceProviderNeutralityTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('naturalquery.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
        $app['config']->set('naturalquery.routes.middleware', []);
        $app['config']->set('naturalquery.cache.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gb_sales', function ($t) {
            $t->id();
            $t->string('customer_name');
            $t->string('region');
            $t->string('status');
            $t->decimal('revenue', 12, 2);
        });

        DB::table('gb_sales')->insert([
            ['customer_name' => 'Ada', 'region' => 'West', 'status' => 'delivered', 'revenue' => 300],
        ]);
    }

    /** A provider with no audio support of its own — Claude, Ollama, most local models. */
    private function deafProvider(): RecordingProvider
    {
        $provider = new RecordingProvider();
        $provider->voiceSupported = false;
        $provider->intentResponse = [
            'success' => true,
            'scheme' => 'gb_sales',
            'metric' => 'revenue',
            'group_by' => 'region',
            'confidence' => 0.9,
            'needs_clarification' => false,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);
        $this->app->forgetInstance(TranscriberInterface::class);

        return $provider;
    }

    /** Point the package at a Whisper-compatible server, local or hosted. */
    private function useTranscriptionEndpoint(string $baseUrl = 'http://127.0.0.1:8080/v1', array $extra = []): void
    {
        config(['naturalquery.voice.transcribers.openai_compatible' => array_merge([
            'base_url' => $baseUrl,
            'model' => 'whisper-1',
            'api_key' => null,
        ], $extra)]);

        $this->app->forgetInstance(TranscriberInterface::class);
        $this->app->forgetInstance(QueryOrchestrator::class);
    }

    // ------------------------------------------------------- the whole point

    #[Test]
    public function a_provider_that_cannot_hear_still_gets_voice_from_a_local_whisper_server()
    {
        $this->deafProvider();
        $this->useTranscriptionEndpoint();

        Http::fake([
            '*/audio/transcriptions' => Http::response(['text' => 'revenue by region'], 200),
        ]);

        $response = $this->postJson('/naturalquery/voice', [
            'audio' => base64_encode('fake-audio-bytes'),
            'mime_type' => 'audio/webm',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('parsed_query.group_by', 'region');

        // Proves the transcription server did the hearing. Without this the
        // case could pass on some other route to a successful answer.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/audio/transcriptions'));
    }

    #[Test]
    public function the_answer_reports_what_was_heard()
    {
        $this->deafProvider();
        $this->useTranscriptionEndpoint();

        Http::fake(['*/audio/transcriptions' => Http::response(['text' => 'revenue by region'], 200)]);

        // Without this a misheard question looks like a wrong answer, and the
        // user argues with the data when the fault was in the microphone.
        $this->postJson('/naturalquery/voice', [
            'audio' => base64_encode('x'),
            'mime_type' => 'audio/webm',
        ])->assertJsonPath('transcribed_text', 'revenue by region');
    }

    #[Test]
    public function health_reports_whether_THIS_APP_can_take_audio_not_whether_the_llm_can()
    {
        $this->deafProvider();
        $this->useTranscriptionEndpoint();

        $health = $this->getJson('/naturalquery/health')->json();

        $this->assertFalse($health['provider']['supports_voice'], 'the provider genuinely has no audio support');
        $this->assertTrue($health['voice']['enabled'], 'but the app can still accept audio');
        $this->assertSame('openai-compatible', $health['voice']['transcriber']);
    }

    // ------------------------------------------------------ driver selection

    #[Test]
    public function auto_prefers_an_endpoint_the_app_configured_on_purpose()
    {
        $provider = new RecordingProvider();
        $provider->voiceSupported = true; // even when the LLM could do it itself
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(TranscriberInterface::class);

        $this->useTranscriptionEndpoint();

        $this->assertInstanceOf(OpenAiCompatibleTranscriber::class, $this->app->make(TranscriberInterface::class));
    }

    #[Test]
    public function auto_falls_back_to_the_provider_when_no_endpoint_is_configured()
    {
        $provider = new RecordingProvider();
        $provider->voiceSupported = true;
        $this->app->instance(LlmProviderInterface::class, $provider);

        config(['naturalquery.voice.transcribers.openai_compatible.base_url' => null]);
        $this->app->forgetInstance(TranscriberInterface::class);

        $this->assertInstanceOf(ProviderTranscriber::class, $this->app->make(TranscriberInterface::class));
    }

    #[Test]
    public function auto_settles_on_nothing_when_neither_is_available()
    {
        $this->deafProvider();
        config(['naturalquery.voice.transcribers.openai_compatible.base_url' => null]);
        $this->app->forgetInstance(TranscriberInterface::class);

        $this->assertInstanceOf(NullTranscriber::class, $this->app->make(TranscriberInterface::class));
    }

    #[Test]
    public function voice_can_be_turned_off_outright()
    {
        $this->deafProvider();
        $this->useTranscriptionEndpoint();
        config(['naturalquery.voice.driver' => 'none']);
        $this->app->forgetInstance(TranscriberInterface::class);

        $this->assertInstanceOf(NullTranscriber::class, $this->app->make(TranscriberInterface::class));
    }

    // ------------------------------------------------------- what it sends

    #[Test]
    public function it_posts_the_audio_where_a_whisper_server_expects_it()
    {
        $this->deafProvider();
        $this->useTranscriptionEndpoint('http://127.0.0.1:9000/v1', ['language' => 'en', 'api_key' => 'sk-test']);

        Http::fake(['*' => Http::response(['text' => 'revenue by region'], 200)]);

        $this->postJson('/naturalquery/voice', [
            'audio' => base64_encode('bytes'),
            'mime_type' => 'audio/webm;codecs=opus',
        ]);

        Http::assertSent(function ($request) {
            $this->assertSame('http://127.0.0.1:9000/v1/audio/transcriptions', $request->url());

            $body = (string) $request->body();
            $this->assertStringContainsString('whisper-1', $body, 'the model was not sent');
            $this->assertStringContainsString('name="language"', $body, 'the language hint was not sent');
            // Whisper implementations choose a decoder by extension, so a
            // parameterised mime type must not end up in the filename.
            $this->assertStringContainsString('audio.webm', $body, 'the filename lost its extension');
            $this->assertSame('Bearer sk-test', $request->header('Authorization')[0] ?? null);

            return true;
        });
    }

    #[Test]
    public function a_keyless_local_server_is_not_sent_an_empty_bearer_token()
    {
        // Sending one makes several local servers reject the request outright.
        $transcriber = new OpenAiCompatibleTranscriber([
            'base_url' => 'http://127.0.0.1:8080/v1',
            'api_key' => '',
        ]);

        Http::fake(['*' => Http::response(['text' => 'hello'], 200)]);
        $transcriber->transcribe(base64_encode('bytes'), 'audio/wav');

        Http::assertSent(fn ($request) => empty($request->header('Authorization')));
    }

    // ------------------------------------------------------------- failures

    #[Test]
    public function an_unconfigured_setup_explains_the_browser_path_instead_of_reporting_a_fault()
    {
        $this->deafProvider();
        config(['naturalquery.voice.transcribers.openai_compatible.base_url' => null]);
        $this->app->forgetInstance(TranscriberInterface::class);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $response = $this->postJson('/naturalquery/voice', [
            'audio' => base64_encode('bytes'),
            'mime_type' => 'audio/webm',
        ]);

        $response->assertJsonPath('error_code', ErrorCode::VOICE_UNSUPPORTED);

        $error = (string) $response->json('error');
        // It must not blame the LLM, which was never the reason.
        $this->assertStringNotContainsStringIgnoringCase('provider does not support', $error);
        $this->assertStringContainsString('browser', $error, 'the free path is not mentioned');
        $this->assertStringContainsString('base_url', $error, 'the fix is not named');
    }

    #[Test]
    public function a_throttled_transcription_service_is_reported_as_a_rate_limit()
    {
        $this->deafProvider();
        $this->useTranscriptionEndpoint();

        Http::fake(['*/audio/transcriptions' => Http::response(['error' => 'slow down'], 429)]);

        $this->postJson('/naturalquery/voice', ['audio' => base64_encode('b'), 'mime_type' => 'audio/webm'])
            ->assertStatus(429)
            ->assertJsonPath('error_code', ErrorCode::RATE_LIMITED)
            ->assertJsonPath('retryable', true);
    }

    #[Test]
    public function a_rejected_key_says_so_rather_than_blaming_the_recording()
    {
        $this->deafProvider();
        $this->useTranscriptionEndpoint();

        Http::fake(['*/audio/transcriptions' => Http::response(['error' => 'bad key'], 401)]);

        $error = (string) $this->postJson('/naturalquery/voice', [
            'audio' => base64_encode('b'),
            'mime_type' => 'audio/webm',
        ])->json('error');

        $this->assertStringContainsString('rejected the API key', $error);
    }

    #[Test]
    public function a_wrong_base_url_names_the_mistake_people_actually_make()
    {
        $transcriber = new OpenAiCompatibleTranscriber([
            'base_url' => 'https://api.openai.com/v1/audio/transcriptions',
        ]);

        Http::fake(['*' => Http::response('not found', 404)]);
        $result = $transcriber->transcribe(base64_encode('b'), 'audio/webm');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('should end in /v1', $result['error']);
    }

    #[Test]
    public function silence_is_reported_as_silence()
    {
        $this->deafProvider();
        $this->useTranscriptionEndpoint();

        Http::fake(['*/audio/transcriptions' => Http::response(['text' => '   '], 200)]);

        $this->postJson('/naturalquery/voice', ['audio' => base64_encode('b'), 'mime_type' => 'audio/webm'])
            ->assertJsonPath('error_code', ErrorCode::TRANSCRIPTION_FAILED);
    }
}
