<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\FileSystem\AiFileSystem;
use Phore\AiHarness\FileSystem\FileOperationContext;
use Phore\AiHarness\FileSystem\FileSystemPolicy;
use Phore\AiHarness\FileSystem\SqliteRevisionStore;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$context = new AiContext();

$fileSystem = new AiFileSystem(
    $context,
    id: 'backend',
    alias: 'Backend project',
    description: 'PHP application code and project documentation.',
    policy: new FileSystemPolicy(
        ignore: ['app/vendor/*', 'app/node_modules/*', 'app/.git/*'],
        editable: ['app/src/*', 'app/docs/*'],
        creatable: ['app/docs/*'],
        deletable: ['app/docs/generated/*'],
    ),
    revisionStore: new SqliteRevisionStore('/tmp/backend-ai-history.sqlite'),
);

$fileSystem->addRoot(
    '/srv/backend',
    'app',
    searchable: true,
    editable: true,
    creatable: true,
    deletable: true,
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

$fileSystem->addFile(
    '/srv/shared/architecture.md',
    'architecture',
    searchable: true,
    editable: false,
);

$context->do(
    'Find the deprecated cache adapter in backend, update its use in app/src, '
    . 'and update the matching documentation in app/docs. Do not edit architecture.',
    throw: true,
);

echo $context->text('Summarize which files you changed and why.') . PHP_EOL;
