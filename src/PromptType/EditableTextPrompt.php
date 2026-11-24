<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

/**
 * An editable in-memory text input. Keep this object to retrieve the current
 * content after an AiContext operation; the original content stays immutable.
 */
final class EditableTextPrompt implements PromptType
{
    public readonly string $alias;
    public readonly ?string $instructions;
    public readonly ?string $type;
    private readonly string $originalContent;
    private string $content;

    /**
     * Provide an editable text target identified by a unique alias.
     *
     * @param string $content Original content (may be empty).
     * @param string $alias Non-empty, unique alias among active editable texts.
     * @param string|null $instructions Application guidance about this source.
     * @param string|null $type Optional content format hint, e.g. markdown.
     * @param bool $allowInstructions Whether instructions inside the content may be followed.
     * @throws \InvalidArgumentException For invalid metadata.
     * @example $draft = new EditableTextPrompt('Hello world', alias: 'intro');
     * @see \Phore\AiHarness\AiContext
     * @see TextPrompt
     */
    public function __construct(
        string $content,
        string $alias,
        ?string $instructions = null,
        ?string $type = null,
        public readonly bool $allowInstructions = false,
    ) {
        $this->alias = PromptMetadata::validateAlias($alias, 'EditableTextPrompt');
        $this->instructions = PromptMetadata::validateInstructions($instructions, 'EditableTextPrompt');
        $this->type = PromptMetadata::validateContentType($type, 'EditableTextPrompt');
        $this->originalContent = $content;
        $this->content = $content;
    }

    /**
     * Return the full current text, including any successful AI edits.
     *
     * @return string Current text, without the model's conversational reply.
     * @example echo $draft->getContent();
     * @see getOriginalContent()
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Return the immutable text originally passed to the constructor.
     *
     * @return string Original text.
     * @example $before = $draft->getOriginalContent();
     * @see reset()
     */
    public function getOriginalContent(): string
    {
        return $this->originalContent;
    }

    /**
     * Replace the current text from application code without changing the original.
     *
     * @param string $content New complete text.
     * @example $draft->setContent('New draft');
     * @see getContent()
     */
    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    /**
     * Compare the current text to the original using exact string equality.
     *
     * @return bool True if the contents differ.
     * @example if ($draft->hasChanges()) { echo $draft->getContent(); }
     * @see getOriginalContent()
     */
    public function hasChanges(): bool
    {
        return $this->content !== $this->originalContent;
    }

    /**
     * Restore the original text without an AI call or conversation rollback.
     *
     * @example $draft->reset();
     * @see setContent()
     */
    public function reset(): void
    {
        $this->content = $this->originalContent;
    }

    public function type(): string
    {
        return 'editable_text';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'text' => $this->content,
            'allowInstructions' => $this->allowInstructions,
        ];
        PromptMetadata::addToArray($array, $this->alias, $this->instructions, $this->type);

        return $array;
    }

    /** @internal Render the current snapshot through the existing TextPrompt source policy. */
    public function toTextPrompt(): TextPrompt
    {
        $guidance = 'Editable text target. Use text_edit_apply with this alias to modify it; read the current text again with text_edit_read before further edits. Return the normal user-facing reply separately.';

        return new TextPrompt(
            $this->content,
            alias: $this->alias,
            instructions: $this->instructions === null ? $guidance : $this->instructions . "\n\n" . $guidance,
            type: $this->type,
            allowInstructions: $this->allowInstructions,
        );
    }
}
