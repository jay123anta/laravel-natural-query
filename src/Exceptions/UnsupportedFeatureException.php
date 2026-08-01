<?php

namespace Jayanta\NaturalQuery\Exceptions;

class UnsupportedFeatureException extends \RuntimeException
{
    public static function voiceNotSupported(string $provider): self
    {
        return new self("Voice input is not supported by the '{$provider}' LLM provider.");
    }

    public static function databaseNotSupported(string $driver): self
    {
        return new self("Database driver '{$driver}' is not supported. Supported: pgsql, mysql, sqlsrv, sqlite.");
    }
}
