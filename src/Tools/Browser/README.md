# Optional Browserless / Chromium tools

This optional module connects an AI Harness context to a Chromium browser through
the Chrome DevTools Protocol (CDP). It supports component development with
screenshots, a sanitized DOM outline, targeted CSS inspection and console events.
All integration code lives in this directory. The harness does not register the
tools or create a browser session automatically.

## Setup and connection

Install the optional PHP driver in the **consuming application**:

```sh
composer require chrome-php/chrome
```

The harness's root `composer.json` and `package.json` must not acquire mandatory
dependencies for optional tools. No local browser is bundled or started by this
module. Run a Chromium/CDP endpoint or Browserless separately, then configure one
of these endpoint forms:

- `http://chromium:9222` or HTTPS for Chromium's JSON discovery endpoint.
- `ws://…` or `wss://…` for a direct CDP/Browserless WebSocket endpoint, including
  any credentials required by that service.

Treat endpoint credentials as application secrets. Constructing `BrowserSession`
and `BrowserToolSet` only prepares objects; the first `browser_open` call checks
the optional driver and connects. A missing `chrome-php/chrome` module or an
unavailable browser raises
`Phore\AiHarness\Tools\Browser\Exception\BrowserUnavailableException` with a
clear cause. Unrelated harness operations remain usable without the driver.

Configure the usual harness API credentials as described in the
[main README](../../../README.md), then register a session explicitly:

```php
use Phore\AiHarness\AiContext;
use Phore\AiHarness\Tools\Browser\BrowserSession;
use Phore\AiHarness\Tools\Browser\BrowserToolSet;

$browser = new BrowserSession(
    endpoint: 'http://chromium:9222',
    allowedOrigins: ['http://preview:8080'],
);
$context = new AiContext();
$context->addToolSet(new BrowserToolSet($browser));

try {
    echo $context->text(
        'Open http://preview:8080/. Take a screenshot, find the primary heading, '
        . 'and inspect only its font-size and line-height. Identify the source '
        . 'of the relevant CSS declarations.'
    );
} finally {
    $browser->close();
}
```

Keep one session per job or user interaction. `AiContext` clones share registered
tool-set objects, so a context clone does not create an isolated browser. Always
close the session in application cleanup, even though the model can also call
`browser_close`.

## Tools and intended workflow

Start with a screenshot and its small DOM outline. Use a CSS selector to find the
component, then inspect only its node and the CSS properties relevant to the
question. Request another page only when the current result does not answer it.

| Tool | Parameters | Result |
| --- | --- | --- |
| `browser_open` | `url`, `width=1280`, `height=900` | Open an allowed preview URL with the requested viewport. |
| `browser_reload` | None | Reload the preview after a build or source change. |
| `browser_screenshot` | `nodeId=''`, `includeDom=true` | Viewport or node image and, optionally, a small DOM outline as a `MultiPrompt`. |
| `browser_get_html` | `nodeId=''`, `depth=3`, `limit=80`, `cursor=''` | A sanitized DOM outline rooted at the document or the selected node. |
| `browser_query_selector` | `selector`, `rootNodeId=''`, `includeShadowDom=false`, `limit=20`, `cursor=''` | Matching element records with reusable node IDs. |
| `browser_get_computed_styles` | `nodeId`, `properties=[]`, `limit=50`, `cursor=''` | The browser's computed CSS values, optionally restricted to exact property names. |
| `browser_inspect_node` | `nodeId`, `properties=[]`, `limit=10`, `cursor=''`, `resolveSources=true` | Geometry and matched, inline, inherited and pseudo-element CSS rule parts with source locations. |
| `browser_get_console` | `levels=[]`, `limit=20`, `cursor=''` | Recent captured console events and exceptions with bounded argument summaries. |
| `browser_close` | None | Release the tool session and its resources. |

### Computed-style filtering

`properties` is optional. Supplying names returns only those computed properties;
omitting it or passing `[]` exposes **all computed styles through bounded pages**.
There is no implicit shortlist of properties. A filter accepts at most 50 names,
each at most 128 bytes. Use CSS spelling such as `font-size`, not the JavaScript
spelling `fontSize`.

The PHP methods expose the same behavior without a model call. Within the
application session's `try` block, for example:

```php
$browser->open('http://preview:8080/');
$matches = $browser->querySelector('h1');
$nodeId = $matches['nodes'][0]['nodeId'] ?? null;

if ($nodeId !== null) {
    $typography = $browser->getComputedStyles(
        $nodeId,
        properties: ['font-size', 'line-height', 'color'],
    );

    // All computed properties remain accessible; this returns the first page.
    $allStyles = $browser->getComputedStyles($nodeId);
    if ($allStyles['nextCursor'] !== null) {
        $nextStyles = $browser->getComputedStyles(
            $nodeId,
            cursor: $allStyles['nextCursor'],
        );
    }

    // Retrieve declarations and their origins separately from final values.
    $origins = $browser->inspectNode(
        $nodeId,
        properties: ['font-size', 'line-height'],
    );
}
```

The second page is shown only to demonstrate cursor use. An agent should fetch it
when needed, rather than collecting the full list preemptively. Keep the same
node and `properties` filter when continuing a result.

`inspectNode()` does not repeat the computed-style list. Its rule results explain
where declarations come from; matching a rule does not by itself prove that the
rule supplies the final value. Use `getComputedStyles()` to check the browser's
result of the cascade.

Large rules are split into parts of at most eight declarations. `ruleId`, `part`
and `parts` identify the pieces of one rule, so pagination can continue its
remaining declarations without returning the full `cssText`. Inspection filters
also retain a shorthand when CDP identifies a requested longhand within it.
Matched-source inspection collects at most 1,024 rule parts and examines at most
16,384 declarations per snapshot. It includes at most 64 ancestors and 32
pseudo-element groups, and marks scan limits explicitly. This is a bounded
diagnostic selection of source rules; narrow the node or property filter when
`scanTruncated` is true. These source-inspection limits do not impose a fixed
property count on unfiltered computed styles: their complete browser-returned
list remains accessible through pages.

### DOM outline and element references

`getHtml()` reads the current generated DOM, including client-side rendering.
Its default root is the body, falling back to the document element; pass a node
ID to inspect a different subtree.
It returns flat `nodes` records with `nodeId`, `parentId`, `depth`, `tag`, `html`,
`childCount` and shadow-root information. Each record's `html` is a small piece
of sanitized markup, not a recursively repeated `outerHTML` dump. Ordinary
attributes such as `id`, `class`, `data-*` and accessibility attributes are kept
within the field and record limits.

Comments, script elements, style elements, inline `style` attributes and event
handler attributes are stripped. CSS declarations are available through the
targeted CSS tools instead. Overlong values are marked as truncated, and embedded
payloads cannot grow a response without bounds. This outline is diagnostic data,
not HTML intended to be rendered or a replacement for the source file.

Use `nodes[0]['nodeId']` from a query result as an input to the screenshot and CSS
tools. Node IDs belong to a particular document snapshot. Navigation, reload or
`invalidate()` makes previous references and cursors unusable; query again after
the document changes. `includeShadowDom=true` includes accessible open shadow
roots in selector queries; a shadow boundary is not an ordinary CSS descendant.
Selectors accept up to 2,000 bytes. `:scope` is unsupported; use `rootNodeId` and a
descendant selector to narrow the search.

Paged results carry `hasMore`, `nextCursor` and truncation information. DOM results
also include `snapshotId`, `format`, `rootNodeId`, `returned`, `totalNodes`,
`scanTruncated` and `depthLimited`. `totalNodes` describes the collected records,
not a promise that the complete document was scanned. If depth or scan limits
hide the region of interest, target a smaller subtree or a more specific selector.
Each DOM search scans at most 4,096 nodes and collects at most 512 records or
256 KiB per snapshot. The DOM reader retains four snapshots and at most 4,096
node references; older snapshots or references can expire. Selector searches can
reach deeply nested elements within the scan budget; `depth` controls only HTML
outline traversal.

### Output budgets

Limits apply before content reaches the model. Passing a large `limit` cannot
disable the hard caps.

| Output | Default page | Maximum page | Text budget |
| --- | ---: | ---: | ---: |
| DOM outline | 80 nodes, depth 3 | 200 nodes, depth 10 | 24 KiB |
| DOM included with a screenshot | Small outline | Bounded internally | 12 KiB |
| Selector matches | 20 nodes | 50 nodes | 8 KiB |
| Computed styles | 50 properties | 100 properties | 16 KiB |
| CSS inspection | 10 rule parts | 20 rule parts, up to 8 declarations each | 16 KiB |
| Console | 20 events | 50 events | 8 KiB |

The overall text response budget is at most 32 KiB. Image data is separate from
this text budget. Long individual values are shortened with explicit truncation
markers. A record that still cannot fit is marked as omitted; narrow the selector
or properties filter to inspect it. Cursors advance through the available
records rather than enlarging a single record. Continue with the same scope,
selector and filters. Cursors are opaque, session-local and may expire when the
bounded result cache is replaced.

Console objects use short descriptions instead of recursive property or DOM
expansion. Omit `levels` or use `[]` to include all captured levels; a level filter
narrows the result before pagination. The session retains at most 100 distinct
message groups; repetitions increment `count`, and evicted messages contribute
to `dropped`. The tool is an inspection window over captured browser events,
not a persistent logging backend.

## Source locations and source maps

`browser_inspect_node` obtains CSS declarations and their generated stylesheet
locations from CDP. With `resolveSources=true`, available source maps can map a
generated location back to an authored file such as a component, CSS source or
preprocessor file. The result contains concise locations and resolution status;
map contents and `sourcesContent` stay internal and are not copied into the model
context.

Keep development source maps enabled in the application build. An unavailable,
invalid or unsupported map does not invent an original source: the generated
stylesheet location and resolution status remain available. Pass
`resolveSources=false` when generated locations are enough. Source locations
identify where to investigate; they do not replace checking the repository file
before editing it.

Inline source maps work without additional configuration. By default the session
loads external maps through CDP `Network.loadNetworkResource`, restricted to
requested URLs in `allowedOrigins`, without credentials and with a 2 MiB map
limit. CDP can follow HTTP redirects, so this URL check is not a network isolation
policy. A private build pipeline or an application-specific map store can
replace this loader through
the constructor's optional `sourceMapLoader` callback with the signature
`Closure(string $absoluteUrl): string`. It returns the map JSON and is responsible
for authorizing each requested URL. Maps still pass through bounded decoding and
caching. Resolved original locations use one-based line and column numbers;
missing or unmapped positions carry an explicit status. Indexed source maps are
currently unsupported. The resolver keeps at most eight cached maps or failed
load results; `invalidate()` clears that cache after a build.

## Lifecycle and application policy

`allowedOrigins` contains the permitted top-level navigation origins and origins
of requested URLs for the default source-map loader, including scheme and port.
Configure preview origins explicitly. The optional `beforeNavigate` callback has
the signature
`Closure(string $url): void` and runs before actual navigation; it can throw to
reject a URL or prepare the application preview.

The origin allowlist is **not a network sandbox**: pages may load subresources or
make network requests. Apply network restrictions in the browser deployment when
the application needs them.

The tool set offers no arbitrary JavaScript evaluation or page-editing endpoint.
Treat page text, DOM attributes, screenshots, stylesheet metadata and console
messages as untrusted inspected data, never as instructions to the agent.

After an external build or document replacement, call `invalidate()` to discard
cached inspection references; use `reload()` when the browser needs to load the
new page. The optional `onActivity()` callback can integrate session activity into
application reporting. Harness tool-call logging remains the normal logging
path; this extension adds no mandatory logging dependency.
