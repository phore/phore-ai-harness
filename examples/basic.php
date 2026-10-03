<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;

require dirname(__DIR__) . '/vendor/autoload.php';

$context = new AiContext(
    prompts: ['Thema: Relaunch einer Praxiswebsite. Antworte auf Deutsch.'],
    options: ['model' => 'gpt-5-mini'],
);

$title = $context->text('Schlage einen passenden Titel vor.');
$intro = $context->text('Schreibe zu diesem Titel eine Einleitung mit zwei Sätzen.');
$summary = $context->text('Fasse Titel und Einleitung in einem Satz zusammen.');

echo $title . "\n\n";
echo $intro . "\n\n";
echo $summary . "\n";
