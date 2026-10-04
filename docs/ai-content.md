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

$document = $factory->fromRaw(
    rawData: $attachmentBytes,
    fileName: 'lebenslauf.pdf',
    description: 'Attachment from the current applicant mail.',
);

$isCv = $document->ai_yes_no('Is this a CV?');
```

`fromFile()` resolves the content type from the extension. `fromRaw()` accepts
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

Every `AiContent` has a unique immutable ID. Pass `id:` when an application
already has a stable identifier; otherwise the harness generates one. Aliases
are optional human-friendly names and may intentionally occur on several
content objects. `instructions` are document-specific handling notes and
default to an empty string.

```php
$cv = $factory->fromRaw(
    $bytes,
    fileName: 'cv.pdf',
    id: 'cv',
    aliases: ['resume', 'application'],
    instructions: 'Treat as applicant-provided source material.',
);

$context = new AiContext(prompts: [$cv, $photo, $coverLetter]);
$matches = $context->queryContent('Which items are relevant to the CV?');

$all = $matches->all();
$first = $matches->first();
$same = $context->getContentById('cv');
```

`queryContent()` lets the model select only from IDs registered in the
context, validates the returned IDs locally and returns an
`AiContentResultSet`. The result set supports `all()`, `first()`,
`getById()`, another `query()`, and `withContext()` to continue the subset
on a fresh or supplied conversation branch. IDs must be unique inside one
context; aliases do not have to be unique.

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
matter automatically for Markdown input. An optional
`Phore\Schema\Schema\ClassSchema` can be passed as `headerSchema`; it provides
the JSON Schema including field descriptions to the AI and validates every
generated header with `Phore\Schema\Validator\Validator`.

## Instruction boundary

All `AiDocument` content is untrusted source material. Embedded text such as
"ignore previous instructions" stays data. Only `AiInstruction` and
`SystemPrompt` are allowed into the provider instruction channel.

## Images

`AiImage` validates binary image data at construction time and exposes width,
height and MIME type. `resizedToFit()` preserves aspect ratio and keeps the
bound context. GD is required only when an actual resize is necessary.

See `examples/10-ai-content.php` for the document/factory flow and
`examples/11-front-matter.php` for structured front matter.
