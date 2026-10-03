<?php

declare(strict_types=1);

namespace Phore\AiHarness\Edit;

/** @internal Typed schema description; decoded callback values remain arrays. */
final readonly class TextReplacement
{
    /**
     * Describe one exact replacement for the provider's function-tool schema.
     *
     * @param string|null $search Unique original text; null means a full rewrite.
     * @param string $replacement New text; an empty string deletes the match.
     * @see TextEditEngine::applyEdits()
     */
    public function __construct(public ?string $search, public string $replacement)
    {
    }
}
