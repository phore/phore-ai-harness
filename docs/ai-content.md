# AI content

`AiContent` is the common immutable source abstraction for data supplied to the
AI. Content is never promoted to instructions implicitly.

Available types: `AiDocument`, `AiMarkdown`, `AiCode`, `AiImage`,
`AiAudio` and `AiVideo`.

Every type accepts raw bytes, a stream or a file. A file name is optional for
raw/stream input and should be supplied when it carries useful meaning, for
example `bewerberfoto.png` or `lebenslauf.pdf`.

## Raw mail attachment

```php
$document = AiDocument::fromRaw(
    $attachmentBytes,
    fileName: 'lebenslauf.pdf',
    description: 'Attachment from the current applicant mail.',
);

if ($document->ai_yes_no('Is this a CV?')) {
    $text = $document->extractText();
}
```

No temporary file is necessary.

## Instruction boundary

`AiContent` is always untrusted source material. Embedded text such as
"ignore previous instructions" stays data. Only `AiInstruction` and
`SystemPrompt` are allowed into the provider instruction channel.

## Images

`AiImage` validates binary image data at construction time and exposes width,
height and MIME type. `resizedToFit()` preserves aspect ratio and uses GD only
when a resize is actually necessary. GD remains optional for the package.

## Context

Each content object binds one `AiContext`. Without an explicit context a fresh
context is created with the content as prepared source. A supplied fresh context
is cloned and extended with the content.

See `examples/10-ai-content.php`.
