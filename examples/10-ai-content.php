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
);

$isCv = $document->ai_yes_no('Is this document a CV?');

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
        ?string $description = null,
        ?\Phore\AiHarness\AiContext $context = null,
    ) {
        parent::__construct($rawData, $fileName, 'text/plain', $description, $context);
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
    ): AiDocument => new CustomNote($rawData, $fileName, $description, $context),
    extensions: ['note'],
);

$custom = $factory->fromRaw('Remember this.', fileName: 'memo.note');

var_dump($isCv, (string) $editedText, $detached instanceof AiDocument, $custom::class);
