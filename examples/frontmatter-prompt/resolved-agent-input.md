# Resolved agent input

This file shows the effective request content produced by `prompts/review.prompt.md` after resolving `extends` and `references`.

The paths are resolved from the file that declares them:

- `review.prompt.md` resolves `php.prompt.md` relative to `prompts/`.
- `php.prompt.md` resolves `shared/base.prompt.md` relative to `prompts/`.
- `shared/base.prompt.md` resolves `../../references/project-rules.md` relative to `prompts/shared/`.

This is recursive: an inherited file becomes the new declaring file for its own `extends` and `references`. A prompt module can therefore keep its own relative dependencies when reused from another directory.

The OpenAI request contains one user message with ordered content sections. References are real `input_file` sections and are not concatenated into the surrounding text.

## 1. Provenance preamble — `input_text`

```text
Main prompt: review.prompt.md
Prompt inheritance: base.prompt.md -> php.prompt.md -> review.prompt.md
```

## 2. Inherited base prompt — `input_text`

The alias and description belong to the `extends` entry in `php.prompt.md`, so the converter renders them immediately before the inherited body:

```text
Reference alias: baseRules
Other prompts may refer to this text as `baseRules`.
Text instructions:
General rules that remain binding for PHP work.
Work only within the requested scope.
Prefer small, reviewable changes and make assumptions explicit when they affect the result.
```

## 3. Reference metadata — `input_text`

The reference itself was declared in `shared/base.prompt.md`, therefore its relative path is evaluated from `prompts/shared/`:

```text
Reference alias: projectRules
Other prompts may refer to the following file segment as `projectRules`.
Instructions for the following file segment:
Treat these project rules as binding source material.
```

## 4. Referenced file — `input_file`

The next OpenAI content section is an actual file input. Apart from the machine-specific absolute path and Base64 data, its structure is:

```json
{
  "type": "input_file",
  "filename": "/absolute/resolved/path/examples/frontmatter-prompt/references/project-rules.md",
  "file_data": "data:text/markdown;base64,<encoded file content>"
}
```

The encoded file contains:

```markdown
# Project rules

- Keep changes focused on the requested behavior.
- Prefer existing project conventions over introducing a new abstraction.
- Treat missing required input files as errors instead of silently skipping them.
```

## 5. PHP prompt — `input_text`

```text
Target PHP 8.5.
Use strict typing and keep public APIs explicit.
```

## 6. Main review prompt — `input_text`

```text
Review the supplied change for correctness, regressions, and unnecessary complexity.
Report only actionable findings and order them by severity.
```

## Effective request shape

The relevant part of the Responses API payload therefore has this ordering:

```json
{
  "input": [
    {
      "role": "user",
      "content": [
        {"type": "input_text", "text": "<provenance preamble>"},
        {"type": "input_text", "text": "<baseRules metadata + base prompt>"},
        {"type": "input_text", "text": "<projectRules reference metadata>"},
        {"type": "input_file", "filename": "<resolved project-rules.md path>", "file_data": "<data URL>"},
        {"type": "input_text", "text": "<PHP prompt>"},
        {"type": "input_text", "text": "<main review prompt>"}
      ]
    }
  ]
}
```

The normal default/system instructions are still carried separately in the Responses API `instructions` field.

## Alias validation

Before these sections are sent, the fully resolved request exposes:

```text
baseRules
projectRules
```

`review.prompt.md` requires both aliases, so validation succeeds. The same validation also sees aliases from separately supplied prompt objects outside this `FrontMatterPrompt`.
