<?php

namespace Jayanta\NaturalQuery\Transcription;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jayanta\NaturalQuery\Contracts\TranscriberInterface;
use Jayanta\NaturalQuery\Support\ResolvesSslOptions;

/**
 * Speech to text over the OpenAI `/audio/transcriptions` shape.
 *
 * One implementation, because everything speaks it. Point `base_url` at
 * whatever you run:
 *
 *   http://127.0.0.1:8080/v1        whisper.cpp server
 *   http://127.0.0.1:8000/v1        faster-whisper-server
 *   http://127.0.0.1:8080/v1        LocalAI
 *   http://127.0.0.1:1234/v1        LM Studio
 *   https://api.groq.com/openai/v1  Groq (whisper-large-v3, generous free tier)
 *   https://api.openai.com/v1       OpenAI (whisper-1)
 *
 * That range is the point. A package that only did voice on one commercial
 * vendor's API left every self-hosted adopter out, and self-hosting is the
 * case with the strongest reason to want it: the audio never leaves the
 * network. This driver treats a local server and a hosted one as the same
 * thing, because to the caller they are.
 *
 * No API key is required — local servers almost never want one, so an empty
 * key is a valid configuration rather than a missing one.
 */
class OpenAiCompatibleTranscriber implements TranscriberInterface
{
    use ResolvesSslOptions;

    /** @var array<string, mixed> */
    protected array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function getName(): string
    {
        return 'openai-compatible';
    }

    /**
     * A base URL is the whole requirement. Everything else has a default, and
     * a key is optional because a local server has no use for one.
     */
    public function isConfigured(): bool
    {
        return trim((string) ($this->config['base_url'] ?? '')) !== '';
    }

    public function transcribe(string $audioBase64, string $mimeType): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'No transcription endpoint is configured. Set voice.transcribers.openai_compatible.base_url.',
            ];
        }

        $audio = base64_decode($audioBase64, true);

        if ($audio === false || $audio === '') {
            return ['success' => false, 'error' => 'The audio could not be read.'];
        }

        $url = rtrim((string) $this->config['base_url'], '/') . '/audio/transcriptions';
        $model = (string) ($this->config['model'] ?? 'whisper-1');

        try {
            $request = Http::withOptions($this->sslOptions())
                ->timeout((int) ($this->config['timeout'] ?? 60));

            // Local servers are usually keyless; sending an empty bearer token
            // makes some of them reject the request outright.
            if ($key = trim((string) ($this->config['api_key'] ?? ''))) {
                $request = $request->withToken($key);
            }

            // The filename matters. Whisper implementations pick the decoder
            // from the extension, and a name with the wrong one is rejected as
            // an unsupported format even when the bytes are fine.
            $request = $request->attach('file', $audio, 'audio.' . $this->extensionFor($mimeType));

            $form = ['model' => $model, 'response_format' => 'json'];

            // Worth setting when you know it: naming the language stops short
            // clips being detected as the wrong one, which is the usual cause
            // of a transcript that is confident and entirely wrong.
            if ($language = trim((string) ($this->config['language'] ?? ''))) {
                $form['language'] = $language;
            }

            $response = $request->post($url, $form);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'status' => $response->status(),
                    'error' => $this->describeFailure($response->status(), (string) $response->body()),
                ];
            }

            $text = trim((string) ($response->json('text') ?? ''));

            if ($text === '') {
                return ['success' => false, 'error' => 'Nothing was recognised in the recording.'];
            }

            return ['success' => true, 'text' => $text];
        } catch (\Throwable $e) {
            Log::error('[NaturalQuery] Transcription failed', [
                'endpoint' => $url,
                'error' => $this->redact($e->getMessage()),
            ]);

            return [
                'success' => false,
                'error' => 'The transcription service could not be reached: ' . $this->redact($e->getMessage()),
            ];
        }
    }

    /**
     * Say which of the several things a non-200 means, since the fixes differ
     * entirely and the raw body is usually a JSON blob nobody reads.
     */
    protected function describeFailure(int $status, string $body): string
    {
        return match (true) {
            $status === 401 || $status === 403 =>
                'The transcription service rejected the API key.',
            $status === 404 =>
                'No transcription endpoint at that URL. Check base_url — it should end in /v1, not /v1/audio/transcriptions.',
            $status === 413 =>
                'The recording was too large for the transcription service.',
            $status === 429 =>
                'The transcription service is rate limiting. Try again shortly.',
            $status >= 500 =>
                'The transcription service failed (HTTP ' . $status . ').',
            default =>
                'Transcription failed (HTTP ' . $status . '): ' . $this->redact(mb_substr($body, 0, 200)),
        };
    }

    /**
     * Browsers hand over audio/webm;codecs=opus and similar, so the parameters
     * are stripped before mapping.
     */
    protected function extensionFor(string $mimeType): string
    {
        $type = strtolower(trim(explode(';', $mimeType)[0]));

        return match ($type) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/wav', 'audio/wave', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/flac', 'audio/x-flac' => 'flac',
            'video/mp4' => 'mp4',
            default => 'webm',
        };
    }

    protected function redact(string $message): string
    {
        return (string) preg_replace('/Bearer\s+[A-Za-z0-9_.-]+/i', 'Bearer ***', $message);
    }
}
