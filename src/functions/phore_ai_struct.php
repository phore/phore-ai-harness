<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Generate and hydrate a typed object with the unchanged legacy signature.
 * Options accept ai_context as a registry ID, an AiContext object or null.
 * The class schema constrains the provider output, including callback follow-ups.
 * Shared callbacks and explicit prompt tools can run; no object is persisted.
 *
 * @template T of object
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Instructions and sources.
 * @param class-string<T> $className Class defining the result schema.
 * @param array<string, mixed> $options Common context/client/model/logging options.
 * @return T Hydrated result.
 * @throws InvalidArgumentException For invalid options, class or result shape.
 * @throws JsonException For an undecodable structured response.
 * @example $answer = phore_ai_struct('Extract the answer.', Answer::class, ['ai_context' => 'default']);
 * @see AiContext::struct()
 */
function phore_ai_struct(string|PromptType|ToolType|array $prompts, string $className, array $options = []): object
{
    return AiContextRegistry::resolve($options)->struct($prompts, $className, $options);
}
