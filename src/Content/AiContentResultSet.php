<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\AiOptions;
use Traversable;

/**
 * Immutable subset of AI content returned by a context query.
 *
 * The set keeps its own context containing exactly the selected items. That
 * makes repeated query() calls refine the current subset instead of starting
 * again from the original context.
 *
 * @implements IteratorAggregate<int, AiContent>
 */
final readonly class AiContentResultSet implements Countable, IteratorAggregate
{
    /** @var list<AiContent> */
    private array $items;

    /**
     * @param list<AiContent> $items Selected content objects.
     * @param AiContext $context Context containing exactly those items.
     * @throws InvalidArgumentException For non-content items or duplicate IDs.
     * @example $result = new AiContentResultSet([$image], new AiContext(prompts: [$image]));
     * @see AiContext::queryContent()
     */
    public function __construct(
        array $items,
        private AiContext $context,
    ) {
        $seen = [];
        foreach ($items as $item) {
            if (!$item instanceof AiContent) {
                throw new InvalidArgumentException('AI content result sets accept only AiContent instances.');
            }
            if (isset($seen[$item->id])) {
                throw new InvalidArgumentException('Duplicate AI content ID in result set: ' . $item->id);
            }

            $seen[$item->id] = true;
        }

        $this->items = array_values($items);
    }

    /**
     * Return every selected content object in result order.
     *
     * @return list<AiContent>
     * @example $items = $result->all();
     * @see first()
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Return the first selected object.
     *
     * @return AiContent|null Null for an empty result.
     * @example $image = $result->first();
     * @see all()
     */
    public function first(): ?AiContent
    {
        return $this->items[0] ?? null;
    }

    /**
     * Resolve one object from this subset by its unique ID.
     *
     * @param string $id Exact immutable content ID.
     * @return AiContent|null Matching object or null when it is not in this subset.
     * @example $image = $result->getById('damage-photo');
     * @see AiContent::getId()
     */
    public function getById(string $id): ?AiContent
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Return the context that represents this subset.
     *
     * @return AiContext Context containing exactly the selected items.
     * @example $subsetContext = $result->getContext();
     * @see withContext()
     */
    public function getContext(): AiContext
    {
        return $this->context;
    }

    /**
     * Refine this subset using another AI query.
     *
     * Only items already present in this result set are candidates for the next
     * query, so chained queries progressively narrow the selection.
     *
     * @param string $prompt Natural-language selection question.
     * @param AiOptions|array<string, mixed>|string|null $options Per-call AI options.
     * @return self Matching subset.
     * @example $portraits = $result->query('Which images show a person?');
     * @see AiContext::queryContent()
     */
    public function query(
        string $prompt,
        AiOptions|array|string|null $options = null,
    ): self {
        return $this->context->queryContent($prompt, $options);
    }

    /**
     * Rebind this subset to a fresh or supplied context.
     *
     * Passing null starts a fresh conversation containing only the selected
     * content. Passing a context clones that branch and attaches the selected
     * content, so subsequent operations can continue from existing state.
     *
     * @param AiContext|null $context Existing branch or null for a fresh context.
     * @return self Same items on the new context.
     * @example $fresh = $result->withContext();
     * @example $continued = $result->withContext($existingContext);
     * @see getContext()
     */
    public function withContext(?AiContext $context = null): self
    {
        $context ??= new AiContext();

        return new self(
            $this->items,
            $context->withSource($this->items),
        );
    }

    /**
     * Return the number of selected items.
     *
     * @example if (count($result) === 0) { ... }
     * @see all()
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Iterate over selected content objects.
     *
     * @return Traversable<int, AiContent>
     * @example foreach ($result as $content) { echo $content->getId(); }
     * @see all()
     */
    public function getIterator(): Traversable
    {
        yield from $this->items;
    }
}
