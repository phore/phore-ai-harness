<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\AiToolSet;
use Phore\AiHarness\ToolType\CallbackTool;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class ProjectToolSet implements AiToolSet
{
    private array $tools;

    public function __construct()
    {
        $this->tools = [
            new CallbackTool(
                static fn (string $key): string => ['deploy-target' => 'staging'][$key] ?? 'unknown',
                name: 'project_lookup',
                description: 'Look up one project setting.',
            ),
        ];
    }

    public function getTools(): array
    {
        return $this->tools;
    }
}

$toolSet = new ProjectToolSet();
$firstContext = new AiContext();
$secondContext = new AiContext();

$firstContext->addToolSet($toolSet);
$secondContext->addToolSet($toolSet);

assert($firstContext->getToolSet(ProjectToolSet::class) === $toolSet);
assert($secondContext->hasToolSet(ProjectToolSet::class));

$answer = $firstContext->text('Use project_lookup to tell me the deploy target.');
echo $answer . PHP_EOL;
