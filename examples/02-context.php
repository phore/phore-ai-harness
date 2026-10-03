<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\PromptFile;
use Phore\AiHarness\ToolType\WebAccessTool;

require dirname(__DIR__) . '/vendor/autoload.php';

// For the smallest helper-first introduction, see 01-basic-functions.php.

$prompts = [
    new PromptFile(__DIR__ . '/prompts/research.prompt.md'),
    new WebAccessTool(),
];

$context = new AiContext(
    prompts: $prompts,
    options: ['model' => 'gpt-5-mini'],
);

// Prepared prompts and tools are part of every step in this conversation.
$context->do('Recherchiere den aktuellen Stand von PHP 8.5 und die wichtigsten Neuerungen.', throw: true);
$context->setCheckpoint('research');

$released = $context->yesNo('Ist PHP 8.5 laut den recherchierten Quellen stabil veröffentlicht?');
// returns: true

$focus = $context->choice(
    'Welcher Schwerpunkt beschreibt die Recherche am besten?',
    ['release', 'features', 'migration'],
);
// returns: 'features'

$topics = $context->choices(
    'Welche Themen kommen in den recherchierten Quellen vor?',
    ['release', 'language', 'performance', 'migration', 'deprecations'],
    min: 1,
    max: 3,
);
// returns: ['language', 'deprecations']

$ranking = $context->rank(
    'Sortiere diese Themen nach ihrer Bedeutung für Anwendungsentwickler.',
    ['language', 'performance', 'migration'],
);
// returns: ['migration', 'language', 'performance']

$confidence = $context->score('Wie vollständig ist die Recherche für einen kurzen technischen Überblick?');
// returns: 0.86

$summary = $context->text('Fasse die wichtigsten Ergebnisse in drei Sätzen zusammen.');
// returns: "PHP 8.5 ..."

// Checkpoint/rollback itself is local and makes no provider request. The next
// call branches from the saved response cursor; provider prompt-cache hits may
// make follow-up calls faster, but a cache hit is not guaranteed.
$context->rollback('research');
$alternative = $context->text('Nenne stattdessen nur die drei wichtigsten Punkte.');
// returns: "1. ..."

// State export is for session/request boundaries. Rebuild the same prepared
// prompts/tools from code before importing the cursor.
$state = $context->exportState();

$resumed = new AiContext(
    prompts: [
        new PromptFile(__DIR__ . '/prompts/research.prompt.md'),
        new WebAccessTool(),
    ],
    options: ['model' => 'gpt-5-mini'],
);
$resumed->importState($state);

$followUp = $resumed->text('Welche der genannten Änderungen betrifft bestehende Projekte am ehesten?');
// returns: "..."
