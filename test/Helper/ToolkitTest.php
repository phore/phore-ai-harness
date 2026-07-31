<?php

declare(strict_types=1);

use Phore\AiHarness\Helper\Toolkit;
use PHPUnit\Framework\TestCase;

final class ToolkitTestSchemaNameWithAVeryLongNameThatShouldBeTrimmedForOpenAiCompatibility
{
}

final class ToolkitTest extends TestCase
{
    public function testJsonEncodeUsesProjectDefaults(): void
    {
        self::assertSame('{"text":"ä/ö"}', Toolkit::jsonEncode(['text' => 'ä/ö']));
    }

    public function testJsonEncodePrettyPrintsWhenRequested(): void
    {
        self::assertStringContainsString("\n", Toolkit::jsonEncode(['text' => 'hello'], true));
    }

    public function testDecodeJsonOutputAcceptsFencedJsonObject(): void
    {
        self::assertSame(['answer' => 'yes'], Toolkit::decodeJsonOutput("```json\n{\"answer\":\"yes\"}\n```"));
    }

    public function testDecodeJsonOutputValueAcceptsEmptyRootArray(): void
    {
        self::assertSame([], Toolkit::decodeJsonOutputValue("```json\n[]\n```"));
    }

    public function testAppendInstructions(): void
    {
        self::assertSame('new', Toolkit::appendInstructions(null, 'new'));
        self::assertSame("old\n\nnew", Toolkit::appendInstructions('old', 'new'));
    }

    public function testSchemaNameIsOpenAiCompatibleAndTrimmed(): void
    {
        $name = Toolkit::schemaName(ToolkitTestSchemaNameWithAVeryLongNameThatShouldBeTrimmedForOpenAiCompatibility::class);

        self::assertLessThanOrEqual(64, strlen($name));
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $name);
    }
}
