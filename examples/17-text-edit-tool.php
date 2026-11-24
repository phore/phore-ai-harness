<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\TextEditTool;

require dirname(__DIR__) . '/vendor/autoload.php';

// With the configured API credentials, edit an in-memory draft and chat about it.
$original = 'Welcome to our practce.';
$buffer = new TextEditTool($original, 'intro.md');
$context = new AiContext();
$context->addToolSet($buffer);

$message = $context->text('Correct the spelling using the text editing tool and summarize the change.');
echo $message . PHP_EOL;
echo $buffer->getContent() . PHP_EOL; // Expected: Welcome to our practice.

// The same instance retains changes across subsequent conversational requests.
$message = $context->text('Now rewrite the entire introduction as a short, friendly greeting. Explain what changed.');
echo $message . PHP_EOL;
echo $buffer->getContent() . PHP_EOL;
$responseId = $context->getResponseId();

// Application-controlled undo does not require another AI request.
$buffer->setContent($original);
echo $buffer->getContent() . PHP_EOL; // Welcome to our practce.
