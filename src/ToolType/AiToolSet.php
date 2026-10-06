<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

/**
 * Groups related tools behind one reusable stateful application object.
 *
 * A context registers at most one instance per concrete class. The same
 * instance may deliberately be shared by several contexts.
 *
 * @see \Phore\AiHarness\AiContext::addToolSet()
 */
interface AiToolSet
{
    /**
     * Return the stable tools contributed by this set.
     *
     * The returned tool objects become prepared context tools. Stateful routing
     * data should stay on the tool-set object instead of changing this list.
     *
     * @return list<ToolType>
     * @example $context->addToolSet(new ProjectToolSet());
     * @see \Phore\AiHarness\AiContext::getToolSet()
     */
    public function getTools(): array;
}
