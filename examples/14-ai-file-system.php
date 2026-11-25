<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\FileSystem\AiFileSystem;
use Phore\AiHarness\FileSystem\FileOperationContext;
use Phore\AiHarness\FileSystem\FileSystemPolicy;
use Phore\AiHarness\PromptType\StructPrompt;
use Phore\AiHarness\PromptType\SystemPrompt;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Prepare the conversation with application context before adding filesystem tools.
$context = new AiContext(prompts: [
    new SystemPrompt('Maintain the application code and documentation. Follow the project settings.'),
    new StructPrompt([
        'project' => 'Backend',
        'cacheAdapter' => 'Redis',
    ], alias: 'project-settings'),
]);

$fileSystem = new AiFileSystem(
    '/srv/backend',
    context: $context,
    id: 'backend',
    description: 'PHP application code and project documentation.',
    policy: new FileSystemPolicy(
        ignore: ['vendor/*', 'node_modules/*', '.git/*'],
        editable: ['src/*', 'docs/*'],
        creatable: ['docs/*'],
        deletable: ['docs/generated/*'],
        movable: ['docs/drafts/*', 'docs/generated/*'],
    ),
);

$fileSystem->onAfterEdit(
    static function (FileOperationContext $event): void {
        if (!str_ends_with($event->path, '.php')) {
            return;
        }

        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($event->realPath) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException(implode("\n", $output));
        }
    },
);

$fileSystem->addFile('/srv/shared/architecture.md');

$fileSystem->resetChangeSet();

$context->do(
    'Find the deprecated cache adapter, update its use in src and update '
    . 'the matching documentation in docs. If docs/drafts/adapter.md exists, '
    . 'move it to docs/generated/adapter.md if that directory exists. '
    . 'Do not edit architecture.md.',
    throw: true,
);

// Export the actual mutations independently of the AI's textual response.
$changes = $fileSystem->getChangeSet();
echo json_encode($changes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
// Example: [{"path":"docs/adapter.md","status":"modified","oldPath":null,
//            "oldContent":"Old adapter","newContent":"Redis adapter"}]
