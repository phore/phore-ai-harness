<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Logging\LogEvent;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\Logging\RunContext;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\Skill\SkillCollection;
use PHPUnit\Framework\TestCase;

final class SkillCollectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ai-skill-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
        $this->makeSkill('pdf-tools', 'Work with PDFs');
        $this->makeSkill('themejs-edit', 'Edit ThemeJS');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        AiContextRegistry::clear();
    }

    private function makeSkill(string $name, string $description): void
    {
        $path = $this->root . '/' . $name;
        mkdir($path . '/references', 0700, true);
        mkdir($path . '/assets', 0700, true);
        mkdir($path . '/scripts', 0700, true);
        mkdir($path . '/bin', 0700, true);
        file_put_contents($path . '/SKILL.md', "---\nname: $name\ndescription: $description\n---\n\n# $name\n\nInstructions.\n");
        file_put_contents($path . '/references/help.md', 'Reference content');
        file_put_contents($path . '/assets/data.bin', "\0\1\2");
        file_put_contents($path . '/scripts/hello.php', '<?php echo $argv[1];');
    }

    public function testRecursiveDiscoveryReadAndDefaultNoExecution(): void
    {
        mkdir($this->root . '/nested', 0700);
        rename($this->root . '/themejs-edit', $this->root . '/nested/themejs-edit');
        $skills = new SkillCollection();
        $skills->addDirectory($this->root);

        self::assertSame(['pdf-tools', 'themejs-edit'], array_column($skills->list(), 'name'));
        self::assertStringContainsString('pdf-tools: Work with PDFs', $skills->prompt()->text);
        self::assertStringNotContainsString('# pdf-tools', $skills->prompt()->text);

        $pdf = $skills->get('pdf-tools');
        self::assertSame(['references/help.md'], $pdf->listFiles('references'));
        self::assertSame('Reference content', $pdf->readFile('references/help.md')['content']);
        self::assertSame('base64', $pdf->readFile('assets/data.bin')['encoding']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Execution is disabled');
        $pdf->exec('scripts/hello.php', ['ok']);
    }

    public function testInitialLoadAndWildcardDiscovery(): void
    {
        $skills = new SkillCollection();
        $skills->add($this->root . '/*', options: ['initialLoad' => true]);

        self::assertCount(2, $skills->list());
        self::assertStringContainsString('# pdf-tools', $skills->prompt()->text);
        self::assertTrue($skills->get('pdf-tools')->initialLoad);

        $separate = new SkillCollection();
        $separate->add($this->root . '/pdf-tools');
        self::assertFalse($separate->get('pdf-tools')->initialLoad);

        $directory = new SkillCollection();
        $directory->addDirectory($this->root, ['initialLoad' => true]);
        self::assertTrue($directory->get('themejs-edit')->initialLoad);
    }

    public function testDuplicateNamesThrowWithoutPartialRegistration(): void
    {
        $skills = new SkillCollection();
        $skills->add($this->root . '/pdf-tools');

        try {
            $skills->addDirectory($this->root);
            self::fail('Duplicate skill must fail.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Duplicate skill name: pdf-tools', $error->getMessage());
        }

        self::assertCount(1, $skills->list());
    }

    public function testSingleCollectionOnContextAndNamedRegistry(): void
    {
        $skills = new SkillCollection();
        $skills->add($this->root . '/pdf-tools');
        $context = new AiContext(prompts: [$skills]);
        self::assertSame($skills, $context->skills());

        $id = AiContextRegistry::resolve(['ai_context' => 'skill-context']);
        $id->skills()->add($this->root . '/themejs-edit');
        self::assertSame($id->skills(), AiContextRegistry::get('skill-context')->skills());

        $this->expectException(InvalidArgumentException::class);
        new AiContext(prompts: [$skills, new SkillCollection()]);
    }

    public function testAllScriptsAllowedWhenExplicitlyEnabled(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable.');
        }

        $skills = new SkillCollection();
        $skills->addDirectory($this->root, ['allowExec' => true]);
        $pdf = $skills->get('pdf-tools');

        self::assertTrue($pdf->allowExec);
        self::assertSame(['scripts/hello.php'], $pdf->listFiles('scripts'));
        self::assertSame(
            ['exitCode' => 0, 'stdout' => 'works', 'stderr' => ''],
            $pdf->exec('scripts/hello.php', ['works']),
        );
    }

    public function testSkillAccessLoggingSummarizesActionsWithoutOutputs(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable.');
        }
        $skills = new SkillCollection();
        $skills->add($this->root . '/pdf-tools', options: ['allowExec' => true]);
        $tools = [];
        foreach ($skills->tools() as $tool) {
            $tools[$tool->name()] = $tool;
        }

        $logger = new class implements LoggerInterface {
            /** @var list<LogEvent> */
            public array $events = [];

            public function log(LogEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $context = new RunContext($logger, 'gpt-test');
        $ai = new PhoreAi(new OpenAiClient('fixture-key'));
        $callback = new \ReflectionMethod($ai, 'invokeLoggedCallback');

        foreach ([
            ['skill_load', ['skill' => 'pdf-tools']],
            ['skill_list_references', ['skill' => 'pdf-tools']],
            ['skill_read_reference', ['skill' => 'pdf-tools', 'path' => 'references/help.md']],
            ['skill_list_assets', ['skill' => 'pdf-tools']],
            ['skill_read_asset', ['skill' => 'pdf-tools', 'path' => 'assets/data.bin']],
            ['skill_exec', ['skill' => 'pdf-tools', 'script' => 'scripts/hello.php', 'args' => ['private-input']]],
        ] as $index => [$name, $arguments]) {
            $callback->invoke($ai, $tools[$name], json_encode($arguments, JSON_THROW_ON_ERROR), 'call_' . $index, $context);
        }

        self::assertSame(
            ['skill_loaded', 'skill_references_listed', 'skill_reference_read',
                'skill_assets_listed', 'skill_asset_read', 'skill_executed'],
            array_column($logger->events, 'type'),
        );
        self::assertSame('references/help.md', $logger->events[2]->context['path']);
        self::assertSame('assets/data.bin', $logger->events[4]->context['path']);
        self::assertSame(0, $logger->events[5]->context['exitCode']);
        foreach ($logger->events as $event) {
            self::assertArrayNotHasKey('args', $event->context);
            self::assertArrayNotHasKey('stdout', $event->context);
            self::assertArrayNotHasKey('content', $event->context);
        }
        self::assertStringNotContainsString('private-input', json_encode(
            array_map(static fn (LogEvent $event): array => $event->context, $logger->events),
            JSON_THROW_ON_ERROR,
        ));
    }

    public function testCannotReadOrExecuteOutsideSkillSubdirectories(): void
    {
        $skills = new SkillCollection();
        $skills->add($this->root . '/pdf-tools', options: ['allowExec' => true]);
        $pdf = $skills->get('pdf-tools');

        foreach (['../../themejs-edit/SKILL.md', 'SKILL.md', 'scripts/hello.php'] as $path) {
            try {
                $pdf->readFile($path);
                self::fail('Must deny reading outside references/assets: ' . $path);
            } catch (InvalidArgumentException | \RuntimeException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $pdf->exec('references/help.md');
    }
}
