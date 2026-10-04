<?php

use Phore\AiHarness\AiContext;
use Phore\AiHarness\Content\AiDocumentFactory;
use Phore\AiHarness\Content\AiImage;

require dirname(__DIR__) . '/vendor/autoload.php';

$factory = new AiDocumentFactory();

$invoice = $factory->fromFile(
    '/path/to/invoice.pdf',
    id: 'invoice',
    aliases: ['Rechnung', 'Liste 123'],
);

$packageFront = $factory->fromFile(
    '/path/to/package-front.jpg',
    id: 'package-front',
    aliases: ['Paketfoto vorne', 'photo'],
    instructions: 'Use this image to assess visible transport damage.',
);

// No explicit ID: the harness creates one automatically.
$packageSide = $factory->fromFile(
    '/path/to/package-side.jpg',
    aliases: ['Paketfoto Seite', 'photo'],
);
$generatedSideId = $packageSide->getId();

$context = new AiContext(prompts: [$invoice, $packageFront, $packageSide]);

// IDs and aliases can be referenced directly in normal prompts.
$comparison = $context->text(
    'Compare Liste 123 with Paketfoto vorne and summarize relevant differences.',
);

$sideDescription = $context->text(
    'Describe the image with ID ' . $generatedSideId . '.',
);

// Exact lookup never calls the model.
$known = $context->getContentById('package-front');
$generated = $context->getContentById($generatedSideId);

// Natural-language selection returns the original AiContent objects.
$damageImages = $context->queryContent(
    'Which images show visible damage to the package?',
);

foreach ($damageImages as $content) {
    if ($content instanceof AiImage) {
        printf(
            "%s: %dx%d\n",
            $content->getId(),
            $content->width,
            $content->height,
        );
    }
}

$all = $damageImages->all();
$first = $damageImages->first();
$front = $damageImages->getById('package-front');

// A chained query sees only the previous result set.
$closeUps = $damageImages->query('Which of these images are close-up views?');

// Start a fresh conversation containing only the selected images.
$freshSubset = $damageImages->withContext();
$summary = $freshSubset->getContext()->text(
    'Summarize the visible damage shown by these selected images.',
);

// Or attach the same subset to an existing conversation branch.
$existingContext = new AiContext();
$continued = $damageImages->withContext($existingContext);
$followUp = $continued->getContext()->text(
    'Use these selected images as the source for the next assessment.',
);

var_dump(
    $comparison,
    $sideDescription,
    $known?->getId(),
    $generated?->getId(),
    $first?->getId(),
    $front?->getId(),
    array_map(static fn ($item): string => $item->getId(), $all),
    count($closeUps),
    $summary,
    $followUp,
);
