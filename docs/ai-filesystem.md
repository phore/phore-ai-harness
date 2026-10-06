# AI File System

`AiFileSystem` exposes selected local files and directories to an `AiContext`
without making the whole host filesystem available. The model sees virtual paths
and one shared set of tools for listing, searching, reading, editing, revision
history and restore.

## Typical flow

Create the context first, then bind one or more filesystems to it:

```php
$context = new AiContext();

$fileSystem = new AiFileSystem(
    $context,
    id: 'backend',
    alias: 'Backend project',
    description: 'Application code and documentation.',
    policy: new FileSystemPolicy(
        ignore: ['app/vendor/*', 'app/node_modules/*', 'app/.git/*'],
        editable: ['app/src/*', 'app/docs/*'],
        creatable: ['app/docs/*'],
        deletable: ['app/docs/generated/*'],
    ),
    revisionStore: new SqliteRevisionStore('/var/lib/app/ai-history.sqlite'),
);

$fileSystem->addRoot(
    '/srv/backend',
    'app',
    searchable: true,
    editable: true,
    creatable: true,
    deletable: true,
);
$fileSystem->addFile(
    '/srv/shared/architecture.md',
    'architecture',
    searchable: true,
    editable: false,
);

$context->do(
    'Find the deprecated cache adapter, update app/src and the matching '
    . 'documentation in app/docs. Do not edit architecture.',
    throw: true,
);
```

The `AiFileSystem` constructor registers the filesystem in the context's
`AiFileSystemToolSet`. A second filesystem attached to the same context reuses
that exact tool-set instance; the context does not receive another copy of the
same callbacks.

## Virtual paths and several roots

Every added root or explicit file gets a virtual top-level name. A root added as
`app` exposes paths such as `app/src/Service.php`; an individual file added as
`architecture` is addressed exactly by that name. Real absolute paths are not
passed to the model.

This also avoids collisions. Two different `AiFileSystem` instances may both
contain `README.md`, because every tool call routes first by the filesystem
`id`. Within one filesystem, different roots must use different virtual names.

`id` is the stable routing value used by tools. `alias` and `description` are
optional model-facing metadata. For resumable applications, set explicit stable
IDs and rebuild the same filesystem/tool-set setup before importing an
`AiContext` state.

## Searchable and editable access

`addRoot()` and `addFile()` independently define whether a source is searchable
and editable. `FileSystemPolicy` applies an additional allowlist and ignore layer
using virtual-path patterns.

```php
$policy = new FileSystemPolicy(
    ignore: ['app/vendor/*', 'app/node_modules/*'],
    searchable: ['*'],
    editable: ['app/src/*', 'app/docs/*'],
);

$fileSystem->addRoot('/srv/backend', 'app', searchable: true, editable: true);
$fileSystem->addFile('/srv/shared/secret.txt', 'secret', searchable: false);
```

A file must pass both checks: the source must permit the operation and the policy
must permit its virtual path. Ignored directories are pruned while walking the
tree, so a recursive list or search does not first enumerate all of
`node_modules` or another ignored subtree.

Listing is paginated with `offset` and `limit`. The policy caps the requested
limit. Recursive listing is opt-in. This keeps large source trees from being
placed into one tool result.

## Create and delete permissions

Creating and deleting files are separate capabilities and are disabled by
default. A root must opt in with `creatable: true` or `deletable: true`, and
the matching `FileSystemPolicy` pattern must allow the concrete virtual path.
This means `editable: true` does not implicitly allow adding or removing files.

`filesystem_create` creates UTF-8 text files below an existing root directory.
It never creates directories. `filesystem_delete` removes an existing UTF-8
text file. Explicit files added with `addFile()` may opt into deletion, but
creation is only available below directory roots.

## Mutation hooks and validation

Create, edit and delete each expose before and after hooks:

`onBeforeCreate()`, `onAfterCreate()`, `onBeforeEdit()`,
`onAfterEdit()`, `onBeforeDelete()` and `onAfterDelete()`.

Every callback receives a `FileOperationContext` containing filesystem ID,
operation, virtual path, real path, previous content and resulting content.
Before hooks can veto a mutation before it touches disk. After hooks inspect the
state that was actually written.

If an after hook throws, the filesystem automatically rolls the mutation back
before propagating the exception. For callbacks reached through the shared tool
set, a `RuntimeException` becomes recoverable tool feedback. The model can then
use the validation message to correct its edit and call the tool again.

```php
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
```

This validation is transactional from the caller's perspective: when the
validator fails, the invalid file content is no longer present when the tool
returns the recoverable error.

## How the model works with files

The shared tool set exposes these callbacks:

| Tool | Purpose |
| --- | --- |
| `filesystem_list_systems` | discover filesystem IDs, aliases and roots |
| `filesystem_list` | list one virtual directory with pagination |
| `filesystem_grep` | literal text search with line context |
| `filesystem_read` | read a bounded line range |
| `filesystem_edit` | apply exact search/replacement edits |
| `filesystem_create` | create an explicitly permitted UTF-8 text file |
| `filesystem_delete` | delete an explicitly permitted UTF-8 text file |
| `filesystem_structure` | inspect a file through a structural editor |
| `filesystem_structure_edit` | edit or move a structural element by stable ID |
| `filesystem_history` | list saved revisions |
| `filesystem_restore` | restore a saved revision |

The model normally starts with `filesystem_list_systems`, narrows the tree with
`filesystem_list` or `filesystem_grep`, reads only the relevant sections and
then edits an explicitly editable file.

`filesystem_edit` uses the same exact-edit semantics as the existing text/file
editing engine: every non-null search must be unique in the original content,
and `search=null` means a full rewrite and must be the only edit. Editors are
local deterministic objects; they do not start nested model requests.

Binary and invalid UTF-8 content can be listed, but it cannot be read, searched
or edited as text. `maxReadBytes` bounds how much one text file may contribute
to read/search operations.

## File-type editors

`AiFileSystem` selects the first registered `FileEditorInterface` that supports
the file. Markdown and generic UTF-8 text editors are registered by default. A
project can prepend a specialized editor:

```php
$fileSystem->registerEditor(new ProjectConfigEditor());
```

This keeps file-type-specific parsing or structural editing outside the generic
filesystem routing. The generic structural tools remain unchanged, so adding a
specialized editor does not duplicate callbacks in the context.

### Markdown sections

The built-in Markdown editor exposes the heading hierarchy as a tree. Each
section includes its heading level, title, line range, children and a stable ID.
The ID remains stable while the section's heading level/title and its duplicate
occurrence remain unchanged. After renaming or adding duplicate headings, obtain
the structure again instead of reusing an old ID.

```php
$structure = $fileSystem->structure('app/docs/guide.md');

$alphaId = $structure['sections'][0]['children'][0]['id'];
$betaId = $structure['sections'][0]['children'][1]['id'];

$fileSystem->structureEdit(
    'app/docs/guide.md',
    'move_before',
    $betaId,
    referenceId: $alphaId,
);
```

Supported actions are `replace`, `delete`, `insert_before`,
`insert_after`, `move_before` and `move_after`. A section range includes
its child sections, so moving or deleting a heading moves or deletes its subtree.
Moves relative to an ancestor or descendant are rejected as ambiguous.

## Revisions and restore

Revision history is optional. Pass a `RevisionStoreInterface` to enable it.
`SqliteRevisionStore` stores file contents and metadata in SQLite and deduplicates
consecutive identical revisions.

The store records both file contents and whether a file existed. Create records
the previous missing state, delete records a missing state after removal, and
edit records its before/after contents. Consequently a create can be restored
back to "missing", and a deleted file can be restored with its previous content.

Only mutations that pass their after hooks are stored as new successful states.
A failed after hook rolls the filesystem back first. Restoring an old revision
also creates a new latest revision, so history is never rewritten destructively.

```php
$history = $fileSystem->history('app/docs/guide.md');
$revisionId = $history['revisions'][1]['id'];

$fileSystem->restore('app/docs/guide.md', $revisionId);
```

The normal `AiContext::setCheckpoint()` and `rollback()` still affect only the
provider conversation cursor. File rollback is explicitly handled by the
filesystem revision store.

## Toolsets and context state

`AiToolSet` is the generic grouping contract behind the filesystem feature.
`AiContext::addToolSet()` registers one instance per concrete tool-set class;
`hasToolSet()` and `getToolSet()` retrieve that instance. The same tool-set
object can deliberately be registered on several contexts, and cloned contexts
share the registered tool-set objects just like other external tool dependencies.

Toolset objects and filesystem registrations are process-local application
objects. `AiContext::exportState()` does not serialize them. Rebuild the same
toolsets, filesystem IDs, roots and policies before `importState()`.

See `examples/13-tool-set.php` for the generic registry pattern and
`examples/14-ai-file-system.php` for a complete filesystem setup.
