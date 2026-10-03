<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\ImagePrompt;

require dirname(__DIR__) . '/vendor/autoload.php';

$context = new AiContext(
    prompts: ['Kontext: Ein Fachartikel für Praxisinhaber wird klassifiziert.'],
    options: ['model' => 'gpt-5-mini'],
);

$tag = $context->choice('Welcher Tag passt am besten?', ['news', 'guide', 'review']);
// returns: 'guide'

// prompt=null uses: "Choose exactly one option that best matches the current context."
$tag = $context->choice(
    null,
    [
        'news' => 'Aktuelle Nachricht oder neue Entwicklung.',
        'guide' => 'Konkrete Anleitung oder Vorgehensweise.',
        'review' => 'Bewertung oder Vergleich.',
    ],
    options: 'gpt-5-mini',
);
// returns: 'guide'

$priority = $context->choice(
    'Welche Priorität passt?',
    [
        10 => 'Blockiert einen laufenden Prozess und muss sofort bearbeitet werden.',
        20 => 'Normale Bearbeitung ohne akuten Blocker.',
    ],
);
// returns: 20

$tags = $context->choices(
    'Welche Tags passen?',
    ['news', 'guide', 'review'],
    min: 1,
    max: 2,
    options: ['selected' => ['guide']],
);
// returns: ['guide', 'review']

// prompt=null includes min/max in the generated prompt.
$tags = $context->choices(null, ['news', 'guide', 'review'], min: 0, max: 2);
// returns: ['guide']

$ready = $context->yesNo('Ist der Artikel anhand des bisherigen Kontexts veröffentlichungsreif?');
// returns: true

$maybeReady = $context->yesNo(
    'Ist die Quellenlage vollständig genug?',
    allowNull: true,
);
// returns: true, false or null when the context is insufficient

$ranking = $context->rank(
    'Sortiere die Tags nach Relevanz.',
    ['news', 'guide', 'review'],
);
// returns: ['guide', 'news', 'review']

$score = $context->score('Wie gut passt der Artikel zur Zielgruppe?');
// returns: 0.82

$maybeScore = $context->score(
    'Wie sicher ist die Aussage zur noch nicht genannten Zielregion?',
    allowNull: true,
);
// returns: null when the context is insufficient

// With allowNull=false (the default), an explicitly undetermined result raises
// TaskErrorException instead of guessing.

// One image can also seed a context and then be queried repeatedly. Run this
// example with a local screenshot path as its first CLI argument.
if (isset($argv[1])) {
    $imageContext = new AiContext(
        prompts: [ImagePrompt::fromFile($argv[1])],
        options: ['model' => 'gpt-5-mini'],
    );

    $hasForm = $imageContext->yesNo('Ist auf dem Screenshot ein Formular sichtbar?', allowNull: true);
    // returns: true, false or null

    $pageType = $imageContext->choice(
        'Welche Art Seite ist am ehesten zu sehen?',
        ['homepage', 'contact', 'article', 'unknown'],
    );
    // returns: 'contact'

    $visualScore = $imageContext->score(
        'Wie klar ist die visuelle Hierarchie der Seite?',
        allowNull: true,
    );
    // returns: 0.74 or null
}
