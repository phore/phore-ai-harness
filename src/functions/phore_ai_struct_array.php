<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Generate a typed list with the unchanged legacy signature and return type.
 * Options accept ai_context (ID/object/null) plus existing common settings.
 * The provider's items envelope is unwrapped; an empty list is valid.
 * Shared callbacks and explicit prompt tools can run without losing the schema.
 *
 * @template T of object
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Instructions and sources.
 * @param class-string<T> $className Class used to hydrate every element.
 * @param array<string, mixed> $options Common context/client/model/logging options.
 * @return list<T> Hydrated results in provider order.
 * @throws InvalidArgumentException For an invalid class or result shape.
 * @throws JsonException For an undecodable result.
 * @example $tasks = phore_ai_struct_array('Extract tasks.', Task::class, ['ai_context' => 'default']);
 * @see AiContext::structArray()
 */
function phore_ai_struct_array(string|PromptType|ToolType|array $prompts, string $className, array $options = []): array
{
    return AiContextRegistry::resolve($options)->structArray($prompts, $className, $options);
}
