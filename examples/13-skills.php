<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\Skill\SkillCollection;

require dirname(__DIR__) . '/vendor/autoload.php';

// Replace this illustrative path with your own skill directory. Each skill
// contains SKILL.md with YAML frontmatter (name and description).
$skillsDir = '/project/.agents/skills';

// 1. Default: discover all nested skills; only names/descriptions are sent
// initially. The agent can load instructions, references and assets on demand.
$context = new AiContext();
$context->skills()->addDirectory($skillsDir);
$answer = $context->text('Use the appropriate skill to review my PHP code.');
print_r($context->skills()->list());

// 2. Alternatively prepare a collection explicitly, with one eager skill.
$skills = new SkillCollection();
$skills->add($skillsDir . '/coding-basis', initialLoad: true);
$skills->add($skillsDir . '/php-style');
$prepared = new AiContext(prompts: [$skills]);
$review = $prepared->text('Review the coding conventions.');

// 3. A separate collection may discover skills through glob patterns.
$fromPackages = new SkillCollection();
$fromPackages->add('/project/vendor/*/skills/*');
$packageContext = new AiContext(prompts: [$fromPackages]);

// 4. Executables are disabled by default. This alternative context explicitly
// allows any script inside bin/ and scripts/ of every discovered skill.
$executableContext = new AiContext();
$executableContext->skills()->addDirectory($skillsDir, options: [
    'allowExec' => true,
]);
$extracted = $executableContext->text('Use the PDF skill to extract the invoice.');

// 5. The same collection is accessible later from a named context.
// A new skill is visible on the next call, even after conversation start.
$named = AiContextRegistry::resolve(['ai_context' => 'skills-example']);
$named->skills()->add($skillsDir . '/coding-basis');
$named->text('Review the draft.');
$again = AiContextRegistry::get('skills-example');
$again->skills()->add($skillsDir . '/php-style');
$followUp = $again->text('Now check the PHP style as well.');

echo $answer . PHP_EOL . $review . PHP_EOL . $extracted . PHP_EOL . $followUp;
