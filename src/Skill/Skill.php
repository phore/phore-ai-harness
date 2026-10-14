<?php

declare(strict_types=1);

namespace Phore\AiHarness\Skill;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * One local Agent Skill: metadata, read-only resources and optional executables.
 *
 * Executables run with the PHP process privileges. Use only trusted skills.
 *
 * @see SkillCollection
 */
final readonly class Skill
{
    public string $directory;
    public string $name;
    public string $description;

    /**
     * @throws RuntimeException If SKILL.md cannot be read.
     * @throws InvalidArgumentException If its frontmatter is invalid.
     * @example $skill = new Skill('/project/.agents/skills/pdf-tools');
     * @see SkillCollection::add()
     */
    public function __construct(
        string $directory,
        public bool $initialLoad = false,
        public bool $allowExec = false,
        public int $execTimeout = 30,
    ) {
        if ($this->execTimeout <= 0) {
            throw new InvalidArgumentException('execTimeout must be a positive number of seconds.');
        }

        $canonical = realpath($directory);
        if ($canonical === false || !is_dir($canonical)) {
            throw new RuntimeException('Skill directory does not exist: ' . $directory);
        }
        $this->directory = $canonical;
        $header = $this->metadata();
        $name = $header['name'] ?? null;
        $description = $header['description'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid skill name in: ' . $this->directory . '/SKILL.md');
        }
        if (!is_string($description) || trim($description) === '') {
            throw new InvalidArgumentException('Missing skill description in: ' . $this->directory . '/SKILL.md');
        }

        $this->name = $name;
        $this->description = trim($description);
    }

    /**
     * Read the full SKILL.md only when requested by the agent, except for initialLoad.
     *
     * @throws RuntimeException For missing/unreadable SKILL.md.
     * @example $instructions = $skill->load();
     * @see listFiles()
     */
    public function load(): string
    {
        $file = $this->directory . '/SKILL.md';
        $text = @file_get_contents($file);
        if ($text === false) {
            throw new RuntimeException('Cannot read skill file: ' . $file);
        }

        return $text;
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        $text = $this->load();
        if (preg_match('/\A---[ \t]*\R(.*?)\R---[ \t]*(?:\R|\z)/s', $text, $match) !== 1) {
            throw new InvalidArgumentException('Missing YAML frontmatter in: ' . $this->directory . '/SKILL.md');
        }
        $header = @yaml_parse($match[1]);
        if (!is_array($header) || array_is_list($header)) {
            throw new InvalidArgumentException('Invalid YAML frontmatter in: ' . $this->directory . '/SKILL.md');
        }

        return $header;
    }

    /**
     * List only resources within a known skill resource directory.
     *
     * @param 'references'|'assets'|'scripts'|'bin' $group
     * @return list<string> Skill-relative file names.
     * @example $files = $skill->listFiles('assets');
     * @see readFile()
     */
    public function listFiles(string $group): array
    {
        if (!in_array($group, ['references', 'assets', 'scripts', 'bin'], true)) {
            throw new InvalidArgumentException('Unknown skill directory: ' . $group);
        }
        $root = $this->directory . '/' . $group;
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($this->directory) + 1);
            try {
                $this->resolveFile($relative, [$group]);
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            } catch (InvalidArgumentException | RuntimeException) {
                // Never expose a symlink target outside this skill.
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Read references and assets by skill-relative path, including binary assets.
     * Binary content is returned as base64 rather than being passed as invalid UTF-8.
     *
     * @return array{path: string, encoding: string, content: string}
     * @throws RuntimeException For unreadable or oversized resources.
     * @example $asset = $skill->readFile('assets/template.json');
     * @see listFiles()
     */
    public function readFile(string $path): array
    {
        $file = $this->resolveFile($path, ['references', 'assets']);
        if (filesize($file) > 1024 * 1024) {
            throw new RuntimeException('Skill resource exceeds 1 MiB: ' . $file);
        }
        $data = @file_get_contents($file);
        if ($data === false) {
            throw new RuntimeException('Cannot read skill resource: ' . $file);
        }
        $isText = !str_contains($data, "\0") && preg_match('//u', $data) === 1;

        return [
            'path' => $path,
            'encoding' => $isText ? 'utf-8' : 'base64',
            'content' => $isText ? $data : base64_encode($data),
        ];
    }

    /**
     * Execute any file within this skill's scripts/ or bin/ if explicitly enabled.
     * Arguments are passed as argv, not via an interpolated shell command.
     *
     * @param list<string> $args
     * @return array{exitCode: int, stdout: string, stderr: string}
     * @throws InvalidArgumentException If disabled or the file escapes the script roots.
     * @throws RuntimeException If starting the process fails or it exceeds limits.
     * @example $result = $skill->exec('scripts/extract.py', ['invoice.pdf']);
     * @see SkillCollection::addDirectory()
     */
    public function exec(string $path, array $args = []): array
    {
        if (!$this->allowExec) {
            throw new InvalidArgumentException('Execution is disabled for skill: ' . $this->name);
        }
        $file = $this->resolveFile($path, ['scripts', 'bin']);
        foreach ($args as $arg) {
            if (!is_string($arg)) {
                throw new InvalidArgumentException('Skill executable arguments must be strings.');
            }
        }

        if (is_executable($file)) {
            $command = [$file, ...$args];
        } else {
            $runner = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                'php' => PHP_BINARY,
                'py' => 'python3',
                'sh' => 'sh',
                'bash' => 'bash',
                default => throw new RuntimeException('Skill script is not executable: ' . $file),
            };
            $command = [$runner, $file, ...$args];
        }

        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start skill script: ' . $file);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $status = ['running' => true, 'exitcode' => -1];

        try {
            do {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                if (strlen($stdout) + strlen($stderr) > 1024 * 1024) {
                    throw new RuntimeException('Skill script output exceeds 1 MiB: ' . $file);
                }
                $status = proc_get_status($process);
                if ($status['running'] && microtime(true) - $started >= $this->execTimeout) {
                    $unit = $this->execTimeout === 1 ? ' second: ' : ' seconds: ';
                    throw new RuntimeException(
                        'Skill script timed out after ' . $this->execTimeout . $unit . $file,
                    );
                }
                if ($status['running']) {
                    usleep(20000);
                }
            } while ($status['running']);

            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            if (strlen($stdout) + strlen($stderr) > 1024 * 1024) {
                throw new RuntimeException('Skill script output exceeds 1 MiB: ' . $file);
            }
        } catch (RuntimeException $error) {
            // Timeout oder Output-Limit: laufenden Prozess erst beenden,
            // danach nur kurz auf das saubere Herunterfahren warten.
            proc_terminate($process);
            $deadline = microtime(true) + 0.3;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(20000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            throw $error;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closeCode = proc_close($process);
        }

        return [
            'exitCode' => $status['exitcode'] >= 0 ? $status['exitcode'] : $closeCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Resolve only files inside the permitted subdirectories, denying traversal
     * and symlinks escaping the skill root (also when allowExec is true).
     *
     * @param list<string> $groups
     */
    private function resolveFile(string $path, array $groups): string
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('/^[A-Za-z]:/', $path) === 1
        ) {
            throw new InvalidArgumentException('Invalid skill-relative path: ' . $path);
        }
        $file = realpath($this->directory . '/' . $path);
        if ($file === false || !is_file($file)) {
            throw new RuntimeException('Skill file not found: ' . $this->directory . '/' . $path);
        }
        foreach ($groups as $group) {
            if (str_starts_with($file, $this->directory . '/' . $group . '/')) {
                return $file;
            }
        }

        throw new InvalidArgumentException('Skill path outside permitted directories: ' . $path);
    }
}
