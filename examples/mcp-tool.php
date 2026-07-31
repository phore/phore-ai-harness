<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\ToolType\McpTool;

// McpTool beschreibt einen Remote-MCP-Server oder OpenAI-Connector für die
// OpenAI Responses API. Dieses Beispiel baut den Request nur auf und sendet ihn
// nicht ab, damit es ohne API-Key und ohne erreichbaren MCP-Server ausführbar ist.
$mcpTool = new McpTool(
    server_label: 'mock',
    server_url: 'https://mock.iterate.com/no-auth',
    allowed_callers: ['direct', 'programmatic'],
    require_approval: 'never',
    defer_loading: true,
);

$request = (new AiRequest(
    model: 'gpt-5-mini',
    input: 'Nutze den MCP-Server "mock", falls ein passendes Tool verfügbar ist.',
))->withTools($mcpTool);

print_r($request->toArray());

// Remote-MCP-Server mit Authentifizierung:
$authenticatedMcpTool = new McpTool(
    server_label: 'docs',
    server_url: 'https://example.test/mcp',
    authorization: 'Bearer example-token',
    headers: ['X-Tenant' => 'main'],
    allowed_tools: ['search', 'fetch'],
);

// OpenAI-Connector statt Remote-Server-URL:
$connectorTool = new McpTool(
    server_label: 'google_drive',
    connector_id: 'connector_googledrive',
    authorization: 'Bearer google-oauth-token',
);

assert($authenticatedMcpTool->type() === 'mcp');
assert($connectorTool->toArray()['connector_id'] === 'connector_googledrive');
