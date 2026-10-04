<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\AiContextTrait;
use Phore\AiHarness\PromptType\StructPrompt;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class SupportCase
{
    use AiContextTrait;

    public function __construct(
        public string $subject,
        public string $message,
    ) {
        $this->ai_set_context(new AiContext(
            prompts: [
                new StructPrompt([
                    'subject' => $this->subject,
                    'message' => $this->message,
                ], alias: 'case'),
            ],
            options: ['model' => 'gpt-5-mini'],
        ));
    }
}

$case = new SupportCase(
    subject: 'Profile update',
    message: 'Please replace my phone number with +49 123 456.',
);

$isProfileChange = $case->ai_yes_no('Does the case request a profile change?');
$topic = $case->ai_choice(
    'Choose the best topic for the current case.',
    ['profile', 'billing', 'other'],
);

$case->ai_set_checkpoint('classified');
$summary = $case->ai_text('Summarize the requested change in one sentence.');

var_dump($isProfileChange, $topic, $summary);
