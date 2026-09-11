# phore-project-template
Template Repository for phore library projects

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



## Reasoning options

All `phore_ai_*` helper functions send `['effort' => 'low']` by default.
Override the Responses API reasoning settings through the shared options:

```php
$result = phore_ai_text('Explain the status.', [
    'reasoning' => ['effort' => 'medium'],
]);

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

$ai = (new \Phore\AiHarness\PhoreAi())
    ->withLogger(new \Phore\AiHarness\Logging\ConsoleLogger())
    ->withModel('gpt-5-mini');
```

`debug_log` is supported by `phore_ai_text`, `phore_ai_struct`,
`phore_ai_struct_array`, `phore_ai_image`, and `phore_ai_edit_file`. It accepts
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
disabled, the previous non-streaming and immediate-error behavior is preserved.

Tool arguments are redacted recursively; invalid argument JSON is omitted.
Results are logged by byte count, and raw exception messages, request headers
and stack traces are omitted. Recognized credentials in model text are redacted
only in logs. A single final summary includes status, requests, tool calls,
errors, retries, available token totals and monotonic total/API/tool durations
in seconds, including failed runs and structured-output hydration. Missing
usage is `null` (`n/a` in console output); when only some responses report usage,
totals sum those available values.

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
