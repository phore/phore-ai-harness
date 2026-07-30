<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiResponse;
use Phore\AiHarness\Client\OpenAiClient;
use PHPUnit\Framework\TestCase;

final class OpenAiClientImageResponseTest extends TestCase
{
    public function testBuildsImageResponseFromImageGenerationCallResult(): void
    {
        $response = new AiResponse(200, [], [
            'output' => [[
                'type' => 'image_generation_call',
                'result' => base64_encode('image-binary'),
            ]],
        ], '{}');

        $result = (new OpenAiClient('test-key'))->buildImageResponse($response, 'image/png');

        self::assertSame('image-binary', $result->data);
        self::assertSame('image/png', $result->contentType);
        self::assertSame('png', $result->fileExtension);
    }

    public function testBuildsImageResponseFromDataUrl(): void
    {
        $response = new AiResponse(200, [], [
            'output' => [[
                'content' => [[
                    'type' => 'output_image',
                    'image_url' => 'data:image/webp;base64,' . base64_encode('webp-binary'),
                ]],
            ]],
        ], '{}');

        $result = (new OpenAiClient('test-key'))->buildImageResponse($response, 'image/png');

        self::assertSame('webp-binary', $result->data);
        self::assertSame('image/webp', $result->contentType);
        self::assertSame('webp', $result->fileExtension);
    }

    public function testThrowsIfResponseDoesNotContainImage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not contain image output');

        (new OpenAiClient('test-key'))->buildImageResponse(new AiResponse(200, [], ['output' => []], '{}'));
    }
}
