<?php

namespace Jayanta\NaturalQuery\Support;

/**
 * Turn the `ssl_verify` config value into Guzzle options.
 *
 * Lived on AbstractProvider until anything other than an LLM provider needed
 * to make an HTTPS call. A local stack whose PHP has no CA store (XAMPP, WAMP)
 * must be able to point one setting at a bundle and have EVERY outbound call
 * in the package honour it — a transcription endpoint that ignored it would
 * fail at the handshake while the LLM calls worked, which is a bewildering
 * thing to debug.
 */
trait ResolvesSslOptions
{
    /**
     * Accepted values:
     *   true / "true" / "1"    → verify against the system CA bundle (default)
     *   false / "false" / "0"  → disable verification (NOT recommended)
     *   "/path/to/cacert.pem"  → verify against this CA bundle file
     *
     * @return array<string, mixed> Guzzle options to merge in, or [] for default behavior
     */
    protected function sslOptions(): array
    {
        $verify = config('naturalquery.ssl_verify', true);

        if ($verify === false) {
            return ['verify' => false];
        }

        if (is_string($verify)) {
            $normalized = strtolower(trim($verify));
            if (in_array($normalized, ['', '1', 'true', 'on', 'yes'], true)) {
                return [];
            }
            if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return ['verify' => false];
            }
            // Anything else is treated as a CA bundle path. Passed through
            // as-is: a bad path fails loudly with a clear Guzzle error rather
            // than being silently ignored.
            return ['verify' => $verify];
        }

        return [];
    }
}
