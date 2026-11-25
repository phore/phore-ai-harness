<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;

/**
 * Transcribe a completed audio recording directly, without an AI context.
 * If no client is provided, the default Keystore supplies the API credentials.
 *
 * @param string $audio Raw binary audio data (not base64).
 * @param string $format File extension, e.g. webm, m4a, wav or mp3.
 * @param string $model Transcription model ID.
 * @param string|null $prompt Optional transcription vocabulary/context.
 * @param OpenAiClient|null $client Optional configured client.
 * @return string The transcript in the original language.
 * @throws InvalidArgumentException For invalid audio or format.
 * @throws \Phore\AiHarness\Client\AiRequestException For transport or provider failures.
 * @throws RuntimeException For missing credentials or transcript.
 * @example $text = phore_ai_speech_to_text($audioBytes, 'webm');
 * @see OpenAiClient::speechToText()
 */
function phore_ai_speech_to_text(
    string $audio,
    string $format,
    string $model = 'gpt-transcribe',
    ?string $prompt = null,
    ?OpenAiClient $client = null,
): string {
    return ($client ?? new OpenAiClient())->speechToText($audio, $format, $model, $prompt);
}
