<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\CallbackTool;

require dirname(__DIR__) . '/vendor/autoload.php';

$context = new AiContext(prompts: [
    'Du analysierst Supportfälle. Trenne beobachtete Fakten klar von Vermutungen.',
    new CallbackTool(
        static function (string $caseId): array {
            return [
                'caseId' => $caseId,
                'customer' => 'Example GmbH',
                'message' => 'Seit dem letzten Deployment liefert der Checkout sporadisch HTTP 500.',
                'deployAt' => '2026-10-03T08:15:00Z',
            ];
        },
        name: 'load_case',
        description: 'Load the current support case from the application backend.',
    ),
]);

// do() prepares conversation state and can execute tools without returning a
// user-facing text result.
$context->do(
    'Lade den Fall CASE-42 und bereite eine belastbare interne Auswertung für Folgefragen vor.',
    throw: true,
);

$cause = $context->text('Was ist nach aktuellem Stand die wahrscheinlichste Ursache?');
$urgent = $context->yesNo('Muss der Fall anhand der vorliegenden Informationen sofort eskaliert werden?');
$nextStep = $context->text('Welchen nächsten technischen Prüfschritt würdest du durchführen?');

echo $cause . PHP_EOL . $nextStep . PHP_EOL;
var_dump($urgent);
