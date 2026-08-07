<?php

namespace Jayanta\NaturalQuery\Contracts;

/**
 * Turning speech into text — a capability in its own right, not a property of
 * whichever LLM happens to be configured.
 *
 * Server-side voice used to work only on Gemini, because Gemini accepts audio
 * inline in the same call that returns intent JSON, and the package was shaped
 * around that. It made voice a feature most adopters did not have: anyone on a
 * local model, on Claude, on an OpenAI-compatible endpoint, was told their
 * provider "does not support voice" when the truth was that nothing had been
 * built for them.
 *
 * The one-call shape bought nothing anyway. The orchestrator discarded the
 * intent Gemini returned and re-ran the query from the transcript, so audio →
 * text → the ordinary text path was already what happened. Splitting
 * transcription out costs no round trip and lets local Whisper, a hosted
 * Whisper-compatible service, or a provider's own audio support each be one
 * driver among several.
 *
 * Implementations must not throw for an ordinary failure — a refused key, an
 * unreachable host, unintelligible audio. Return success:false with a message
 * a person can act on, so the caller can map it to an error code rather than
 * unwinding a stack.
 */
interface TranscriberInterface
{
    /**
     * @param string $audioBase64 Raw audio, base64-encoded (no data: prefix)
     * @param string $mimeType    e.g. audio/webm, audio/mp4, audio/wav
     *
     * @return array{success: bool, text?: string, error?: string, status?: int|null}
     */
    public function transcribe(string $audioBase64, string $mimeType): array;

    /**
     * Whether this transcriber has what it needs to run.
     *
     * A driver that is selected but unconfigured must say so here rather than
     * failing at request time, so `/voice`, the health endpoint and
     * `naturalquery:doctor` can all report the same truth before a user
     * records anything.
     */
    public function isConfigured(): bool;

    /** Short identifier for logs and health output, e.g. "openai-compatible". */
    public function getName(): string;
}
