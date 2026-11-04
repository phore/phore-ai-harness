<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAI\OpenAiPromptToContentConverter;
use Phore\AiHarness\PromptType\AudioPrompt;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\ImagePrompt;
use Phore\AiHarness\PromptType\PromptDirectory;
use Phore\AiHarness\PromptType\SourcePrompt;
use Phore\AiHarness\PromptType\TextPrompt;
use PHPUnit\Framework\TestCase;

final class PromptDirectoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phore-prompt-dir-' . bin2hex(random_bytes(5));
        phore_dir($this->root . '/nested')->mkdir();
        phore_file($this->root . '/nested/brief.txt')->set_contents("Hello\n\nMore text.\n");
        phore_file($this->root . '/document.pdf')->set_contents("%PDF-1.4\n");
        phore_file($this->root . '/image.png')->set_contents("\x89PNG\r\n\x1a\n");
        phore_file($this->root . '/sound.mp3')->set_contents("audio");
        phore_file($this->root . '/mystery.bin')->set_contents("\x01\x02\0");
    }

    protected function tearDown(): void
    {
        phore_dir($this->root)->rmDir(true);
    }

    public function testCreatesNativeSourcesInSortedRelativeOrder(): void
    {
        $prompt = new PromptDirectory($this->root, 'Client attachments');
        $items = $prompt->segments();

        self::assertCount(5, $items);
        self::assertInstanceOf(FilePrompt::class, $items[0]);
        self::assertSame('document.pdf', $items[0]->fileName);
        self::assertSame('application/pdf', $items[0]->contentType);
        self::assertSame('Client attachments', $items[0]->instructions);
        self::assertFalse($items[0]->allowInstructions);
        self::assertInstanceOf(ImagePrompt::class, $items[1]);
        self::assertSame('image.png', $items[1]->fileName);
        self::assertStringStartsWith('data:image/png;base64,', $items[1]->imageUrl);
        self::assertInstanceOf(TextPrompt::class, $items[2]);
        self::assertStringContainsString('mystery.bin', $items[2]->text);
        self::assertInstanceOf(FilePrompt::class, $items[3]);
        self::assertSame('nested/brief.txt', $items[3]->fileName);
        self::assertSame("Hello\n\nMore text.\n", $items[3]->content);
        self::assertInstanceOf(AudioPrompt::class, $items[4]);
        self::assertSame('sound.mp3', $items[4]->fileName);
    }

    public function testDirectoryExpandsIntoProviderContent(): void
    {
        $prompt = new PromptDirectory($this->root, 'Attachments');
        $data = (new OpenAiPromptToContentConverter())->convert([$prompt]);
        $types = array_column($data, 'type');

        self::assertContains('input_image', $types);
        self::assertContains('input_file', $types);
        self::assertContains('input_audio', $types);
        self::assertStringContainsString('Attachments', implode("\n", array_column($data, 'text')));
        self::assertSame('prompt_directory', $prompt->type());
        self::assertCount(5, $prompt->toArray()['segments']);
    }

    public function testSingleRawSourceUsesSameClassification(): void
    {
        $doc = SourcePrompt::fromRaw('letter.docx', 'binary');
        self::assertInstanceOf(FilePrompt::class, $doc);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $doc->contentType,
        );

        $text = SourcePrompt::fromRaw('custom.note', "Hi\n");
        self::assertInstanceOf(FilePrompt::class, $text);
        self::assertSame('text/plain', $text->contentType);

        $unknown = SourcePrompt::fromRaw('binary.blob', "\0");
        self::assertInstanceOf(TextPrompt::class, $unknown);
        self::assertFalse($unknown->allowInstructions);
    }

    public function testNonRecursiveDirectoryOnlyLoadsTopLevel(): void
    {
        $prompt = new PromptDirectory($this->root, recursive: false);
        self::assertCount(4, $prompt->segments());
    }

    public function testRejectsUnreadableDirectoryWithPath(): void
    {
        $path = $this->root . '/not-present';
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage($path);
        new PromptDirectory($path);
    }
}
