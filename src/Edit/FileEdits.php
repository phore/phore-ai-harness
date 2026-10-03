<?php

declare(strict_types=1);

namespace Phore\AiHarness\Edit;

/** @internal Typed schema for one target in a multi-file edit callback. */
final readonly class FileEdits
{
    /** @var \Phore\AiHarness\Edit\TextReplacement[] */
    public array $edits;

    /**
     * Describe one target and its edits without performing filesystem changes.
     *
     * @param string $filename Supplied targetFileN alias or allowed target path.
     * @param \Phore\AiHarness\Edit\TextReplacement[] $edits All replacements for this target.
     * @see FileBatchEditor::write()
     */
    public function __construct(public string $filename, array $edits)
    {
        $this->edits = $edits;
    }
}
