<?php

declare(strict_types=1);

use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\Result\AiInteractiveResult;
use Phore\AiHarness\ToolType\CallbackTool;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    header('Content-Type: application/json');

    try {
        $request = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        $prompt = $request['prompt'];
        $continueId = $request['interactive_continue_id'] ?? null;
        $steering = $request['interactive_steering_message'] ?? null;
        if (!is_string($prompt) || trim($prompt) === ''
            || ($continueId !== null && !is_string($continueId))
            || ($steering !== null && !is_string($steering))) {
            throw new InvalidArgumentException('Expected a prompt and optional continuation/steering strings.');
        }

        // Application-level replay protection: only the latest token of this session is accepted.
        if ($continueId !== null && (!hash_equals($_SESSION['continueHash'] ?? '', hash('sha256', $continueId))
            || !hash_equals($_SESSION['promptHash'] ?? '', hash('sha256', $prompt)))) {
            http_response_code(409);
            echo json_encode(['error' => 'Stale or unauthorized continuation.']);
            return;
        }
        $_SESSION['continueHash'] = null; // No blind retry after an uncertain outcome.
        $_SESSION['promptHash'] = hash('sha256', $prompt);

        // These variables only describe this step; they do not belong to the framework.
        $logMessages = [];
        $questions = [];
        $result = phore_ai_text([
            $prompt,
            new SystemPrompt(
                'Use log_message for relevant progress. If user input is needed, call ask_user '
                . 'in a separate tool round and wait for the answer in the next user message. '
                . 'Do not perform dependent actions until the answer has arrived.'
            ),
            new CallbackTool(
                static function (string $message) use (&$logMessages): string {
                    $logMessages[] = $message;
                    return 'Status displayed to the user.';
                },
                'log_message',
                'Display a relevant status update while work continues.',
            ),
            new CallbackTool(
                static function (string $question) use (&$questions): string {
                    $questions[] = $question;
                    return 'Question displayed. The next user message contains the answer.';
                },
                'ask_user',
                'Ask one question when user input is necessary. Wait for the next user message.',
            ),
        ], [
            'interactive' => true,
            'interactive_continue_id' => $continueId,
            'interactive_steering_message' => $steering,
        ]);

        // Application-defined response fields, no additional framework API.
        if ($result instanceof AiInteractiveResult) {
            $_SESSION['continueHash'] = hash('sha256', $result->continueId);
            $result = [
                ...get_object_vars($result),
                'logMessages' => $logMessages,
                'questions' => $questions,
                'stop' => $questions !== [],
            ];
        }

        echo json_encode($result, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        http_response_code(500);
        error_log($error->getMessage());
        echo json_encode(['error' => 'Step failed; check the outcome before starting another task.']);
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
    <input id="prompt" placeholder="Task, steering message or answer" required>
    <button>Send</button>
</form>
<pre id="events"></pre>
<script type="module" src="client.js"></script>
</html>
