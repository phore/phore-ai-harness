<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\FileSystem\AiFileSystem;
use Phore\AiHarness\FileSystem\FileOperationContext;
use Phore\AiHarness\FileSystem\FileSystemPolicy;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$context = new AiContext();

$fileSystem = new AiFileSystem(
    $context,
    root: '/srv/backend',
    id: 'backend',
    description: 'PHP application code and project documentation.',
    policy: new FileSystemPolicy(
        ignore: ['vendor/*', 'node_modules/*', '.git/*'],
        editable: ['src/*', 'docs/*'],
        creatable: ['docs/*'],
        deletable: ['docs/generated/*'],
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

$context->do(
    'Find the deprecated cache adapter, update its use in src and update '
    . 'the matching documentation in docs. Do not edit architecture.md.',
    throw: true,
);

echo $context->text('Summarize which files you changed and why.') . PHP_EOL;
