# phore-ai-harness

PHP helpers for the OpenAI Responses API, typed results, shared conversation
contexts and targeted text/file editing. Prefer the global `phore_ai_*`
functions for normal use; their existing signatures remain supported.

## Quick start: functions first, contexts when needed

Examples require `vendor/autoload.php` and configured credentials. Each direct
`AiContext` call below is an alternative to the corresponding global call.

For the shortest stateful example, see [`examples/basic.php`](examples/basic.php):
it configures `AiContext` with an inline options array and then runs several
prompts in sequence on the same conversation.

```php
$text = phore_ai_text('Write a short introduction.');
$edited = phore_ai_text('Correct spelling only.', [
    'input' => 'Welcome to our practce.',
]);

// Direct alternative with the new signature: prompts, input, options.
$context = new \Phore\AiHarness\AiContext();
$text = $context->text('Write a short introduction.');
$edited = $context->text('Correct spelling only.', input: 'Welcome to our practce.');
```

`text()` generates when input is `null` and edits when it is a string, including
an empty string. Editing returns the complete text assembled locally from the
model's replacements, not a separately generated copy of the whole result.

| Preferred helper | Direct `AiContext` equivalent |
| --- | --- |
| `phore_ai_do($prompts, $throw, $options)` | `$context->do($prompts, $throw, $options)` |
| `phore_ai_text($prompts, $options)` | `$context->text($prompts, $input, $options)` |
| `phore_ai_edit_file($prompts, $paths, $class, $options)` | `$context->file($prompts, $paths, ['output_class' => $class] + $options)` |
| `phore_ai_struct($prompts, Dto::class, $options)` | `$context->struct($prompts, Dto::class, $options)` |
| `phore_ai_edit_struct($prompts, $object, $options)` | `$context->struct($prompts, $object, $options)` |
| `phore_ai_struct_array($prompts, Dto::class, $options)` | `$context->structArray($prompts, Dto::class, $options)` |
| `phore_ai_image($prompts, $options)` | `$context->image($prompts, $options)` |

The helpers' argument names and return types are preserved. Their bodies only
resolve a context and delegate to the operation traits. The context has no
redundant `editText()`, `editFile()` or `editStruct()` methods.

Use `do()` when only the prepared conversation state or tool side effect is
needed. It returns `true`/`false`; `throw: true` raises `DoException`, or
a `DoException` subclass can be supplied for a domain-specific failure type.
See [`examples/do.php`](examples/do.php) for a callback, checkpoint and
following file edit.

## Reuse a context, ask questions, branch and roll back

All helpers accept `options['ai_context']`: a non-empty registry ID, an
`AiContext` instance, or `null`. Without it, each call remains isolated.
A named context is created on first use and reused within the PHP runtime.

```php
phore_ai_text('Project briefing: a practice website relaunch. Acknowledge.', [
    'ai_context' => 'website',
]);
$title = phore_ai_text('Suggest a title based on the briefing.', [
    'ai_context' => 'website',
]);

// Direct alternative with prepared context.
$context = new \Phore\AiHarness\AiContext(
    prompts: ['Project briefing: a practice website relaunch.'],
    options: ['model' => 'gpt-5-mini'],
);
$title = $context->text('Suggest a title based on the briefing.');
```

Prepare recurring prompts and tools directly on `AiContext`. `CallbackTool` is
not a separate registration mechanism; it is a normal `ToolType` in `prompts`.

```php
$config = \Phore\AiHarness\AiOptions::fromArray([
    'model' => 'gpt-5-mini',
    'debug_log' => true,
]);

$context = new \Phore\AiHarness\AiContext(
    prompts: [
        new \Phore\AiHarness\PromptType\PromptFile(__DIR__ . '/review.prompt.md'),
        new \Phore\AiHarness\ToolType\WebAccessTool(),
    ],
    options: $config,
);
```

`AiOptions::fromArray()` also accepts an existing `AiOptions` instance and
returns it unchanged. Unknown array keys are rejected instead of ignored.

```php
$context->setCheckpoint('briefing');
$variantA = phore_ai_text('Write a factual version.', ['ai_context' => $context]);
$context->rollback('briefing');
$variantB = $context->text('Write a more personal version.');

$context->setCheckpoint(); // Anonymous marker.
$trial = $context->text('Try another structure.');
$context->rollback();      // Restore the most recently set marker.

$branch = clone $context;  // Independent conversation cursor and markers.
$branch->text('Explore a separate alternative.');
```

Checkpoint names are optional. Reusing a name replaces that marker and makes
it the newest. Rollback does not consume markers. **It only restores the
conversation cursor, not written files, tool side effects or incurred
usage/costs.** Clones still share external client/tool dependencies.

The registry is process-local, not persistent or shared between workers. Use
separate IDs per job/user and `AiContextRegistry::forget($id)` or `clear()` at
appropriate lifecycle boundaries. A context cannot run concurrently or
reentrantly. Per-call model overrides are supported where the provider permits
continuation; response chaining does not guarantee a cache hit, particularly
across models. Cache warming is intentionally not implemented.

See the [complete context guide](docs/ai-context.md) for paired examples of
every operation, prepared prompts/tools, registry lifecycle, checkpoint
semantics, options and error handling. [examples/ai-context.php](examples/ai-context.php)
is a runnable CLI example; it makes real, billable model calls when executed.

## Targeted text and multi-file edits

```php
$summary = phore_ai_edit_file(
    'Correct spelling in both files.',
    ['intro.md', 'contact.md'],
    options: ['ai_context' => 'website'],
);

// Direct alternative. Input is the required target path or list of paths.
$summary = (new \Phore\AiHarness\AiContext())->file(
    'Correct spelling in both files.',
    ['intro.md', 'contact.md'],
);
```

Text and file edits share exact `search`/`replacement` operations. Multiple
non-overlapping replacements are matched against the same original snapshot.
A `null` search means a full rewrite and must be the only operation for that
target. There is no automatic full-rewrite fallback for an ambiguous search.

One `write_files` callback can contain multiple files. A failed edit leaves
its entire file untouched while other valid files are kept; corrections only
need to resend failed files. Unresolved results are exposed through
`FileEditException::$files`. Five callback rounds, including questions and
corrections, are the total limit per invocation, with or without logging.

Only explicitly supplied UTF-8 text targets may be edited. Missing files can
be created in existing directories; unreadable files fail instead of being
mistaken for empty files. Candidates are fully prepared before replacing a
file, and source changes are checked. This is not a cross-file transaction or
a substitute for application-level synchronization with external writers.
Domain validation and linting remain separate application steps.

## Git Submodules

Beim Klonen direkt mit auschecken:

```bash
git clone --recurse-submodules <repo-url>
```

Nachträglich initialisieren oder aktualisieren:

```bash
git submodule update --init --recursive
git submodule update --remote --merge
```

## Preferred: prompts from files

For reusable file-based prompts, prefer `PromptFile`. The constructor takes the prompt filename directly:

```php
use Phore\AiHarness\PromptType\PromptFile;

$result = phore_ai_text(
    new PromptFile(__DIR__ . '/prompts/review.prompt.md')
);

// Direct alternative:
$result = (new \Phore\AiHarness\AiContext())->text(
    new PromptFile(__DIR__ . '/prompts/review.prompt.md')
);
```

`PromptFile` means that the file defines the prompt itself. `FilePrompt` means that the file is attached to an existing prompt as source material. The distinction is semantic; YAML frontmatter is the current `PromptFile` storage format, not the concept represented by the class name.

The file body is the prompt text. YAML frontmatter can compose prompt files with ordered `extends`, attach source files with `references`, and declare `requires_aliases` as a safety contract. `extends` and `references` entries can be a simple path or a mapping with `path`, optional `alias`, and optional `description`. Every relative path is resolved from the directory of the file that declares that entry, recursively through inherited prompts. Aliases introduced by inherited prompts and references remain available to later derived prompts and count toward `requires_aliases`; externally supplied aliased prompts count as well. Missing files, invalid frontmatter, inheritance cycles, and missing required aliases fail before the AI request is sent.

See [`examples/frontmatter-prompt/`](examples/frontmatter-prompt/) for the complete format, nested relative-path example, and the resolved Responses API content layout.

## Reasoning options

All `phore_ai_*` helper functions send `['effort' => 'low']` by default.
Override the Responses API reasoning settings through the shared options:

```php
$result = phore_ai_text('Explain the status.', [
    'reasoning' => ['effort' => 'medium'],
]);

// Direct context alternative:
$result = (new \Phore\AiHarness\AiContext())->text('Explain the status.', options: [
    'reasoning' => ['effort' => 'medium'],
]);

// The existing lower-level facade is also retained.
$ai = (new \Phore\AiHarness\PhoreAi())
    ->withReasoning(['effort' => 'high']);
```

`withReasoning()` clones the facade. Settings also apply to streaming, image,
structured-output and callback follow-up requests. Use `'reasoning' => null`
(or `withReasoning(null)`) to omit the parameter for models that do not support
reasoning. The array is passed through unchanged; supported fields and effort
values depend on the selected model. See the
[OpenAI reasoning guide](https://developers.openai.com/api/docs/guides/reasoning).

## Optional debug logging

```php
$result = phore_ai_text('Explain the status.', ['debug_log' => true]);

// Direct context alternative:
$result = (new \Phore\AiHarness\AiContext())->text('Explain the status.', options: [
    'debug_log' => true,
]);

$ai = (new \Phore\AiHarness\PhoreAi())
    ->withLogger(new \Phore\AiHarness\Logging\ConsoleLogger())
    ->withModel('gpt-5-mini');
```

`debug_log` is supported by `phore_ai_do`, `phore_ai_text`, `phore_ai_struct`,
`phore_ai_struct_array`, `phore_ai_edit_struct`, `phore_ai_image`,
`phore_ai_edit_file`, and their `AiContext` methods. It accepts
`false` (the default), `true` (console output on STDERR), or a
`Phore\AiHarness\Logging\LoggerInterface` implementation. Invalid values,
including `null`, throw `InvalidArgumentException` before a request is sent.
`withLogger()` clones the facade; `withLogger(null)` disables logging again.

Debug text and structured-output requests stream. Every console line starts
with `[model]`; partial lines are flushed at response end. Images remain
non-streaming but produce lifecycle and statistics events. Return values and
STDOUT are unaffected. Warnings are red only on a terminal that supports color;
`NO_COLOR` disables color. Custom loggers implement `log(LogEvent $event)` and
receive sanitized events (`run_start`, `request_start`, `text_delta`,
`response_end`, `tool_start`, `tool_result`, `tool_end`, `warning`, `stats`).
Text events are buffered through complete lines before redaction. `runId`
identifies each invocation; the final event contains an immutable
`RunStatistics` object. Logger exceptions are isolated from the AI operation.

As in the current tool-error contract, only an explicit `RecoverableToolException`
produces an `ok: false` tool output so the model can correct its call. Logging
never changes which callback errors are recoverable. Five
callback rounds are the hard limit (`PhoreAi::MAX_CALLBACK_ROUNDS`); unresolved
calls after that limit throw. All other exceptions, including argument decoding/binding errors,
`TaskErrorException`, transport/authentication failures, internal PHP errors and
result-serialization failures, propagate unchanged without retries. Each failed callback counts as one error; one follow-up request
counts as one retry even if multiple calls failed in that round. With logging
disabled, requests remain non-streaming; recoverability, the round cap and
follow-up instruction/schema retention are identical.

Tool arguments are redacted recursively; invalid argument JSON is omitted.
Results are logged by byte count, and raw exception messages, request headers
and stack traces are omitted. Recognized credentials in model text are redacted
only in logs. A single final summary includes status, requests, tool calls,
errors, retries, available token totals and monotonic total/API/tool durations
in seconds, including failed runs and structured-output hydration. Missing
usage is `null` (`n/a` in console output); when only some responses report usage,
totals sum those available values.

The final statistics also include `tokens_cached`, summed from
`usage.input_tokens_details.cached_tokens`. A reported zero remains `0`;
missing data remains `null`/`n/a`. Cached tokens are a subset of `tokens_in`,
not additional tokens. Custom loggers access the same field through
`$event->statistics?->tokens_cached`.

## Global usage and estimated cost

All `OpenAiClient` instances automatically accumulate process-local usage,
independently of debug logging, including facade/helper calls, callback follow-ups,
streaming, images and `AiRequestSpooler`. No setup is required.

```php
phore_ai_text('First task');
phore_ai_text('Second task', ['model' => 'gpt-5-nano']);

$stats = get_ai_usage_stats();
printf(
    "%d requests, %d errors, %d input / %d output / %d total tokens; approx. %s USD\n",
    $stats['requests'], $stats['errors'],
    $stats['inputTokens'], $stats['outputTokens'], $stats['totalTokens'],
    $stats['totalCostUsd'] === null ? 'unknown (partial usage/prices)' : number_format($stats['totalCostUsd'], 6),
);
printf("Cached input tokens: %d\n", $stats['cachedInputTokens']);
print_r($stats['models']); // Same counters and cost fields, keyed by response model.
```

`requests` counts client request attempts (including setup failures), not facade
runs or individual tools; every callback follow-up is another request.
`errors` counts failed client attempts, HTTP failures, failed/incomplete/cancelled
response statuses and stream callback aborts, once per request. Errors in local
tools, hydration or spooler result callbacks after a successful API response
are not API errors. `pendingRequests` reports attempts still in progress.

Tokens are summed only from reported usage. Cached input and reasoning output
are subsets, not added again to totals. The response model takes precedence;
the requested model is the fallback. Missing usage increments
`missingUsageRequests`; only absent/blank model names remain `unpricedRequests`.
Unknown named models receive a conservative fallback. `fallbackRequests` counts
these requests; each model row includes `pricingSource` and
`pricesPerMillionTokensUsd`. `knownCostUsd` includes fallback estimates.
`knownCostUsd` always contains the priced subtotal. `totalCostUsd` is null
when pending requests, missing usage or unknown prices prevent a complete estimate.
Reading stats returns a detached snapshot and never clears the counters.
State lasts for the PHP runtime (one request in typical PHP-FPM, whole execution
in CLI/long-lived workers); separate processes are not combined.

`Phore\AiHarness\Usage\CostEstimator` uses a price snapshot checked on 2026-09-11.
Sources: [OpenAI pricing](https://developers.openai.com/api/docs/pricing),
[GPT-5.5](https://developers.openai.com/api/docs/models/gpt-5.5),
[GPT-5.5 Pro](https://developers.openai.com/api/docs/models/gpt-5.5-pro),
[GPT-5.4](https://developers.openai.com/api/docs/models/gpt-5.4),
[Mini](https://developers.openai.com/api/docs/models/gpt-5.4-mini),
[Nano](https://developers.openai.com/api/docs/models/gpt-5.4-nano),
[GPT-5.2](https://developers.openai.com/api/docs/models/gpt-5.2),
[GPT-4.1](https://developers.openai.com/api/docs/models/gpt-4.1) and
[GPT-5](https://developers.openai.com/api/docs/models/gpt-5).

| Model | Input USD/1M | Cached input USD/1M | Output USD/1M |
|---|---:|---:|---:|
| chat-latest | 5 | 0.5 | 30 |
| gpt-4.1 | 2 | 0.5 | 8 |
| gpt-5.2 | 1.75 | 0.175 | 14 |
| gpt-5.3-codex | 1.75 | 0.175 | 14 |
| gpt-5.4 | 2.5 | 0.25 | 15 |
| gpt-5.4-mini | 0.75 | 0.075 | 4.5 |
| gpt-5.4-nano | 0.2 | 0.02 | 1.25 |
| gpt-5.5 | 10 | 1 | 45 |
| gpt-5.5-pro | 30 | no discount | 180 |
| gpt-5.6-cyber | 12.5 | 1.25 | 75 |
| gpt-5.6-luna | 0.4 | 0.04 | 1.8 |
| gpt-5.6-sol | 8 | 0.8 | 30 |
| gpt-5.6-terra | 4 | 0.4 | 18 |
| gpt-6-astra | 20 | 2 | 75 |
| gpt-5 | 1.25 | 0.125 | 10 |
| gpt-5-mini | 0.25 | 0.025 | 2 |
| gpt-5-nano | 0.05 | 0.005 | 0.40 |

GPT-6 Astra, GPT-5.6 Sol/Terra/Luna and GPT-5.5 deliberately use their higher
long-context standard rates even for short requests. Other named entries use
published standard rates. These are budgeting estimates, not exact billing.

Resolution order: explicit model/override, dated snapshot, then case-insensitive
regex matching of complete name segments separated by `- _ . / :`.
Unknown `mini` models use the highest input/output rates among listed mini models
(currently 0.75 / 4.50); unknown `nano` models use 0.20 / 1.25.
Premium segments (`pro|max|ultra|opus|astra`) take precedence over mini/nano.
All other unknown names, including future higher model versions, use at least
40 / 180 USD per million input/output tokens: the component-wise upper envelope
of GPT-6 Astra long-context Fast input and GPT-5.5 Pro output.
Fallbacks assume **no cache discount** and can rise with higher custom rates;
lower overrides never reduce the built-in fallback floor. Exact overrides still win.
`pricingSource` is `exact`, `snapshot`, `mini-fallback`, `nano-fallback`,
`highest-fallback` or `unknown`; `exact` describes name matching, not invoice accuracy.

Pass overrides in USD per million tokens to price additional models or use
updated/custom rates; the resulting snapshot is recalculated from accumulated tokens:

```php
$estimator = new \Phore\AiHarness\Usage\CostEstimator([
    'my-model' => ['input' => 1.0, 'cachedInput' => 0.1, 'output' => 5.0],
]);
$stats = get_ai_usage_stats($estimator);
```

These are rough token-cost estimates, not invoices: hosted tool fees, separate
image/audio generation charges, storage, taxes, service tiers and long-context
surcharges beyond the conservative rates above are excluded. No network pricing lookup or automatic console output
takes place. Only counters are retained, not prompts or response history.
