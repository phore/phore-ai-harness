<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\EditableTextPrompt;
use Phore\AiHarness\ToolType\RecoverableToolException;
use Phore\AiHarness\ToolType\TextEditToolSet;
use PHPUnit\Framework\TestCase;

final class EditableTextPromptTest extends TestCase
{
    public function testSharedToolsEditOnlySelectedTextAndPreserveOriginal(): void
    {
        $intro = new EditableTextPrompt('one two', alias: 'intro', instructions: 'Introduction', type: 'markdown');
        $footer = new EditableTextPrompt('old footer', alias: 'footer');
        $tools = (new TextEditToolSet([$intro, $footer]))->getTools();
        self::assertCount(2, $tools);
        $read = $tools[0]->callback();
        $apply = $tools[1]->callback();

        $apply('intro', [['search' => 'one', 'replacement' => 'ONE']]);
        self::assertSame('ONE two', $intro->getContent());
        self::assertSame('one two', $intro->getOriginalContent());
        self::assertSame('old footer', $footer->getContent());
        self::assertTrue($intro->hasChanges());
        $source = json_decode($read('intro'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ONE two', $source['text']);
        self::assertSame('Introduction', $source['instructions']);
        self::assertFalse($source['allowInstructions']);
        self::assertSame('markdown', $source['contentFormat']);

        $apply('intro', [['search' => 'two', 'replacement' => 'TWO']]);
        $apply('footer', [['search' => null, 'replacement' => 'new footer']]);
        self::assertSame('ONE TWO', $intro->getContent());
        self::assertSame('new footer', $footer->getContent());

        $intro->reset();
        self::assertFalse($intro->hasChanges());
        self::assertSame('one two', $intro->getContent());
        $footer->setContent('manual edit');
        self::assertSame('manual edit', $footer->getContent());
        self::assertSame('old footer', $footer->getOriginalContent());
    }

    public function testInvalidBatchDoesNotModifyContent(): void
    {
        $draft = new EditableTextPrompt('repeat repeat', alias: 'draft');
        $apply = (new TextEditToolSet([$draft]))->getTools()[1]->callback();

        try {
            $apply('draft', [['search' => 'repeat', 'replacement' => 'changed']]);
            self::fail('Expected recoverable ambiguous search error.');
        } catch (RecoverableToolException) {
            self::assertSame('repeat repeat', $draft->getContent());
        }

        $this->expectException(RecoverableToolException::class);
        $apply('missing', [['search' => null, 'replacement' => 'changed']]);
    }

    public function testDuplicateAliasesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TextEditToolSet([
            new EditableTextPrompt('first', alias: 'same'),
            new EditableTextPrompt('second', alias: 'same'),
        ]);
    }

    public function testContextStateHashDoesNotDependOnMutableText(): void
    {
        $draft = new EditableTextPrompt('original', alias: 'draft');
        $context = new AiContext(prompts: [$draft]);
        $state = $context->exportState();
        $draft->setContent('changed');
        self::assertSame($context, $context->importState($state));
    }
}
