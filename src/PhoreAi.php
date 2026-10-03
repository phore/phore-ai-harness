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
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\Result\DoResultType;
use Phore\AiHarness\Result\ImageResultType;
use Phore\AiHarness\ToolType\CallbackRoundLimitException;
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

    /** @var array<string, mixed>|null */
    private ?array $reasoning = ['effort' => 'low'];

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

    /**
     * Configures Responses API reasoning; null omits the parameter.
     *
     * @param array<string, mixed>|null $reasoning
     */
    public function withReasoning(?array $reasoning): self
    {
        return clone($this, ['reasoning' => $reasoning]);
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
    private ?string $previousResponseId = null;
    private ?string $lastResponseId = null;

    /**
     * Clone the request facade with a conversation parent, or null for a root.
     * The response must belong to the configured provider/project. This does
     * not guarantee cached input and does not modify the original facade.
     *
     * @example $request = $ai->withPreviousResponseId($responseId);
     * @see AiContext
     */
    public function withPreviousResponseId(?string $responseId): self
    {
        return clone($this, ['previousResponseId' => $responseId, 'lastResponseId' => null]);
    }

    /**
     * Return the last completed callback loop's response ID for this facade.
     * Reset at each run; null means no resumable completion was produced.
     *
     * @example $responseId = $ai->getLastResponseId();
     * @see withPreviousResponseId()
     */
    public function getLastResponseId(): ?string
    {
        return $this->lastResponseId;
    }

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

    /**
     * Execute the configured task for its side effects and conversation state.
     *
     * The final model response is a structured success/failure report instead of
     * user-facing text. Tools and callback rounds behave exactly like run(). A
     * false result is a fachlicher Misserfolg of an otherwise valid task;
     * transport errors, task-contract errors and callback exceptions still
     * propagate unchanged.
     *
     * When $throw is true, failures raise DoException. A custom class must extend
     * DoException and inherit its constructor unchanged so message, details and
     * diagnostic data can be populated predictably.
     *
     * @param bool|class-string<DoException> $throw Return false on task failure,
     *     throw DoException when true, or throw the supplied subclass.
     * @return bool True only when the model reports the requested task completed.
     * @throws DoException For a reported task failure when throwing is enabled.
     * @throws InvalidArgumentException For an invalid custom exception class.
     * @example $ok = $ai->with(new TextPrompt('Verify the source.', allowInstructions: true))->do();
     * @example $ai->with(new TextPrompt('Verify the source.', allowInstructions: true))->do(throw: true);
     * @see run()
     * @see DoResultType
     */
    public function do(bool|string $throw = false): bool
    {
        $exceptionClass = $this->resolveDoExceptionClass($throw);
        $instance = clone($this, [
            'prompts' => [
                ...$this->prompts,
                new SystemPrompt(
                    'Execute the requested task completely. The final structured result reports whether the task itself succeeded. '
                    . 'Set success=true only when the requested work was completed. Set success=false for an ordinary fachlicher '
                    . 'Misserfolg after a valid attempt. Put a concise summary in message, substantial diagnostics or relevant text '
                    . 'excerpts in details, and optional short machine-readable diagnostic strings in data. Technical failures and '
                    . 'invalid or incomplete task contracts must still use the existing error mechanisms instead of being hidden as false.'
                ),
            ],
        ]);

        /** @var DoResultType $result */
        $result = $instance->runCasted(DoResultType::class);
        $this->lastResponseId = $instance->lastResponseId;

        if ($result->success) {
            return true;
        }
        if ($exceptionClass === null) {
            return false;
        }

        throw new $exceptionClass(
            $result->message,
            $result->details === '' ? null : $result->details,
            $result->data,
        );
    }

    private function runInternal(?RunContext $context = null): string
    {
        $request = (new OpenAiPromptTypeConverter())->toAiRequest($this->model, $this->prompts);
        if ($this->previousResponseId !== null) {
            $request = $request->withFollowUp($request->input, $this->previousResponseId);
        }
        if ($this->tools !== []) {
            $request = $request->withTools(...$this->tools);
        }
        $request = $this->applyOutputFormat($request, $this->outputFormat);

        $response = $this->sendRequest($request, $context);
        $response = $this->resolveCallbackToolCalls($request, $response, $context);
        $this->lastResponseId = $response->getId();

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
        if ($this->previousResponseId !== null) {
            $request = $request->withFollowUp($request->input, $this->previousResponseId);
        }
        if ($instance->tools !== []) {
            $request = $request->withTools(...$instance->tools);
        }

        $response = $instance->sendRequest($request, $context, false);
        $response = $instance->resolveCallbackToolCalls($request, $response, $context);
        $this->lastResponseId = $response->getId();
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

        $instance = clone($this, ['outputFormat' => $outputFormat]);
        $output = $instance->runInternal($context);
        $this->lastResponseId = $instance->lastResponseId;
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
        if ($this->previousResponseId !== null) {
            $request = $request->withFollowUp($request->input, $this->previousResponseId);
        }
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
        $this->lastResponseId = $response->getId();
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

        // Das Limit und die Fortsetzungsregeln gelten unabhaengig vom Logging.
        for ($iteration = 0; $iteration < self::MAX_CALLBACK_ROUNDS; $iteration++) {
            if (!$this->hasCallbackCalls($response, $callbackTools)) {
                return $response;
            }
            $responseId = $response->getId();
            if ($responseId === null) {
                // Ohne Response-ID duerfen keine nicht fortsetzbaren Seiteneffekte starten.
                throw new \RuntimeException('Callback response is missing its response ID.');
            }
            $outputs = $this->callbackToolCallOutputs($response, $callbackTools, $context);
            if ($outputs === []) {
                throw new \RuntimeException('Callback response contains malformed tool calls.');
            }

            // Instructions, Tools und Output-Schema muessen in jeder Runde erhalten bleiben.
            $nextRequest = $request->withFollowUp($outputs, $responseId);
            if ($context !== null && $context->retryPending) {
                $context->retries++;
                $context->retryPending = false;
            }
            $response = $this->sendRequest($nextRequest, $context);
        }

        if ($this->hasCallbackCalls($response, $callbackTools)) {
            $error = new CallbackRoundLimitException('Callback round limit (' . self::MAX_CALLBACK_ROUNDS . ') reached with unresolved tool calls.');
            $context?->error($error, ['limit' => self::MAX_CALLBACK_ROUNDS], 'Callback round limit reached; unresolved tool calls remain.');
            throw $error;
        }
        return $response;
    }

    private function hasCallbackCalls(AiResponse $response, array $callbackTools): bool
    {
        foreach ($response->body['output'] ?? [] as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'function_call' && isset($callbackTools[$item['name'] ?? ''])) {
                return true;
            }
        }
        return false;
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
        $this->lastResponseId = null;
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
        if ($this->reasoning !== null) {
            $request = $request->withExtraBody(['reasoning' => $this->reasoning]);
        }

        if ($context === null) {
            $response = $this->openAiClient->createResponse($request);
            $this->assertCompletedResponse($response);
            return $response;
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
            $this->assertCompletedResponse($response);
            return $response;
        } finally {
            $context->durationApi += (hrtime(true) - $started) / 1e9;
            $context->flushText();
        }
    }

    private function assertCompletedResponse(AiResponse $response): void
    {
        // HTTP 200 allein reicht nicht: partielle Antworten duerfen keine Tools ausfuehren.
        $status = $response->body['status'] ?? null;
        if (isset($response->body['error']) || ($status !== null && $status !== 'completed')) {
            throw new \RuntimeException('Provider response did not complete successfully.');
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

    /**
     * @param bool|class-string<DoException> $throw
     * @return class-string<DoException>|null
     */
    private function resolveDoExceptionClass(bool|string $throw): ?string
    {
        if ($throw === false) {
            return null;
        }
        if ($throw === true) {
            return DoException::class;
        }
        if (!is_a($throw, DoException::class, true)) {
            throw new InvalidArgumentException('Custom do exception must extend ' . DoException::class . '.');
        }

        return $throw;
    }

    private function recoverableToolOutput(RecoverableToolException $exception): string
    {
        $output = [
            'ok' => false,
            'error' => [
                'type' => 'recoverable_tool_error',
                'message' => $exception->getMessage(),
                'retryable' => true,
            ],
            'instruction' => 'Correct the tool input or choose another approach, then continue.',
        ];
        if ($exception->result !== null) {
            $output['result'] = $exception->result;
        }
        return Toolkit::jsonEncode($output);
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
