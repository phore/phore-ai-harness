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
