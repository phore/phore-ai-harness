<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\CallbackTool;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start(); // Die Session sperrt konkurrierende Schritte dieses Demo-Clients.
    header('Content-Type: application/json');

    try {
        $request = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        $prompt = $request['prompt'] ?? null;
        $responseId = $request['responseId'] ?? null;
        if (($prompt !== null && !is_string($prompt)) || ($responseId !== null && !is_string($responseId))) {
            throw new InvalidArgumentException('Expected text prompt and response ID.');
        }
        if ($responseId !== null && ($responseId !== ($_SESSION['responseId'] ?? null)
            || ($_SESSION['uncertain'] ?? false))) {
            http_response_code(409);
            echo json_encode(['error' => 'Cursor is stale, unauthorized or uncertain.']);
            return;
        }

        // Neue Aufgaben beginnen ohne Cursor; unklare alte Schritte werden nicht wiederholt.
        $_SESSION['uncertain'] = true;
        $_SESSION['responseId'] = null;
        $context = new AiContext(prompts: [
            new CallbackTool(static fn (string $topic): string => 'Prepared: ' . $topic, name: 'prepare_topic'),
            new CallbackTool(static fn (string $topic): string => 'Reviewed: ' . $topic, name: 'review_topic'),
        ]);
        $result = $context->doInteractive($prompt, $responseId);
        $_SESSION['responseId'] = $result->responseId;
        $_SESSION['uncertain'] = false;

        // Der Consumer kann eigene Daten an die Response anfügen.
        echo json_encode([...get_object_vars($result), 'example' => 'interactive'], JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        http_response_code(500);
        error_log($error->getMessage());
        echo json_encode(['error' => 'Step failed; do not automatically retry.']);
    } finally {
        session_write_close();
    }
    return;
}
?>
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<title>Phore AI interactive example</title>
<h1>Interactive tool calls</h1>
<form id="chat">
    <input id="prompt" placeholder="Task or steering message" required>
    <button>Send</button>
</form>
<pre id="events"></pre>
<script type="module" src="client.js"></script>
</html>
