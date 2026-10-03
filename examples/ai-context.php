<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\AiOptions;
use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\ToolType\CallbackTool;

require dirname(__DIR__) . '/vendor/autoload.php';

// Ausfuehrung: php examples/ai-context.php
// Benoetigt konfigurierte Zugangsdaten; erzeugt kostenpflichtige Modellaufrufe.
// Das Beispiel schreibt keine Dateien und fragt nur bei Bedarf ueber STDIN nach.
$askUser = new CallbackTool(
    static function (string $question): string {
        fwrite(STDERR, $question . PHP_EOL . '> ');
        $answer = fgets(STDIN);
        if ($answer === false) {
            throw new RuntimeException('No user answer available on STDIN.');
        }

        return trim($answer);
    },
    name: 'ask_user_question',
    description: 'Ask the user a necessary clarification and return the answer.',
);

$config = AiOptions::fromArray([
    'model' => 'gpt-5-mini',
    'reasoning' => ['effort' => 'low'],
    'debug_log' => true,
]);

$context = new AiContext(
    prompts: [
        'The subject is a practice website relaunch. Audience: practice owners. Use German.',
        $askUser,
    ],
    options: $config,
);
$options = ['ai_context' => $context];

// Die globalen Funktionen bleiben der bevorzugte Einstieg. Beim ersten Aufruf
// werden die vorbereiteten Daten-Prompts geladen; das Tool bleibt danach aktiv.
$draft = phore_ai_text('Write a two-sentence introduction in German.', $options);
$context->setCheckpoint('briefing');
$edited = phore_ai_text('Make the existing text more direct, in German.', [
    ...$options,
    'input' => $draft,
]);
printf("Edited introduction:\n%s\n\n", $edited);

$alternative = clone $context;
$alternative->rollback('briefing');
$variant = $alternative->text('Write a more personal introduction in German.');
printf("Alternative:\n%s\n\n", $variant);

$context->setCheckpoint();
$context->text('Suggest a tentative German title.');
$context->rollback();
$finalTitle = $context->text('Suggest a factual German title instead.');
printf("Title:\n%s\n", $finalTitle);

try {
    phore_ai_text('The project code is WEBSITE. Acknowledge briefly.', [
        'ai_context' => 'example-job',
    ]);
    $code = phore_ai_text('Return only the project code.', [
        'ai_context' => 'example-job',
    ]);
    printf("Project code:\n%s\n", $code);
} finally {
    AiContextRegistry::forget('example-job');
}

$usage = get_ai_usage_stats();
printf("Cached input tokens: %d\n", $usage['cachedInputTokens']);
echo $usage['totalCostUsd'] === null
    ? "Estimated total cost: unknown\n"
    : sprintf("Estimated total cost: USD %.8f\n", $usage['totalCostUsd']);
