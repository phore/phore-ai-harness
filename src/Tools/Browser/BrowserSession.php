<?php

declare(strict_types=1);

namespace Phore\AiHarness\Tools\Browser;

use Closure;
use HeadlessChromium\Communication\Connection;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Communication\Session;
use Phore\AiHarness\Content\AiImage;
use Phore\AiHarness\PromptType\MultiPrompt;
use Phore\AiHarness\PromptType\TextPrompt;
use Phore\AiHarness\ToolType\RecoverableToolException;
use Phore\AiHarness\Tools\Browser\Exception\BrowserUnavailableException;
use Phore\AiHarness\Tools\Browser\Helper\CssRules;
use Phore\AiHarness\Tools\Browser\Helper\ResultPages;
use Phore\AiHarness\Tools\Browser\Helper\SourceMapResolver;

/** Application-owned, read-oriented browser inspection with bounded model output. */
final class BrowserSession
{
    private ?Connection $connection = null;
    private ?Session $session = null;
    private ?string $contextId = null;
    private ?int $worldId = null;
    private string $frameId = '';
    private string $loaderId = '';
    private string $url = '';
    private int $width = 1280;
    private int $height = 900;
    private array $origins;
    private array $styleSheets = [];
    private array $console = [];
    private int $droppedConsole = 0;
    private ?Closure $activity = null;
    private readonly ResultPages $pages;
    private readonly SourceMapResolver $sourceMaps;

    /**
     * Configure the optional integration without loading its driver or connecting.
     *
     * @param string $endpoint Browserless ws(s) URL or Chromium http(s) discovery origin.
     * @param list<string> $allowedOrigins Exact http(s) origins for navigation and the default map loader.
     * @param (Closure(string): void)|null $beforeNavigate Optional preview preparation hook.
     * @param (Closure(string): string)|null $sourceMapLoader Authorized external-map loader override.
     * @throws \InvalidArgumentException If no valid allowed origins are configured.
     * @example $browser = new BrowserSession('http://chromium:9222', ['http://preview:8080']);
     * @see BrowserToolSet
     */
    public function __construct(
        private readonly string $endpoint,
        array $allowedOrigins,
        private readonly ?Closure $beforeNavigate = null,
        ?Closure $sourceMapLoader = null,
    ) {
        if ($allowedOrigins === []) {
            throw new \InvalidArgumentException('Configure at least one allowed browser origin.');
        }
        $this->origins = [];
        foreach ($allowedOrigins as $origin) {
            if (!is_string($origin)) {
                throw new \InvalidArgumentException('Allowed origins must be strings.');
            }
            $normalized = self::origin($origin);
            $parts = parse_url($origin);
            if (!in_array($parts['path'] ?? '', ['', '/'], true) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \InvalidArgumentException('Allowed origins must contain only scheme, host and optional port.');
            }
            $this->origins[] = $normalized;
        }
        $this->pages = new ResultPages();
        $this->sourceMaps = new SourceMapResolver($sourceMapLoader ?? $this->loadSourceMap(...));
    }

    /**
     * Open an allowed preview URL with a bounded viewport.
     *
     * @param string $url Absolute http(s) URL or root-relative path on the first allowed origin.
     * @param int $width Viewport width, 320 through 1920 pixels.
     * @param int $height Viewport height, 240 through 1600 pixels.
     * @return array Page URL, viewport and navigation state.
     * @throws BrowserUnavailableException If the optional driver or browser is unavailable.
     * @throws RecoverableToolException For a disallowed URL or invalid viewport.
     * @example $browser->open('http://preview:8080/components/card');
     * @see self::screenshot()
     */
    public function open(string $url, int $width = 1280, int $height = 900): array
    {
        $url = $this->allowedUrl($url);
        if ($width < 320 || $width > 1920 || $height < 240 || $height > 1600) {
            throw new RecoverableToolException('Viewport must be 320–1920 by 240–1600 pixels.');
        }
        if ($this->beforeNavigate !== null) {
            ($this->beforeNavigate)($url);
        }
        $this->connect();
        $this->width = $width;
        $this->height = $height;
        $this->cdp('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => $height, 'deviceScaleFactor' => 1, 'mobile' => false]);
        $this->invalidate();
        $this->styleSheets = [];
        $this->console = [];
        $this->droppedConsole = 0;
        $result = $this->cdp('Page.navigate', ['url' => $url]);
        if (isset($result['errorText']) || ($result['isDownload'] ?? false)) {
            throw new RecoverableToolException('Preview navigation failed or returned a download.');
        }
        $this->url = $url;
        $this->frameId = $result['frameId'];
        $this->loaderId = $result['loaderId'] ?? '';
        $ready = $this->waitUntilReady($result['loaderId'] ?? null);
        $this->refreshStyleSheets();
        $this->ensurePage();
        $this->notify('open');
        return ['url' => ResultPages::clip($this->url, 2048), 'viewport' => ['width' => $width, 'height' => $height], 'ready' => $ready, 'referencesInvalidated' => true];
    }

    /**
     * Reload the current preview after a source change and invalidate old references.
     *
     * @return array Updated page URL, viewport and navigation state.
     * @throws RecoverableToolException If no preview is open.
     * @example $browser->reload();
     * @see self::invalidate()
     */
    public function reload(): array
    {
        if ($this->url === '') {
            throw new RecoverableToolException('Open a preview with browser_open first.');
        }
        // Page.navigate auf dieselbe URL darf Cache nutzen; explizit neu laden.
        $url = $this->allowedUrl($this->url);
        if ($this->beforeNavigate !== null) {
            ($this->beforeNavigate)($url);
        }
        $oldLoader = $this->cdp('Page.getFrameTree')['frameTree']['frame']['loaderId'] ?? '';
        $this->invalidate();
        $this->console = [];
        $this->droppedConsole = 0;
        $this->styleSheets = [];
        $this->cdp('Page.reload', ['ignoreCache' => true]);
        $ready = $this->waitUntilReady(previousLoader: $oldLoader);
        $this->refreshStyleSheets();
        $this->ensurePage();
        $this->notify('reload');
        return ['url' => ResultPages::clip($this->url, 2048), 'viewport' => ['width' => $this->width, 'height' => $this->height], 'ready' => $ready, 'referencesInvalidated' => true];
    }

    /**
     * Return a PNG image and optionally a small sanitized DOM outline.
     *
     * @param string $nodeId Node from a DOM query, or empty for the viewport.
     * @param bool $includeDom Include up to 12 KiB of sanitized structural metadata.
     * @return MultiPrompt Native image plus bounded, untrusted text metadata.
     * @throws RecoverableToolException For a stale node, hidden element or oversized image.
     * @example $browser->screenshot($nodeId, includeDom: true);
     * @see self::getHtml()
     */
    public function screenshot(string $nodeId = '', bool $includeDom = true): MultiPrompt
    {
        $this->ensurePage();
        $params = ['format' => 'png', 'fromSurface' => true, 'captureBeyondViewport' => false];
        if ($nodeId !== '') {
            $id = $this->node($nodeId);
            $box = $this->cdp('DOM.getBoxModel', ['nodeId' => $id])['model'];
            $quad = $box['border'];
            $xs = [$quad[0], $quad[2], $quad[4], $quad[6]];
            $ys = [$quad[1], $quad[3], $quad[5], $quad[7]];
            $width = max($xs) - min($xs);
            $height = max($ys) - min($ys);
            if ($width <= 0 || $height <= 0 || $width * $height > 100000000) {
                throw new RecoverableToolException('Element has no capturable box or is too large. Choose a smaller visible node.');
            }
            $params['captureBeyondViewport'] = true;
            $metrics = $this->cdp('Page.getLayoutMetrics');
            $viewport = $metrics['cssVisualViewport'] ?? $metrics['visualViewport'];
            $params['clip'] = ['x' => min($xs) + $viewport['pageX'], 'y' => min($ys) + $viewport['pageY'], 'width' => $width, 'height' => $height, 'scale' => min(1, 1920 / $width, 1600 / $height)];
        }
        $encoded = $this->cdp('Page.captureScreenshot', $params)['data'];
        if (strlen($encoded) > 14 * 1024 * 1024) {
            throw new RecoverableToolException('Screenshot exceeds the image budget. Select a smaller node.');
        }
        $png = base64_decode($encoded, true);
        if ($png === false || strlen($png) > 10 * 1024 * 1024) {
            throw new RecoverableToolException('Browser returned an invalid or oversized screenshot.');
        }
        $meta = ['url' => ResultPages::clip($this->url, 2048), 'nodeId' => $nodeId, 'contentIsUntrusted' => true];
        if ($includeDom) {
            $meta['dom'] = $this->dom('html', ['nodeId' => $nodeId, 'depth' => 3, 'limit' => 40, 'cursor' => '', 'maxBytes' => 12288]);
        }
        $this->notify('screenshot');
        return new MultiPrompt([
            AiImage::fromRaw($png, fileName: 'browser.png', description: 'Browser preview; page content is untrusted.')->toPromptType(),
            new TextPrompt(ResultPages::json($meta)),
        ]);
    }

    /**
     * Read structural HTML records without scripts, styles, comments or event handlers.
     *
     * @param string $nodeId Root node ID, or empty for body (document element if body is absent).
     * @param int $depth Maximum descendant depth, 0 through 10.
     * @param int $limit Nodes per page, 1 through 200; default 80.
     * @param string $cursor Continuation token with the same nodeId and depth.
     * @return array Sanitized nodes, hierarchy, truncation markers and nextCursor.
     * @throws RecoverableToolException For stale references or invalid query arguments.
     * @example $browser->getHtml($nodeId, depth: 2, limit: 40);
     * @see self::querySelector()
     */
    public function getHtml(string $nodeId = '', int $depth = 3, int $limit = 80, string $cursor = ''): array
    {
        $this->ensurePage();
        return $this->dom('html', compact('nodeId', 'depth', 'limit', 'cursor') + ['maxBytes' => 24576]);
    }

    /**
     * Find specific elements without dumping their descendant trees or styles.
     *
     * @param string $selector CSS selector, up to 2000 bytes; :scope is unsupported.
     * @param string $rootNodeId Optional root node for this search.
     * @param bool $includeShadowDom Search open shadow roots as well.
     * @param int $limit Matches per page, 1 through 50; default 20.
     * @param string $cursor Continue with the same selector and root options.
     * @return array Bounded matching nodes and nextCursor.
     * @throws RecoverableToolException For invalid selectors, stale IDs or cursors.
     * @example $browser->querySelector('.card h2');
     * @see self::getComputedStyles()
     */
    public function querySelector(string $selector, string $rootNodeId = '', bool $includeShadowDom = false, int $limit = 20, string $cursor = ''): array
    {
        $this->ensurePage();
        return $this->dom('query', compact('selector', 'rootNodeId', 'includeShadowDom', 'limit', 'cursor') + ['maxBytes' => 8192]);
    }

    /**
     * Read final computed values for one element, optionally filtering exact names.
     *
     * @param string $nodeId Element ID from browser_get_html or browser_query_selector.
     * @param list<string> $properties Exact CSS names, e.g. ["font-size","color"]; [] returns all through pages.
     * @param int $limit Properties per page, 1 through 100; default 50.
     * @param string $cursor Continue the same nodeId/properties query.
     * @return array Filtered computed styles and an opaque nextCursor.
     * @throws RecoverableToolException For a stale node, invalid filter or cursor.
     * @example $browser->getComputedStyles($nodeId, ['font-size', 'line-height', 'color']);
     * @see self::inspectNode()
     */
    public function getComputedStyles(string $nodeId, array $properties = [], int $limit = 50, string $cursor = ''): array
    {
        $this->ensurePage();
        $properties = $this->properties($properties);
        $id = $this->node($nodeId);
        return $this->pages->read('styles', [$nodeId, $properties], $cursor, $limit, 100, 16384, function () use ($id, $nodeId, $properties): array {
            $rows = [];
            foreach ($this->cdp('CSS.getComputedStyleForNode', ['nodeId' => $id])['computedStyle'] as $style) {
                if ($properties === [] || in_array($style['name'], $properties, true)) {
                    $rows[] = ['name' => ResultPages::clip($style['name'], 128), 'value' => ResultPages::clip($style['value'], 1024)];
                }
            }
            return ['rows' => $rows, 'meta' => ['nodeId' => $nodeId, 'properties' => $properties, 'missingProperties' => array_values(array_diff($properties, array_column($rows, 'name')))]];
        });
    }

    /**
     * Inspect one element's geometry and source declarations without a computed dump.
     *
     * @param string $nodeId Element reference from a DOM query.
     * @param list<string> $properties Optional exact declaration/longhand names; [] includes all.
     * @param int $limit Rule parts per page, 1 through 20; default 10.
     * @param string $cursor Continue the same nodeId/properties/resolveSources query.
     * @param bool $resolveSources Resolve available source maps for returned declarations.
     * @return array Geometry, compact rule parts, generated/original source locations and cursor.
     * @throws RecoverableToolException For stale nodes or invalid query arguments.
     * @example $browser->inspectNode($nodeId, ['font-size'], resolveSources: true);
     * @see self::getComputedStyles()
     */
    public function inspectNode(string $nodeId, array $properties = [], int $limit = 10, string $cursor = '', bool $resolveSources = true): array
    {
        $this->ensurePage();
        $properties = $this->properties($properties);
        $id = $this->node($nodeId);
        return $this->pages->read('rules', [$nodeId, $properties, $resolveSources], $cursor, $limit, 20, 16384, function () use ($id, $nodeId, $properties): array {
            $data = CssRules::collect($this->cdp('CSS.getMatchedStylesForNode', ['nodeId' => $id]), $properties);
            try {
                $model = $this->cdp('DOM.getBoxModel', ['nodeId' => $id])['model'];
                $box = ['width' => $model['width'], 'height' => $model['height'], 'border' => $model['border']];
            } catch (RecoverableToolException) {
                $box = ['available' => false, 'reason' => 'The element has no layout box.'];
            }
            $data['meta'] += ['nodeId' => $nodeId, 'properties' => $properties, 'box' => $box];
            return $data;
        }, function (array $row) use ($resolveSources): array {
            $sheet = $this->styleSheets[$row['styleSheetId']] ?? [];
            $row['source'] = $this->sourceLocation($sheet, $row['location'] ?? null, $resolveSources);
            foreach ($row['declarations'] as &$declaration) {
                if (isset($declaration['location'])) {
                    $declaration['source'] = $this->sourceLocation($sheet, $declaration['location'], $resolveSources);
                    unset($declaration['location']);
                }
            }
            unset($declaration, $row['location']);
            return $row;
        });
    }

    /**
     * Read a bounded snapshot of recent console messages, without object expansion.
     *
     * @param list<string> $levels Optional levels; [] includes all levels.
     * @param int $limit Messages per page, 1 through 50; default 20.
     * @param string $cursor Continue the same levels query; omit for a new snapshot.
     * @return array Grouped recent messages, dropped count and nextCursor.
     * @throws RecoverableToolException For an unsupported level or invalid cursor.
     * @example $browser->getConsole(['error', 'warning']);
     * @see self::reload()
     */
    public function getConsole(array $levels = [], int $limit = 20, string $cursor = ''): array
    {
        $this->ensurePage();
        $allowed = ['log', 'debug', 'info', 'warning', 'error', 'assert', 'trace', 'verbose'];
        if (!array_is_list($levels) || count($levels) > count($allowed)) {
            throw new RecoverableToolException('levels must be a list of console levels.');
        }
        foreach ($levels as $level) {
            if (!is_string($level) || !in_array($level, $allowed, true)) {
                throw new RecoverableToolException('Supported levels: ' . implode(', ', $allowed) . '.');
            }
        }
        $levels = array_values(array_unique($levels));
        sort($levels);
        return $this->pages->read('messages', [$levels], $cursor, $limit, 50, 8192, function () use ($levels): array {
            $rows = array_values(array_filter($this->console, static fn (array $row): bool => $levels === [] || in_array($row['level'], $levels, true)));
            return ['rows' => $rows, 'meta' => ['levels' => $levels, 'dropped' => $this->droppedConsole]];
        });
    }

    /**
     * Release this tool's context without shutting down the shared browser process.
     *
     * @return string Confirmation suitable for the browser_close tool result.
     * @example $browser->closeTool();
     * @see self::close()
     */
    public function closeTool(): string
    {
        $this->close();
        return 'Browser session closed; node IDs and cursors are invalid.';
    }

    /**
     * Release owned resources; call from the application's finally block.
     *
     * @return void
     * @example try { $context->text($prompt); } finally { $browser->close(); }
     * @see self::closeTool()
     */
    public function close(): void
    {
        try {
            if ($this->connection !== null && $this->contextId !== null) {
                $this->cdp('Target.disposeBrowserContext', ['browserContextId' => $this->contextId], browser: true);
            }
        } finally {
            try {
                $this->connection?->disconnect();
            } finally {
                $this->connection = null;
                $this->session = null;
                $this->contextId = null;
                $this->url = '';
                $this->frameId = '';
                $this->loaderId = '';
                $this->styleSheets = [];
                $this->console = [];
                $this->invalidate();
            }
        }
    }

    /**
     * Invalidate node references, paginated snapshots and maps after a rebuild.
     *
     * @return void
     * @example $browser->invalidate(); // Reload before inspecting rebuilt content.
     * @see self::reload()
     */
    public function invalidate(): void
    {
        $this->worldId = null;
        $this->pages->clear();
        $this->sourceMaps->clear();
    }

    /**
     * Attach an application-owned activity sink without adding a logging dependency.
     *
     * @param (Closure(string): void)|null $listener Receives short activity names; null disables it.
     * @return void
     * @example $browser->onActivity(static fn (string $event) => $logger->info($event));
     * @see self::open()
     */
    public function onActivity(?Closure $listener): void
    {
        $this->activity = $listener;
    }

    private function connect(): void
    {
        if ($this->session !== null) {
            return;
        }
        $this->connection = (new BrowserConnection($this->endpoint))->open();
        try {
            $this->contextId = $this->cdp('Target.createBrowserContext', ['disposeOnDetach' => true], browser: true)['browserContextId'];
            $target = $this->cdp('Target.createTarget', ['url' => 'about:blank', 'browserContextId' => $this->contextId], browser: true)['targetId'];
            $this->session = $this->connection->createSession($target);
            $this->listen();
            foreach (['Page', 'DOM', 'Runtime', 'CSS', 'Log', 'Network'] as $domain) {
                $this->cdp($domain . '.enable');
            }
        } catch (\Throwable $error) {
            try {
                $this->close();
            } catch (\Throwable) {
                // Die ursprüngliche Verbindungsursache bleibt erhalten.
            }
            throw new BrowserUnavailableException('Browser connection unavailable: Chromium could not create an inspection context.', previous: $error);
        }
    }

    private function listen(): void
    {
        $this->session->on('method:CSS.styleSheetAdded', function (array $event): void {
            $header = $event['header'];
            if (count($this->styleSheets) < 1024) {
                $this->styleSheets[$header['styleSheetId']] = array_intersect_key($header, array_flip(['sourceURL', 'sourceMapURL', 'startLine', 'startColumn', 'isInline']));
            }
        });
        $this->session->on('method:CSS.styleSheetRemoved', function (array $event): void {
            unset($this->styleSheets[$event['styleSheetId']]);
            $this->pages->clear();
            $this->sourceMaps->clear();
        });
        $this->session->on('method:CSS.styleSheetChanged', function (): void {
            $this->pages->clear();
            $this->sourceMaps->clear();
        });
        $this->session->on('method:Runtime.consoleAPICalled', function (array $event): void {
            $parts = [];
            foreach (array_slice($event['args'] ?? [], 0, 8) as $argument) {
                $value = $argument['value'] ?? $argument['unserializableValue'] ?? $argument['description'] ?? '[' . ($argument['type'] ?? 'object') . ']';
                $parts[] = ResultPages::clip(is_scalar($value) || $value === null ? (string) $value : '[object]', 256);
            }
            $frame = $event['stackTrace']['callFrames'][0] ?? [];
            $this->recordConsole($event['type'] === 'warn' ? 'warning' : $event['type'], implode(' ', $parts), $frame['url'] ?? '', (int) ($frame['lineNumber'] ?? -1) + 1);
        });
        $this->session->on('method:Runtime.exceptionThrown', function (array $event): void {
            $details = $event['exceptionDetails'];
            $this->recordConsole('error', $details['exception']['description'] ?? $details['text'] ?? 'Uncaught exception', $details['url'] ?? '', (int) ($details['lineNumber'] ?? -1) + 1);
        });
        $this->session->on('method:Log.entryAdded', function (array $event): void {
            $entry = $event['entry'];
            $this->recordConsole($entry['level'], $entry['text'], $entry['url'] ?? '', (int) ($entry['lineNumber'] ?? -1) + 1);
        });
    }

    private function recordConsole(string $level, string $text, string $url, int $line): void
    {
        $row = ['level' => $level, 'text' => ResultPages::clip($text, 1024), 'url' => ResultPages::clip($url, 512), 'line' => $line, 'count' => 1];
        $key = hash('sha256', ResultPages::json($row));
        if (isset($this->console[$key])) {
            $row['count'] = $this->console[$key]['count'] + 1;
            unset($this->console[$key]);
        } elseif (count($this->console) >= 100) {
            $oldest = array_key_first($this->console);
            $this->droppedConsole += $this->console[$oldest]['count'];
            unset($this->console[$oldest]);
        }
        $this->console[$key] = $row;
    }

    private function cdp(string $method, array $params = [], bool $browser = false): array
    {
        $transport = $browser ? $this->connection : $this->session;
        if ($transport === null) {
            throw new RecoverableToolException('Open a preview with browser_open first.');
        }
        try {
            $response = $transport->sendMessageSync(new Message($method, $params), 10000);
        } catch (\Throwable $error) {
            // disposeOnDetach gibt den eigenen Context frei; ein späteres open darf neu verbinden.
            try {
                $this->connection?->disconnect();
            } catch (\Throwable) {
                // Einen bereits defekten Transport nur noch lokal verwerfen.
            }
            $this->connection = null;
            $this->session = null;
            $this->contextId = null;
            $this->url = '';
            $this->invalidate();
            throw new BrowserUnavailableException('Browser connection unavailable while running ' . $method . '.', previous: $error);
        }
        if (!$response->isSuccessful()) {
            throw new RecoverableToolException($method . ' failed. The node or document may have changed; repeat the query.', result: ['method' => $method]);
        }
        return $response['result'] ?? [];
    }

    private function ensurePage(): void
    {
        if ($this->session === null || $this->url === '') {
            throw new RecoverableToolException('Open a preview with browser_open first.');
        }
        $frame = $this->cdp('Page.getFrameTree')['frameTree']['frame'];
        try {
            $url = $this->allowedUrl($frame['url']);
        } catch (RecoverableToolException $error) {
            $this->close();
            throw new RecoverableToolException('The page navigated outside the allowed origins; the inspection session was closed.', previous: $error);
        }
        if ($this->loaderId !== ($frame['loaderId'] ?? '') || $this->frameId !== $frame['id']) {
            $this->invalidate();
        }
        $this->url = $url;
        $this->frameId = $frame['id'];
        $this->loaderId = $frame['loaderId'] ?? '';
        if ($this->worldId === null) {
            $this->worldId = $this->cdp('Page.createIsolatedWorld', ['frameId' => $this->frameId, 'worldName' => 'phore-browser-inspection'])['executionContextId'];
            try {
                $script = file_get_contents(__DIR__ . '/Script/dom.js');
                if ($script === false) {
                    throw new BrowserUnavailableException('Browser DOM reader is missing from the installation.');
                }
                $expression = 'globalThis.__phoreBrowser = (' . $script . ')(' . ResultPages::json(bin2hex(random_bytes(12))) . '); true';
                $this->evaluate($expression);
            } catch (\Throwable $error) {
                $this->worldId = null;
                throw $error;
            }
        }
    }

    private function refreshStyleSheets(): void
    {
        // Auch reine Fragment-Navigationen müssen ihre bereits vorhandenen Sheets behalten.
        $this->cdp('CSS.disable');
        $this->styleSheets = [];
        $this->cdp('CSS.enable');
    }

    private function waitUntilReady(?string $expectedLoader = null, ?string $previousLoader = null): bool
    {
        $deadline = microtime(true) + 10;
        do {
            $frame = $this->cdp('Page.getFrameTree')['frameTree']['frame'];
            $loader = $frame['loaderId'] ?? '';
            $committed = ($expectedLoader === null || $loader === $expectedLoader)
                && ($previousLoader === null || $loader !== $previousLoader)
                && $frame['url'] !== 'about:blank';
            if ($committed) {
                try {
                    $this->allowedUrl($frame['url']);
                } catch (RecoverableToolException $error) {
                    $this->close();
                    throw new RecoverableToolException('Navigation left the allowed preview origins; the session was closed.', previous: $error);
                }
                try {
                    $result = $this->cdp('Runtime.evaluate', ['expression' => 'document.readyState === "complete" && (!document.fonts || document.fonts.status === "loaded")', 'returnByValue' => true]);
                    if (($result['result']['value'] ?? false) === true) {
                        return true;
                    }
                } catch (RecoverableToolException) {
                    // Der Context kann während eines Redirects erneut ersetzt werden.
                }
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function evaluate(string $expression, bool $returnByValue = true): array
    {
        $result = $this->cdp('Runtime.evaluate', ['expression' => $expression, 'contextId' => $this->worldId, 'returnByValue' => $returnByValue, 'silent' => true]);
        if (isset($result['exceptionDetails'])) {
            $description = $result['exceptionDetails']['exception']['description'] ?? 'DOM query failed.';
            if (isset($result['exceptionDetails']['exception']['objectId'])) {
                $this->releaseObject($result['exceptionDetails']['exception']['objectId']);
            }
            throw new RecoverableToolException(ResultPages::clip(explode("\n", $description, 2)[0], 512));
        }
        return $result['result'];
    }

    private function dom(string $operation, array $args): array
    {
        $expression = 'globalThis.__phoreBrowser(' . ResultPages::json($operation) . ',' . ResultPages::json($args) . ')';
        $value = $this->evaluate($expression)['value'] ?? null;
        if (!is_array($value) || strlen(ResultPages::json($value)) > 32768) {
            throw new RecoverableToolException('DOM result exceeds the output budget. Narrow the root node or depth.');
        }
        return $value;
    }

    private function node(string $nodeId): int
    {
        if ($nodeId === '' || strlen($nodeId) > 128) {
            throw new RecoverableToolException('Use a nodeId from a current DOM query.');
        }
        $remote = $this->evaluate('globalThis.__phoreBrowser("resolve",' . ResultPages::json(['nodeId' => $nodeId]) . ')', false);
        $objectId = $remote['objectId'] ?? null;
        if ($objectId === null) {
            throw new RecoverableToolException('Node no longer exists; repeat the DOM query.');
        }
        try {
            $this->cdp('DOM.getDocument', ['depth' => 0]);
            $id = $this->cdp('DOM.requestNode', ['objectId' => $objectId])['nodeId'];
            if ($id === 0) {
                throw new RecoverableToolException('Node is stale; repeat the DOM query.');
            }
            return $id;
        } finally {
            $this->releaseObject($objectId);
        }
    }

    private function releaseObject(string $objectId): void
    {
        if ($this->session === null) {
            return;
        }
        try {
            $this->cdp('Runtime.releaseObject', ['objectId' => $objectId]);
        } catch (RecoverableToolException | BrowserUnavailableException) {
            // Nach einem Context-/Verbindungswechsel ist das Remote-Objekt bereits ungültig.
        }
    }

    private function properties(array $properties): array
    {
        if (!array_is_list($properties) || count($properties) > 50) {
            throw new RecoverableToolException('properties must be a list of at most 50 CSS property names.');
        }
        foreach ($properties as $property) {
            if (!is_string($property) || preg_match('/\A(?:--[a-zA-Z0-9_-]+|-?[a-z][a-z0-9-]*)\z/', $property) !== 1 || strlen($property) > 128) {
                throw new RecoverableToolException('Use exact CSS property names, e.g. font-size, color or --brand-color.');
            }
        }
        $properties = array_values(array_unique($properties));
        sort($properties);
        return $properties;
    }

    private function sourceLocation(array $sheet, ?array $location, bool $resolve): array
    {
        $url = (string) ($sheet['sourceURL'] ?? '');
        $generated = ['file' => ResultPages::clip(str_starts_with($url, 'data:') ? '[inline stylesheet]' : $url, 1024)];
        if ($location !== null) {
            $generated['line'] = $location['line'] + (int) ($sheet['startLine'] ?? 0);
            $generated['column'] = $location['column'] + ($location['line'] === 1 ? (int) ($sheet['startColumn'] ?? 0) : 0);
        }
        $original = ['status' => 'unavailable', 'reason' => $resolve ? 'No source map or generated location available.' : 'Source resolution disabled.'];
        if ($resolve && $location !== null && isset($sheet['sourceMapURL']) && $url !== '') {
            $original = $this->sourceMaps->resolve($sheet['sourceMapURL'], $url, $location['line'] - 1, $location['column'] - 1);
            if (isset($original['file'])) {
                $original['file'] = ResultPages::clip($original['file'], 1024);
            }
        }
        return ['generated' => $generated, 'original' => $original];
    }

    private function loadSourceMap(string $url): string
    {
        $url = $this->allowedUrl($url);
        $resource = $this->cdp('Network.loadNetworkResource', ['frameId' => $this->frameId, 'url' => $url, 'options' => ['disableCache' => true, 'includeCredentials' => false]])['resource'];
        $stream = $resource['stream'] ?? null;
        if (!($resource['success'] ?? false) || $stream === null) {
            throw new \RuntimeException('Source-map resource unavailable.');
        }
        $body = '';
        try {
            $deadline = microtime(true) + 10;
            do {
                $chunk = $this->cdp('IO.read', ['handle' => $stream, 'size' => 65536]);
                $data = ($chunk['base64Encoded'] ?? false) ? base64_decode($chunk['data'], true) : $chunk['data'];
                if ($data === false || strlen($body) + strlen($data) > 2097152 || microtime(true) > $deadline) {
                    throw new \RuntimeException('Source map exceeds the read budget.');
                }
                $body .= $data;
            } while (!($chunk['eof'] ?? false));
        } finally {
            $this->cdp('IO.close', ['handle' => $stream]);
        }
        return $body;
    }

    private function allowedUrl(string $url): string
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $url = $this->origins[0] . $url;
        }
        try {
            $origin = self::origin($url);
        } catch (\InvalidArgumentException $error) {
            throw new RecoverableToolException('Use an allowed http(s) preview URL.', previous: $error);
        }
        if (!in_array($origin, $this->origins, true)) {
            throw new RecoverableToolException('URL origin is not allowed for this browser session.');
        }
        return $url;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || strlen($url) > 8192 || !isset($parts['host'])
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\\\\]/', $url)
        ) {
            throw new \InvalidArgumentException('Invalid HTTP origin.');
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $suffix = $port === ($scheme === 'https' ? 443 : 80) ? '' : ':' . $port;
        return $scheme . '://' . strtolower($parts['host']) . $suffix;
    }

    private function notify(string $event): void
    {
        if ($this->activity !== null) {
            ($this->activity)('browser.' . $event);
        }
    }
}
