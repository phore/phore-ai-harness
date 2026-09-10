<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;

/**
 * Return the last OpenAI Responses API request recorded in this PHP process.
 * This reads shared diagnostic state without making a network request. It may refer
 * to another facade/client call or an earlier operation; it is not per-call storage.
 * The object is returned as recorded, without a detached copy. No parameters or options.
 *
 * Example (after requiring vendor/autoload.php):
 * <code>
 * $request = get_last_ai_request();
 * if ($request !== null) {
 *     // Inspect the recorded request while debugging.
 *     var_dump($request);
 * }
 * </code>
 *
 * @return AiRequest|null Last recorded request, or null when none has been recorded.
 */
function get_last_ai_request(): ?AiRequest
{
    return AiRequest::$last;
}
