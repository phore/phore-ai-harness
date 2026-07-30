<?php

declare(strict_types=1);

use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\ImagePrompt;
use Phore\AiHarness\PromptType\StructPrompt;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\PromptType\TextPrompt;
use PHPUnit\Framework\TestCase;

/**
 * A small DTO used by StructPrompt tests.
 */
final readonly class PromptTypeTestAddress
{
    public function __construct(
        /** City name. */
        public string $city,
        public int $zip,
    ) {
    }
}

final class PromptTypeTest extends TestCase
{
    public function testTextPrompt(): void
    {
        $prompt = new TextPrompt('Hello');

        self::assertSame('text', $prompt->type());
        self::assertSame(['type' => 'text', 'text' => 'Hello'], $prompt->toArray());
    }

    public function testTextPromptFromFile(): void
    {
        $fileName = $this->createTempFile('Text from file');

        $prompt = TextPrompt::fromFile($fileName);

        self::assertSame('Text from file', $prompt->text);
    }

    public function testSystemPrompt(): void
    {
        $prompt = new SystemPrompt('Answer in German.');

        self::assertSame('system', $prompt->type());
        self::assertSame(['type' => 'system', 'text' => 'Answer in German.'], $prompt->toArray());
    }

    public function testSystemPromptFromFile(): void
    {
        $fileName = $this->createTempFile('System from file');

        $prompt = SystemPrompt::fromFile($fileName);

        self::assertSame('System from file', $prompt->text);
    }

    public function testFilePrompt(): void
    {
        $prompt = new FilePrompt('example.txt', 'File content');

        self::assertSame('file', $prompt->type());
        self::assertSame([
            'type' => 'file',
            'fileName' => 'example.txt',
            'content' => 'File content',
        ], $prompt->toArray());
    }

    public function testFilePromptFromFile(): void
    {
        $fileName = $this->createTempFile('File prompt content');

        $prompt = FilePrompt::fromFile($fileName);

        self::assertSame($fileName, $prompt->fileName);
        self::assertSame('File prompt content', $prompt->content);
    }

    public function testImagePrompt(): void
    {
        $prompt = new ImagePrompt('https://example.test/image.png', 'image.png', 'image/png');

        self::assertSame('image', $prompt->type());
        self::assertSame([
            'type' => 'image',
            'imageUrl' => 'https://example.test/image.png',
            'fileName' => 'image.png',
            'mimeType' => 'image/png',
        ], $prompt->toArray());
    }

    public function testImagePromptFromFile(): void
    {
        $fileName = tempnam(sys_get_temp_dir(), 'phore-ai-image-');
        self::assertIsString($fileName);
        file_put_contents($fileName, base64_decode('iVBORw0KGgo=', true));

        $prompt = ImagePrompt::fromFile($fileName);

        self::assertSame($fileName, $prompt->fileName);
        self::assertStringStartsWith('data:', $prompt->imageUrl);
        self::assertStringContainsString(';base64,', $prompt->imageUrl);
    }

    public function testStructPromptWithObjectAddsDataAndJsonSchema(): void
    {
        $prompt = new StructPrompt(new PromptTypeTestAddress('Berlin', 10115));
        $array = $prompt->toArray();

        self::assertSame('struct', $prompt->type());
        self::assertTrue($prompt->hasData());
        self::assertSame(PromptTypeTestAddress::class, $array['className']);
        self::assertSame(['city' => 'Berlin', 'zip' => 10115], $array['data']);
        self::assertSame('object', $array['jsonSchema']['type']);
        self::assertSame('string', $array['jsonSchema']['properties']['city']['type']);
        self::assertSame('integer', $array['jsonSchema']['properties']['zip']['type']);
    }

    public function testStructPromptWithClassNameAddsOnlyJsonSchema(): void
    {
        $prompt = new StructPrompt(PromptTypeTestAddress::class);
        $array = $prompt->toArray();

        self::assertFalse($prompt->hasData());
        self::assertArrayHasKey('jsonSchema', $array);
        self::assertArrayNotHasKey('data', $array);
    }

    private function createTempFile(string $content): string
    {
        $fileName = tempnam(sys_get_temp_dir(), 'phore-ai-prompt-');
        self::assertIsString($fileName);
        file_put_contents($fileName, $content);

        return $fileName;
    }
}
