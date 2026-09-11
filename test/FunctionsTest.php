<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Client\AiRequestException;
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

    public function testSharedLoaderLoadsEveryFunctionAndCanBeIncludedAgain(): void
    {
        require dirname(__DIR__) . '/src/functions.php';
        require dirname(__DIR__) . '/src/functions.php';

        foreach ([
            'phore_ai_text', 'phore_ai_image', 'phore_ai_struct',
            'phore_ai_struct_array', 'phore_ai_edit_struct', 'phore_ai_edit_file',
            'get_last_ai_request', 'get_last_ai_response',
        ] as $function) {
            self::assertTrue(is_callable($function), $function);
        }
    }

    public function testEditFileFunctionExists(): void
    {
        self::assertTrue(function_exists('phore_ai_edit_file'));
        self::assertFalse(function_exists('phore_ai_file'));
    }

    public function testEditFileAddsFilenamesAndOriginalContentWithOnlyBatchWriteTool(): void
    {
        $firstFile = tempnam(sys_get_temp_dir(), 'phore-ai-edit-file-');
        $secondFile = tempnam(sys_get_temp_dir(), 'phore-ai-edit-file-');
        self::assertIsString($firstFile);
        self::assertIsString($secondFile);
        file_put_contents($firstFile, 'First original');
        file_put_contents($secondFile, 'Second original');

        try {
            phore_ai_edit_file('Change the files.', [$firstFile, $secondFile], options: [
                'client' => new OpenAiClient(
                    'test-key',
                    baseUrl: 'http://127.0.0.1:1',
                    timeout: 1,
                    connectTimeout: 1,
                ),
            ]);
            self::fail('Expected the intentionally unreachable test client to fail.');
        } catch (AiRequestException) {
            $request = get_last_ai_request();
            self::assertNotNull($request);
            self::assertCount(1, $request->tools ?? []);
            self::assertSame('write_files', $request->tools[0]['name'] ?? null);
            self::assertSame('string', $request->tools[0]['parameters']['properties']['filenames']['items']['type'] ?? null);
            self::assertSame('string', $request->tools[0]['parameters']['properties']['contents']['items']['type'] ?? null);
            self::assertSame($firstFile, $request->input[0]['content'][2]['filename'] ?? null);
            self::assertStringContainsString(base64_encode('First original'), $request->input[0]['content'][2]['file_data'] ?? '');
            self::assertSame($secondFile, $request->input[0]['content'][4]['filename'] ?? null);
            self::assertStringContainsString(base64_encode('Second original'), $request->input[0]['content'][4]['file_data'] ?? '');
            self::assertStringNotContainsString('get_file_content', $request->instructions ?? '');
            self::assertStringNotContainsString('batch mode', $request->instructions ?? '');
        } finally {
            @unlink($firstFile);
            @unlink($secondFile);
        }
    }

    public function testEditFileUsesEmptyPromptContentForMissingFile(): void
    {
        $fileName = sys_get_temp_dir() . '/phore-ai-missing-' . bin2hex(random_bytes(4)) . '.txt';

        try {
            phore_ai_edit_file('Create the file.', $fileName, options: [
                'client' => new OpenAiClient(
                    'test-key',
                    baseUrl: 'http://127.0.0.1:1',
                    timeout: 1,
                    connectTimeout: 1,
                ),
            ]);
            self::fail('Expected the intentionally unreachable test client to fail.');
        } catch (AiRequestException) {
            $request = get_last_ai_request();
            self::assertNotNull($request);
            self::assertSame('data:text/plain;base64,', $request->input[0]['content'][2]['file_data'] ?? null);
            self::assertFileDoesNotExist($fileName);
        }
    }

    public function testEditFileRequiresAtLeastOneFilename(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one target filename is required');

        phore_ai_edit_file('Change the files.', []);
    }

    public function testEditFileSupportsStructuredOutput(): void
    {
        $fileName = sys_get_temp_dir() . '/phore-ai-structured-' . bin2hex(random_bytes(4)) . '.txt';

        try {
            phore_ai_edit_file('Create the file.', $fileName, FunctionsTestDto::class, [
                'client' => new OpenAiClient(
                    'test-key',
                    baseUrl: 'http://127.0.0.1:1',
                    timeout: 1,
                    connectTimeout: 1,
                ),
            ]);
            self::fail('Expected the intentionally unreachable test client to fail.');
        } catch (AiRequestException) {
            $request = get_last_ai_request();
            self::assertNotNull($request);
            self::assertSame('json_schema', $request->text['format']['type'] ?? null);
        }
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

    public function testReasoningOptionsReachRequests(): void
    {
        foreach ([
            [[], ['effort' => 'low']],
            [['reasoning' => ['effort' => 'medium', 'summary' => 'auto']], ['effort' => 'medium', 'summary' => 'auto']],
            [['reasoning' => null], null],
        ] as [$options, $expected]) {
            AiRequest::$last = null;
            try {
                phore_ai_text('Hello', $options + [
                    'client' => new OpenAiClient('test-key', baseUrl: 'http://127.0.0.1:1', timeout: 1, connectTimeout: 1),
                ]);
                self::fail('Expected the intentionally unreachable test client to fail.');
            } catch (AiRequestException) {
                self::assertNotNull(AiRequest::$last);
                $body = AiRequest::$last->toArray();
                if ($expected === null) {
                    self::assertArrayNotHasKey('reasoning', $body);
                } else {
                    self::assertSame($expected, $body['reasoning']);
                }
            }
        }
    }

    public function testReasoningRejectsInvalidOptionBeforeCreatingClient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reasoning must be an array or null.');

        Toolkit::createAi(['reasoning' => 'low']);
    }

    public function testReasoningConfigurationClonesFacade(): void
    {
        $ai = new PhoreAi(new OpenAiClient('test-key'));
        $configured = $ai->withReasoning(['effort' => 'high']);

        self::assertNotSame($ai, $configured);
        self::assertSame(['effort' => 'low'], $this->readProperty($ai, 'reasoning'));
        self::assertSame(['effort' => 'high'], $this->readProperty($configured, 'reasoning'));
        self::assertNull($this->readProperty($configured->withReasoning(null), 'reasoning'));
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
