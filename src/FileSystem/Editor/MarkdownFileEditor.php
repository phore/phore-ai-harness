<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem\Editor;

use InvalidArgumentException;
use Phore\AiHarness\FileSystem\StructuredFileEditorInterface;

final class MarkdownFileEditor extends TextFileEditor implements StructuredFileEditorInterface
{
    public function supports(string $path, string $content): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['md', 'markdown'], true)
            && parent::supports($path, $content);
    }

    public function structure(string $path, string $content): array
    {
        $sections = $this->sections($content);
        $children = [];
        foreach ($sections as $section) {
            $children[$section['parentId'] ?? 'root'][] = $section['id'];
        }

        $tree = [];
        foreach ($children['root'] ?? [] as $id) {
            $tree[] = $this->buildNode($id, $sections, $children);
        }

        return [
            'type' => 'markdown',
            'path' => $path,
            'preamble' => $sections === [] ? $content : substr($content, 0, $sections[0]['start']),
            'sections' => $tree,
        ];
    }

    public function applyStructure(
        string $path,
        string $content,
        string $action,
        string $sectionId,
        ?string $markdown = null,
        ?string $referenceId = null,
    ): string {
        $sections = $this->sections($content);
        $source = $this->findSection($sections, $sectionId);

        return match ($action) {
            'replace' => $this->replace($content, $source, $this->requireMarkdown($markdown)),
            'delete' => $this->replace($content, $source, ''),
            'insert_before' => $this->insert($content, $source['start'], $this->requireMarkdown($markdown)),
            'insert_after' => $this->insert($content, $source['end'], $this->requireMarkdown($markdown)),
            'move_before', 'move_after' => $this->move(
                $content,
                $source,
                $this->findSection($sections, $this->requireReference($referenceId)),
                $action === 'move_after',
            ),
            default => throw new InvalidArgumentException('Unknown Markdown section action: ' . $action),
        };
    }

    private function sections(string $content): array
    {
        preg_match_all(
            '/^(#{1,6})[ \t]+(.+?)[ \t]*#*[ \t]*$/m',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $raw = [];
        $occurrences = [];
        $parents = [];
        foreach ($matches[0] as $index => $match) {
            $level = strlen($matches[1][$index][0]);
            $title = trim($matches[2][$index][0]);
            $key = $level . ':' . strtolower($title);
            $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
            $id = 'mdsec_' . substr(hash('sha256', $key . ':' . $occurrences[$key]), 0, 12);

            while ($parents !== [] && $parents[array_key_last($parents)]['level'] >= $level) {
                array_pop($parents);
            }
            $parentId = $parents === [] ? null : $parents[array_key_last($parents)]['id'];
            $parents[] = ['id' => $id, 'level' => $level];
            $raw[] = [
                'id' => $id,
                'parentId' => $parentId,
                'level' => $level,
                'title' => $title,
                'start' => $match[1],
            ];
        }

        $sections = [];
        foreach ($raw as $index => $section) {
            $end = strlen($content);
            for ($next = $index + 1, $count = count($raw); $next < $count; $next++) {
                if ($raw[$next]['level'] <= $section['level']) {
                    $end = $raw[$next]['start'];
                    break;
                }
            }
            $sections[] = [
                ...$section,
                'end' => $end,
                'startLine' => substr_count(substr($content, 0, $section['start']), "\n") + 1,
                'endLineExclusive' => substr_count(substr($content, 0, $end), "\n") + 1,
            ];
        }

        return $sections;
    }

    private function buildNode(string $id, array $sections, array $children): array
    {
        $section = $this->findSection($sections, $id);
        $node = [
            'id' => $section['id'],
            'level' => $section['level'],
            'title' => $section['title'],
            'startLine' => $section['startLine'],
            'endLineExclusive' => $section['endLineExclusive'],
            'children' => [],
        ];
        foreach ($children[$id] ?? [] as $childId) {
            $node['children'][] = $this->buildNode($childId, $sections, $children);
        }

        return $node;
    }

    private function findSection(array $sections, string $id): array
    {
        foreach ($sections as $section) {
            if ($section['id'] === $id) {
                return $section;
            }
        }

        throw new InvalidArgumentException('Unknown Markdown section ID: ' . $id);
    }

    private function requireMarkdown(?string $markdown): string
    {
        if ($markdown === null) {
            throw new InvalidArgumentException('Markdown section operation requires markdown.');
        }

        return $this->normalizeBlock($markdown);
    }

    private function requireReference(?string $referenceId): string
    {
        if ($referenceId === null || trim($referenceId) === '') {
            throw new InvalidArgumentException('Markdown move operation requires referenceId.');
        }

        return $referenceId;
    }

    private function replace(string $content, array $section, string $replacement): string
    {
        return substr($content, 0, $section['start'])
            . $replacement
            . substr($content, $section['end']);
    }

    private function insert(string $content, int $offset, string $markdown): string
    {
        return substr($content, 0, $offset)
            . $this->normalizeBlock($markdown)
            . substr($content, $offset);
    }

    private function move(string $content, array $source, array $reference, bool $after): string
    {
        if (max($source['start'], $reference['start']) < min($source['end'], $reference['end'])) {
            throw new InvalidArgumentException(
                'Cannot move a Markdown section relative to its own ancestor or subtree.',
            );
        }

        $block = substr($content, $source['start'], $source['end'] - $source['start']);
        $without = substr($content, 0, $source['start']) . substr($content, $source['end']);
        $removedLength = $source['end'] - $source['start'];
        $referenceStart = $reference['start'];
        $referenceEnd = $reference['end'];
        if ($source['start'] < $reference['start']) {
            $referenceStart -= $removedLength;
            $referenceEnd -= $removedLength;
        }
        $offset = $after ? $referenceEnd : $referenceStart;

        return substr($without, 0, $offset)
            . $this->normalizeBlock($block)
            . substr($without, $offset);
    }

    private function normalizeBlock(string $markdown): string
    {
        return $markdown === '' ? '' : rtrim($markdown, "\r\n") . PHP_EOL;
    }
}
