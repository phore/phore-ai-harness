# Frontmatter prompt files

`FrontMatterPrompt` is the preferred way to load reusable prompts from files.

## Usage

```php
use Phore\AiHarness\PromptType\FrontMatterPrompt;

$prompt = new FrontMatterPrompt(__DIR__ . '/prompts/review.prompt.md');
$result = phore_ai_text($prompt);
```

The Markdown body is the prompt text. YAML frontmatter can define `description`, `extends`, `references`, and `requires_aliases`.

```markdown
---
description: Review an existing PHP change.
extends:
  - base.prompt.md
  - path: php.prompt.md
    alias: phpRules
    description: PHP-specific rules for this review.
references:
  - path: ../references/project-rules.md
    alias: projectContext
    description: Treat these project rules as binding context.
requires_aliases:
  - phpRules
  - projectContext
---

Review the supplied change and report only actionable findings.
```

`extends` and `references` accept either a path string, one mapping, or an ordered list. Mapping entries support:

- `path`: required file path, relative to the file that declares it unless absolute.
- `alias`: optional alias for the included prompt body or referenced file.
- `description`: optional handling instructions for that included prompt body or referenced file.

`description` at the top level is descriptive metadata only. A `description` attached to an `extends` or `references` entry is passed to the resulting prompt segment as its handling instructions.

Unknown frontmatter keys and unknown keys inside `extends`/`references` entries are rejected.

## Resolution order

For `review.prompt.md` the effective prompt order is parent-first and child-last. Resolution is recursive and `extends` entries are processed left to right. The same canonical prompt file is emitted only once at its first resolved position.

A short generated preamble is prepended so the model can see the effective composition, for example:

```text
Main prompt: review.prompt.md
Prompt inheritance: base.prompt.md -> php.prompt.md -> review.prompt.md
```

The main prompt therefore remains the most specific instruction layer.

## References

`references` are additional source files, not inherited prompt bodies. They are emitted as normal `FilePrompt` segments. References declared by inherited prompts remain available further down the inheritance chain.

An alias declared on a reference is part of the resolved alias space and can satisfy `requires_aliases` in any later derived prompt.

## Required aliases

`requires_aliases` is a string or ordered list of aliases that must exist in the complete request before it is sent.

Aliases can come from:

- externally supplied `TextPrompt`, `FilePrompt`, `ImagePrompt`, `AudioPrompt`, or `StructPrompt` instances;
- aliased `extends` entries;
- aliased `references` entries;
- inherited prompt layers and their references.

This lets a prompt safely refer to required context without silently running when that context was omitted.

Example with an external alias:

```php
use Phore\AiHarness\PromptType\FrontMatterPrompt;
use Phore\AiHarness\PromptType\StructPrompt;

$result = phore_ai_text([
    new StructPrompt($customerData, alias: 'customerContext'),
    new FrontMatterPrompt(__DIR__ . '/prompts/review.prompt.md'),
]);
```

If `review.prompt.md` contains `requires_aliases: customerContext`, the request fails before it is sent when that alias is missing.

## Errors

Loading or conversion fails immediately when:

- the main prompt file does not exist or cannot be read;
- an `extends` target does not exist or cannot be read;
- a `references` target does not exist or cannot be read;
- YAML frontmatter is invalid;
- a referenced prompt has no valid frontmatter block;
- an inheritance cycle is detected;
- a frontmatter field or include entry has an invalid type;
- a required alias is missing from the fully resolved request.

A cycle such as `a.prompt.md -> b.prompt.md -> a.prompt.md` includes the involved files in the error. Missing-file errors include both the missing path and the prompt file that declared it.
