# AI content

`AiDocument` is the primary abstraction for AI-processable content. Application
code should normally accept and work with `AiDocument`; concrete classes are
used only when their specialized API is needed. `AiDocumentFactory` is the
preferred creation entry point for files and raw attachment data.

Available specialized types include `AiText`, `AiMarkdown`, `AiFrontMatter`,
`AiCode`, `AiImage`, `AiAudio` and `AiVideo`. All remain untrusted data and
are never promoted to instructions implicitly.

## Preferred: document factory

```php
$factory = new AiDocumentFactory();

$document = $factory->fromFile(
    '/path/to/lebenslauf.pdf',
    description: 'Attachment from the current applicant mail.',
    id: 'cv',
    aliases: ['resume', 'application document'],
);

$isCv = $document->ai_yes_no('Is cv a CV?');
```

`fromFile()` is the preferred entry point for real files and resolves the
content type from the extension. `fromRaw()` is intended for bytes that are
already in memory and accepts
an explicit `ContentType`/MIME type or a filename with a supported extension.
If both are supplied, the explicit content type wins. The small `ContentType`
value object intentionally contains only the mappings supported by the harness.

## Custom document types

Applications can override a built-in type or register a project-specific type.
User registrations take precedence:

```php
$factory->register(
    'application/x-note',
    fn ($raw, $name, $type, $description, $context) =>
        new CustomNote($raw, $name, $description, $context),
    extensions: ['note'],
);

$note = $factory->fromFile('/tmp/customer.note');
```

Registered factories must return an `AiDocument`.

## IDs, aliases and content queries

Every `AiContent` has a unique immutable ID. Set `id:` when the application
already has a stable identifier. Otherwise the harness generates an ID
automatically; retrieve it with `getId()` and use that value in later prompts.
IDs must be unique inside one `AiContext`.

Aliases are optional human-readable alternative names. They do not have to be
unique. Both the ID and aliases are exposed to the model as trusted metadata, so
prompts can refer to content by either name. `instructions` are
document-specific handling notes and default to an empty string.

For files, prefer `fromFile()`:

```php
$factory = new AiDocumentFactory();

$priceList = $factory->fromFile(
    '/path/to/preisliste.pdf',
    id: 'price-list-2026',
    aliases: ['Preisliste', 'Liste 123'],
    instructions: 'Use this as the authoritative current price list.',
);

$photo = $factory->fromFile(
    '/path/to/package.jpg',
    aliases: ['Paketfoto'],
);

$generatedPhotoId = $photo->getId();

$context = new AiContext(prompts: [$priceList, $photo]);

$answer = $context->text(
    'Compare Liste 123 with Paketfoto and summarize relevant differences.',
);

$answerById = $context->text(
    'Use content ' . $generatedPhotoId . ' and describe the visible package.',
);
```

Exact lookup does not call the model:

```php
$priceListAgain = $context->getContentById('price-list-2026');
$imageAgain = $context->getContentById($generatedPhotoId);
```

Natural-language selection uses `queryContent()`. The model can only select
from IDs already registered in the context; the harness validates the returned
IDs and resolves them back to the original `AiContent` objects:

```php
$images = $context->queryContent(
    'Which content items are images showing visible transport damage?',
);

foreach ($images as $content) {
    echo $content->getId() . PHP_EOL;
}

$all = $images->all();
$first = $images->first();
$known = $images->getById($generatedPhotoId);
```

The returned `AiContentResultSet` is itself queryable. A chained query only
sees the previous subset:

```php
$closeUps = $images->query('Which of these are close-up images?');
```

A result set also owns a context containing exactly its selected items.
`withContext()` rebinds that subset. Without an argument it starts a fresh
conversation; with an existing context it clones that conversation branch and
attaches the subset:

```php
$freshImages = $images->withContext();
$summary = $freshImages->getContext()->text(
    'Summarize the damage shown by these images.',
);

$continued = $images->withContext($existingContext);
$answer = $continued->getContext()->text(
    'Relate these selected images to the previous conversation.',
);
```

Objects using `AiContextTrait` expose the same operations as
`ai_get_content_by_id()` and `ai_query_content()`. When a context is exported
and rebuilt later, provide stable explicit IDs so the reconstructed setup hash
and references remain stable.

## Text and Markdown

`AiText` is the generic string-backed document. `AiMarkdown` specializes it
for Markdown. Both expose immutable `edit()` operations. `withContext()`
returns the same content as a new object: passing an existing `AiContext`
clones that conversation branch and attaches the document for the next request,
including when the context has already started. Passing `null` creates a fresh
context and deliberately detaches the document from its previous conversation.

## Front matter

`AiFrontMatter` combines a structured YAML header with an `AiMarkdown` body.
`headerEdit()` edits only metadata, `bodyEdit()` only Markdown content, and
`edit()` may change both. `AiDocumentFactory` detects conventional YAML front
matter automatically for Markdown input. An optional `headerSchema` accepts either a
`Phore\Schema\Schema\ClassSchema` or a PHP class name. A class name is
parsed automatically through `phore/schema`; property types and descriptions
become the JSON Schema supplied to the AI, and every generated header is
validated against the same schema.

```php
$article = AiFrontMatter::fromFile(
    '/path/to/article.md',
    headerSchema: ArticleHeader::class,
    id: 'article',
    aliases: ['page', 'release article'],
);
```

## Instruction boundary

All `AiDocument` content is untrusted source material. Embedded text such as
"ignore previous instructions" stays data. Only `AiInstruction` and
`SystemPrompt` are allowed into the provider instruction channel.

## Images

`AiImage` validates binary image data at construction time and exposes width,
height and MIME type. `resizedToFit()` preserves aspect ratio and keeps the
bound context. GD is required only when an actual resize is necessary.

See `examples/10-ai-content.php` for the document/factory flow,
`examples/11-front-matter.php` for structured front matter and
`examples/12-content-query.php` for IDs, aliases, exact lookup, image queries,
result-set refinement and context rebinding.
