# Atomic struct patches

Implements the deterministic core, typed editor, single-batch AI helper and stable
array view from `proposals/2026-09-04-struct-patch.md` (§§ 4–9, § 11 steps 1–4).
The existing proposal is retained as the design record.

```php
$edited = phore_ai_edit_struct('Rename customer b to Lina.', $customerList, [
    'addressing' => 'stable', // pointer is the default
    'max_operations' => 20,
]);
```

The input is never mutated. A new instance is released only after the entire patch,
transport validation, JSON Schema validation and hydration succeed. Constructors
must behave as value-object constructors; application-side effects inside user
constructors cannot be rolled back by this library. Constructor failures are
sanitized, and constructors cannot silently alter supplied JSON values. Missing
optional fields use only defaults/nullability declared by the target schema.

## Deterministic API

```php
use Phore\AiHarness\Patch\{JsonPatch, JsonPatchApplier, PatchApplyOptions, StructPatcher};

$patch = JsonPatch::fromArray([
    ['op' => 'test', 'path' => '/title', 'value' => 'Original'],
    ['op' => 'replace', 'path' => '/title', 'value' => 'Edited'],
]);
$result = (new StructPatcher())->apply($target, $patch, ['return_patch' => true]);
$edited = $result->value;

// Independent of AI and phore/schema:
$jsonResult = (new JsonPatchApplier())->apply(
    json_decode('{"title":"Original"}'), $patch, new PatchApplyOptions(),
);
```

All six RFC 6902 operations are implemented; `move` and `copy` require an explicit
allowlist entry. JSON objects are `stdClass`, arrays are PHP lists. Associative PHP
arrays normalize to objects. Use `(object) []` for `{}` and `[]` for `[]`.
Values are detached on construction and access (`JsonPatchOperation::value()`).
`fromArray()` distinguishes a missing value from an explicit null. Operations use
sequential RFC 6901 pointers, including empty-root pointers, empty property names,
`~0`, `~1` and array append `-`. Root mutations require explicit opt-in. A generic
root removal sets `documentExists=false`; its hash uses the domain marker
`SHA256("undefined")`, distinct from JSON null. A struct root cannot be removed.

## Options

| Option | Default | Meaning |
|---|---|---|
| `mode` | `patch` | Only patch is currently supported; auto/replace fail before a model request. |
| `addressing` | `pointer` | Standard document or stable transport view. |
| `allowed_operations` | add, remove, replace, test | Other operations are rejected locally. |
| `max_operations` | 100 | Maximum operations in one batch. |
| `max_patch_bytes` | 65536 | Both raw provider envelope and decoded patch must fit. |
| `max_document_bytes` | 4194304 | Bounds original, transport and intermediate JSON. |
| `max_depth` | 64 | Bounds document and pointer depth. |
| `require_tests` | `none` | `arrays` guards positional remove/replace and move sources; `all` guards every remove/replace/move source. |
| `expected_hash` | null | Expected `JsonValue::hash($target)`; checked before application. |
| `forbidden_paths` | `[]` | Protected pointer prefixes in the chosen addressing view; ancestors and overlapping sources are also blocked. |
| `allow_root_replacement` | false | Explicit opt-in for root changes. |
| `identity_pointers` | `[]` | Map of original array pointer to identity pointer within each element. |
| `dry_run` | false | Validate and hydrate a detached candidate, return metadata; never persist. |
| `return_patch` | false | Helper returns `PatchApplyResult`, including the native patch. |
| `client`, `model`, `timeout`, `connect_timeout` | existing helper defaults | Existing client configuration. |

`StructPatcher::apply()` always returns `PatchApplyResult`; the helper returns the
new object by default, or the result when `dry_run`/`return_patch` is enabled.
Result fields: `status`, `oldHash`, `newHash`, `operationCount`, `changedPaths`,
`value`, `documentExists`, `patch`. Paths report successful mutating operation
targets (and move sources), including explicit no-op replacements; they use the
transport representation in stable mode. Hashes always refer to the normalized
struct, with recursively sorted object keys and preserved list order. This is a
library-local canonical format, not an RFC 8785 implementation.

The helper snapshots and rechecks the source hash across the model call even when
the caller supplies no hash. This is an in-memory conflict check; external stores
must compare-and-swap the old hash inside their own transaction before persistence.

A preceding successful test must cover the mutation path, or an identity/value
inside the exact array element being removed/replaced. Test guards do not make
indices refer to the original document. Forbidden paths also stay unchanged across
array insertions/removals, preventing indirect index-shift bypasses.

## Stable transport

```json
{"items":{"$order":["i_...","e_1"],"$values":{"i_...":{"id":"a"},"e_1":{"name":"No ID"}}}}
```

Use `StableArrayView::encode()` to inspect the view before constructing a local
patch. Scalar `id`, `key`, `uuid` values are preferred; duplicate automatic IDs
and missing IDs receive request-local ephemeral keys. Explicit identities (e.g.
`['/items' => '/metadata/uuid']`) must exist, be scalar and be unique. Transport
keys are separate from business IDs. Keep existing keys during the batch; changes
to an item's business ID do not retarget later operations. Encoding is deterministic
for an unchanged request snapshot; ephemeral keys have no cross-request meaning.

Delete by removing both the `$values` entry and its `$order` reference. Add both
the entry and a new unique key in `$order`. Reorder by replacing `$order`. Every
nested/new list must use the same wrapper, except `$order` itself. Decoding rejects
duplicate/order-only/value-only entries and malformed wrappers. Original objects
with reserved `$order` or `$values` properties are rejected; use pointer mode for
such documents. Stable transport is patched using ordinary RFC 6902 semantics.

## Strict model output

The native local patch uses RFC `value`. OpenAI's strict object schemas require
`additionalProperties:false` and all properties to be required. An unconstrained
JSON object/map is therefore not a valid generic strict `value` schema. The
provider boundary explicitly encodes it as `value_json`:

```json
{"unsupported":false,"operations":[{"op":"replace","path":"/title","value_json":"\"Edited\"","from":null}]}
```

`StructPatchOutput::parse()` decodes this into native `value: "Edited"`. JSON null
is `value_json: "null"`; unused values use `value_json: null`. Objects, arrays and
scalars roundtrip without loss of object/list distinctions. The strict envelope
does not replace local validation. This transport adaptation is confined to the
provider boundary and is not a new JSON Patch dialect.

The prompt contains the current target, target schema, limits, sequential rules
and data-derived examples (field replacement and combined list operations when
those shapes exist). Output is one request with one batch. Tool inputs are
rejected before execution; no callbacks, repair rounds or silent replacement
fallback run. Unsupported requests fail closed. See the
[OpenAI Structured Outputs contract](https://developers.openai.com/api/docs/guides/structured-outputs).

Exceptions distinguish validation, failed tests, limits and hash conflicts.
Operation errors expose index/op/path/errorCode, never target values or chained
constructor exceptions. `StructSchemaValidator` supports the vocabulary generated
by `phore/schema`; class references become JSON Schema definitions, including
recursive classes. Unsupported keywords or unresolved references fail closed
rather than allowing unvalidated hydration.

## Verification and evaluation

```sh
vendor/bin/phpunit --filter 'JsonPatchTest|StableArrayViewTest|StructPatcherTest|StructPatchOutputTest'
vendor/bin/phpunit -c e2etests/phpunit.xml.dist --filter StructPatchE2eTest
php examples/struct-patch-eval.php > struct-patch-eval.jsonl
```

Unit tests include RFC appendix examples, copy isolation, nulls, pointer escaping,
root deletion/recreation, invalid indices, atomic failures, policy bypasses, schema
failures, nested hydration, constructor failures, stable roundtrips and a single
HTTP request through the real client with a local fixture response.

Live tests require a configured API key. The manual eval makes 27 paid requests
across small/medium/large documents, field/list/no-op tasks and replacement/pointer/
stable modes. It reports exact state equality, application/schema success,
unexpected paths, actual usage tokens, request count, latency and errors as JSONL.
Invalid-input/conflict cases are deterministic unit tests, not model-quality runs.
No live quality or latency claim is made without recorded measurements. Automatic
mode selection, repair, agent tools and the other patch families remain deferred
as specified in § 11 step 5; pointer mode remains the explicit helper default.
