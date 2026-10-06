<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\AiToolSet;
use Phore\AiHarness\ToolType\CallbackTool;
use PHPUnit\Framework\TestCase;

final class FixtureToolSet implements AiToolSet
{
    private array $tools;

    public function __construct()
    {
        $this->tools = [
            new CallbackTool(
                static fn (string $value): string => strtoupper($value),
                'fixture_uppercase',
            ),
        ];
    }

    public function getTools(): array
    {
        return $this->tools;
    }
}

final class AiToolSetTest extends TestCase
{
    public function testToolSetCanBeRegisteredLookedUpAndSharedAcrossContexts(): void
    {
        $toolSet = new FixtureToolSet();
        $first = new AiContext();
        $second = new AiContext();

        self::assertFalse($first->hasToolSet(FixtureToolSet::class));

        $first->addToolSet($toolSet);
        $second->addToolSet($toolSet);

        self::assertTrue($first->hasToolSet(FixtureToolSet::class));
        self::assertSame($toolSet, $first->getToolSet(FixtureToolSet::class));
        self::assertSame($toolSet, $second->getToolSet(FixtureToolSet::class));
        self::assertSame($toolSet, (clone $first)->getToolSet(FixtureToolSet::class));
    }

    public function testDuplicateConcreteToolSetClassIsRejected(): void
    {
        $context = new AiContext();
        $context->addToolSet(new FixtureToolSet());

        $this->expectException(\InvalidArgumentException::class);
        $context->addToolSet(new FixtureToolSet());
    }
}
