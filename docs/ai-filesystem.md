# AI File System

`AiFileSystem` exposes one optional directory root plus explicitly added real
files to an `AiContext`. The model sees relative paths and one shared set of
filesystem tools instead of the host filesystem.

## Typical flow

The root can be supplied directly in the constructor:

```php
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

$fileSystem->addFile('/srv/shared/architecture.md');
```

The root can instead be added later with `addRoot('/srv/backend')`. Each
filesystem accepts at most one root. Multiple roots are modeled as multiple
`AiFileSystem` instances with different IDs. Paths below the root are relative,
for example `src/Service.php`, with no additional root alias.

`addFile()` exposes one real file under its basename. There is no file alias and
no virtual/in-memory file API. Duplicate basenames are rejected. A file already
inside the configured root cannot be added again because it is already reachable
through its relative root path.

Without a root, explicit files can still be listed, searched, read, edited and
restored. Create and delete require a configured root.

## Policy

There are no permission flags on `addRoot()` or `addFile()`. Omitting the
policy uses an open `FileSystemPolicy`: search, edit, create and delete are
allowed inside the safe filesystem boundary.

A supplied policy is the single path-level restriction layer:

```php
$policy = new FileSystemPolicy(
    ignore: ['vendor/*', '.git/*'],
    searchable: ['*'],
    editable: ['src/*', 'docs/*'],
    creatable: ['docs/generated/*'],
    deletable: ['docs/generated/*'],
);
```

Policy defaults allow every operation; narrow only the capabilities that need
restrictions. Ignored directories are not traversed. `maxListLimit`,
`maxSearchResults` and `maxReadBytes` bound tool results.

## Tools and edits

The shared `AiFileSystemToolSet` provides list, grep, read, edit, create,
delete, history and restore operations. Editing is file-type agnostic. Every
non-null `search` must be an exact unique match in the original content.
`search=null` means a full rewrite and must be the only edit in that call.

Create, edit and delete support optional before/after hooks. A failing after-hook
rolls the filesystem state back before propagating the error. Runtime validation
errors routed through the toolset become recoverable tool feedback, so the model
can correct the mutation and retry.

## Revision history

Revision history is active by default through `MemoryRevisionStore`. It keeps
restorable states for the lifetime of the current process without setup.

For history that must survive later processes or sessions, inject
`SqliteRevisionStore`:

```php
$fileSystem = new AiFileSystem(
    $context,
    root: '/srv/backend',
    id: 'backend',
    revisionStore: new SqliteRevisionStore('/var/lib/app/ai-history.sqlite'),
);
```

Both stores record content and existence state. A created file can therefore be
restored to "missing", and a deleted root file can be recreated from history.

Revision history is independent of `AiContext::rollback()`, which only moves
the conversation cursor.

## Safety boundaries

Model tool calls never receive arbitrary host paths. Path traversal is rejected,
symlinks leaving the root are not traversed, reads are bounded, mutations accept
UTF-8 text only, and writes use snapshot checks plus atomic replacement.
