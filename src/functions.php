<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Client\AiResponse;

require_once __DIR__ . '/functions/phore_ai_text.php';
require_once __DIR__ . '/functions/phore_ai_image.php';
require_once __DIR__ . '/functions/phore_ai_struct.php';
require_once __DIR__ . '/functions/phore_ai_struct_array.php';
require_once __DIR__ . '/functions/phore_ai_file.php';

/**
 * Returns the last OpenAI Responses API request created by this process.
 */
function get_last_ai_request(): ?AiRequest
{
    return AiRequest::$last;
}

/**
 * Returns the last OpenAI Responses API response created by this process.
 */
function get_last_ai_response(): ?AiResponse
{
    return AiResponse::$last;
}
