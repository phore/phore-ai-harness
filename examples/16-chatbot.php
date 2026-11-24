<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\SystemPrompt;

require dirname(__DIR__) . '/vendor/autoload.php';

// Local example: php -S 127.0.0.1:8080 -t examples
// Open http://127.0.0.1:8080/16-chatbot.php with configured Harness credentials.
// A deployed service must add its own authentication and rate limits.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        http_response_code(415);
        echo json_encode(['error' => 'Expected application/json.']);
        return;
    }

    try {
        $request = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON.']);
        return;
    }
    $message = $request['message'] ?? null;
    $responseId = $request['responseId'] ?? null;
    if (!is_string($message) || trim($message) === ''
        || ($responseId !== null && (!is_string($responseId) || trim($responseId) === ''))
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Expected message and optional responseId.']);
        return;
    }

    // Only the last issued ID is stored for authorization, not the conversation.
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'use_strict_mode' => true]);
    if ($responseId !== null && $responseId !== ($_SESSION['chatResponseId'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unknown response ID for this session.']);
        return;
    }

    // Rebuild the same environment on every HTTP request; no previous messages.
    $context = new AiContext(
        prompts: [new SystemPrompt('You are a helpful, concise chatbot.')],
        options: ['model' => 'gpt-5-mini', 'store' => true],
    );
    $context->resumeFromResponseId($responseId);

    try {
        $answer = $context->text($message);
        $nextId = $context->getResponseId();
        if ($nextId === null) {
            throw new RuntimeException('No resumable response ID.');
        }
        $_SESSION['chatResponseId'] = $nextId;

        echo json_encode([
            'text' => $answer,
            'responseId' => $nextId,
        ], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        // Do not expose provider errors, credentials or internal data.
        http_response_code(502);
        echo json_encode(['error' => 'AI request failed. Start a new chat if the response expired.']);
    }
    return;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>AI Harness – Resumable chatbot</title>
</head>
<body>
    <h1>Chatbot</h1>
    <div id="messages" aria-live="polite"></div>
    <form id="chat">
        <label>Message <input name="message" required autocomplete="off"></label>
        <button>Send</button>
    </form>
    <button id="new-chat">New chat</button>
    <script type="module">
        const form = document.querySelector('#chat');
        const messages = document.querySelector('#messages');
        const input = form.elements.message;
        let responseId = null;
        let queue = Promise.resolve();

        function append(text) {
            const line = document.createElement('p');
            line.textContent = text;
            messages.append(line);
        }

        async function send(message) {
            // The browser sends one new message and the latest response ID.
            const response = await fetch(location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({message, responseId}),
            });
            if (!response.ok) {
                throw new Error((await response.json()).error);
            }
            const result = await response.json();
            responseId = result.responseId;
            append('You: ' + message);
            append('AI: ' + result.text);
            if (input.value === message) input.value = '';
        }

        form.addEventListener('submit', event => {
            event.preventDefault();
            const message = input.value;
            // Serialize turns so each request uses the preceding response ID.
            queue = queue.then(() => send(message)).catch(error => alert(error.message));
        });

        document.querySelector('#new-chat').addEventListener('click', () => {
            queue = queue.then(() => {
                responseId = null;
                messages.replaceChildren();
            });
        });
    </script>
</body>
</html>
