<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\CallbackTool;

require dirname(__DIR__) . '/vendor/autoload.php';

$loadCase = new CallbackTool(
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
);

$context = new AiContext(
    prompts: [
        'Du analysierst Supportfälle. Trenne beobachtete Fakten klar von Vermutungen.',
        $loadCase,
    ],
    options: ['model' => 'gpt-5-mini'],
);

// do() lets the model perform preparatory reasoning and tool calls without
// returning a user-facing text result.
$context->do(
    'Lade den Fall CASE-42 und bereite eine belastbare interne Auswertung für Folgefragen vor.',
    throw: true,
);

// The loaded tool result and the analysis remain in the same conversation, so
// later questions can reuse that prepared state.
$cause = $context->text('Was ist nach aktuellem Stand die wahrscheinlichste Ursache?');
// returns: "Der zeitliche Zusammenhang mit dem Deployment ist auffällig, aber noch kein Beweis ..."

$urgent = $context->yesNo('Muss der Fall anhand der vorliegenden Informationen sofort eskaliert werden?');
// returns: true or false

$nextStep = $context->text('Welchen nächsten technischen Prüfschritt würdest du durchführen?');
// returns: "..."

// Prompt caching is separate from previous_response_id and remains
// provider/model dependent. Current OpenAI docs describe a 30 minute minimum
// lifetime for GPT-5.6+ cache entries after their latest write/reuse; earlier
// models and retention modes can range from minutes up to 24 hours. Reusing an
// AiContext preserves the conversation prefix but never guarantees a cache hit.
// https://developers.openai.com/api/docs/guides/prompt-caching
