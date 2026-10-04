<?php

declare(strict_types=1);

namespace Phore\AiHarness;

use LogicException;
use Phore\AiHarness\Content\AiContent;
use Phore\AiHarness\Content\AiContentResultSet;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\Result\ImageResultType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Binds the complete high-level AiContext API to a domain object.
 *
 * The context is initialized exactly once and then keeps one conversation
 * cursor for all ai_* calls on that object. A consuming class may either bind a
 * prepared context with ai_set_context(), prepare one with ai_prepare(), or let
 * the first ai_* call lazily create an empty context.
 *
 * The readonly context property keeps the trait compatible with readonly domain
 * objects while AiContext itself remains mutable and advances its cursor.
 *
 * @example
 * final readonly class Ticket
 * {
 *     use AiContextTrait;
 *
 *     public function __construct(AiContext $context)
 *     {
 *         $this->ai_set_context($context);
 *     }
 * }
 * @see AiContext
 */
trait AiContextTrait
{
    private readonly AiContext $aiContextInstance;

    /**
     * Bind an already prepared context to this object.
     *
     * Binding is intentionally one-shot so a domain object cannot silently
     * switch conversation cursors after AI work has started.
     *
     * @return $this
     * @throws LogicException When a context was already bound or lazily created.
     * @example $mail->ai_set_context(new AiContext(prompts: [$mailPrompt]));
     * @see ai_prepare()
     * @see ai_get_context()
     */
    public function ai_set_context(AiContext $context): static
    {
        if (isset($this->aiContextInstance)) {
            throw new LogicException('AI context is already initialized for this object.');
        }

        $this->aiContextInstance = $context;

        return $this;
    }

    /**
     * Prepare and bind a new context to this object.
     *
     * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Reusable context items.
     * @param AiOptions|array<string,mixed> $options Shared context defaults.
     * @return $this
     * @throws LogicException When a context was already initialized.
     * @example $ticket->ai_prepare(prompts: [$source], options: ['model' => 'gpt-5-mini']);
     * @see AiContext::__construct()
     * @see ai_set_context()
     */
    public function ai_prepare(
        string|PromptType|ToolType|array $prompts = [],
        AiOptions|array $options = [],
    ): static {
        return $this->ai_set_context(new AiContext($prompts, $options));
    }

    /**
     * Return the bound context, lazily creating an empty one when necessary.
     *
     * @return AiContext Shared conversation context used by every ai_* method.
     * @example $responseId = $ticket->ai_get_context()->getResponseId();
     * @see ai_set_context()
     */
    public function ai_get_context(): AiContext
    {
        return $this->ai_context();
    }

    /**
     * Internal context provider used by every delegated operation.
     *
     * Consuming classes normally do not need to override this method. Prepare or
     * bind a context before the first AI call when domain data should be present.
     */
    protected function ai_context(): AiContext
    {
        if (!isset($this->aiContextInstance)) {
            $this->aiContextInstance = new AiContext();
        }

        return $this->aiContextInstance;
    }

    /**
     * Execute a work step in the bound conversation.
     *
     * @param string|PromptType|ToolType|array $prompts Instructions and sources.
     * @param bool|class-string<DoException> $throw Failure handling.
     * @param array<string,mixed> $options Per-call options.
     * @example $mail->ai_do('Verify the supplied facts.', throw: true);
     * @see AiContext::do()
     */
    public function ai_do(
        string|PromptType|ToolType|array $prompts,
        bool|string $throw = false,
        array $options = [],
    ): bool {
        return $this->ai_context()->do($prompts, $throw, $options);
    }

    /**
     * Generate or edit text in the bound conversation.
     *
     * @param string|PromptType|ToolType|array $prompts Instructions and sources.
     * @param ?string $input Optional existing text to edit.
     * @param array<string,mixed> $options Per-call options.
     * @example $answer = $mail->ai_text('Summarize the latest request.');
     * @see AiContext::text()
     */
    public function ai_text(
        string|PromptType|ToolType|array $prompts,
        ?string $input = null,
        array $options = [],
    ): string {
        return $this->ai_context()->text($prompts, $input, $options);
    }

    /**
     * Create or edit UTF-8 text files through the bound conversation.
     *
     * @param string|PromptType|ToolType|array $prompts Instructions and sources.
     * @param string|list<string> $input Target path or paths.
     * @param array<string,mixed> $options Per-call options.
     * @example $summary = $job->ai_file('Update the changelog.', 'CHANGELOG.md');
     * @see AiContext::file()
     */
    public function ai_file(
        string|PromptType|ToolType|array $prompts,
        string|array $input,
        array $options = [],
    ): object|string {
        return $this->ai_context()->file($prompts, $input, $options);
    }

    /**
     * Generate a DTO or patch an existing object in the bound conversation.
     *
     * @template T of object
     * @param string|PromptType|ToolType|array $prompts Instructions and sources.
     * @param class-string<T>|T $input Output class or existing object.
     * @param array<string,mixed> $options Per-call options.
     * @return T|object
     * @example $result = $mail->ai_struct('Extract the request.', Request::class);
     * @see AiContext::struct()
     */
    public function ai_struct(
        string|PromptType|ToolType|array $prompts,
        string|object $input,
        array $options = [],
    ): object {
        return $this->ai_context()->struct($prompts, $input, $options);
    }

    /**
     * Generate a list of DTOs in the bound conversation.
     *
     * @template T of object
     * @param string|PromptType|ToolType|array $prompts Instructions and sources.
     * @param class-string<T> $input Output element class.
     * @param array<string,mixed> $options Per-call options.
     * @return list<T>
     * @example $items = $mail->ai_struct_array('Extract tasks.', Task::class);
     * @see AiContext::structArray()
     */
    public function ai_struct_array(
        string|PromptType|ToolType|array $prompts,
        string $input,
        array $options = [],
    ): array {
        return $this->ai_context()->structArray($prompts, $input, $options);
    }

    /**
     * Generate an image in the bound conversation.
     *
     * @param string|PromptType|ToolType|array $prompts Instructions and sources.
     * @param array<string,mixed> $options Image and runtime options.
     * @example $image = $card->ai_image('Create the illustration.');
     * @see AiContext::image()
     */
    public function ai_image(
        string|PromptType|ToolType|array $prompts,
        array $options = [],
    ): ImageResultType {
        return $this->ai_context()->image($prompts, $options);
    }

    /**
     * Select exactly one value from the supplied choices.
     *
     * @param array<int|string,int|string|null> $choices Values or value => description mappings.
     * @param AiOptions|array<string,mixed>|string|null $options Runtime options or model name.
     * @example $type = $mail->ai_choice('Which request fits?', ['question', 'profile']);
     * @see AiContext::choice()
     */
    public function ai_choice(
        ?string $prompt,
        array $choices,
        bool $allowNull = false,
        AiOptions|array|string|null $options = null,
    ): string|int|null {
        return $this->ai_context()->choice($prompt, $choices, $allowNull, $options);
    }

    /**
     * Select zero or more supplied values.
     *
     * @param array<int|string,int|string|null> $choices Values or value => description mappings.
     * @param AiOptions|array<string,mixed>|string|null $options Runtime options or model name.
     * @return list<string|int>|null
     * @example $tags = $mail->ai_choices('Which topics apply?', ['billing', 'profile'], max: 2);
     * @see AiContext::choices()
     */
    public function ai_choices(
        ?string $prompt,
        array $choices,
        int $min = 0,
        ?int $max = null,
        bool $allowNull = false,
        AiOptions|array|string|null $options = null,
    ): ?array {
        return $this->ai_context()->choices($prompt, $choices, $min, $max, $allowNull, $options);
    }

    /**
     * Decide yes/no from the bound conversation.
     *
     * @param AiOptions|array<string,mixed>|string|null $options Runtime options or model name.
     * @example $firstReply = $mail->ai_yes_no('Is this the first applicant reply?');
     * @see AiContext::yesNo()
     */
    public function ai_yes_no(
        ?string $prompt,
        bool $allowNull = false,
        AiOptions|array|string|null $options = null,
    ): ?bool {
        return $this->ai_context()->yesNo($prompt, $allowNull, $options);
    }

    /**
     * Rank all choices from best to worst match.
     *
     * @param array<int|string,int|string|null> $choices Values or value => description mappings.
     * @param AiOptions|array<string,mixed>|string|null $options Runtime options or model name.
     * @return list<string|int>|null
     * @example $ranking = $mail->ai_rank('Rank by relevance.', ['cv', 'profile']);
     * @see AiContext::rank()
     */
    public function ai_rank(
        ?string $prompt,
        array $choices,
        bool $allowNull = false,
        AiOptions|array|string|null $options = null,
    ): ?array {
        return $this->ai_context()->rank($prompt, $choices, $allowNull, $options);
    }

    /**
     * Score the current conversation from 0.0 to 1.0.
     *
     * @param AiOptions|array<string,mixed>|string|null $options Runtime options or model name.
     * @example $confidence = $mail->ai_score('How clearly is the request stated?');
     * @see AiContext::score()
     */
    public function ai_score(
        ?string $prompt,
        bool $allowNull = false,
        AiOptions|array|string|null $options = null,
    ): ?float {
        return $this->ai_context()->score($prompt, $allowNull, $options);
    }

    /**
     * Resolve one content object by its unique ID in the bound context.
     *
     * @param string $id Exact immutable content ID.
     * @return AiContent|null Matching object or null when the ID is unknown.
     * @example $image = $ticket->ai_get_content_by_id('damage-photo');
     * @see AiContext::getContentById()
     */
    public function ai_get_content_by_id(string $id): ?AiContent
    {
        return $this->ai_context()->getContentById($id);
    }

    /**
     * Query all content in the bound context and return a reusable subset.
     *
     * The model selects only from IDs already registered in the context. The
     * result set resolves those IDs back to the original AiContent instances and
     * can be queried again or rebound to another context.
     *
     * @param string $prompt Natural-language selection question.
     * @param AiOptions|array<string,mixed>|string|null $options Runtime options or model name.
     * @return AiContentResultSet Matching content subset.
     * @example $images = $ticket->ai_query_content('Which images show visible damage?');
     * @see AiContext::queryContent()
     * @see AiContentResultSet
     */
    public function ai_query_content(
        string $prompt,
        AiOptions|array|string|null $options = null,
    ): AiContentResultSet {
        return $this->ai_context()->queryContent($prompt, $options);
    }

    /**
     * Mark the current conversation cursor.
     *
     * @return $this
     * @example $mail->ai_set_checkpoint('before-draft');
     * @see AiContext::setCheckpoint()
     */
    public function ai_set_checkpoint(?string $name = null): static
    {
        $this->ai_context()->setCheckpoint($name);

        return $this;
    }

    /**
     * Restore a named or latest checkpoint.
     *
     * @return $this
     * @example $mail->ai_rollback('before-draft');
     * @see AiContext::rollback()
     */
    public function ai_rollback(?string $name = null): static
    {
        $this->ai_context()->rollback($name);

        return $this;
    }

    /**
     * Export provider cursor and checkpoints for persistence.
     *
     * @example $state = $mail->ai_export_state();
     * @see AiContext::exportState()
     */
    public function ai_export_state(): string
    {
        return $this->ai_context()->exportState();
    }

    /**
     * Import previously exported provider cursor and checkpoints.
     *
     * @param ResumeOptions|array{on_mismatch?:string} $options Resume mismatch policy.
     * @return $this
     * @example $mail->ai_import_state($storedState);
     * @see AiContext::importState()
     */
    public function ai_import_state(string $state, ResumeOptions|array $options = []): static
    {
        $this->ai_context()->importState($state, $options);

        return $this;
    }

    /**
     * Return the current provider response cursor.
     *
     * @example $responseId = $mail->ai_get_response_id();
     * @see AiContext::getResponseId()
     */
    public function ai_get_response_id(): ?string
    {
        return $this->ai_context()->getResponseId();
    }
}
