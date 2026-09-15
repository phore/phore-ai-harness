# Resolved agent input

This file shows the effective request content produced by the example `prompts/review.prompt.md` after resolving `extends` and `references`.

The paths are resolved from the file that declares them:

- `review.prompt.md` resolves `php.prompt.md` relative to `prompts/`.
- `php.prompt.md` resolves `shared/base.prompt.md` relative to `prompts/`.
- `shared/base.prompt.md` resolves `../../references/project-rules.md` relative to `prompts/shared/`.

Therefore the same inherited prompt file can be moved into another directory together with its own relative dependencies without changing the caller.

The OpenAI request contains one user message with ordered content sections. File references remain file sections rather than being concatenated into the prompt text.

## 1. Provenance preamble — `input_text`

```text
Main prompt: review.prompt.md
Prompt inheritance: base.prompt.md -> php.prompt.md -> review.prompt.md
```

## 2. Inherited base prompt — `input_text`

The alias and description belong to the `extends` entry in `php.prompt.md`, so they are rendered directly before the inherited prompt body:

```text
Reference alias: baseRules
Other prompts may refer to this text as `baseRules`.
Text instructions:
General rules that remain binding for PHP work.
Work only within the requested scope.
Prefer small, reviewable changes and make assumptions explicit when they affect the result.
```

## 3. Reference metadata — `input_text`

The reference is declared inside `shared/base.prompt.md`. Its relative path is therefore resolved from `prompts/shared/`.

```text
Reference alias: projectRules
Other prompts may refer to the following file segment as `projectRules`.
Instructions for the following file segment:
Treat these project rules as binding source material.
```

## 4. Referenced file — `input_file`

The next content section is a real OpenAI `input_file` segment. Conceptually it is sent as:

```json
{
  "type": "input_file",
  "filename": "/absolute/resolved/path/examples/frontmatter-prompt/references/project-rules.md",
  "file_data": "data:text/markdown;base64,<encoded file content>"
}
```

The file content represented by that segment is:

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

## Alias validation

Before these sections are sent, the complete resolved request contains the aliases:

```text
baseRules
projectRules
```

`review.prompt.md` requires both aliases. The validation therefore succeeds. The same validation also sees aliases from separately supplied prompt objects outside this `FrontMatterPrompt`.
