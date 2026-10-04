<?php

declare(strict_types=1);

use Phore\AiHarness\Content\AiCode;
use Phore\AiHarness\Content\AiDocument;
use Phore\AiHarness\Content\AiImage;

require dirname(__DIR__) . '/vendor/autoload.php';

// Raw mail attachment: no temporary file required.
$attachmentBytes = file_get_contents(__DIR__ . '/fixtures/example.pdf');
$cv = AiDocument::fromRaw(
    rawData: $attachmentBytes,
    fileName: 'lebenslauf.pdf',
    description: 'Application document received by email.',
);

$isCv = $cv->ai_yes_no('Is this document a CV?');
$text = $cv->extractText();

// Streams are accepted as well.
$imageStream = fopen(__DIR__ . '/fixtures/example.png', 'rb');
$image = AiImage::fromStream(
    stream: $imageStream,
    fileName: 'bewerberfoto.png',
    description: 'Applicant photo.',
);
$webInput = $image->resizedToFit(1600, 1600);

// Code carries language/version metadata.
$code = AiCode::fromRaw(
    rawData: '<?php echo "hello";',
    language: 'php',
    version: '8.5',
    fileName: 'Action.php',
);
$summary = $code->ai_text('Summarize what this code does in three bullets.');
