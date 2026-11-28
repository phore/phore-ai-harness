<?php

declare(strict_types=1);

namespace Phore\AiHarness\Tools\Browser;

use HeadlessChromium\Communication\Connection;
use HeadlessChromium\Communication\Message;
use Phore\AiHarness\Tools\Browser\Exception\BrowserUnavailableException;

/** Lazy transport for an application-configured Browserless or Chromium endpoint. */
final readonly class BrowserConnection
{
    /**
     * Store an endpoint without loading the optional driver or opening a socket.
     *
     * @param string $endpoint Browserless ws(s) URL or Chromium http(s) discovery origin.
     * @example $transport = new BrowserConnection('http://chromium:9222');
     * @see self::open()
     */
    public function __construct(private string $endpoint)
    {
    }

    /**
     * Open a caller-owned connection; the application supplies chrome-php/chrome.
     *
     * @return Connection Connected optional CDP transport.
     * @throws BrowserUnavailableException If the optional module or endpoint is unavailable.
     * @example $connection = (new BrowserConnection($endpoint))->open();
     * @see BrowserSession::close()
     */
    public function open(): Connection
    {
        try {
            $available = class_exists(Connection::class) && class_exists(Message::class);
        } catch (\Throwable $error) {
            throw new BrowserUnavailableException(
                'Browser connection unavailable: the optional chrome-php/chrome module or one of its dependencies could not be loaded.',
                previous: $error,
            );
        }
        if (!$available) {
            throw new BrowserUnavailableException(
                'Browser connection unavailable: install the optional chrome-php/chrome module in the consuming application. See src/Tools/Browser/README.md.',
            );
        }

        $connection = null;
        try {
            $parts = parse_url($this->endpoint);
            if ($parts === false || !isset($parts['host']) || isset($parts['user'])
                || isset($parts['pass']) || isset($parts['fragment'])
                || preg_match('/[\x00-\x20\\\\]/', $this->endpoint)
            ) {
                throw new \InvalidArgumentException('Invalid browser endpoint.');
            }
            $scheme = strtolower($parts['scheme'] ?? '');
            $parts['scheme'] = $scheme;
            if (in_array($scheme, ['ws', 'wss'], true)) {
                $url = $this->endpoint;
            } elseif (in_array($scheme, ['http', 'https'], true)
                && !isset($parts['query']) && in_array($parts['path'] ?? '', ['', '/'], true)
            ) {
                $url = $this->discover($parts);
            } else {
                throw new \InvalidArgumentException('Use a ws(s) URL or http(s) discovery origin.');
            }

            $connection = new Connection($url, sendSyncDefaultTimeout: 10000);
            if (!$connection->connect() || !$connection->isConnected()) {
                throw new \RuntimeException('CDP connection failed.');
            }
            return $connection;
        } catch (\Throwable $error) {
            try {
                $connection?->disconnect();
            } catch (\Throwable) {
                // Der ursprüngliche Verbindungsfehler bleibt die Ursache.
            }
            // Endpoint-Queries können Browserless-Tokens enthalten und bleiben intern.
            throw new BrowserUnavailableException(
                'Browser connection unavailable: check the configured Browserless/Chromium endpoint and optional driver.',
                previous: $error,
            );
        }
    }

    /** @param array<string, mixed> $parts */
    private function discover(array $parts): string
    {
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $origin = $parts['scheme'] . '://' . $authority;
        $body = '';
        $handle = curl_init($origin . '/json/version');
        if ($handle === false) {
            throw new \RuntimeException('Cannot initialize Chrome discovery.');
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                // Direct Chromium refuses arbitrary DNS names in the Host header.
                CURLOPT_HTTPHEADER => $parts['scheme'] === 'http' ? ['Host: localhost'] : [],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new \RuntimeException('Chrome discovery failed.');
            }
        } finally {
            curl_close($handle);
        }
        $version = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $path = parse_url($version['webSocketDebuggerUrl'] ?? '', PHP_URL_PATH);
        if (!is_string($path) || preg_match('~\A/devtools/browser/[a-zA-Z0-9-]+\z~', $path) !== 1) {
            throw new \RuntimeException('Invalid Chrome discovery response.');
        }
        // Nur den Pfad übernehmen; der vom Betreiber konfigurierte Host bleibt maßgeblich.
        return ($parts['scheme'] === 'https' ? 'wss://' : 'ws://') . $authority . $path;
    }
}
