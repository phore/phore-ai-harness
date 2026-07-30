<?php

declare(strict_types=1);

use Phore\AiHarness\OutputFormat\FileOutput;
use Phore\AiHarness\OutputFormat\ImageOutput;
use Phore\AiHarness\OutputFormat\StructOutput;
use Phore\AiHarness\OutputFormat\TextOutput;
use Phore\Schema\Generator\JsonSchema\JsonSchemaCompatibility;
use Phore\Schema\Generator\JsonSchema\JsonSchemaGeneratorOptions;
use PHPUnit\Framework\TestCase;

final readonly class OutputFormatTestAddress
{
    public function __construct(
        public string $city,
        public int $zip,
    ) {
    }
}

final readonly class OutputFormatTestAddressBook
{
    /**
     * @var OutputFormatTestAddress[]
     */
    public array $addresses;

    public function __construct(
        public string $source,
    ) {
    }
}

final class OutputFormatTest extends TestCase
{
    public function testTextOutput(): void
    {
        $output = new TextOutput('Plain answer.');

        self::assertSame('text', $output->type());
        self::assertSame([
            'type' => 'text',
            'description' => 'Plain answer.',
        ], $output->toArray());
    }

    public function testStructOutput(): void
    {
        $output = new StructOutput(OutputFormatTestAddress::class, 'Address JSON.');
        $array = $output->toArray();

        self::assertSame('struct', $output->type());
        self::assertSame(OutputFormatTestAddress::class, $output->className());
        self::assertSame(OutputFormatTestAddress::class, $array['className']);
        self::assertSame('Address JSON.', $array['description']);
        self::assertSame('object', $array['jsonSchema']['type']);
        self::assertSame('string', $array['jsonSchema']['properties']['city']['type']);
        self::assertSame('integer', $array['jsonSchema']['properties']['zip']['type']);
    }

    public function testStructOutputInlinesNestedClassArrayItems(): void
    {
        $output = new StructOutput(
            OutputFormatTestAddressBook::class,
            jsonSchemaOptions: new JsonSchemaGeneratorOptions(JsonSchemaCompatibility::OpenAiStructuredOutput),
        );

        $schema = $output->jsonSchema();
        $itemsSchema = $schema['properties']['addresses']['items'];

        self::assertSame('object', $itemsSchema['type']);
        self::assertArrayNotHasKey('phpClass', $itemsSchema);
        self::assertSame(false, $itemsSchema['additionalProperties']);
        self::assertSame('string', $itemsSchema['properties']['city']['type']);
        self::assertSame('integer', $itemsSchema['properties']['zip']['type']);
        self::assertSame(['city', 'zip'], $itemsSchema['required']);
    }

    public function testImageOutput(): void
    {
        $output = new ImageOutput('image/jpeg', 'Preview image.');

        self::assertSame('image', $output->type());
        self::assertSame([
            'type' => 'image',
            'mimeType' => 'image/jpeg',
            'description' => 'Preview image.',
        ], $output->toArray());
    }

    public function testFileOutput(): void
    {
        $output = new FileOutput('report.pdf', 'application/pdf', 'Generated report.');

        self::assertSame('file', $output->type());
        self::assertSame([
            'type' => 'file',
            'fileName' => 'report.pdf',
            'mimeType' => 'application/pdf',
            'description' => 'Generated report.',
        ], $output->toArray());
    }
}
