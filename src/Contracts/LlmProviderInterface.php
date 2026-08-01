<?php

namespace Jayanta\NaturalQuery\Contracts;

/**
 * LLM Provider Interface
 *
 * All AI providers (Gemini, OpenAI, Claude, Ollama) implement this contract.
 * This is the ONLY point where external AI services are contacted.
 *
 * PRIVACY GUARANTEE:
 * - The prompt parameter contains ONLY schema structure (table names, column names, types)
 * - Actual database values/data are NEVER included in any method call
 * - The AI generates SQL which is executed locally - data never leaves the user's system
 */
interface LlmProviderInterface
{
    /**
     * Generate SQL from a schema-aware prompt.
     *
     * The prompt contains:
     * - Database schema (table names, column names, types, relationships)
     * - LLM instructions (how to use the schema)
     * - Example queries (for few-shot learning)
     * - The user's natural language question
     *
     * The prompt NEVER contains actual data values.
     *
     * @param string $prompt The complete schema-aware prompt
     * @return array {success: bool, data?: array, error?: string}
     *               data contains parsed JSON with: sql, query_type, metric, explanation
     */
    public function generateSql(string $prompt): array;

    /**
     * Parse a natural language query into structured intent.
     *
     * Used when the system first identifies what scheme/metric the user wants,
     * before generating SQL.
     *
     * @param string $text User's natural language query
     * @param array $schemeList Available schemes [{key, name, aliases, metrics}]
     * @return array {
     *   success: bool,
     *   scheme?: string,
     *   metric?: string,
     *   limit?: int,
     *   order?: 'asc'|'desc',
     *   district?: string,
     *   confidence?: float (0.0-1.0),
     *   needs_clarification?: bool,
     *   clarification_type?: 'scheme'|'metric'|'ambiguous',
     *   error?: string
     * }
     */
    public function parseIntent(string $text, array $schemeList): array;

    /**
     * Parse voice audio into structured intent.
     *
     * Not all providers support this. Providers that don't support audio
     * should throw UnsupportedFeatureException.
     *
     * @param string $audioBase64 Base64-encoded audio data
     * @param string $mimeType Audio MIME type (e.g., 'audio/webm', 'audio/wav')
     * @param array $schemeList Available schemes
     * @return array Same format as parseIntent(), plus transcribed_text
     * @throws \Jayanta\NaturalQuery\Exceptions\UnsupportedFeatureException
     */
    public function parseVoiceQuery(string $audioBase64, string $mimeType, array $schemeList): array;

    /**
     * Check if the provider is available and configured correctly.
     *
     * @return array {status: 'ok'|'error', message?: string, model?: string}
     */
    public function healthCheck(): array;

    /**
     * Get the provider name for logging and metadata.
     *
     * @return string e.g., 'gemini', 'openai', 'claude', 'ollama', 'null'
     */
    public function getName(): string;

    /**
     * Whether this provider supports voice/audio input.
     *
     * @return bool
     */
    public function supportsVoice(): bool;
}
