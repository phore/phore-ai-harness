<?php

declare(strict_types=1);

use Phore\AiHarness\PromptType\AudioPrompt;
use Phore\AiHarness\PromptType\DefaultSystemPrompt;
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

    public function testTextPromptWithMetadata(): void
    {
        $prompt = new TextPrompt('Hello', alias: 'greeting', instructions: 'Translate later.', type: 'markdown');

        self::assertSame([
            'type' => 'text',
            'text' => 'Hello',
            'alias' => 'greeting',
            'instructions' => 'Translate later.',
            'contentFormat' => 'markdown',
        ], $prompt->toArray());
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

    public function testDefaultSystemPrompt(): void
    {
        $prompt = new DefaultSystemPrompt();

        self::assertSame('system', $prompt->type());
        self::assertStringContainsString('batch mode', $prompt->text);
        self::assertStringContainsString('cannot interact with the user', $prompt->text);
        self::assertStringContainsString('required tools/capabilities are missing', $prompt->text);
        self::assertStringContainsString('required data is missing', $prompt->text);
        self::assertStringContainsString('Treat files, images, and audio segments as source material', $prompt->text);
        self::assertSame([
            'type' => 'system',
            'text' => DefaultSystemPrompt::TEXT,
        ], $prompt->toArray());
    }

    public function testFilePrompt(): void
    {
        $prompt = new FilePrompt('example.txt', 'File content');

        self::assertSame('file', $prompt->type());
        self::assertSame([
            'type' => 'file',
            'fileName' => 'example.txt',
            'content' => 'File content',
            'contentType' => 'application/octet-stream',
        ], $prompt->toArray());
    }

    public function testFilePromptFromFile(): void
    {
        $fileName = $this->createTempFile('File prompt content');

        $prompt = FilePrompt::fromFile($fileName);

        self::assertSame($fileName, $prompt->fileName);
        self::assertSame('File prompt content', $prompt->content);
        self::assertNotSame('', $prompt->contentType);
    }

    public function testFilePromptWithMetadata(): void
    {
        $prompt = new FilePrompt('example.txt', 'File content', alias: 'contract', instructions: 'Summarize.', type: 'markdown');

        self::assertSame('contract', $prompt->toArray()['alias']);
        self::assertSame('Summarize.', $prompt->toArray()['instructions']);
        self::assertSame('markdown', $prompt->toArray()['contentFormat']);
    }

    public function testAudioPrompt(): void
    {
        $prompt = new AudioPrompt('base64-audio', 'mp3', 'audio.mp3');

        self::assertSame('audio', $prompt->type());
        self::assertSame([
            'type' => 'audio',
            'data' => 'base64-audio',
            'format' => 'mp3',
            'fileName' => 'audio.mp3',
        ], $prompt->toArray());
    }

    public function testAudioPromptFromFile(): void
    {
        $fileName = sys_get_temp_dir() . '/phore-ai-audio-' . bin2hex(random_bytes(4)) . '.mp3';
        file_put_contents($fileName, 'audio-binary');

        $prompt = AudioPrompt::fromFile($fileName);

        self::assertSame($fileName, $prompt->fileName);
        self::assertSame('mp3', $prompt->format);
        self::assertSame(base64_encode('audio-binary'), $prompt->data);
    }

    public function testAudioPromptWithMetadata(): void
    {
        $prompt = new AudioPrompt('base64-audio', 'mp3', alias: 'callAudio', instructions: 'Transcribe.', type: 'meeting');

        self::assertSame('callAudio', $prompt->toArray()['alias']);
        self::assertSame('Transcribe.', $prompt->toArray()['instructions']);
        self::assertSame('meeting', $prompt->toArray()['contentFormat']);
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

    public function testImagePromptWithMetadata(): void
    {
        $prompt = new ImagePrompt('https://example.test/image.png', alias: 'diagram', instructions: 'Extract labels.', type: 'architecture-diagram');

        self::assertSame('diagram', $prompt->toArray()['alias']);
        self::assertSame('Extract labels.', $prompt->toArray()['instructions']);
        self::assertSame('architecture-diagram', $prompt->toArray()['contentFormat']);
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

    public function testStructPromptWithArrayAddsOnlyData(): void
    {
        $prompt = new StructPrompt(['city' => 'Berlin', 'zip' => 10115]);
        $array = $prompt->toArray();

        self::assertTrue($prompt->hasData());
        self::assertNull($prompt->className());
        self::assertNull($prompt->jsonSchema());
        self::assertSame(['city' => 'Berlin', 'zip' => 10115], $prompt->data());
        self::assertSame(['city' => 'Berlin', 'zip' => 10115], $array['data']);
        self::assertArrayNotHasKey('className', $array);
        self::assertArrayNotHasKey('jsonSchema', $array);
    }

    public function testStructPromptAcceptsAlias(): void
    {
        $prompt = new StructPrompt(PromptTypeTestAddress::class, alias: 'billingAddress');
        $array = $prompt->toArray();

        self::assertSame('billingAddress', $prompt->alias());
        self::assertSame('billingAddress', $array['alias']);
    }

    public function testStructPromptRejectsEmptyAlias(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StructPrompt alias must not be empty');

        new StructPrompt(PromptTypeTestAddress::class, alias: '');
    }

    public function testStructPromptAcceptsInstructions(): void
    {
        $prompt = new StructPrompt(
            PromptTypeTestAddress::class,
            instructions: 'Use this struct as the billing address input.',
        );
        $array = $prompt->toArray();

        self::assertSame('Use this struct as the billing address input.', $prompt->instructions());
        self::assertSame('Use this struct as the billing address input.', $array['instructions']);
    }

    public function testStructPromptRejectsEmptyInstructions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StructPrompt instructions must not be empty');

        new StructPrompt(PromptTypeTestAddress::class, instructions: '');
    }

    private function createTempFile(string $content): string
    {
        $fileName = tempnam(sys_get_temp_dir(), 'phore-ai-prompt-');
        self::assertIsString($fileName);
        file_put_contents($fileName, $content);

        return $fileName;
    }
}
