<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class OfferData
{
    /**
     * @param list<string> $services
     */
    public function __construct(
        public string $title,
        public int $priceEur,
        public array $services,
    ) {
    }
}

$input = 'Angebot: Website-Check für 490 EUR. Enthalten sind Technik- und SEO-Prüfung.';

// Existing text is edited through options['input']; model/runtime options stay
// local to the call instead of being prepared in a separate config variable.
$short = phore_ai_text('Kürze den Text auf einen Satz.', [
    'input' => $input,
]);
// returns: "Website-Check für 490 EUR inklusive Technik- und SEO-Prüfung."

$isOffer = phore_ai_yes_no(
    "Ist der folgende Text ein konkretes Angebot?\n\n" . $input,
);
// returns: true

$focus = phore_ai_choice(
    "Welcher Schwerpunkt passt am besten?\n\n" . $input,
    [
        'offer' => 'Konkrete Leistung mit Preis oder Angebotscharakter.',
        'release' => 'Nur wählen, wenn eine neue Version oder Veröffentlichung angekündigt wird.',
        'support' => 'Support- oder Hilfsanfrage ohne konkretes Angebot.',
    ],
);
// returns: 'offer'

$structured = phore_ai_struct(
    "Extrahiere Titel, Preis in EUR und enthaltene Leistungen.\n\n" . $input,
    OfferData::class,
);
// returns: OfferData(title: "Website-Check", priceEur: 490, services: ["Technik-Prüfung", "SEO-Prüfung"])

$editSummary = phore_ai_edit_file(
    'Korrigiere nur Rechtschreibung und Zeichensetzung.',
    __DIR__ . '/angebot.md',
);
// returns: short summary of the applied file edit

// Add the same ai_context key to consecutive helper calls when later calls
// should build on earlier results in the same PHP process.
const AI_CONTEXT = 'offer-review';

$summary = phore_ai_text(
    "Analysiere den folgenden Angebotstext kurz.\n\n" . $input,
    ['ai_context' => AI_CONTEXT],
);
// returns: "Der Text bietet einen Website-Check für 490 EUR an ..."

$isOffer = phore_ai_yes_no(
    'Ist der zuvor analysierte Text ein konkretes Angebot?',
    options: ['ai_context' => AI_CONTEXT],
);
// returns: true

$focus = phore_ai_choice(
    'Welcher Schwerpunkt passt zum zuvor analysierten Text?',
    ['offer', 'release', 'support'],
    options: ['ai_context' => AI_CONTEXT],
);
// returns: 'offer'

$structured = phore_ai_struct(
    'Extrahiere aus dem zuvor analysierten Text Titel, Preis und Leistungen.',
    OfferData::class,
    ['ai_context' => AI_CONTEXT],
);
// returns: OfferData(...)

// The helper API is ideal for straight flows. Use AiContext directly when
// checkpoints, rollback, exportState()/importState() or resume are required;
// see 02-context.php.
