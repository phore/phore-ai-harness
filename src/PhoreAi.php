<?php

declare(strict_types=1);

namespace Phore\AiHarness;

use InvalidArgumentException;
use JsonException;
use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Client\AiResponse;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Client\OpenAI\OpenAiPromptTypeConverter;
use Phore\AiHarness\Helper\DataUrl;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\Keystore\Keystore;
use Phore\AiHarness\OutputFormat\FileOutput;
use Phore\AiHarness\OutputFormat\ImageOutput;
use Phore\AiHarness\OutputFormat\OutputFormat;
use Phore\AiHarness\OutputFormat\StructOutput;
use Phore\AiHarness\OutputFormat\TextOutput;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\Result\ImageResultType;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\ImageGenerationTool;
use Phore\AiHarness\ToolType\ToolType;
use Phore\Schema\Generator\JsonSchema\JsonSchemaCompatibility;
use Phore\Schema\Generator\JsonSchema\JsonSchemaGeneratorOptions;
use Phore\Schema\Parser\SchemaParser;

final class PhoreAi
{
    private OpenAiClient $openAiClient;

    public function __construct(OpenAiClient|string|null $client = null, private string $model = 'gpt-5-mini')
    {
        $client ??= 'openai:';

        if ($client instanceof OpenAiClient) {
            $this->openAiClient = $client;
            return;
        }

        $this->openAiClient = $this->createClientFromDsn($client);
    }

    public function getOpenAiClient(): OpenAiClient
    {
        return $this->openAiClient;
    }

    public function withModel(string $model): self
    {
        if ($model === '') {
            throw new InvalidArgumentException('Model must not be empty.');
        }

        return clone($this, [
            'model' => $model,
        ]);
    }

    private function createClientFromDsn(string $dsn): OpenAiClient
    {
        $prefix = 'openai:';
        if (!str_starts_with($dsn, $prefix)) {
            throw new InvalidArgumentException('Unsupported AI client DSN. Expected "openai:<apikey>".');
        }

        $apiKey = substr($dsn, strlen($prefix));
        if ($apiKey === '') {
            $apiKey = Keystore::instance()->getKey('open_ai');
        }

        return new OpenAiClient($apiKey);
    }


    /**
     * @var list<PromptType>
     */
    private array $prompts = [];

    /**
     * @var list<ToolType>
     */
    private array $tools = [];

    private ?OutputFormat $outputFormat = null;

    public function with(PromptType|ToolType ...$items): self
    {
        $prompts = [];
        $tools = [];

        foreach ($items as $item) {
            if ($item instanceof ToolType) {
                $tools[] = $item;
                continue;
            }

            $prompts[] = $item;
        }

        return clone($this, [
            'prompts' => $prompts,
            'tools' => $tools,
        ]);
    }

    public function withOutput(OutputFormat $outputFormat): self
    {
        return clone($this, [
            'outputFormat' => $outputFormat,
        ]);
    }

    /**
     * Sends the configured prompts to OpenAI and returns the response output text.
     *
     * @throws JsonException
     */
    public function run(): string
    {
        $request = (new OpenAiPromptTypeConverter())->toAiRequest($this->model, $this->prompts);
        if ($this->tools !== []) {
            $request = $request->withTools(...$this->tools);
        }
        $request = $this->applyOutputFormat($request, $this->outputFormat);

        $response = $this->openAiClient->createResponse($request);
        $response = $this->resolveCallbackToolCalls($request, $response);

        return $response->getOutputText();
    }

    public function runImage(string $contentType = 'image/png'): ImageResultType
    {
        $instance = $this;
        if (!$this->hasTool(ImageGenerationTool::class)) {
            $instance = clone($this, [
                'tools' => [...$this->tools, new ImageGenerationTool(output_format: DataUrl::openAiImageOutputFormatFromContentType($contentType))],
            ]);
        }

        $request = (new OpenAiPromptTypeConverter())->toAiRequest($instance->model, $instance->prompts);
        if ($instance->tools !== []) {
            $request = $request->withTools(...$instance->tools);
        }

        return $instance->openAiClient->buildImageResponse(
            $instance->openAiClient->createResponse($request),
            $contentType,
        );
    }

    /**
     * Runs the prompt with OpenAI structured output and hydrates the JSON response into the requested class.
     *
     * @template T of object
     * @param class-string<T> $outputClass
     * @return T
     * @throws JsonException
     */
    public function runCasted(string $outputClass): object
    {
        if (!class_exists($outputClass)) {
            throw new InvalidArgumentException('Output class does not exist: ' . $outputClass);
        }

        $outputFormat = new StructOutput(
            $outputClass,
            jsonSchemaOptions: new JsonSchemaGeneratorOptions(JsonSchemaCompatibility::OpenAiStructuredOutput),
        );

        $output = (clone($this, [
            'outputFormat' => $outputFormat,
        ]))->run();

        $data = Toolkit::decodeJsonOutput($output);

        /** @var T $object */
        $object = (new SchemaParser())->parseClass($outputClass)->hydrate($data);
        return $object;
    }

    private function resolveCallbackToolCalls(AiRequest $request, AiResponse $response): AiResponse
    {
        $callbackTools = $this->callbackToolsByName();
        if ($callbackTools === []) {
            return $response;
        }

        for ($iteration = 0; $iteration < 5; $iteration++) {
            $outputs = $this->callbackToolCallOutputs($response, $callbackTools);
            if ($outputs === []) {
                return $response;
            }

            $responseId = $response->getId();
            if ($responseId === null) {
                return $response;
            }

            $nextRequest = new AiRequest(
                model: $this->model,
                input: $outputs,
                previousResponseId: $responseId,
                tools: $request->tools,
            );
            $response = $this->openAiClient->createResponse($nextRequest);
        }

        return $response;
    }

    /**
     * @return array<string, CallbackTool>
     */
    private function callbackToolsByName(): array
    {
        $callbackTools = [];
        foreach ($this->tools as $tool) {
            if ($tool instanceof CallbackTool) {
                $callbackTools[$tool->name()] = $tool;
            }
        }

        return $callbackTools;
    }

    /**
     * @param array<string, CallbackTool> $callbackTools
     * @return list<array{type: string, call_id: string, output: string}>
     * @throws JsonException
     */
    private function callbackToolCallOutputs(AiResponse $response, array $callbackTools): array
    {
        $output = $response->body['output'] ?? null;
        if (!is_array($output)) {
            return [];
        }

        $toolOutputs = [];
        foreach ($output as $item) {
            if (!is_array($item) || ($item['type'] ?? null) !== 'function_call') {
                continue;
            }
            if (!isset($item['name'], $item['call_id'], $item['arguments']) || !is_string($item['name']) || !is_string($item['call_id']) || !is_string($item['arguments'])) {
                continue;
            }
            if (!isset($callbackTools[$item['name']])) {
                continue;
            }

            $toolOutputs[] = [
                'type' => 'function_call_output',
                'call_id' => $item['call_id'],
                'output' => $this->invokeCallbackTool($callbackTools[$item['name']], $item['arguments']),
            ];
        }

        return $toolOutputs;
    }

    /**
     * @throws JsonException
     */
    private function invokeCallbackTool(CallbackTool $tool, string $argumentsJson): string
    {
        $arguments = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($arguments) || array_is_list($arguments)) {
            throw new InvalidArgumentException('Callback tool arguments must decode to a JSON object.');
        }

        $result = call_user_func_array($tool->callback(), $arguments);

        return is_string($result) ? $result : Toolkit::jsonEncode($result);
    }

    /**
     * @param class-string<ToolType> $class
     */
    private function hasTool(string $class): bool
    {
        foreach ($this->tools as $tool) {
            if ($tool instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function applyOutputFormat(AiRequest $request, ?OutputFormat $outputFormat): AiRequest
    {
        if ($outputFormat === null) {
            return $request;
        }

        if ($outputFormat instanceof TextOutput) {
            if ($outputFormat->description === null) {
                return $request;
            }

            return $request->withInstructions(Toolkit::appendInstructions(
                $request->instructions,
                'Expected text output: ' . $outputFormat->description,
            ));
        }

        if ($outputFormat instanceof StructOutput) {
            return $request->withOutputSchema(
                Toolkit::schemaName($outputFormat->className()),
                $outputFormat->jsonSchema(),
                $outputFormat->description,
            );
        }

        if ($outputFormat instanceof FileOutput || $outputFormat instanceof ImageOutput) {
            return $request->withInstructions(Toolkit::appendInstructions(
                $request->instructions,
                'Return the result as ' . Toolkit::jsonEncode($outputFormat->toArray()) . '.',
            ));
        }

        throw new InvalidArgumentException('Unsupported OutputFormat: ' . $outputFormat::class);
    }

}
