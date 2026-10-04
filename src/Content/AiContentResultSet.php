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
 * @implements IteratorAggregate<int, AiContent>
 */
final readonly class AiContentResultSet implements Countable, IteratorAggregate
{
    /** @var list<AiContent> */
    private array $items;

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

    /** @return list<AiContent> */
    public function all(): array
    {
        return $this->items;
    }

    public function first(): ?AiContent
    {
        return $this->items[0] ?? null;
    }

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
     * Refine this subset using another AI query.
     *
     * @param AiOptions|array<string, mixed>|string|null $options Per-call AI options.
     * @return self Matching subset.
     * @example $images = $result->query('Only images showing a person.');
     * @see AiContext::queryContent()
     */
    public function query(
        string $prompt,
        AiOptions|array|string|null $options = null,
    ): self {
        return $this->context->queryContent($prompt, $options);
    }

    /**
     * Rebind this subset to a fresh or supplied context for subsequent queries.
     *
     * @return self Same items using the new conversation branch.
     * @example $fresh = $result->withContext();
     * @see query()
     */
    public function withContext(?AiContext $context = null): self
    {
        $context ??= new AiContext();

        return new self(
            $this->items,
            $context->withSource($this->items),
        );
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return Traversable<int, AiContent> */
    public function getIterator(): Traversable
    {
        yield from $this->items;
    }
}
