<?php

namespace Jayanta\NaturalQuery\Transcription;

use Jayanta\NaturalQuery\Contracts\LlmProviderInterface;
use Jayanta\NaturalQuery\Contracts\TranscriberInterface;

/**
 * Transcription by an LLM provider that accepts audio directly.
 *
 * Gemini does; Claude and Ollama do not. This keeps that path available
 * without letting it dictate the design — it is one driver behind the same
 * interface as everything else, so an app that has it configured gains
 * nothing structural over an app pointing at a local Whisper server.
 *
 * Only the transcript is taken, even though the provider also returns a parsed
 * intent. The orchestrator already discarded that intent and re-ran the query
 * from the transcript, so nothing is lost — and taking only the text means one
 * code path decides what a question means, whether it arrived typed or spoken.
 * Two paths would eventually disagree, and the spoken one would be the one
 * nobody had tested.
 */
class ProviderTranscriber implements TranscriberInterface
{
    public function __construct(protected LlmProviderInterface $provider)
    {
    }

    public function getName(): string
    {
        return 'provider:' . $this->provider->getName();
    }

    public function isConfigured(): bool
    {
        return $this->provider->supportsVoice();
    }

    public function transcribe(string $audioBase64, string $mimeType): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => "The '{$this->provider->getName()}' provider does not accept audio.",
            ];
        }

        try {
            $result = $this->provider->parseVoiceQuery($audioBase64, $mimeType, []);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Voice processing failed: ' . $e->getMessage()];
        }

        $text = trim((string) ($result['transcribed_text'] ?? ''));

        if ($text === '') {
            return [
                'success' => false,
                'status' => $result['status'] ?? null,
                'error' => $result['error'] ?? 'Nothing was recognised in the recording.',
            ];
        }

        return ['success' => true, 'text' => $text];
    }
}
