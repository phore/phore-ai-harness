<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\FileSystem\AiFileSystem;
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
    ),
    revisionStore: new SqliteRevisionStore('/tmp/backend-ai-history.sqlite'),
);

$fileSystem->addRoot('/srv/backend', 'app', searchable: true, editable: true);

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
