# AI File System

`AiFileSystem` exposes one optional directory root plus explicitly added real
files to an `AiContext`. The model sees relative paths and one shared set of
filesystem tools instead of the host filesystem.

## Typical flow

The root is the first constructor argument. If no context is supplied, the
filesystem creates its own `AiContext`; pass `context:` only when it should
share an existing conversation.

```php
$fileSystem = new AiFileSystem(
    '/srv/backend',
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

$fileSystem->addFile('/srv/shared/architecture.md');
```

The generated context is available through `getContext()`. The root can
instead be added later with `addRoot('/srv/backend')`. Each
filesystem accepts at most one root. Multiple roots are modeled as multiple
`AiFileSystem` instances with different IDs. Paths below the root are relative,
for example `src/Service.php`, with no additional root alias.

`addFile()` exposes one real file under its basename. There is no file alias and
no virtual/in-memory file API. Duplicate basenames are rejected. A file already
inside the configured root cannot be added again because it is already reachable
through its relative root path.

Without a root, explicit files can still be listed, searched, read, edited and
restored without changing their existence. Create, delete and move require a
configured root.

## Policy

There are no permission flags on `addRoot()` or `addFile()`. Omitting the
policy allows search and edit within the safe filesystem boundary. Create,
delete and move are denied by default, including when a policy is omitted.

A supplied policy is the single path-level restriction layer:

```php
$policy = new FileSystemPolicy(
    ignore: ['vendor/*', '.git/*'],
    searchable: ['*'],
    editable: ['src/*', 'docs/*'],
    creatable: ['docs/generated/*'],
    deletable: ['docs/generated/*'],
    movable: ['docs/drafts/*', 'docs/generated/*'],
);
```

Empty allowlists deny their operation; `['*']` explicitly permits all paths.
Move requires both paths to match `movable`, independently of `creatable` and
`deletable`. The destination must not exist; its parent must already exist on
the same filesystem. Ignored paths and their descendants take precedence. `writeIgnore` excludes
paths and descendants from every mutation while preserving read access. A `**`
path segment matches zero or more segments; patterns containing `/` are rooted,
while single-segment patterns match basenames. Set `literalWritePaths: true`
when mutation allowlists contain exact filenames rather than glob patterns.
`maxListLimit`,
`maxSearchResults` and `maxReadBytes` bound tool results.

## Tools and edits

The shared `AiFileSystemToolSet` provides list, grep, read, edit, create,
delete, move, history and restore operations. Editing is file-type agnostic. Every
non-null `search` must be an exact unique match in the original content.
`search=null` means a full rewrite and must be the only edit in that call.

Create, edit, delete and move support optional before/after hooks. A failing after-hook
rolls the filesystem state back before propagating the error. Runtime validation
errors routed through the toolset become recoverable tool feedback, so the model
can correct the mutation and retry. Move hooks receive source and destination
in `path`/`realPath` and `targetPath`/`targetRealPath` respectively.

```php
$fileSystem->getContext()->do(
    'Move docs/drafts/contact.md to docs/generated/contact.md.',
    throw: true,
);
// Direct API equivalent (alternative to the AI call):
// $fileSystem->move('docs/drafts/contact.md', 'docs/generated/contact.md');
```

The source is removed and the destination contains the original text.

`onChange()` observes successful create, edit, delete, move and restore
operations after the file and revision have been recorded. Unchanged or
rolled-back operations do not emit events. An observer exception does not
roll back the persisted file; handle notification failures separately.

## Export a change set

Attach the filesystem to an existing context with prepared prompts and data.
The constructor registers its tools in that context; no filesystem prompt or
additional AI call is needed to export the results.

```php
$context = new AiContext(prompts: [
    new SystemPrompt('Maintain the website using the project settings.'),
    new StructPrompt(['language' => 'de'], alias: 'settings'),
]);
$fileSystem = new AiFileSystem('/srv/website', context: $context, id: 'website');

$fileSystem->resetChangeSet();
$context->do('Update the contact page headline.', throw: true);

$changes = $fileSystem->getChangeSet(); // list<FileChange>
echo json_encode($changes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
```

Example result (assuming the model changed the headline):

```json
[
    {
        "path": "pages/contact.md",
        "status": "modified",
        "oldPath": null,
        "oldContent": "# Contact",
        "newContent": "# Kontakt"
    }
]
```

Each `FileChange` DTO has a relative path, status (`created`, `modified`,
`deleted` or `moved`), original path for moves, and full old/new contents.
Null content indicates a missing file; an empty string is an existing empty
file. Repeated edits and moves retain the first state and final state.
Create-then-delete and changes reverted to their starting state are omitted.
A move followed by an edit remains a move. A moved file subsequently deleted
is reported as a deletion of its original path. Successful restores are
included; rejected operations and successfully rolled-back hooks are not.

Tracking begins at construction and is instance-local, independent of the
revision store. It is not a filesystem scan and does not detect external
writes. Reset clears only the change set. Reading it is non-destructive.
Only export or forward file contents to authorized consumers.

## Revision history

Revision history is active by default through `MemoryRevisionStore`. It keeps
restorable states for the lifetime of the current process without setup.

For history that must survive later processes or sessions, inject
`SqliteRevisionStore`:

```php
$fileSystem = new AiFileSystem(
    '/srv/backend',
    id: 'backend',
    revisionStore: new SqliteRevisionStore('/var/lib/app/ai-history.sqlite'),
);
```

Both stores record content and existence state. A created file can therefore be
restored to "missing" only with delete permission, and a deleted root file can
be recreated only with create permission. Restoring content of an existing
file requires edit permission. Move records revisions for both paths; restoring
each path independently still requires the corresponding permissions.

Revision history is independent of `AiContext::rollback()`, which only moves
the conversation cursor.

## Safety boundaries

Model tool calls never receive arbitrary host paths. Path traversal is rejected,
symlinks leaving the root are not traversed, root mutation paths cannot follow
symlinks, reads are bounded, mutations accept
UTF-8 text only, and writes use snapshot checks plus atomic replacement.
