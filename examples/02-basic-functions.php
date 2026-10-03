<?php

declare(strict_types=1);

use Phore\AiHarness\PromptType\PromptFile;
use Phore\AiHarness\ToolType\WebAccessTool;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = [
    'ai_context' => 'php-85-research',
    'model' => 'gpt-5-mini',
];

// The first call seeds the named process-local context. Reuse the same
// ai_context key in every following helper call to continue the conversation.
phore_ai_do([
    new PromptFile(__DIR__ . '/prompts/research.prompt.md'),
    new WebAccessTool(),
    'Recherchiere den aktuellen Stand von PHP 8.5 und die wichtigsten Neuerungen.',
], throw: true, options: $options);

$released = phore_ai_yes_no('Ist PHP 8.5 stabil veröffentlicht?', options: $options);
// returns: true

$focus = phore_ai_choice(
    'Welcher Schwerpunkt passt am besten?',
    ['release', 'features', 'migration'],
    options: $options,
);
// returns: 'features'

$topics = phore_ai_choices(
    'Welche Themen sind relevant?',
    ['release', 'language', 'performance', 'migration', 'deprecations'],
    min: 1,
    max: 3,
    options: $options,
);
// returns: ['language', 'deprecations']

$ranking = phore_ai_rank(
    'Sortiere nach Bedeutung für Anwendungsentwickler.',
    ['language', 'performance', 'migration'],
    options: $options,
);
// returns: ['migration', 'language', 'performance']

$confidence = phore_ai_score('Wie vollständig ist die Recherche?', options: $options);
// returns: 0.86

$summary = phore_ai_text('Fasse die Ergebnisse in drei Sätzen zusammen.', $options);
// returns: "PHP 8.5 ..."

// The function API is convenient for straight process-local flows. Use an
// AiContext object when checkpoints, rollback, exportState()/importState() or
// persistent resume across requests are required; see 01-basic.php.
