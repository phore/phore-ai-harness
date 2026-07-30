<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Keystore\Keystore;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\PromptType\TextPrompt;
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

    public function testOpenAiClientCreateResponseAgainstRealApi(): void
    {
        $client = new OpenAiClient();
        $response = $client->createResponse(new AiRequest(
            model: self::MODEL,
            input: 'Reply with exactly this token and no extra text: phore-ai-e2e-ok',
            maxOutputTokens: 256,
            extraBody: ['reasoning' => ['effort' => 'minimal']],
        ));

        self::assertSame(200, $response->statusCode);
        self::assertTrue($response->isCompleted());
        self::assertStringContainsString('phore-ai-e2e-ok', strtolower($response->getOutputText()));
    }

    public function testPhoreAiRunAgainstRealApi(): void
    {
        $output = (new PhoreAi())
            ->withModel(self::MODEL)
            ->with(
                new SystemPrompt('Be deterministic. Return only the requested token.'),
                new TextPrompt('Reply with exactly this token and no extra text: phore-ai-run-ok'),
            )
            ->run();

        self::assertStringContainsString('phore-ai-run-ok', strtolower($output));
    }

    public function testPhoreAiRunCastedAgainstRealApi(): void
    {
        $answer = (new PhoreAi())
            ->withModel(self::MODEL)
            ->with(new TextPrompt(
                'Create the structured response. Set answer exactly to "structured-ok" and number exactly to 7.',
            ))
            ->runCasted(PhoreAiOpenAiE2eAnswer::class);

        self::assertSame('structured-ok', $answer->answer);
        self::assertSame(7, $answer->number);
    }

    public function testPhoreAiRunImageAgainstRealApi(): void
    {
        $image = (new PhoreAi())
            ->withModel(self::MODEL)
            ->with(new TextPrompt(
                'Generate a tiny simple PNG icon: a red circle centered on a plain white background. No text.',
            ))
            ->runImage('image/png');

        self::assertSame('image/png', $image->contentType);
        self::assertSame('png', $image->fileExtension);
        self::assertGreaterThan(100, strlen($image->data));

        $fileName = sys_get_temp_dir() . '/phore-ai-e2e-image-' . bin2hex(random_bytes(4)) . '.png';
        $image->saveToFile($fileName);

        self::assertFileExists($fileName);
        self::assertSame(filesize($fileName), strlen($image->data));
    }
}
