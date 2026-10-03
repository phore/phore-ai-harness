<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\AiOptions;
use Phore\AiHarness\ToolType\WebAccessTool;

require dirname(__DIR__) . '/vendor/autoload.php';

// Normal examples pass options as arrays. Use AiOptions when configuration is
// built once and passed around as a typed application object.
$options = new AiOptions(
    model: 'gpt-5-mini',
    reasoning: ['effort' => 'medium'],
    timeout: 120,
    connectTimeout: 10,
    debugLog: true,
);

$context = new AiContext(
    prompts: [new WebAccessTool()],
    options: $options,
);

$context->do('Recherchiere die aktuelle PHP-8.5-Dokumentation.', throw: true);

$summary = $context->text('Fasse die drei wichtigsten Punkte für Anwendungsentwickler zusammen.');
// returns: "..."
