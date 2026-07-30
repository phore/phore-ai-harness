<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use PHPUnit\Framework\TestCase;

final class AiRequestTest extends TestCase
{
    public function testSerializesResponsesApiPayload(): void
    {
        $request = new AiRequest(
            model: 'gpt-4.1-mini',
            input: 'Hello',
            instructions: 'Answer shortly',
            maxOutputTokens: 123,
            temperature: 0.2,
            metadata: ['tenant' => 'test'],
        );

        self::assertSame([
            'model' => 'gpt-4.1-mini',
            'input' => 'Hello',
            'instructions' => 'Answer shortly',
            'max_output_tokens' => 123,
            'temperature' => 0.2,
            'metadata' => ['tenant' => 'test'],
            'stream' => false,
        ], $request->toArray(false));
    }

    public function testExtraBodyCanAddResponsesApiFields(): void
    {
        $request = AiRequest::text('gpt-4.1-mini', 'Hello')
            ->withExtraBody(['reasoning' => ['effort' => 'low']]);

        self::assertSame('low', $request->toArray()['reasoning']['effort']);
    }
}
