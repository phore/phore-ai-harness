<?php

declare(strict_types=1);

namespace Phore\AiHarness\Skill;

use InvalidArgumentException;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\ToolType\CallbackTool;
use RuntimeException;

/**
 * Discover Agent Skills and expose one fixed set of lazy-loading tools.
 *
 * A collection contains unique skill names. Execution is disabled by default;
 * allowExec=true applies to every file under bin/ or scripts/ for those skills.
 *
 * @see \Phore\AiHarness\AiContext::skills()
 */
final class SkillCollection implements PromptType
{
    /** @var array<string, Skill> */
    private array $skills = [];

    /**
     * Add one skill directory or a glob of skill directories.
     *
     * A path without SKILL.md is recursively searched like addDirectory().
     * The entire operation rejects duplicate names before registering entries.
     *
     * @param array{allowExec?: bool} $options
     * @return $this
     * @throws InvalidArgumentException For duplicate names or invalid options.
     * @example $skills->add('/project/skills/pdf', initialLoad: true);
     * @see addDirectory()
     */
    public function add(string $path, bool $initialLoad = false, array $options = []): self
    {
        $this->validateOptions($options, ['allowExec']);
        $paths = strpbrk($path, '*?[') === false ? [$path] : (glob($path, GLOB_ONLYDIR) ?: []);
        if ($paths === []) {
            throw new RuntimeException('No skill directories match: ' . $path);
        }

        return $this->register($paths, $initialLoad, $options['allowExec'] ?? false);
    }

    /**
     * Discover SKILL.md files recursively, including the root itself.
     * Nested files under an already discovered skill are not treated as skills.
     *
     * @param array{initialLoad?: bool, allowExec?: bool} $options
     * @return $this
     * @throws InvalidArgumentException For duplicate names or invalid options.
     * @example $skills->addDirectory('/project/.agents/skills', ['allowExec' => true]);
     * @see add()
     */
    public function addDirectory(string $directory, array $options = []): self
    {
        $this->validateOptions($options, ['allowExec', 'initialLoad']);

        return $this->register(
            [$directory],
            $options['initialLoad'] ?? false,
            $options['allowExec'] ?? false,
        );
    }

    /**
     * Fetch an already registered skill by its unique name.
     *
     * @throws InvalidArgumentException For an unknown skill.
     * @example $skill = $skills->get('pdf-tools');
     * @see list()
     */
    public function get(string $name): Skill
    {
        return $this->skills[$name]
            ?? throw new InvalidArgumentException('Unknown skill: ' . $name);
    }

    /**
     * Inspect registered names, descriptions and execution flags locally.
     *
     * @return list<array{name: string, description: string, initialLoad: bool, allowExec: bool}>
     * @example $catalogue = $skills->list();
     * @see get()
     */
    public function list(): array
    {
        return array_values(array_map(
            static fn (Skill $skill): array => [
                'name' => $skill->name,
                'description' => $skill->description,
                'initialLoad' => $skill->initialLoad,
                'allowExec' => $skill->allowExec,
            ],
            $this->skills,
        ));
    }

    /** @internal Render the current skill index on every request. */
    public function prompt(): SystemPrompt
    {
        $lines = [
            'Available local skills. Choose relevant skills by name and call skill_load',
            'before following their instructions. References and assets are read on demand.',
            'Only skill_exec may execute scripts, and only when allowExec is enabled.',
        ];
        foreach ($this->skills as $skill) {
            $lines[] = '- ' . $skill->name . ': ' . $skill->description;
            if ($skill->initialLoad) {
                $lines[] = 'Loaded skill ' . $skill->name . ":\n" . $skill->load();
            }
        }

        return new SystemPrompt(implode("\n", $lines));
    }

    /**
     * Eight generic tools serve all registered skills; none depend on a name.
     *
     * @return list<CallbackTool>
     * @example $tools = $skills->tools();
     * @see prompt()
     */
    public function tools(): array
    {
        return [
            new CallbackTool(
                /** @return string JSON list of discovered skill metadata. */
                fn (): string => $this->encode($this->list()),
                'skill_list',
                'List available skill names and descriptions.',
            ),
            new CallbackTool(
                /** @param string $skill The exact skill name. */
                fn (string $skill): string => $this->get($skill)->load(),
                'skill_load',
                'Read the complete SKILL.md for a named skill.',
            ),
            new CallbackTool(
                /** @param string $skill The exact skill name. */
                fn (string $skill): string => $this->encode($this->get($skill)->listFiles('references')),
                'skill_list_references',
                'List relative paths in the references/ directory of a skill.',
            ),
            new CallbackTool(
                /** @param string $skill Skill name. @param string $path Path such as references/guide.md. */
                fn (string $skill, string $path): string => $this->encode($this->get($skill)->readFile($path)),
                'skill_read_reference',
                'Read a skill reference under references/ by its skill-relative path.',
            ),
            new CallbackTool(
                /** @param string $skill The exact skill name. */
                fn (string $skill): string => $this->encode($this->get($skill)->listFiles('assets')),
                'skill_list_assets',
                'List available files under assets/ of a skill.',
            ),
            new CallbackTool(
                /** @param string $skill Skill name. @param string $path Path such as assets/template.json. */
                fn (string $skill, string $path): string => $this->encode($this->get($skill)->readFile($path)),
                'skill_read_asset',
                'Read an asset. Binary assets are returned base64-encoded.',
            ),
            new CallbackTool(
                /** @param string $skill The exact skill name. */
                fn (string $skill): string => $this->encode([
                    'allowExec' => $this->get($skill)->allowExec,
                    'scripts' => $this->get($skill)->listFiles('scripts'),
                    'bin' => $this->get($skill)->listFiles('bin'),
                ]),
                'skill_list_scripts',
                'List executables under scripts/ and bin/ and whether execution is enabled.',
            ),
            new CallbackTool(
                /**
                 * @param string $skill Exact skill name.
                 * @param string $script Relative file in scripts/ or bin/.
                 * @param list<string> $args Positional command arguments.
                 */
                fn (string $skill, string $script, array $args = []): string
                    => $this->encode($this->get($skill)->exec($script, $args)),
                'skill_exec',
                'Execute a file in scripts/ or bin/ when that skill has allowExec=true; arguments are argv.',
            ),
        ];
    }

    /** A marker; AiContext expands it to its SystemPrompt and CallbackTools. */
    public function type(): string
    {
        return 'skill_collection';
    }

    /**
     * Stable setup fingerprint input for AiContext export/import validation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'skills' => array_map(
                static fn (Skill $skill): array => [
                    'name' => $skill->name,
                    'directory' => $skill->directory,
                    'initialLoad' => $skill->initialLoad,
                    'allowExec' => $skill->allowExec,
                ],
                $this->skills,
            ),
        ];
    }

    /** @param list<string> $roots */
    private function register(array $roots, bool $initialLoad, bool $allowExec): self
    {
        $found = [];
        foreach ($roots as $root) {
            foreach ($this->discover($root) as $directory) {
                $skill = new Skill($directory, $initialLoad, $allowExec);
                if (isset($this->skills[$skill->name]) || isset($found[$skill->name])) {
                    throw new InvalidArgumentException(
                        'Duplicate skill name: ' . $skill->name . ' (' . $directory . ')',
                    );
                }
                $found[$skill->name] = $skill;
            }
        }

        foreach ($found as $name => $skill) {
            $this->skills[$name] = $skill;
        }
        ksort($this->skills, SORT_STRING);

        return $this;
    }

    /**
     * @return list<string>
     * @throws RuntimeException For a missing/unreadable directory.
     */
    private function discover(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Skill search directory does not exist: ' . $directory);
        }

        $found = [];
        $queue = [$root];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (is_file($current . '/SKILL.md')) {
                $found[] = $current;
                continue;
            }
            $entries = scandir($current);
            if ($entries === false) {
                throw new RuntimeException('Cannot scan skill directory: ' . $current);
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $subdir = $current . '/' . $entry;
                if (is_dir($subdir) && !is_link($subdir)) {
                    $queue[] = $subdir;
                }
            }
        }
        sort($found, SORT_STRING);

        return $found;
    }

    /** @param array<string, mixed> $options @param list<string> $allowed */
    private function validateOptions(array $options, array $allowed): void
    {
        foreach ($options as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_bool($value)) {
                throw new InvalidArgumentException('Invalid skill option: ' . (string) $key);
            }
        }
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
