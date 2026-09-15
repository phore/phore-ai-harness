<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use InvalidArgumentException;
use RuntimeException;

final readonly class PromptFile implements PromptType
{
    /** @var list<PromptType> */
    private array $segments;

    /** @var list<string> */
    private array $requiredAliases;

    public string $fileName;

    public function __construct(string $fileName)
    {
        $canonical = realpath($fileName);
        if ($canonical === false || !is_file($canonical)) {
            throw new RuntimeException('Could not read prompt file: ' . $fileName);
        }

        $this->fileName = $canonical;
        $resolved = [];
        $required = [];
        $chain = [];
        $stack = [];
        $segments = [];

        $this->resolvePrompt($canonical, null, null, $resolved, $required, $chain, $stack, $segments);

        array_unshift($segments, new TextPrompt(
            "Main prompt: " . basename($canonical) . "\nPrompt inheritance: " . implode(' -> ', array_map('basename', $chain))
        ));

        $this->segments = $segments;
        $this->requiredAliases = array_values(array_unique($required));
    }

    public function type(): string
    {
        return 'prompt_file';
    }

    /** @return list<PromptType> */
    public function segments(): array
    {
        return $this->segments;
    }

    /** @return list<string> */
    public function requiredAliases(): array
    {
        return $this->requiredAliases;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'fileName' => $this->fileName,
            'requiredAliases' => $this->requiredAliases,
            'segments' => array_map(static fn (PromptType $prompt): array => $prompt->toArray(), $this->segments),
        ];
    }

    /**
     * @param array<string, true> $resolved
     * @param list<string> $required
     * @param list<string> $chain
     * @param list<string> $stack
     * @param list<PromptType> $segments
     */
    private function resolvePrompt(
        string $fileName,
        ?string $alias,
        ?string $description,
        array &$resolved,
        array &$required,
        array &$chain,
        array &$stack,
        array &$segments,
    ): void {
        $cycleIndex = array_search($fileName, $stack, true);
        if ($cycleIndex !== false) {
            $cycle = array_slice($stack, $cycleIndex);
            $cycle[] = $fileName;
            throw new RuntimeException('Prompt file inheritance cycle: ' . implode(' -> ', array_map('basename', $cycle)));
        }

        if (isset($resolved[$fileName])) {
            return;
        }

        $stack[] = $fileName;
        $frontMatter = phore_file($fileName)->get_front_matter();
        if (!is_array($frontMatter->header)) {
            throw new InvalidArgumentException('Prompt file frontmatter must be a YAML mapping in: ' . $fileName);
        }

        $header = $frontMatter->header;
        $allowed = ['description', 'extends', 'references', 'requires_aliases'];
        foreach (array_keys($header) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Unknown prompt file frontmatter field ' . var_export($key, true) . ' in: ' . $fileName);
            }
        }

        if (isset($header['description']) && !is_string($header['description'])) {
            throw new InvalidArgumentException('Prompt file field description must be a string in: ' . $fileName);
        }

        foreach ($this->normalizeIncludes($header['extends'] ?? [], 'extends', $fileName) as $include) {
            $target = $this->resolvePath($fileName, $include['path'], 'extends');
            $this->resolvePrompt($target, $include['alias'], $include['description'], $resolved, $required, $chain, $stack, $segments);
        }

        $resolved[$fileName] = true;
        $chain[] = $fileName;
        $segments[] = new TextPrompt($frontMatter->content, alias: $alias, instructions: $description);

        foreach ($this->normalizeIncludes($header['references'] ?? [], 'references', $fileName) as $include) {
            $target = $this->resolvePath($fileName, $include['path'], 'references');
            $segments[] = FilePrompt::fromFile($target, alias: $include['alias'], instructions: $include['description']);
        }

        foreach ($this->normalizeStringList($header['requires_aliases'] ?? [], 'requires_aliases', $fileName) as $requiredAlias) {
            $required[] = $requiredAlias;
        }

        array_pop($stack);
    }

    /** @return list<array{path: string, alias: ?string, description: ?string}> */
    private function normalizeIncludes(mixed $value, string $field, string $fileName): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        $items = is_string($value) || $this->isIncludeMap($value) ? [$value] : $value;
        if (!is_array($items)) {
            throw new InvalidArgumentException("Prompt file field {$field} must be a string, mapping, or list in: {$fileName}");
        }

        $result = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                if (trim($item) === '') {
                    throw new InvalidArgumentException("Prompt file field {$field} contains an empty path in: {$fileName}");
                }
                $result[] = ['path' => $item, 'alias' => null, 'description' => null];
                continue;
            }

            if (!is_array($item)) {
                throw new InvalidArgumentException("Prompt file field {$field} contains an invalid entry in: {$fileName}");
            }

            foreach (array_keys($item) as $key) {
                if (!in_array($key, ['path', 'alias', 'description'], true)) {
                    throw new InvalidArgumentException("Unknown {$field} entry field " . var_export($key, true) . " in: {$fileName}");
                }
            }

            if (!isset($item['path']) || !is_string($item['path']) || trim($item['path']) === '') {
                throw new InvalidArgumentException("Prompt file field {$field} entry requires a non-empty path in: {$fileName}");
            }

            $entryAlias = $item['alias'] ?? null;
            $entryDescription = $item['description'] ?? null;
            if ($entryAlias !== null && (!is_string($entryAlias) || trim($entryAlias) === '')) {
                throw new InvalidArgumentException("Prompt file field {$field} alias must be a non-empty string in: {$fileName}");
            }
            if ($entryDescription !== null && (!is_string($entryDescription) || trim($entryDescription) === '')) {
                throw new InvalidArgumentException("Prompt file field {$field} description must be a non-empty string in: {$fileName}");
            }

            $result[] = ['path' => $item['path'], 'alias' => $entryAlias, 'description' => $entryDescription];
        }

        return $result;
    }

    /** @return list<string> */
    private function normalizeStringList(mixed $value, string $field, string $fileName): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        $items = is_string($value) ? [$value] : $value;
        if (!is_array($items)) {
            throw new InvalidArgumentException("Prompt file field {$field} must be a string or list in: {$fileName}");
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException("Prompt file field {$field} must contain only non-empty strings in: {$fileName}");
            }
            $result[] = $item;
        }
        return $result;
    }

    private function resolvePath(string $declaringFile, string $path, string $field): string
    {
        $candidate = $this->isAbsolutePath($path) ? $path : dirname($declaringFile) . DIRECTORY_SEPARATOR . $path;
        $canonical = realpath($candidate);
        if ($canonical === false || !is_file($canonical)) {
            throw new RuntimeException("Prompt file {$field} target not found: {$path} (declared in {$declaringFile})");
        }
        return $canonical;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function isIncludeMap(mixed $value): bool
    {
        return is_array($value) && array_key_exists('path', $value);
    }
}
