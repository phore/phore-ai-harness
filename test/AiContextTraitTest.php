<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use LogicException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\AiContextTrait;
use Phore\AiHarness\Content\AiText;
use Phore\AiHarness\PromptType\TextPrompt;
use PHPUnit\Framework\TestCase;

final readonly class ReadonlyAiContextHost
{
    use AiContextTrait;

    public function __construct(AiContext $context)
    {
        $this->ai_set_context($context);
    }
}

final class LazyAiContextHost
{
    use AiContextTrait;
}

final class AiContextTraitTest extends TestCase
{
    public function testPreparedContextCanBeBoundToReadonlyDomainObject(): void
    {
        $context = new AiContext(prompts: [new TextPrompt('Source')]);
        $host = new ReadonlyAiContextHost($context);

        self::assertSame($context, $host->ai_get_context());
        self::assertNull($host->ai_get_response_id());

        $host->ai_set_checkpoint('initial');
        $state = $host->ai_export_state();

        self::assertStringContainsString('"checkpoints"', $state);
        self::assertSame($host, $host->ai_rollback('initial'));
    }

    public function testContentLookupAndQueryAreForwardedByTrait(): void
    {
        $content = AiText::fromRaw('hello', id: 'message');
        $host = new ReadonlyAiContextHost(new AiContext(prompts: [$content]));

        self::assertSame($content, $host->ai_get_content_by_id('message'));

        $emptyHost = new ReadonlyAiContextHost(new AiContext());
        self::assertCount(0, $emptyHost->ai_query_content('Find matching content.'));
    }

    public function testContextIsCreatedLazilyAndCannotBeRebound(): void
    {
        $host = new LazyAiContextHost();
        $context = $host->ai_get_context();

        self::assertSame($context, $host->ai_get_context());

        $this->expectException(LogicException::class);
        $host->ai_set_context(new AiContext());
    }

    public function testPrepareBindsConfiguredContextOnce(): void
    {
        $host = new LazyAiContextHost();

        self::assertSame($host, $host->ai_prepare(
            prompts: [new TextPrompt('Prepared source')],
            options: ['model' => 'gpt-5-mini'],
        ));
        self::assertSame($host->ai_get_context(), $host->ai_get_context());

        $this->expectException(LogicException::class);
        $host->ai_prepare();
    }
}
