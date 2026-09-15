# Frontmatter prompt files

`PromptFile` is the preferred way to load reusable prompts from files. `PromptFile` means that the file defines the prompt itself; `FilePrompt` means that the file itself is attached to a prompt as source material. Frontmatter is the current file format, not the public concept represented by the class name.

## Usage

```php
use Phore\AiHarness\PromptType\PromptFile;

$prompt = new PromptFile(__DIR__ . '/prompts/review.prompt.md');
$result = phore_ai_text($prompt);
```

The Markdown body is the prompt text. YAML frontmatter can define `description`, `extends`, `references`, and `requires_aliases`.

```markdown
---
description: Review an existing PHP change.
extends:
  - path: php.prompt.md
requires_aliases:
  - baseRules
  - projectRules
---

Review the supplied change and report only actionable findings.
```

`extends` and `references` accept either a path string, one mapping, or an ordered list. Mapping entries support:

- `path`: required file path.
- `alias`: optional alias for the included prompt body or referenced file.
- `description`: optional handling instructions for that included prompt body or referenced file.

Every relative `path` is resolved against the directory of the file that declares it. This rule is recursive for both `extends` and `references`: if `review.prompt.md` extends `php.prompt.md`, paths inside `php.prompt.md` are resolved relative to `php.prompt.md`, and paths inside a prompt extended by `php.prompt.md` are resolved relative to that file. This makes complete prompt modules portable between directories.

The example deliberately demonstrates this:

- `prompts/review.prompt.md` extends `php.prompt.md` relative to `prompts/`.
- `prompts/php.prompt.md` extends `shared/base.prompt.md` relative to `prompts/`.
- `prompts/shared/base.prompt.md` references `../../references/project-rules.md` relative to `prompts/shared/`.

`description` at the top level is descriptive metadata only. A `description` attached to an `extends` or `references` entry is passed to the resulting prompt segment as its handling instructions. Unknown frontmatter keys and unknown keys inside `extends`/`references` entries are rejected.

## Resolution order

For `review.prompt.md` the effective prompt order is parent-first and child-last. Resolution is recursive and `extends` entries are processed left to right. The same canonical prompt file is emitted only once at its first resolved position.

A short generated preamble is prepended so the model can see the effective composition:

```text
Main prompt: review.prompt.md
Prompt inheritance: base.prompt.md -> php.prompt.md -> review.prompt.md
```

The main prompt therefore remains the most specific instruction layer.

See [`resolved-agent-input.md`](resolved-agent-input.md) for the ordered `input_text` and `input_file` sections produced by this example, including the exact metadata formatting used for aliased references.

## References

`references` are additional source files, not inherited prompt bodies. They are emitted as normal `FilePrompt` segments. References declared by inherited prompts remain available further down the inheritance chain.

An alias declared on a reference is part of the fully resolved alias space and can satisfy `requires_aliases` in any later derived prompt.

## Required aliases

`requires_aliases` is a string or ordered list of aliases that must exist in the complete request before it is sent.

Aliases can come from:

- externally supplied `TextPrompt`, `FilePrompt`, `ImagePrompt`, `AudioPrompt`, or `StructPrompt` instances;
- aliased `extends` entries;
- aliased `references` entries;
- inherited prompt layers and their references.

Requirements declared by inherited prompts are retained as well. This lets any layer safely depend on context introduced earlier in the inheritance tree or supplied externally.

Example with an external alias:

```php
use Phore\AiHarness\PromptType\PromptFile;
use Phore\AiHarness\PromptType\StructPrompt;

$result = phore_ai_text([
    new StructPrompt($customerData, alias: 'customerContext'),
    new PromptFile(__DIR__ . '/prompts/review.prompt.md'),
]);
```

If a resolved prompt contains `requires_aliases: customerContext`, the request fails before it is sent when that alias is missing.

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
