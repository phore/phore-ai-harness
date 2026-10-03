<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Phore\AiHarness\ToolType\CallbackTool;

/**
 * Berechnet einen stabilen internen Rabattcode.
 *
 * @param string $customerId Interne Kundennummer, z. B. C-1001.
 * @param int $percent Rabatt in Prozent.
 * @return array{code: string, message: string} Rabattcode und kurze Beschreibung.
 */
function create_discount_code(string $customerId, int $percent): array
{
    return [
        'code' => strtoupper($customerId) . '-' . $percent . 'OFF',
        'message' => 'Rabattcode für ' . $customerId . ' mit ' . $percent . '% Rabatt.',
    ];
}

// CallbackTool registriert ein PHP-callable als OpenAI Function Tool.
// Parameter, @param-Beschreibungen und @return werden über phore/schema gelesen.
$tool = new CallbackTool(
    'create_discount_code',
    name: 'create_discount_code',
);

// Der Callback wird automatisch ausgeführt, wenn OpenAI das Function Tool aufruft.
$response = phore_ai_text([
    'Erzeuge einen Rabattcode für Kunde C-1001 mit genau 15 Prozent Rabatt. Nutze dafür das verfügbare Tool und nenne nur den Code.',
    $tool,
]);

echo $response . "\n";

print_r(get_last_ai_request()?->toArray()['tools'] ?? []);
