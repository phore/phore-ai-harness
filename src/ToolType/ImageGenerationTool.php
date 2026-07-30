<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

use InvalidArgumentException;

final readonly class ImageGenerationTool extends AbstractToolType
{
    private const ALLOWED_SIZES = ['auto', '1024x1024', '1024x1536', '1536x1024'];
    private const ALLOWED_OUTPUT_FORMATS = ['png', 'jpeg', 'webp'];
    private const ALLOWED_QUALITIES = ['auto', 'low', 'medium', 'high'];
    private const ALLOWED_BACKGROUNDS = ['auto', 'transparent', 'opaque'];
    private const ALLOWED_OPTION_KEYS = ['size', 'output_format', 'quality', 'background'];

    protected const PROVIDER_TYPES = [
        'open_ai' => 'image_generation',
    ];

    /**
     * @param string|array<string, mixed>|null $size Pass an array for backwards-compatible raw option input.
     */
    public function __construct(
        string|array|null $size = null,
        ?string $output_format = null,
        ?string $quality = null,
        ?string $background = null,
    ) {
        $options = is_array($size) ? $size : [];

        if (!is_array($size) && $size !== null) {
            $options['size'] = $size;
        }
        if ($output_format !== null) {
            $options['output_format'] = $output_format;
        }
        if ($quality !== null) {
            $options['quality'] = $quality;
        }
        if ($background !== null) {
            $options['background'] = $background;
        }

        $this->assertValidOptions($options);

        parent::__construct($options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertValidOptions(array $options): void
    {
        foreach ($options as $key => $value) {
            if (!in_array($key, self::ALLOWED_OPTION_KEYS, true)) {
                throw new InvalidArgumentException('Unsupported image generation option: ' . $key);
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException('Image generation option "' . $key . '" must be a string.');
            }
        }

        $this->assertAllowedValue($options, 'size', self::ALLOWED_SIZES);
        $this->assertAllowedValue($options, 'output_format', self::ALLOWED_OUTPUT_FORMATS);
        $this->assertAllowedValue($options, 'quality', self::ALLOWED_QUALITIES);
        $this->assertAllowedValue($options, 'background', self::ALLOWED_BACKGROUNDS);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $allowedValues
     */
    private function assertAllowedValue(array $options, string $key, array $allowedValues): void
    {
        if (!isset($options[$key])) {
            return;
        }

        if (!in_array($options[$key], $allowedValues, true)) {
            throw new InvalidArgumentException(
                'Invalid image generation option "' . $key . '". Allowed values: ' . implode(', ', $allowedValues),
            );
        }
    }
}
