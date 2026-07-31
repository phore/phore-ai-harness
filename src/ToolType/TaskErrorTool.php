<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

final readonly class TaskErrorTool extends CallbackTool
{
    public function __construct(
        string $name = 'task_error',
    ) {
        $callback = /**
         * Report that the requested job is not fulfillable instead of guessing.
         *
         * Call this tool when prompts are ambiguous or contradictory, required data is
         * missing, or required tools/capabilities are not available. Use it instead of
         * producing a best-effort answer that may be wrong.
         *
         * @param string $errorType One of: ambiguous, contradictory, missing_data, missing_tools, missing_information.
         * @param string $contradictoryOrAmbiguousStatements Quote the exact contradictory or ambiguous statements. Use "n/a" if not applicable.
         * @param string $missingInformation List the missing data, information, tools, or capabilities that are required to proceed. Use "n/a" if not applicable.
         * @param string $requestedAt Describe where or from whom the missing clarification, data, or tool access should be requested, e.g. "ask the user before continuing".
         * @param string $reason Explain why the task cannot be completed safely without clarification, data, or tool access.
         * @return never
         */ static function (
            string $errorType,
            string $contradictoryOrAmbiguousStatements,
            string $missingInformation,
            string $requestedAt,
            string $reason,
        ): never {
            throw new TaskErrorException(
                $errorType,
                $contradictoryOrAmbiguousStatements,
                $missingInformation,
                $requestedAt,
                $reason,
            );
        };

        parent::__construct(
            $callback,
            name: $name,
            description: 'Call this function instead of guessing when the job is not fulfillable because prompts are ambiguous or contradictory, required data is missing, or required tools/capabilities are not available. The function raises a TaskErrorException with quoted conflicting statements, missing data/tools/information, and where clarification or access must be requested.',
        );
    }
}
