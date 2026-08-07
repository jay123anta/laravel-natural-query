<?php

namespace Jayanta\NaturalQuery\Transcription;

use Jayanta\NaturalQuery\Contracts\TranscriberInterface;

/**
 * No server-side transcription configured.
 *
 * This is a perfectly ordinary setup, not a broken one: the widget transcribes
 * in the browser by default, which costs nothing, needs no configuration, and
 * keeps the audio on the user's machine. `/voice` exists for browsers without
 * SpeechRecognition, and an app that does not need it should not have to
 * configure anything.
 *
 * So the message says what to do rather than reporting a fault, and names both
 * a local and a hosted option — the choice is the adopter's, and a package
 * that mentions only one is making it for them.
 */
class NullTranscriber implements TranscriberInterface
{
    public function getName(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function transcribe(string $audioBase64, string $mimeType): array
    {
        return [
            'success' => false,
            'error' => 'Server-side voice is not configured. Most apps do not need it — the widget '
                . 'transcribes speech in the browser and posts the text to /text, which works with '
                . 'every provider and keeps the audio on the device. To accept audio here as well, '
                . 'set voice.transcribers.openai_compatible.base_url to a Whisper-compatible '
                . 'endpoint: a local whisper.cpp or faster-whisper server, or a hosted one.',
        ];
    }
}
