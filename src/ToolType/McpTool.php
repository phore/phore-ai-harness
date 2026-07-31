<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

use InvalidArgumentException;

final readonly class McpTool extends AbstractToolType
{
    private const ALLOWED_OPTION_KEYS = [
        'server_label',
        'server_url',
        'connector_id',
        'authorization',
        'headers',
        'allowed_tools',
        'allowed_callers',
        'require_approval',
        'defer_loading',
    ];

    private const ALLOWED_CALLERS = ['direct', 'programmatic'];

    protected const PROVIDER_TYPES = [
        'open_ai' => 'mcp',
    ];

    /**
     * Erstellt ein OpenAI MCP-Tool für Remote-MCP-Server oder OpenAI-Connectors.
     *
     * Beispiel Remote-MCP-Server:
     * ```php
     * $tool = new McpTool(
     *     server_label: 'docs',
     *     server_url: 'https://example.test/mcp',
     *     authorization: 'Bearer secret',
     *     headers: ['X-Tenant' => 'main'],
     *     allowed_tools: ['search', 'fetch'],
     *     allowed_callers: ['direct', 'programmatic'],
     *     require_approval: 'never',
     *     defer_loading: true,
     * );
     * ```
     *
     * Beispiel OpenAI-Connector:
     * ```php
     * $tool = new McpTool(
     *     server_label: 'google_drive',
     *     connector_id: 'connector_googledrive',
     *     authorization: 'Bearer google-oauth-token',
     * );
     * ```
     *
     * @param string|array<string, mixed> $server_label Eindeutiger Name des MCP-Servers innerhalb des Requests. Pass an array for backwards-compatible raw option input.
     * @param string|null $server_url URL eines Remote-MCP-Servers. Genau eines von $server_url oder $connector_id muss gesetzt sein.
     * @param string|null $connector_id OpenAI-Connector-ID anstelle einer Remote-Server-URL. Genau eines von $server_url oder $connector_id muss gesetzt sein.
     * @param string|null $authorization Optionaler OAuth-/Bearer-Token für den MCP-Server oder Connector.
     * @param array<string, string>|null $headers Zusätzliche HTTP-Header, die an den MCP-Server gesendet werden.
     * @param list<string>|null $allowed_tools Beschränkt die importierten bzw. verfügbaren MCP-Tools auf die angegebenen Tool-Namen.
     * @param list<'direct'|'programmatic'>|null $allowed_callers Erlaubte Aufrufkontexte für MCP-Tool-Aufrufe.
     * @param string|array<string, mixed>|null $require_approval Definiert, welche MCP-Tool-Aufrufe eine Bestätigung benötigen, z. B. "always", "never" oder eine OpenAI-Konfiguration.
     * @param bool|null $defer_loading Verzögert das Laden der Tool-Definitionen, bis sie benötigt werden, insbesondere für Tool Search.
     */
    public function __construct(
        string|array $server_label,
        ?string $server_url = null,
        ?string $connector_id = null,
        ?string $authorization = null,
        ?array $headers = null,
        ?array $allowed_tools = null,
        ?array $allowed_callers = null,
        string|array|null $require_approval = null,
        ?bool $defer_loading = null,
    ) {
        $options = is_array($server_label) ? $server_label : ['server_label' => $server_label];

        $this->addIfNotNull($options, 'server_url', $server_url);
        $this->addIfNotNull($options, 'connector_id', $connector_id);
        $this->addIfNotNull($options, 'authorization', $authorization);
        $this->addIfNotNull($options, 'headers', $headers);
        $this->addIfNotNull($options, 'allowed_tools', $allowed_tools);
        $this->addIfNotNull($options, 'allowed_callers', $allowed_callers);
        $this->addIfNotNull($options, 'require_approval', $require_approval);
        $this->addIfNotNull($options, 'defer_loading', $defer_loading);

        $this->assertValidOptions($options);

        parent::__construct($options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertValidOptions(array $options): void
    {
        foreach ($options as $key => $_value) {
            if (!in_array($key, self::ALLOWED_OPTION_KEYS, true)) {
                throw new InvalidArgumentException('Unsupported MCP tool option: ' . $key);
            }
        }

        $this->assertNonEmptyString($options, 'server_label', required: true);
        $this->assertNonEmptyString($options, 'server_url');
        $this->assertNonEmptyString($options, 'connector_id');
        $this->assertNonEmptyString($options, 'authorization');

        $hasServerUrl = array_key_exists('server_url', $options);
        $hasConnectorId = array_key_exists('connector_id', $options);
        if ($hasServerUrl === $hasConnectorId) {
            throw new InvalidArgumentException('MCP tool requires exactly one of "server_url" or "connector_id".');
        }

        if (isset($options['headers'])) {
            if (!is_array($options['headers'])) {
                throw new InvalidArgumentException('MCP tool option "headers" must be an array.');
            }
            foreach ($options['headers'] as $key => $value) {
                if (!is_string($key) || $key === '' || !is_string($value)) {
                    throw new InvalidArgumentException('MCP tool option "headers" must be an array<string, string>.');
                }
            }
        }

        if (isset($options['allowed_tools'])) {
            $this->assertStringList($options['allowed_tools'], 'allowed_tools');
        }

        if (isset($options['allowed_callers'])) {
            $this->assertStringList($options['allowed_callers'], 'allowed_callers');
            foreach ($options['allowed_callers'] as $caller) {
                if (!in_array($caller, self::ALLOWED_CALLERS, true)) {
                    throw new InvalidArgumentException(
                        'Invalid MCP tool allowed caller "' . $caller . '". Allowed values: ' . implode(', ', self::ALLOWED_CALLERS),
                    );
                }
            }
        }

        if (isset($options['require_approval']) && !is_string($options['require_approval']) && !is_array($options['require_approval'])) {
            throw new InvalidArgumentException('MCP tool option "require_approval" must be a string or array.');
        }

        if (isset($options['defer_loading']) && !is_bool($options['defer_loading'])) {
            throw new InvalidArgumentException('MCP tool option "defer_loading" must be a boolean.');
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function addIfNotNull(array &$options, string $key, mixed $value): void
    {
        if ($value !== null) {
            $options[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertNonEmptyString(array $options, string $key, bool $required = false): void
    {
        if (!array_key_exists($key, $options)) {
            if ($required) {
                throw new InvalidArgumentException('MCP tool option "' . $key . '" is required.');
            }

            return;
        }

        if (!is_string($options[$key]) || trim($options[$key]) === '') {
            throw new InvalidArgumentException('MCP tool option "' . $key . '" must be a non-empty string.');
        }
    }

    private function assertStringList(mixed $value, string $key): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('MCP tool option "' . $key . '" must be a list of strings.');
        }

        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException('MCP tool option "' . $key . '" must be a list of non-empty strings.');
            }
        }
    }
}
