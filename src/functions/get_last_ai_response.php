<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiResponse;

/**
 * Return the last OpenAI Responses API response recorded in this PHP process.
 * This reads shared diagnostic state without making a network request. It may refer
 * to another facade/client call or an earlier operation; it is not per-call storage.
 * The object is returned as recorded, without a detached copy. No parameters or options.
 *
 * Example (after requiring vendor/autoload.php):
 * <code>
 * $response = get_last_ai_response();
 * if ($response !== null) {
 *     // Inspect the recorded response while debugging.
 *     var_dump($response);
 * }
 * </code>
 *
 * @return AiResponse|null Last recorded response, or null when none has been recorded.
 */
function get_last_ai_response(): ?AiResponse
{
    return AiResponse::$last;
}
