<?php

use Phore\AiHarness\Content\AiDocument;
use Phore\AiHarness\Content\AiDocumentFactory;
use Phore\AiHarness\Content\AiText;

require dirname(__DIR__) . '/vendor/autoload.php';

// Preferred entry point: the factory selects the concrete AiDocument subtype.
$attachmentBytes = file_get_contents(__DIR__ . '/fixtures/example.pdf');
$factory = new AiDocumentFactory();
$document = $factory->fromRaw(
    rawData: $attachmentBytes,
    fileName: 'lebenslauf.pdf',
    description: 'Application document received by email.',
    id: 'cv',
    aliases: ['resume', 'application attachment'],
    instructions: 'Use this as the applicant-provided CV.',
);

$isCv = $document->ai_yes_no('Is this document a CV?');

$coverLetter = $factory->fromRaw(
    rawData: 'Dear team, ...',
    fileName: 'cover-letter.txt',
    id: 'cover-letter',
    aliases: ['letter'],
);

$context = new \Phore\AiHarness\AiContext(prompts: [$document, $coverLetter]);
$matching = $context->queryContent('Which content is part of the applicant CV?');
$selected = $matching->all();

// Plain text is still an AiDocument and can be edited immutably.
$text = AiText::fromRaw('A short draft.');
$editedText = $text->edit('Make this more precise.');

// withContext(null) deliberately detaches content from its previous conversation.
$detached = $editedText->withContext();

// Applications may register their own AiDocument subtype.
final readonly class CustomNote extends AiDocument
{
    public function __construct(
        string $rawData,
        ?string $fileName = null,
        \Phore\AiHarness\Content\ContentType|string|null $contentType = null,
        ?string $description = null,
        ?\Phore\AiHarness\AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ) {
        parent::__construct(
            $rawData,
            $fileName,
            $contentType ?? 'application/x-note',
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
    }
}

$factory->register(
    'application/x-note',
    fn (
        string $rawData,
        ?string $fileName,
        string $contentType,
        ?string $description,
        ?\Phore\AiHarness\AiContext $context,
        ?string $id,
        array $aliases,
        string $instructions,
    ): AiDocument => new CustomNote(
        $rawData,
        $fileName,
        $contentType,
        $description,
        $context,
        $id,
        $aliases,
        $instructions,
    ),
    extensions: ['note'],
);

$custom = $factory->fromRaw('Remember this.', fileName: 'memo.note');

var_dump(
    $isCv,
    array_map(static fn (AiDocument $item): string => $item->getId(), $selected),
    (string) $editedText,
    $detached instanceof AiDocument,
    $custom::class,
);
