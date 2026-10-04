# phore-ai-harness

PHP helpers for the OpenAI Responses API, typed results, shared conversation
contexts and targeted text/file editing. Prefer the global `phore_ai_*`
functions for normal use; their existing signatures remain supported.

## Quick start: functions first

For a one-shot request, start with the global helper. Common runtime options are
shown once here, directly on the call:

```php
echo phore_ai_text('Write a four-line poem about a rainy autumn morning.', [
    'client' => null,
    'model' => 'gpt-5-mini',
    'reasoning' => ['effort' => 'medium'],
    'timeout' => 120,
    'connect_timeout' => 10,
    'debug_log' => true,
]);
```

Normally omit options you do not need. `client => null` resolves the configured
default client/credentials; the default reasoning setting is low effort.

### Convenience functions

Use the helpers for isolated one- or two-shot work:

| Function | Purpose |
| --- | --- |
| `phore_ai_text()` | generate or edit text |
| `phore_ai_do()` | perform a work step without returning user-facing text |
| `phore_ai_choice()` / `phore_ai_choices()` | select one or several values |
| `phore_ai_yes_no()` | boolean decision, optionally `null` |
| `phore_ai_rank()` / `phore_ai_score()` | rank values or return a `0.0..1.0` score |
| `phore_ai_struct()` / `phore_ai_struct_array()` | hydrate one DTO or a DTO list |
| `phore_ai_edit_struct()` | patch an existing DTO |
| `phore_ai_image()` | generate an image |
| `phore_ai_edit_file()` | edit one or more explicit files |
| `get_last_ai_request()` / `get_last_ai_response()` | inspect the latest request/response |
| `get_ai_usage_stats()` | inspect process-wide usage and estimated cost |

A file can be attached directly to a one-shot call:

```php
use Phore\AiHarness\PromptType\FilePrompt;

$summary = phore_ai_text([
    'Summarize the important claims in three bullets.',
    FilePrompt::fromFile('/path/to/File.pdf'),
]);
```

## When several requests belong together

The functional API can share a conversation by repeating exactly the same
`ai_context` name:

```php
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\ToolType\WebAccessTool;

phore_ai_do([
    'Verify the claims in this file against current web sources.',
    FilePrompt::fromFile('/path/to/File.pdf'),
    new WebAccessTool(),
], throw: true, options: ['ai_context' => 'article-review']);

$correct = phore_ai_yes_no(
    'Are the central claims in the previously checked article correct?',
    options: ['ai_context' => 'article-review'],
);

$summary = phore_ai_text(
    'Summarize the corrections that are needed.',
    ['ai_context' => 'article-review'],
);
```

This is the same underlying context mechanism as the object API. The string name
is convenient, but a typo or renamed identifier silently selects another
context. For repeated, stateful work, prefer one `AiContext` object:

```php
use Phore\AiHarness\AiContext;

$context = new AiContext(prompts: [
    FilePrompt::fromFile('/path/to/File.pdf'),
    new WebAccessTool(),
]);

$context->do('Verify the claims in this file against current web sources.', throw: true);
$context->setCheckpoint('verified');
$correct = $context->yesNo('Are the central claims in the checked article correct?');
$summary = $context->text('Summarize the corrections that are needed.');
```

From this point on, the detailed examples use `AiContext`; the helper functions
delegate to the same operations and do not need a parallel explanation.

### State, checkpoints and sessions

`setCheckpoint()` and `rollback()` move only the conversation cursor. They do
not undo file writes, tool side effects or incurred cost. `clone $context`
creates an independent cursor/checkpoint branch while external dependencies are
still shared.

For a chat that spans HTTP requests, persist the exported state in the session
and rebuild the same prepared prompt/tool setup before importing it:

```php
session_start();

$context = new AiContext(prompts: [new WebAccessTool()]);
if (isset($_SESSION['ai_state'])) {
    $context->importState($_SESSION['ai_state']);
}

$answer = $context->text($userMessage);
$_SESSION['ai_state'] = $context->exportState();
```

The export contains provider/cursor metadata and checkpoints, not prompts,
tools, client or model. Provider/setup mismatches fail by default; provider-side
response retention still limits how long a cursor can be resumed.

### Callbacks and `do()`

`CallbackTool` is just another prepared tool on the context:

```php
use Phore\AiHarness\ToolType\CallbackTool;

$context = new AiContext(prompts: [
    new CallbackTool(
        static fn (string $customerId): array => ['customerId' => $customerId, 'status' => 'active'],
        name: 'load_customer',
    ),
]);

$context->do('Load customer C-1001 and retain the relevant facts.', throw: true);
$status = $context->text('What is the customer status?');
```

`do()` is useful when the work or tool side effect matters but no text result is
needed yet. `RecoverableToolException` is the only callback failure returned to
the model as retryable tool feedback; other exceptions abort the run.

### Simple typed decisions

```php
$tag = $context->choice('Which tag fits best?', ['news', 'guide', 'review']);
$tags = $context->choices(null, ['news', 'guide', 'review'], min: 1, max: 2);
$ready = $context->yesNo('Is the draft ready?', allowNull: true);
$ranking = $context->rank('Rank by relevance.', ['news', 'guide', 'review']);
$score = $context->score('How well does the draft fit the audience?');
```

`prompt: null` uses the method's short default prompt. `allowNull: true` permits
`null` when the current context is explicitly insufficient for a reliable
decision.

### Targeted text and file edits

```php
$generated = $context->text('Write a short introduction.');
$edited = $context->text('Correct spelling only.', input: 'Welcome to our practce.');

$summary = $context->file(
    'Correct spelling in both files.',
    ['/path/to/intro.md', '/path/to/contact.md'],
);
```

Text and file edits use exact `search`/`replacement` operations against the
original snapshot. A `null` search is a full rewrite and must be the only
operation for that target. A failed file remains unchanged while other valid
files in the batch may still be written; this is not a cross-file transaction.

See [`examples/01-basic-functions.php`](examples/01-basic-functions.php) first,
then [`examples/02-context.php`](examples/02-context.php). The complete context
contract is documented in [`docs/ai-context.md`](docs/ai-context.md).
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

For reusable prompt files, prepare `PromptFile` directly on the context:

```php
use Phore\AiHarness\PromptType\PromptFile;

$context = new \Phore\AiHarness\AiContext(prompts: [
    new PromptFile(__DIR__ . '/prompts/review.prompt.md'),
]);
$result = $context->text('Run the review.');
```

`PromptFile` means the file defines the prompt. `FilePrompt` means the file is
source material attached to a prompt. YAML frontmatter can compose ordered
`extends`, `references` and `requires_aliases`; see
[`examples/frontmatter-prompt/`](examples/frontmatter-prompt/) for the complete
format and resolved Responses API content.
## Reasoning options

The common options shown in the quick start can be supplied as `AiContext`
defaults or overridden for one call. Reasoning defaults to
`['effort' => 'low']`. Set `reasoning => null` to omit the parameter for models
that do not support it. Supported reasoning fields and effort values depend on
the selected model.
## Optional debug logging

`debug_log` is one of the common options shown in the quick start. Set it on an
`AiContext` to keep the setting for all operations:

```php
$context = new \Phore\AiHarness\AiContext(options: ['debug_log' => true]);
$result = $context->text('Explain the status.');
```

`false` disables logging, `true` logs to STDERR, and a
`Phore\AiHarness\Logging\LoggerInterface` instance receives structured events.
Text and structured requests stream while logging is enabled; images remain
non-streaming. Return values and STDOUT are unaffected.

Only `RecoverableToolException` becomes retryable tool feedback. Other callback,
binding, transport, authentication and serialization errors propagate. The hard
limit is five callback rounds per invocation. Logs redact tool arguments and
known credentials; results are represented by byte counts.

The final `RunStatistics` event contains status, request/tool/error/retry counts,
`tokens_in`, `tokens_out`, `tokens_total`, `tokens_cached` and monotonic
total/API/tool durations. Cached tokens are part of input tokens and are not
counted twice.
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


## Bind a context to a domain object

`AiContextTrait` exposes the high-level `AiContext` operations with an
`ai_` prefix directly on a domain object. Bind a prepared context once with
`ai_set_context()`, prepare one through `ai_prepare()`, or let the first
operation lazily create an empty context.

See `examples/09-context-trait.php` for a complete example. The trait also
forwards checkpoints, state export/import and the provider response ID, so all
operations on the object keep one shared conversation cursor.


## AI content objects

`AiDocument` is the highest-level abstraction for AI-processable content.
Application code should normally work with `AiDocument` and use
`AiDocumentFactory` as the preferred creation entry point for files and raw
attachments:

```php
use Phore\AiHarness\Content\AiDocumentFactory;

$factory = new AiDocumentFactory();
$document = $factory->fromFile(
    '/path/to/lebenslauf.pdf',
    description: 'Applicant attachment.',
    id: 'cv',
    aliases: ['resume', 'application document'],
);

$isCv = $document->ai_yes_no('Is cv a CV?');
$text = $document->extractText();
```

The factory resolves supported MIME types from `ContentType`, selects
specialized `AiText`, `AiMarkdown`, `AiFrontMatter`, `AiImage` and
`AiAudio` documents where appropriate, and allows project-specific document
types through `register()`. Markdown with conventional YAML front matter is
recognized automatically; an optional `headerSchema` accepts either a
`ClassSchema` or a PHP class name. Class names are parsed through `phore/schema`,
including property descriptions, and the resulting schema is used both for AI
guidance and validation.

Each content object also has a unique immutable ID, optional non-unique
aliases and optional document-specific instructions. If no ID is supplied, the
harness generates one and `getId()` returns it. IDs and aliases are included as
trusted metadata, so later prompts can refer to content by names such as
`cv`, `resume` or an automatically generated ID.

Use `$context->getContentById()` for exact lookup without an AI call and
`$context->queryContent()` for natural-language selection. Queries return an
`AiContentResultSet` with `all()`, `first()`, `getById()`, `query()`,
`getContext()` and `withContext()`. Chained queries refine only the previous
subset; `withContext()` starts that subset on a fresh or supplied conversation
branch. Objects using `AiContextTrait` expose the same operations as
`ai_get_content_by_id()` and `ai_query_content()`.

See `examples/12-content-query.php` for the complete file → ID/alias → query →
result-set → new-context flow.

All document objects are immutable. `withContext($context)` returns a copy on
a cloned conversation branch and can also attach content to an already started
context for its next request. `withContext(null)` deliberately detaches the
document into a fresh context.

Content always remains data. Only explicit `AiInstruction` or `SystemPrompt`
instances may enter the provider instruction channel. `AiImage::resizedToFit()`
uses optional GD only when a resize is needed.

See `examples/10-ai-content.php`, `examples/11-front-matter.php`,
`examples/12-content-query.php` and `docs/ai-content.md`.
