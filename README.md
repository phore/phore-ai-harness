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

Use the optional context or per-call option `max_output_tokens` (positive
integer) to limit output. Null or omission uses the provider default.

### API credentials

The default Keystore discovers OpenAI credentials from the environment or
`/var/run/secrets/open_ai` (usually also accessible as `/run/secrets/open_ai`).
To use an application-configured secret file instead, configure the Keystore
before the first AI call:

```php
use Phore\AiHarness\Keystore\Keystore;

Keystore::instance()->addKeyFromFile('/run/secrets/open_ai');
```

No OpenAiClient construction is needed. Existing clients keep their original
credentials; configure the key before creating or using a context.

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
| `phore_ai_speech_to_text()` | transcribe a completed audio recording |
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

### Speech-to-text

Transcribe raw audio bytes (not base64) from a completed recording:

```php
$text = phore_ai_speech_to_text($audioBytes, 'webm');
echo $text;
```

The default model is `gpt-transcribe`. Supported formats include WebM, MP4/M4A,
Ogg, WAV, MP3 and FLAC; the maximum size is 25 MB. Optional named arguments
`model`, `prompt` and `client` let you select the transcription model, supply
vocabulary hints or use a configured `OpenAiClient`. No conversation is needed.

### Edit text and return a separate reply

For a simple string edit, `phore_ai_text()` with `options['input']`
returns the edited string. To edit text and also receive a separate
user-facing reply, pass an `EditableTextPrompt` in the prompt array:

```php
use Phore\AiHarness\PromptType\EditableTextPrompt;

$draft = new EditableTextPrompt('Welcome to our practce.', alias: 'draft');
$message = phore_ai_text([
    'Correct the spelling in draft and explain what changed.',
    $draft,
]);
echo $message; // AI's explanation.
echo $draft->getContent(); // Expected: Welcome to our practice.
echo $draft->getOriginalContent(); // Welcome to our practce.
```

When no conversational reply is needed, use the same editable input with
`phore_ai_do()`. It returns a boolean success flag, not the edited text:

```php
$ok = phore_ai_do(['Rewrite draft as a friendly greeting.', $draft]);
echo $draft->getContent();
```

Both helpers automatically expose the shared editing tools for their inputs.
No explicit context or file is required. Keep the object to retrieve the
latest text or pass it to another call. See [example 17](examples/17-text-edit-tool.php).

### Edit multiple texts and return a structured report

Use `EditableTextPrompt` when an operation must modify one or more independent
texts and also return a useful result for the application. For example, a
website editor can update an introduction, footer and contact section in the
same AI task, while returning a completion report for a UI or another process.
The edited text stays in each input object; the response can be a message
(`phore_ai_text()`), a success flag (`phore_ai_do()`), or a typed DTO
(`phore_ai_struct()`). No need to return complete texts inside the DTO or parse
them out of the model's explanation.

```php
class PendingTextEdit
{
    public string $alias;
    public string $reason;
}

class TextEditReport
{
    public int $completedCount;
    public string $message;

    /** @var PendingTextEdit[] */
    public array $pending;
}

$intro = new EditableTextPrompt('Welcome to our practce.', alias: 'intro');
$footer = new EditableTextPrompt('Contakt us.', alias: 'footer');
$contact = new EditableTextPrompt('Phone: TBD', alias: 'contact');

$report = phore_ai_struct([
    'Correct intro and footer. Replace TBD in contact with our verified phone number. '
    . 'Do not invent missing facts. Complete independent edits even if others are blocked. '
    . 'Count targets whose requested edits were completed. Report unfinished targets by alias and reason.',
    $intro,
    $footer,
    $contact,
], TextEditReport::class);

echo $intro->getContent();   // Expected: Welcome to our practice.
echo $footer->getContent();  // Expected: Contact us.
echo $contact->getContent(); // Expected: Phone: TBD
echo $report->message;
echo $report->completedCount; // Expected: 2
foreach ($report->pending as $pending) {
    echo $pending->alias . ': ' . $pending->reason;
}
```

In this example, two texts can be corrected and the contact section remains
unchanged because no verified phone number was supplied. The expected report
has `completedCount: 2` and a pending entry for `contact` with the reason.
The application can present the report or forward pending tasks for manual
completion or a later AI call. The report is model-generated; use
`hasChanges()` and `getContent()` to inspect actual changes. A failed provider
or invalid structured response can throw an exception; already applied edits
are not automatically rolled back. See [example 17](examples/17-text-edit-tool.php).

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

### Agent Skills

An `AiContext` supports a single mutable `SkillCollection`. Discover local
`SKILL.md` directories recursively; the model receives names and descriptions
first and can load the full instructions, references and assets when necessary:

```php
$context = new \Phore\AiHarness\AiContext();
$context->skills()->addDirectory(__DIR__ . '/.agents/skills');
echo $context->text('Use the relevant skill to complete this task.');
```

By default scripts cannot execute. For trusted skills only, enable *all*
executables under their `bin/` and `scripts/` directories:

```php
$context->skills()->addDirectory(
    __DIR__ . '/trusted-skills',
    options: ['allowExec' => true],
);
```

The same collection can also be registered explicitly with
`$context->addToolSet($skills)`. `add()` supports explicit skills and wildcards
with optional `options: ['initialLoad' => true]`. Names must be unique. References/assets are readable
without granting execution. A single generic set of tools supports all skills.
With `debug_log` enabled, a brief catalog of skill names and one concise
lifecycle event per skill load, resource read or script execution are logged;
contents and command output are omitted. Both `add()` and `addDirectory()`
accept `options: ['execTimeout' => 60]` (seconds; default 30) to terminate
long-running skill scripts and report a timeout error.
See [`examples/15-skills.php`](examples/15-skills.php) and the
[`AI Usage Info`](.ai-usage-info.md) for the complete API and permissions.

### AI file system

Attach an `AiFileSystem` to an existing context to let the AI search, read and
edit selected files within a directory. The policy controls each operation
independently:

```php
use Phore\AiHarness\FileSystem\AiFileSystem;
use Phore\AiHarness\FileSystem\FileSystemPolicy;

$context = new AiContext();
$fileSystem = new AiFileSystem(
    '/srv/website',
    context: $context,
    id: 'website',
    policy: new FileSystemPolicy(
        ignore: ['.git/*', 'vendor/*'],
        searchable: ['docs/*'],
        editable: ['docs/*'],
        creatable: ['docs/generated/*'],
        deletable: ['docs/generated/*'],
        movable: ['docs/drafts/*', 'docs/generated/*'],
    ),
);

$context->do('Correct spelling in docs/contact.md.', throw: true);
$changes = $fileSystem->getChangeSet(); // FileChange DTOs with old/new contents.
```

Create, delete and move are denied by default. Empty allowlists deny the
respective operation; `['*']` permits all paths inside the filesystem boundary.
Ignored paths take precedence; moves require permission for both source and
destination. Changes are applied to real files, not just returned as text.
Use `getChangeSet()` to inspect changes, including created, deleted and moved
files. See [AI file system](docs/ai-filesystem.md) for explicit files, revision
history, path restrictions and the full API.

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

### Resume a single response ID

If the application stores only a trusted provider response ID, rebuild the
same context configuration and set the cursor directly:

```php
use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\SystemPrompt;

$context = new AiContext(
    prompts: [new SystemPrompt('You assist a source code editor.')],
    options: ['model' => 'gpt-5-mini', 'timeout' => 30],
);
$context->text('Initialize the editor context.');
$baseId = $context->getResponseId();

// In another HTTP request, rebuild the same configuration first.
$context = new AiContext(
    prompts: [new SystemPrompt('You assist a source code editor.')],
    options: ['model' => 'gpt-5-mini', 'timeout' => 30],
);
$context->resumeFromResponseId($baseId);
$suggestion = $context->text('Complete this expression: const value =');
$latestId = $context->getResponseId();
$usage = get_last_ai_response()?->getUsage()->toArray();
```

Keep `$baseId` to fork independent suggestions; save `$latestId` to continue
the conversation. Passing null starts a fresh root. Resuming from the ID
clears checkpoints and queued source prompts, and does not validate the
setup hash. Use export/import for checkpoint persistence and setup validation.
Only use IDs authorized by your application for the current user/scope.
Provider retention and context limits still apply; reusing an ID does not
guarantee cached input. The usage export includes `cachedInputTokens` for the
last provider response only, not all tool rounds. `get_ai_usage_stats()`
reports process-wide totals.

For a normal chatbot, return the latest response ID with each answer and send
it back alongside the next user message. No conversation transcript needs to
be stored or resent by the application. The provider retains the conversation
within its retention and context limits. The ID is not an authorization token.
The Harness leaves response storage at the OpenAI default (`store: true`),
which normally retains responses for 30 days, subject to provider data
retention settings. Storage is independent of prompt caching.

See [example 16: Chat and Resume Conversation](examples/16-chat-resume-conversation.php)
for two independent contexts connected by a simulated JSON roundtrip.
Run `php examples/16-chat-resume-conversation.php` with configured credentials.
An actual HTTP endpoint must authorize incoming IDs before resuming them.

### System prompts on follow-up requests

`AiContext` automatically includes prepared `SystemPrompt` instructions again
on subsequent requests, including requests that continue via
`previous_response_id`. The OpenAI Responses API does **not** automatically
carry top-level `instructions` forward when a previous response ID is used.
The harness therefore supplies the currently prepared system instructions
again; callers do not need to repeat them manually. This also applies to the
current skill catalog, which is added as a system prompt for each request.

Keep system prompts **as short as possible and as detailed as necessary**:
include only essential behavioral rules and output requirements. Put extensive
reference material, project data and the current task into the appropriate
regular context/input instead. System instructions sent on every request count
toward input usage; prompt-cache hits can lower cost but are not guaranteed.
`previous_response_id` is conversation continuation, **not** a cache key and
does not make input tokens free.

For example, the same prepared instructions apply to both calls:

```php
use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\SystemPrompt;

$context = new AiContext(prompts: [
    new SystemPrompt('Answer concisely. Use plain text.'),
]);
$first = $context->text('Explain HTTP caching.');
$second = $context->text('Summarize that in one sentence.');
// The harness sends the prepared SystemPrompt again for this follow-up.
```

When restoring a context using `importState()`, rebuild the same prepared
prompts and tools first: exported state contains the response cursor, **not**
the system prompt or other prepared instructions.

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

### Multimodal callback results

Return a `MultiPrompt` from a callback to deliver multiple images, text or
structured source data in one tool response. Each image carries its own
`instructions` and optional `alias`; no separate text prompt is needed.

```php
use Phore\AiHarness\PromptType\ImagePrompt;
use Phore\AiHarness\PromptType\MultiPrompt;
use Phore\AiHarness\ToolType\CallbackTool;

$tool = new CallbackTool(
    static fn (): MultiPrompt => new MultiPrompt([
        ImagePrompt::fromFile('desktop.png', instructions: 'Desktop viewport 1440 × 900. Inspect spacing.'),
        ImagePrompt::fromFile('mobile.png', instructions: 'Mobile viewport 393 × 852. Inspect wrapping.'),
    ]),
    name: 'load_screenshots',
);
```

Register the tool on an `AiContext` as above. Image instructions are
application-provided guidance; text embedded in screenshots remains untrusted
by default. The wrapper is a callback return value, not an ordinary input
prompt. Use a non-empty list of prompt objects that convert to text, images or
files (e.g. `TextPrompt`, `ImagePrompt`, `AiImage`, `StructPrompt` or `FilePrompt`).
Audio output is not supported by the function tool API. Ordinary string, array
and DTO returns retain their previous text/JSON behavior. A vision-capable model
is required for images.

See [example 05](examples/05-callback-tool.php) for a complete runnable
comparison with two screenshot files:
`php examples/05-callback-tool.php desktop.png mobile.png`.

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

### Edit multiple texts while chatting

An `EditableTextPrompt` is a mutable input object. Add one or more to an
`AiContext`; the context supplies one shared set of editing tools automatically.

```php
use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\EditableTextPrompt;

$intro = new EditableTextPrompt('Welcome to our practce.', alias: 'intro');
$footer = new EditableTextPrompt('Contact us.', alias: 'footer');
$context = new AiContext(prompts: [$intro, $footer]);

$message = $context->text('Correct the spelling of intro and summarize.');
echo $message;
echo $intro->getContent(); // Expected: Welcome to our practice.
echo $intro->getOriginalContent(); // Welcome to our practce.

$message = $context->text('Make both texts friendlier.');
echo $intro->getContent();
echo $footer->getContent();
```

The model can apply exact replacements or a single full rewrite
(`search: null`). Each target is addressed by its unique alias; no files are
read or written. `hasChanges()`, `setContent()` and `reset()` allow
application-managed change detection and undo. Keep the same objects for
follow-up calls. For a running conversation, pass an additional editable input
in the `text()` prompt array for that call. Persist text separately from the
response ID or exported context state when resuming in a new PHP request.
See [example 17](examples/17-text-edit-tool.php) and
[context documentation](docs/ai-context.md#bearbeitbare-text-inputs-im-chat).

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

`Phore\AiHarness\Usage\CostEstimator` uses a price snapshot checked on 2026-11-25.
Sources: [OpenAI pricing](https://developers.openai.com/api/docs/pricing)
(including GPT-6.2 Astra, GPT-6.2 Sol, GPT-6.1 Terra and GPT-6 Luna),
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
| gpt-6-cyber | 20 | 2 | 100 |
| gpt-6-rosalind | 10 | 1 | 50 |
| gpt-6-sol | 4 | 0.4 | 15 |
| gpt-6.1-terra | 2 | 0.1 | 7.5 |
| gpt-6.2-astra | 16 | 0.8 | 60 |
| gpt-6.2-sol | 4 | 0.2 | 15 |
| gpt-6-astra | 20 | 2 | 75 |
| gpt-6.1-sol | 4 | 0.2 | 15 |
| gpt-6-luna | 0.2 | 0.02 | 0.75 |
| gpt-5 | 1.25 | 0.125 | 10 |
| gpt-5-mini | 0.25 | 0.025 | 2 |
| gpt-5-nano | 0.05 | 0.005 | 0.40 |

GPT-6.2 Astra/Sol, GPT-6 Astra/Sol/Luna, GPT-6.1 Sol/Terra, GPT-5.6
Sol/Terra/Luna and GPT-5.5 deliberately use their higher long-context standard
rates even for short requests. Other named entries use published standard rates. These are budgeting estimates, not exact billing. For short-context Standard
requests, GPT-6.2 Astra costs 8 / 0.4 / 40 and GPT-6.2 Sol costs 2 / 0.1 / 10
USD per million input / cached input / output tokens. Specialized models may
require additional provider access; promotional prices can change.

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
