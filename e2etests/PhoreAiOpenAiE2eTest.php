<?php

declare(strict_types=1);

use Phore\AiHarness\Keystore\Keystore;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\PromptType\TextPrompt;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\McpTool;
use Phore\AiHarness\ToolType\TaskErrorException;
use Phore\AiHarness\ToolType\TaskErrorTool;
use PHPUnit\Framework\TestCase;

final readonly class PhoreAiOpenAiE2eAnswer
{
    public function __construct(
        public string $answer,
        public int $number,
    ) {
    }
}

/**
 * End-to-end tests against the real OpenAI API.
 *
 * These tests intentionally live outside test/ and are not part of the default
 * phpunit.xml.dist suite. Run manually with:
 *
 *   vendor/bin/phpunit -c e2etests/phpunit.xml.dist
 */
final class PhoreAiOpenAiE2eTest extends TestCase
{
    private const MODEL = 'gpt-5-mini';

    protected function setUp(): void
    {
        if (!Keystore::instance()->hasKey('open_ai')) {
            self::markTestSkipped('No OpenAI API key configured in Keystore for provider open_ai.');
        }
    }

    public function testPhoreAiTextAgainstRealApi(): void
    {
        $output = phore_ai_text(
            'Reply with exactly this token and no extra text: phore-ai-e2e-ok',
            ['model' => self::MODEL],
        );

        self::assertStringContainsString('phore-ai-e2e-ok', strtolower($output));
    }

    public function testPhoreAiTextWithPromptsAgainstRealApi(): void
    {
        $output = phore_ai_text([
            new SystemPrompt('Be deterministic. Return only the requested token.'),
            new TextPrompt('Reply with exactly this token and no extra text: phore-ai-run-ok'),
        ], ['model' => self::MODEL]);

        self::assertStringContainsString('phore-ai-run-ok', strtolower($output));
    }

    public function testPhoreAiStructAgainstRealApi(): void
    {
        $answer = phore_ai_struct(
            'Create the structured response. Set answer exactly to "structured-ok" and number exactly to 7.',
            PhoreAiOpenAiE2eAnswer::class,
            ['model' => self::MODEL],
        );

        self::assertSame('structured-ok', $answer->answer);
        self::assertSame(7, $answer->number);
    }

    public function testPhoreAiTextWithMockMcpToolAgainstRealApi(): void
    {
        try {
            $answer = phore_ai_text([
                'Ask the MCP server labelled mock which tools are available. Answer in one short line starting with: mock tools:',
                new McpTool(
                    server_label: 'mock',
                    server_url: 'https://mock.iterate.com/no-auth',
                ),
            ], ['model' => self::MODEL]);
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'Error retrieving tool list from MCP server')) {
                self::markTestSkipped('Mock MCP server is currently not reachable by OpenAI: ' . $exception->getMessage());
            }

            throw $exception;
        }

        self::assertNotFalse(strpos(strtolower($answer), 'mock tools:'), $answer);
    }

    public function testPhoreAiTextWithCallbackToolAgainstRealApi(): void
    {
        $calls = [];

        $answer = phore_ai_text([
            'Call the function tool create_invoice_marker exactly once with customerId exactly "C-4242" and percent exactly 17. Then answer with the exact tool result and no extra text.',
            new CallbackTool(
                function (string $customerId, int $percent) use (&$calls): string {
                    $calls[] = [
                        'customerId' => $customerId,
                        'percent' => $percent,
                    ];

                    return 'callback-tool-e2e:' . $customerId . ':' . $percent;
                },
                name: 'create_invoice_marker',
                description: 'Creates a deterministic E2E invoice marker from customerId and percent.',
            ),
        ], ['model' => self::MODEL]);

        self::assertSame([[
            'customerId' => 'C-4242',
            'percent' => 17,
        ]], $calls);
        self::assertStringContainsString('callback-tool-e2e:C-4242:17', $answer);
    }

    public function testPhoreAiTextWithTaskErrorToolAgainstRealApi(): void
    {
        try {
            phore_ai_text([
                new TextPrompt(
                    'Search the web for "dentists in cologne"',
                ),
                new TaskErrorTool(),
            ], ['model' => self::MODEL]);
        } catch (TaskErrorException $exception) {
            self::assertStringContainsString('contradict', strtolower($exception->getMessage()));
            self::assertNotSame('', trim($exception->requestedAt));
            return;
        }

        self::fail('Expected TaskErrorException because the task contains contradictory instructions.');
    }

    public function testPhoreAiImageAgainstRealApi(): void
    {
        $image = phore_ai_image(
            'Generate a tiny simple PNG icon: a red circle centered on a plain white background. No text.',
            ['model' => self::MODEL, 'output_format' => 'png'],
        );

        self::assertSame('image/png', $image->contentType);
        self::assertSame('png', $image->fileExtension);
        self::assertGreaterThan(100, strlen($image->data));

        $fileName = sys_get_temp_dir() . '/phore-ai-e2e-image-' . bin2hex(random_bytes(4)) . '.png';
        $image->saveToFile($fileName);

        self::assertFileExists($fileName);
        self::assertSame(filesize($fileName), strlen($image->data));
    }
}
