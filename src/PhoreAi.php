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
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\Logging\RunContext;
use Phore\AiHarness\Logging\Redactor;
use Phore\AiHarness\OutputFormat\FileOutput;
use Phore\AiHarness\OutputFormat\ImageOutput;
use Phore\AiHarness\OutputFormat\OutputFormat;
use Phore\AiHarness\OutputFormat\StructOutput;
use Phore\AiHarness\OutputFormat\StructPatchOutput;
use Phore\AiHarness\OutputFormat\TextOutput;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\Result\ImageResultType;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\ImageGenerationTool;
use Phore\AiHarness\ToolType\RecoverableToolException;
use Phore\AiHarness\ToolType\ToolType;
use Phore\Schema\Generator\JsonSchema\JsonSchemaCompatibility;
use Phore\Schema\Generator\JsonSchema\JsonSchemaGeneratorOptions;
use Phore\Schema\Parser\SchemaParser;

final class PhoreAi
{
    /** Hard limit on executed callback rounds, including correction attempts. */
    public const MAX_CALLBACK_ROUNDS = 5;

    private OpenAiClient $openAiClient;

    public function __construct(OpenAiClient|string|null $client = null, private string $model = 'gpt-5-mini', private ?LoggerInterface $logger = null)
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

    public function withLogger(?LoggerInterface $logger): self
    {
        return clone($this, ['logger' => $logger]);
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
        return $this->executeRun(fn (?RunContext $context) => $this->runInternal($context));
    }

    private function runInternal(?RunContext $context = null): string
    {
        $request = (new OpenAiPromptTypeConverter())->toAiRequest($this->model, $this->prompts);
        if ($this->tools !== []) {
            $request = $request->withTools(...$this->tools);
        }
        $request = $this->applyOutputFormat($request, $this->outputFormat);

        $response = $this->sendRequest($request, $context);
        $response = $this->resolveCallbackToolCalls($request, $response, $context);

        return $response->getOutputText();
    }

    public function runImage(string $contentType = 'image/png'): ImageResultType
    {
        return $this->executeRun(fn (?RunContext $context) => $this->runImageInternal($contentType, $context));
    }

    private function runImageInternal(string $contentType = 'image/png', ?RunContext $context = null): ImageResultType
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

        $response = $instance->sendRequest($request, $context, false);
        if ($context !== null) {
            $response = $instance->resolveCallbackToolCalls($request, $response, $context);
        }
        return $instance->openAiClient->buildImageResponse($response, $contentType);
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
        return $this->executeRun(fn (?RunContext $context) => $this->runCastedInternal($outputClass, $context));
    }

    private function runCastedInternal(string $outputClass, ?RunContext $context = null): object
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
        ]))->runInternal($context);

        $data = Toolkit::decodeJsonOutput($output);

        /** @var T $object */
        $object = (new SchemaParser())->parseClass($outputClass)->hydrate($data);
        return $object;
    }

    /**
     * Runs the prompt with OpenAI structured output and hydrates a list of objects into the requested class.
     *
     * OpenAI structured output requires an object as root schema, so the model returns
     * `{ "items": [...] }` and this method hydrates each item in that list.
     *
     * @template T of object
     * @param class-string<T> $outputClass
     * @return list<T>
     * @throws JsonException
     */
    public function runCastedArray(string $outputClass): array
    {
        return $this->executeRun(fn (?RunContext $context) => $this->runCastedArrayInternal($outputClass, $context));
    }

    private function runCastedArrayInternal(string $outputClass, ?RunContext $context = null): array
    {
        if (!class_exists($outputClass)) {
            throw new InvalidArgumentException('Output class does not exist: ' . $outputClass);
        }

        $schemaParser = new SchemaParser();
        $classSchema = $schemaParser->parseClass($outputClass);
        $itemSchema = $classSchema
            ->toJsonSchema(new JsonSchemaGeneratorOptions(JsonSchemaCompatibility::OpenAiStructuredOutput))
            ->toArray();

        $request = (new OpenAiPromptTypeConverter())->toAiRequest($this->model, $this->prompts);
        if ($this->tools !== []) {
            $request = $request->withTools(...$this->tools);
        }

        $request = $request->withOutputSchema(
            substr(Toolkit::schemaName($outputClass) . 'List', 0, 64),
            [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => $itemSchema,
                    ],
                ],
                'required' => ['items'],
                'additionalProperties' => false,
            ],
            'Return the result as an object with an items array. Each item must match the requested PHP class schema.',
        );

        $response = $this->sendRequest($request, $context);
        $response = $this->resolveCallbackToolCalls($request, $response, $context);
        $data = Toolkit::decodeJsonOutputValue($response->getOutputText());
        $items = is_array($data) && array_is_list($data) ? $data : (is_array($data) ? ($data['items'] ?? null) : null);

        if (!is_array($items) || !array_is_list($items)) {
            throw new InvalidArgumentException('Expected structured array output as a list or as an object with list property "items".');
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Expected every structured array item to be an object.');
            }

            /** @var T $object */
            $object = $classSchema->hydrate($item);
            $result[] = $object;
        }

        return $result;
    }

    private function resolveCallbackToolCalls(AiRequest $request, AiResponse $response, ?RunContext $context = null): AiResponse
    {
        $callbackTools = $this->callbackToolsByName();
        if ($callbackTools === []) {
            return $response;
        }

        for ($iteration = 0; $iteration < self::MAX_CALLBACK_ROUNDS; $iteration++) {
            $outputs = $this->callbackToolCallOutputs($response, $callbackTools, $context);
            if ($outputs === []) {
                return $response;
            }

            $responseId = $response->getId();
            if ($responseId === null) {
                if ($context !== null) {
                    throw new \RuntimeException('Callback response is missing its response ID.');
                }
                return $response;
            }

            $nextRequest = new AiRequest(
                model: $this->model,
                input: $outputs,
                previousResponseId: $responseId,
                tools: $request->tools,
            );
            if ($context !== null) {
                // Keep instructions and structured-output constraints on every debug follow-up.
                $nextRequest = clone($request, ['input' => $outputs, 'previousResponseId' => $responseId]);
                if ($context->retryPending) {
                    $context->retries++;
                    $context->retryPending = false;
                }
            }
            $response = $this->sendRequest($nextRequest, $context);
        }

        if ($context !== null) {
            foreach ($response->body['output'] ?? [] as $item) {
                if (is_array($item) && ($item['type'] ?? null) === 'function_call' && isset($callbackTools[$item['name'] ?? ''])) {
                    $error = new \RuntimeException('Callback round limit (' . self::MAX_CALLBACK_ROUNDS . ') reached with unresolved tool calls.');
                    $context->error($error, ['limit' => self::MAX_CALLBACK_ROUNDS], 'Callback round limit reached; unresolved tool calls remain.');
                    throw $error;
                }
            }
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
    private function callbackToolCallOutputs(AiResponse $response, array $callbackTools, ?RunContext $context = null): array
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
                'output' => $context === null
                    ? $this->invokeCallbackTool($callbackTools[$item['name']], $item['arguments'])
                    : $this->invokeLoggedCallback($callbackTools[$item['name']], $item['arguments'], $item['call_id'], $context),
            ];
        }

        return $toolOutputs;
    }

    private function executeRun(callable $run): mixed
    {
        $context = $this->logger === null ? null : new RunContext($this->logger, $this->model);
        $status = 'failed';
        try {
            $context?->emit('run_start');
            $result = $run($context);
            $status = 'ok';
            return $result;
        } catch (\Throwable $error) {
            $context?->error($error);
            throw $error;
        } finally {
            $context?->finish($status);
        }
    }

    private function sendRequest(AiRequest $request, ?RunContext $context, bool $stream = true): AiResponse
    {
        if ($context === null) {
            return $this->openAiClient->createResponse($request);
        }
        // Image generation has no compatible text streaming contract.
        $stream = $stream && !$this->hasTool(ImageGenerationTool::class);
        $context->requests++;
        $context->emit('request_start', ['request' => $context->requests, 'stream' => $stream]);
        $started = hrtime(true);
        try {
            $response = $stream
                ? $this->openAiClient->streamResponse($request, static function (array $event) use ($context): void {
                    if (($event['type'] ?? null) === 'response.output_text.delta' && is_string($event['delta'] ?? null)) {
                        $context->text($event['delta']);
                    }
                    if (in_array($event['type'] ?? null, ['response.failed', 'response.incomplete', 'error'], true)) {
                        throw new \RuntimeException('Streaming response did not complete successfully.');
                    }
                })
                : $this->openAiClient->createResponse($request);
            $context->addResponse($response);
            return $response;
        } finally {
            $context->durationApi += (hrtime(true) - $started) / 1e9;
            $context->flushText();
        }
    }

    private function invokeLoggedCallback(CallbackTool $tool, string $json, string $callId, RunContext $context): string
    {
        $details = ['tool' => $tool->name(), 'call_id' => $callId];
        $context->emit('tool_start', [...$details, 'args' => Redactor::arguments($json)]);
        $context->toolCalls++;
        $started = hrtime(true);
        $status = 'failed';
        try {
            try {
                $root = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
                if (!$root instanceof \stdClass) {
                    throw new InvalidArgumentException('Callback arguments must be a JSON object.');
                }
                $arguments = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                $result = call_user_func_array($tool->callback(), $arguments);
            } catch (RecoverableToolException $error) {
                $context->error($error, $details, 'Recoverable tool error; correct the input or choose another approach.', deduplicate: false);
                $context->retryPending = true;
                return $this->recoverableToolOutput($error);
            }
            // Serialization failures are internal errors, not a reason to repeat a side effect.
            $output = is_string($result) ? $result : Toolkit::jsonEncode($result);
            $status = 'ok';
            $context->emit('tool_result', [...$details, 'bytes' => strlen($output)]);
            return $output;
        } catch (\Throwable $error) {
            $context->error($error, $details);
            throw $error;
        } finally {
            $duration = (hrtime(true) - $started) / 1e9;
            $context->durationTools += $duration;
            $context->emit('tool_end', [...$details, 'status' => $status, 'duration' => $duration]);
        }
    }

    /**
     * @throws JsonException
     */
    private function invokeCallbackTool(CallbackTool $tool, string $argumentsJson): string
    {
        $decodedRoot = json_decode($argumentsJson, false, 512, JSON_THROW_ON_ERROR);
        if (!$decodedRoot instanceof \stdClass) {
            throw new InvalidArgumentException('Callback tool arguments must decode to a JSON object.');
        }

        $arguments = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($arguments)) {
            throw new InvalidArgumentException('Callback tool arguments must decode to a JSON object.');
        }

        try {
            $result = call_user_func_array($tool->callback(), $arguments);
        } catch (RecoverableToolException $exception) {
            return $this->recoverableToolOutput($exception);
        }

        return is_string($result) ? $result : Toolkit::jsonEncode($result);
    }

    private function recoverableToolOutput(RecoverableToolException $exception): string
    {
        return Toolkit::jsonEncode([
            'ok' => false,
            'error' => [
                'type' => 'recoverable_tool_error',
                'message' => $exception->getMessage(),
                'retryable' => true,
            ],
            'instruction' => 'Correct the tool input or choose another approach, then continue.',
        ]);
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

        if ($outputFormat instanceof StructPatchOutput) {
            return $request->withOutputSchema('StructPatch', $outputFormat->jsonSchema());
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
