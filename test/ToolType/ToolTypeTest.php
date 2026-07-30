<?php

declare(strict_types=1);

use Phore\AiHarness\ToolType\CodeInterpreterTool;
use Phore\AiHarness\ToolType\ComputerUseTool;
use Phore\AiHarness\ToolType\FileSearchTool;
use Phore\AiHarness\ToolType\ImageGenerationTool;
use Phore\AiHarness\ToolType\LocalShellTool;
use Phore\AiHarness\ToolType\McpTool;
use Phore\AiHarness\ToolType\WebAccessTool;
use PHPUnit\Framework\TestCase;

final class ToolTypeTest extends TestCase
{
    public function testWebAccessTool(): void
    {
        $tool = new WebAccessTool(['search_context_size' => 'low']);

        self::assertTrue($tool->available('open_ai'));
        self::assertTrue($tool->available('openai'));
        self::assertSame('web_search_preview', $tool->type('open_ai'));
        self::assertSame([
            'type' => 'web_search_preview',
            'search_context_size' => 'low',
        ], $tool->toArray('open_ai'));
    }

    public function testFileSearchTool(): void
    {
        self::assertSame([
            'type' => 'file_search',
            'vector_store_ids' => ['vs_123'],
        ], (new FileSearchTool(['vector_store_ids' => ['vs_123']]))->toArray());
    }

    public function testCodeInterpreterTool(): void
    {
        self::assertSame([
            'type' => 'code_interpreter',
            'container' => ['type' => 'auto'],
        ], (new CodeInterpreterTool(['container' => ['type' => 'auto']]))->toArray());
    }

    public function testImageGenerationTool(): void
    {
        self::assertSame(['type' => 'image_generation'], (new ImageGenerationTool())->toArray());
    }

    public function testImageGenerationToolWithValidatedOptions(): void
    {
        $tool = new ImageGenerationTool(
            size: '1024x1024',
            output_format: 'webp',
            quality: 'high',
            background: 'transparent',
        );

        self::assertSame([
            'type' => 'image_generation',
            'size' => '1024x1024',
            'output_format' => 'webp',
            'quality' => 'high',
            'background' => 'transparent',
        ], $tool->toArray());
    }

    public function testImageGenerationToolAcceptsBackwardsCompatibleOptionsArray(): void
    {
        self::assertSame([
            'type' => 'image_generation',
            'output_format' => 'png',
        ], (new ImageGenerationTool(['output_format' => 'png']))->toArray());
    }

    public function testImageGenerationToolRejectsInvalidOptionValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid image generation option "quality"');

        new ImageGenerationTool(quality: 'ultra');
    }

    public function testImageGenerationToolRejectsUnsupportedOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image generation option');

        new ImageGenerationTool(['unknown' => 'value']);
    }

    public function testComputerUseTool(): void
    {
        self::assertSame([
            'type' => 'computer_use_preview',
            'display_width' => 1024,
            'display_height' => 768,
            'environment' => 'browser',
        ], (new ComputerUseTool([
            'display_width' => 1024,
            'display_height' => 768,
            'environment' => 'browser',
        ]))->toArray());
    }

    public function testMcpTool(): void
    {
        self::assertSame([
            'type' => 'mcp',
            'server_label' => 'docs',
            'server_url' => 'https://example.test/mcp',
        ], (new McpTool([
            'server_label' => 'docs',
            'server_url' => 'https://example.test/mcp',
        ]))->toArray());
    }

    public function testLocalShellTool(): void
    {
        self::assertSame(['type' => 'local_shell'], (new LocalShellTool())->toArray());
    }

    public function testRejectsUnsupportedProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not available for provider');

        (new WebAccessTool())->toArray('anthropic');
    }
}
