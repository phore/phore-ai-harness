<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Client\AiResponse;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\TextPrompt;
use Phore\AiHarness\ToolType\ImageGenerationTool;
use Phore\AiHarness\ToolType\WebAccessTool;
use PHPUnit\Framework\TestCase;

final class FunctionsTestDto
{
    public function __construct(public string $answer)
    {
    }
}

final class FunctionsTest extends TestCase
{
    public function testNormalizeItemsConvertsStringsAndKeepsPromptsAndTools(): void
    {
        $items = Toolkit::normalizePromptItems([
            'Hello',
            new TextPrompt('World'),
            new WebAccessTool(),
        ]);

        self::assertCount(3, $items);
        self::assertInstanceOf(TextPrompt::class, $items[0]);
        self::assertSame('Hello', $items[0]->text);
        self::assertInstanceOf(TextPrompt::class, $items[1]);
        self::assertInstanceOf(WebAccessTool::class, $items[2]);
    }

    public function testHasToolDetectsToolClass(): void
    {
        $items = Toolkit::normalizePromptItems([new TextPrompt('Hello'), new WebAccessTool()]);

        self::assertTrue(Toolkit::hasTool($items, WebAccessTool::class));
        self::assertFalse(Toolkit::hasTool($items, ImageGenerationTool::class));
    }

    public function testMapsImageOutputFormatToContentType(): void
    {
        self::assertSame('image/png', Toolkit::contentTypeFromImageOutputFormat('png'));
        self::assertSame('image/jpeg', Toolkit::contentTypeFromImageOutputFormat('jpeg'));
        self::assertSame('image/webp', Toolkit::contentTypeFromImageOutputFormat('webp'));
    }

    public function testStructArrayFunctionExists(): void
    {
        self::assertTrue(function_exists('phore_ai_struct_array'));
    }

    public function testCreateUsesClientAndModelOptions(): void
    {
        $client = new OpenAiClient('test-key');
        $ai = Toolkit::createAi([
            'client' => $client,
            'model' => 'gpt-5-mini',
        ]);

        self::assertInstanceOf(PhoreAi::class, $ai);
        self::assertSame($client, $ai->getOpenAiClient());
    }

    public function testCreateConfiguresTimeoutOptions(): void
    {
        $ai = Toolkit::createAi([
            'client' => 'openai:test-key',
            'timeout' => 900,
            'connect_timeout' => 20,
        ]);

        $client = $ai->getOpenAiClient();

        self::assertSame(900, $this->readProperty($client, 'timeout'));
        self::assertSame(20, $this->readProperty($client, 'connectTimeout'));
    }

    public function testOpenAiClientDefaultTimeoutIsRaised(): void
    {
        $client = new OpenAiClient('test-key');

        self::assertSame(600, $this->readProperty($client, 'timeout'));
        self::assertSame(10, $this->readProperty($client, 'connectTimeout'));
    }

    public function testOpenAiClientRejectsInvalidTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OpenAI request timeout must be at least 1 second');

        new OpenAiClient('test-key', timeout: 0);
    }

    public function testGetLastAiRequestReturnsLastCreatedRequest(): void
    {
        $request = AiRequest::text('gpt-5-mini', 'Hello');
        $client = new OpenAiClient('test-key');
        $headers = [];

        $client->createCurlHandle($request, false, $headers);

        self::assertSame($request, AiRequest::$last);
        self::assertSame($request, get_last_ai_request());
    }

    public function testGetLastAiResponseReturnsLastBuiltResponse(): void
    {
        $request = AiRequest::text('gpt-5-mini', 'Hello');
        $client = new OpenAiClient('test-key');
        $headers = [];
        $curl = $client->createCurlHandle($request, false, $headers);

        $response = $client->buildJsonResponse($curl, [], '{"id":"resp_123","status":"completed"}');

        self::assertSame($response, AiResponse::$last);
        self::assertSame($response, get_last_ai_response());
    }

    public function testNormalizeItemsRejectsInvalidInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected string, PromptType or ToolType');

        Toolkit::normalizePromptItems([new stdClass()]);
    }

    private function readProperty(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
}
