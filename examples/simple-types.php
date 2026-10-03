<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;

require dirname(__DIR__) . '/vendor/autoload.php';

$context = new AiContext(
    prompts: ['Kontext: Ein kurzer Fachartikel für Praxisinhaber wird klassifiziert.'],
    options: ['model' => 'gpt-5-mini'],
);

$tag = $context->choice('Welcher Tag passt am besten?', ['news', 'guide', 'review']);
assert(in_array($tag, ['news', 'guide', 'review'], true));

// prompt=null -> "Choose exactly one option that best matches the current context."
$tag = $context->choice(null, [
    'news' => 'Aktuelle Nachricht oder neue Entwicklung.',
    'guide' => 'Konkrete Anleitung oder Vorgehensweise.',
    'review' => 'Bewertung oder Vergleich.',
], 'gpt-5-mini');
assert(in_array($tag, ['news', 'guide', 'review'], true));

$tags = $context->choices('Welche Tags passen?', ['news', 'guide', 'review'], min: 1, max: 2);
assert(count($tags) >= 1 && count($tags) <= 2);

// prompt=null -> "Choose between 0 and 2 options that best match the current context."
$tags = $context->choices(
    null,
    ['news', 'guide', 'review'],
    min: 0,
    max: 2,
    options: ['selected' => ['guide']],
);
assert(count($tags) <= 2);

$ready = $context->yesNo('Ist der Artikel anhand des bisherigen Kontexts veröffentlichungsreif?');
assert(is_bool($ready));

// prompt=null -> "Answer the current question from the conversation with yes, no, or null when it cannot be decided reliably."
$ready = $context->yesNo(null, allowNull: true);
assert($ready === null || is_bool($ready));

$ranking = $context->rank('Sortiere die Tags nach Relevanz.', ['news', 'guide', 'review']);
assert(count($ranking) === 3);

// prompt=null -> "Rank all options from best match to worst match for the current context."
$ranking = $context->rank(null, ['news', 'guide', 'review']);
assert(count(array_unique($ranking, SORT_REGULAR)) === 3);

$score = $context->score('Wie gut passt der Artikel zur Zielgruppe?');
assert($score >= 0.0 && $score <= 1.0);

// prompt=null -> "Score how well the current context matches the task on a scale from 0.0 to 1.0."
$score = $context->score(null);
assert($score >= 0.0 && $score <= 1.0);
