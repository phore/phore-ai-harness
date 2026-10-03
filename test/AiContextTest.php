<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use Phore\AiHarness\AiContext;
use Phore\AiHarness\AiOptions;
use Phore\AiHarness\Client\AiRequestException;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\Edit\FileEditException;
use Phore\AiHarness\Logging\ConsoleLogger;
use Phore\AiHarness\Logging\LogEvent;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\TextPrompt;
use Phore\AiHarness\ToolType\CallbackRoundLimitException;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\WebAccessTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final readonly class ContextValue
{
    public function __construct(public string $value)
    {
    }
}

final class ContextRecordingLogger implements LoggerInterface
{
    public array $events = [];

    public function log(LogEvent $event): void
    {
        $this->events[] = $event;
    }
}

final class AiContextTest extends TestCase
{
    private static $server;
    private static array $pipes = [];
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is needed for the loopback fixture.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            self::markTestSkipped('Loopback sockets unavailable.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$baseUrl = 'http://' . $address;
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$server = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/context-server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $nullDevice, 'a'], 2 => ['file', $nullDevice, 'a']],
            self::$pipes,
        );
        if (!is_resource(self::$server)) {
            self::fail('Cannot start context fixture.');
        }
        // Serverstart abwarten, ohne einen externen Dienst anzusprechen.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(10000);
        }
        self::tearDownAfterClass();
        self::fail('Context fixture did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            foreach (self::$pipes as $pipe) {
                fclose($pipe);
            }
            proc_close(self::$server);
        }
    }

    protected function tearDown(): void
    {
        AiContextRegistry::clear();
    }

    private function client(string $scenario = 'normal'): OpenAiClient
    {
        return new OpenAiClient('fixture-key', baseUrl: self::$baseUrl . '/' . $scenario, timeout: 5);
    }

    private function context(string $scenario = 'normal', array $prompts = []): AiContext
    {
        return new AiContext(
            prompts: $prompts,
            options: new AiOptions(client: $this->client($scenario)),
        );
    }

    private function lastRequest(): array
    {
        return get_last_ai_response()->body['fixture_request'];
    }

    public function testRegistryResolvesNullNamesAndInstancesWithoutAProviderCall(): void
    {
        $context = new AiContext();
        self::assertSame($context, AiContextRegistry::resolve(['ai_context' => $context]));
        self::assertNotSame(AiContextRegistry::resolve(), AiContextRegistry::resolve());
        self::assertNotSame(AiContextRegistry::resolve(['ai_context' => null]), AiContextRegistry::resolve());
        $named = AiContextRegistry::resolve(['ai_context' => 'default']);
        self::assertSame($named, AiContextRegistry::resolve(['ai_context' => 'default']));
        self::assertSame($named, AiContextRegistry::get('default'));
        AiContextRegistry::forget('default');
        self::assertNull(AiContextRegistry::get('default'));
        self::assertNull($named->getResponseId());
    }

    public static function invalidSelectors(): iterable
    {
        yield [''];
        yield ['  '];
        yield [true];
        yield [42];
        yield [[]];
        yield [new \stdClass()];
    }

    #[DataProvider('invalidSelectors')]
    public function testInvalidRegistrySelectorsFailEarly(mixed $selection): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AiContextRegistry::resolve(['ai_context' => $selection]);
    }

    public function testLegacyFunctionsShareNamedOrObjectContextsAndStayIsolatedByDefault(): void
    {
        $options = ['ai_context' => 'job', 'client' => $this->client()];
        self::assertSame('ready', phore_ai_text('First', $options));
        $first = AiContextRegistry::get('job')->getResponseId();
        phore_ai_text('Second', ['ai_context' => 'job']);
        self::assertSame($first, $this->lastRequest()['previous_response_id']);
        $context = AiContextRegistry::get('job');
        $second = $context->getResponseId();
        phore_ai_struct('Third', ContextValue::class, ['ai_context' => $context]);
        self::assertSame($second, $this->lastRequest()['previous_response_id']);
        phore_ai_text('Isolated', ['client' => $this->client()]);
        self::assertArrayNotHasKey('previous_response_id', $this->lastRequest());
    }

    public function testEveryOperationAdvancesOneSharedConversationWithoutLeakingToolsOrSchema(): void
    {
        $context = $this->context();
        $context->text('Start');
        $parent = $context->getResponseId();
        self::assertSame('ready', $context->struct('Object', ContextValue::class)->value);
        self::assertSame($parent, $this->lastRequest()['previous_response_id']);
        $parent = $context->getResponseId();
        self::assertSame('ready', $context->structArray('List', ContextValue::class)[0]->value);
        self::assertSame($parent, $this->lastRequest()['previous_response_id']);
        $parent = $context->getResponseId();
        self::assertSame('fixture-image', $context->image('Image')->data);
        self::assertSame($parent, $this->lastRequest()['previous_response_id']);
        self::assertArrayNotHasKey('text', $this->lastRequest());
        $parent = $context->getResponseId();
        $context->text('Finish');
        self::assertSame($parent, $this->lastRequest()['previous_response_id']);
        self::assertArrayNotHasKey('tools', $this->lastRequest());
        self::assertArrayNotHasKey('text', $this->lastRequest());
    }

    public function testModelOverrideIsPerCallAndKeepsTheResponseParent(): void
    {
        $context = $this->context();
        $context->text('Start');
        $parent = $context->getResponseId();
        $context->text('Other model', options: ['model' => 'gpt-5-nano']);
        self::assertSame('gpt-5-nano', $this->lastRequest()['model']);
        self::assertSame($parent, $this->lastRequest()['previous_response_id']);
        $context->text('Default model');
        self::assertSame('gpt-5-mini', $this->lastRequest()['model']);
    }

    public function testCloneAndNamedAndAnonymousCheckpointsOnlyMoveTheCursor(): void
    {
        $context = $this->context();
        $context->setCheckpoint('empty');
        $context->text('Shared source');
        $shared = $context->getResponseId();
        $context->setCheckpoint('source');
        $fork = clone $context;
        $fork->text('Alternative');
        self::assertSame($shared, $context->getResponseId());
        self::assertNotSame($shared, $fork->getResponseId());
        $context->text('Main continuation');
        $context->setCheckpoint();
        $anonymous = $context->getResponseId();
        $context->text('Temporary continuation');
        $usageBeforeRollback = get_ai_usage_stats();
        $context->rollback();
        self::assertSame($anonymous, $context->getResponseId());
        self::assertSame($usageBeforeRollback, get_ai_usage_stats());
        $context->rollback('source')->text('New branch');
        self::assertSame($shared, $this->lastRequest()['previous_response_id']);
        $context->rollback('empty')->text('New root');
        self::assertArrayNotHasKey('previous_response_id', $this->lastRequest());
        // Checkpoints werden nicht konsumiert; der zuletzt gesetzte bleibt verfuegbar.
        $context->rollback();
        self::assertSame($anonymous, $context->getResponseId());
        $fork->setCheckpoint('fork-only');
        $this->expectException(\LogicException::class);
        $context->rollback('fork-only');
    }

    public function testRepeatedNamedCheckpointBecomesLatestAndMissingMarkerThrows(): void
    {
        $context = $this->context();
        $context->setCheckpoint('marker')->text('First');
        $first = $context->getResponseId();
        $context->setCheckpoint('marker')->text('Second');
        $context->rollback();
        self::assertSame($first, $context->getResponseId());
        $this->expectException(\LogicException::class);
        (new AiContext())->rollback();
    }

    public function testContextContentLoadsOnceWhileToolsRemainAvailable(): void
    {
        $context = new AiContext(
            prompts: [
                new TextPrompt('Persistent briefing', alias: 'briefing'),
                new WebAccessTool(),
            ],
            options: new AiOptions(client: $this->client()),
        );

        $context->text('First task.');
        $first = $this->lastRequest();
        self::assertStringContainsString('Persistent briefing', json_encode($first['input']));
        self::assertContains('web_search_preview', array_column($first['tools'] ?? [], 'type'));

        $parent = $context->getResponseId();
        $context->text('Second task.');
        $second = $this->lastRequest();
        self::assertSame($parent, $second['previous_response_id']);
        self::assertStringNotContainsString('Persistent briefing', json_encode($second['input']));
        self::assertContains('web_search_preview', array_column($second['tools'] ?? [], 'type'));
    }

    public function testContextToolsAreAvailableAcrossAllOperationsIncludingStructPatching(): void
    {
        $questions = [];
        $context = $this->context('ask', [new CallbackTool(
            static function (string $question) use (&$questions): string {
                $questions[] = $question;
                return 'Use the approved variant.';
            },
            'ask_user_question',
        )]);
        self::assertSame([], $questions);
        $context->text('Ask first.');
        self::assertSame('ONE TWO', $context->text('Ask, then edit.', 'one two'));
        $context->struct('Ask, then extract.', ContextValue::class);
        $context->structArray('Ask, then extract a list.', ContextValue::class);
        $original = new ContextValue('original');
        self::assertSame('updated', $context->struct('Ask, then update.', $original)->value);
        self::assertSame('original', $original->value);
        self::assertSame('fixture-image', $context->image('Ask, then generate.')->data);
        $file = tempnam(sys_get_temp_dir(), 'context-file-');
        try {
            file_put_contents($file, 'original');
            $context->setCheckpoint('before-file');
            $context->file('Ask, then edit.', $file, ['output_class' => ContextValue::class]);
            self::assertSame('updated', file_get_contents($file));
            self::assertSame('json_schema', $this->lastRequest()['text']['format']['type']);
            $fileResponse = $context->getResponseId();
            $context->text('Continue after file.');
            self::assertNotSame($fileResponse, $context->getResponseId());
            $context->rollback('before-file');
            self::assertSame('updated', file_get_contents($file));
        } finally {
            unlink($file);
        }
        self::assertCount(8, $questions);
    }

    public function testDuplicateContextCallbackToolNamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AiContext(prompts: [
            new CallbackTool(static fn (): string => 'one', 'same_name'),
            new CallbackTool(static fn (): string => 'two', 'same_name'),
        ]);
    }

    public function testReservedContextCallbackCannotOverrideEditPrimitive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AiContext(prompts: [
            new CallbackTool(static fn (): string => 'no', 'write_files'),
        ]);
    }

    public function testReentrantUseOfSameContextIsRejectedWithoutStartingAnotherRequest(): void
    {
        $context = null;
        $callback = new CallbackTool(
            static function (string $question) use (&$context): string {
                return $context->text('Nested use must fail.');
            },
            'ask_user_question',
        );
        $context = new AiContext(
            prompts: [$callback],
            options: new AiOptions(client: $this->client('ask')),
        );

        $this->expectException(\LogicException::class);
        $context->text('Ask first.');
    }

    public function testTextEditingReturnsAssembledTextAndCanCorrectItsSearch(): void
    {
        $context = $this->context('text-retry');
        self::assertSame('ONE TWO', phore_ai_text('Correct both.', ['ai_context' => $context, 'input' => 'one two']));
        self::assertNotNull($context->getResponseId());
        self::assertStringNotContainsString('one two', json_encode($this->lastRequest()['input']));
        $schema = $this->lastRequest()['tools'][0]['parameters'];
        self::assertSame('array', $schema['properties']['edits']['type']);
        self::assertStringContainsString('"search"', json_encode($schema));
        self::assertStringContainsString('"replacement"', json_encode($schema));
        self::assertSame('new text', $this->context('rewrite')->text('Rewrite empty input.', ''));
        self::assertSame('ready', $this->context()->text('Generate.', null));
    }

    public function testFiveRoundLimitAlsoAppliesWithoutLogging(): void
    {
        $context = $this->context('text-limit');
        $before = get_ai_usage_stats()['requests'];
        try {
            $context->text('Edit.', 'one two');
            self::fail('Expected callback limit.');
        } catch (CallbackRoundLimitException) {
        }
        self::assertSame(PhoreAi::MAX_CALLBACK_ROUNDS + 1, get_ai_usage_stats()['requests'] - $before);
        self::assertNull($context->getResponseId());
    }

    public static function fileScenarios(): iterable
    {
        yield ['file-retry', false];
        yield ['file-limit', true];
        yield ['file-unresolved', true];
    }

    #[DataProvider('fileScenarios')]
    public function testFileCorrectionsKeepSuccessesAndExposeIncompleteOutcomes(string $scenario, bool $fails): void
    {
        $first = tempnam(sys_get_temp_dir(), 'context-first-');
        $second = tempnam(sys_get_temp_dir(), 'context-second-');
        file_put_contents($first, 'one');
        file_put_contents($second, 'repeat one; repeat two');
        try {
            try {
                phore_ai_edit_file('Edit both.', [$first, $second], options: ['ai_context' => $this->context($scenario)]);
                self::assertFalse($fails, 'Expected incomplete edit exception.');
            } catch (FileEditException $error) {
                self::assertTrue($fails);
                self::assertSame('applied', $error->files[0]['status']);
                self::assertSame('failed', $error->files[1]['status']);
                if ($scenario === 'file-limit') {
                    self::assertInstanceOf(CallbackRoundLimitException::class, $error->getPrevious());
                }
            }
            self::assertSame('ONE', file_get_contents($first));
            self::assertSame($fails ? 'repeat one; repeat two' : 'repeat one; fixed two', file_get_contents($second));
        } finally {
            unlink($first);
            unlink($second);
        }
    }

    public function testCachedTokensAppearInLoggerAndGlobalUsageWithoutDoubleCounting(): void
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($stream);
        $before = get_ai_usage_stats();
        $this->context()->text('Generate.', options: ['debug_log' => $logger]);
        $after = get_ai_usage_stats();
        rewind($stream);
        self::assertStringContainsString('tokens_cached=1024', stream_get_contents($stream));
        fclose($stream);
        self::assertSame(1024, $after['cachedInputTokens'] - $before['cachedInputTokens']);
        self::assertSame(2080, $after['totalTokens'] - $before['totalTokens']);
        $recording = new ContextRecordingLogger();
        $this->context('text-retry')->text('Edit.', 'one two', ['debug_log' => $recording]);
        $stats = array_values(array_filter($recording->events, static fn (LogEvent $event): bool => $event->type === 'stats'))[0]->statistics;
        self::assertSame(3072, $stats->tokens_cached);
        self::assertSame(3, $stats->requests);
        self::assertSame(1, $stats->retries);
    }

    public function testMissingCacheUsageIsDifferentFromAReportedZero(): void
    {
        foreach (['missing-cache' => null, 'zero-cache' => 0] as $scenario => $expected) {
            $logger = new ContextRecordingLogger();
            $this->context($scenario)->text('Generate.', options: ['debug_log' => $logger]);
            $stats = array_values(array_filter($logger->events, static fn (LogEvent $event): bool => $event->type === 'stats'))[0]->statistics;
            self::assertSame($expected, $stats->tokens_cached);
        }
    }

    public function testFailedRequestDoesNotOverwriteTheLastCompletedCursor(): void
    {
        $context = $this->context('request-failure');
        $context->text('Success.');
        $parent = $context->getResponseId();
        try {
            $context->text('fail-now');
            self::fail('Expected fixture failure.');
        } catch (AiRequestException) {
        }
        self::assertSame($parent, $context->getResponseId());
        $context->text('Continue.');
        self::assertSame($parent, $this->lastRequest()['previous_response_id']);
    }

    public function testIncompleteResponsesNeverExecuteCallbacksOrAdvanceTheCursor(): void
    {
        foreach ([false, new ContextRecordingLogger()] as $logger) {
            $called = 0;
            $context = $this->context('response-failure', [new CallbackTool(
                static function (string $question) use (&$called): string {
                    $called++;
                    return 'not reached';
                },
                'ask_user_question',
            )]);
            $context->text('Success.');
            $parent = $context->getResponseId();
            try {
                $context->text('fail-now', options: ['debug_log' => $logger]);
                self::fail('Expected incomplete response error.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('did not complete', $error->getMessage());
            }
            self::assertSame(0, $called);
            self::assertSame($parent, $context->getResponseId());
        }
    }

    public function testLegacyParameterNamesAndCleanContextMethodsRemainStable(): void
    {
        $signatures = [
            'phore_ai_text' => ['prompts', 'options'],
            'phore_ai_image' => ['prompts', 'options'],
            'phore_ai_struct' => ['prompts', 'className', 'options'],
            'phore_ai_struct_array' => ['prompts', 'className', 'options'],
            'phore_ai_edit_struct' => ['prompts', 'target', 'options'],
            'phore_ai_edit_file' => ['prompts', 'filenames', 'className', 'options'],
        ];
        foreach ($signatures as $function => $names) {
            self::assertSame($names, array_map(static fn (\ReflectionParameter $parameter): string => $parameter->name, (new \ReflectionFunction($function))->getParameters()));
        }
        self::assertFalse(method_exists(AiContext::class, 'editText'));
        self::assertFalse(method_exists(AiContext::class, 'editFile'));
        self::assertFalse(method_exists(AiContext::class, 'editStruct'));
        self::assertSame(['prompts', 'input', 'options'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->name, (new \ReflectionMethod(AiContext::class, 'text'))->getParameters()));
    }
}
