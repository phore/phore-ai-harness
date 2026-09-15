# Frontmatter prompt files

Status: API example / design draft. The runtime implementation is intentionally not part of this example-only change.

## Goal

A prompt can live in a Markdown file with YAML frontmatter. The Markdown body is the prompt text. The header may describe the prompt, inherit one or more other prompt files with `extends`, and attach additional files with `references`.

`FilePrompt` already represents a file attachment. The proposed loader therefore uses the distinct name `FrontMatterPrompt`.

## Proposed file format

```markdown
---
description: Review an existing PHP change.
extends:
  - php.prompt.md
references:
  - ../references/project-rules.md
---

Review the supplied change and report only actionable findings.
```

Supported header fields:

- `description`: optional human-readable description; it does not become prompt instructions by itself.
- `extends`: optional string or ordered list of prompt files. Paths are relative to the file that declares them.
- `references`: optional string or ordered list of additional files. Paths are relative to the file that declares them.

Unknown header keys should be rejected instead of silently ignored so spelling mistakes do not change prompt behavior unnoticed.

## Proposed usage

```php
$prompt = FrontMatterPrompt::fromFile(__DIR__ . '/prompts/review.prompt.md');

$result = phore_ai_text($prompt);
```

`FrontMatterPrompt` is proposed API and does not exist yet. The example fixes the expected caller-facing shape before implementation.

## Resolution order

For `review.prompt.md` in this directory the effective prompt order is:

1. generated provenance preamble
2. `base.prompt.md`
3. `php.prompt.md`
4. `review.prompt.md`

The generated preamble is deliberately short and makes the composition visible to the model, for example:

```text
Main prompt: review.prompt.md
Prompt inheritance: base.prompt.md -> php.prompt.md -> review.prompt.md
```

Parents are always emitted before the child that extends them. When `extends` contains multiple files, entries are resolved from left to right. Resolution is recursive. If the same canonical prompt file is reached more than once, it is emitted only once at its first resolved position.

The body of the main prompt is therefore the most specific prompt text and comes last among inherited prompt bodies.

## References

`references` are not inherited prompt bodies. They are additional source files attached to the resolved prompt using the existing file-prompt mechanism. References declared by inherited prompts remain part of the resolved result.

Reference order is deterministic: prompts are processed in effective inheritance order, and each prompt's references are attached in the order listed in its frontmatter.

For the files in this example, `project-rules.md` is attached because `php.prompt.md` references it.

## Errors

Loading must fail immediately when:

- the main prompt file does not exist or cannot be read;
- an `extends` target does not exist or cannot be read;
- a `references` target does not exist or cannot be read;
- YAML frontmatter is invalid;
- a referenced prompt has no valid frontmatter block;
- an inheritance cycle is detected;
- a header field has an invalid type.

A cycle such as `a.prompt.md -> b.prompt.md -> a.prompt.md` must include the involved files in the error message. Missing-file errors must include both the missing path and the prompt file that declared it.

## Why `extends` and `references` are separate

`extends` changes the instruction stack. It composes reusable prompt instructions and therefore participates in inheritance ordering.

`references` adds material the prompt may use, but does not turn that material into inherited instructions. This keeps source material, project rules, examples, schemas, or documents separate from the prompt hierarchy.
