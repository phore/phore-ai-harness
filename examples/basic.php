<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\WebAccessTool;

require dirname(__DIR__) . '/vendor/autoload.php';

$context = new AiContext(
    prompts: [new WebAccessTool()],
    options: ['model' => 'gpt-5-mini'],
);

$context->do('Recherchiere die wichtigsten aktuellen Fakten zum Thema.');
$context->do('Prüfe die gefundenen Fakten auf Widersprüche.');
$summary = $context->text('Fasse die geprüften Fakten kurz zusammen.');

echo $summary . "\n";
