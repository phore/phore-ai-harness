<?php

declare(strict_types=1);

use Phore\AiHarness\Keystore\Keystore;
use Phore\JsonPatch\JsonValue;
use PHPUnit\Framework\TestCase;

final class StructPatchE2eItem
{
    public function __construct(public string $id, public string $name) {}
}

final class StructPatchE2eDocument
{
    /** @var list<StructPatchE2eItem> */
    public array $items;

    public function __construct(public string $title, array $items)
    {
        $this->items = $items;
    }
}

final class StructPatchE2eTest extends TestCase
{
    public function testPointerAndStableBatchesAgainstRealApi(): void
    {
        if (!Keystore::instance()->hasKey('open_ai')) {
            self::markTestSkipped('No OpenAI API key configured.');
        }
        foreach (['pointer','stable'] as $addressing) {
            $target = new StructPatchE2eDocument('keep', [new StructPatchE2eItem('a','Ada'), new StructPatchE2eItem('b','Lin'), new StructPatchE2eItem('c','Max')]);
            $hash = JsonValue::hash($target);
            $result = phore_ai_edit_struct('Remove items a and c. Change the name of item b to "Lina". Keep the title unchanged.', $target,
                ['addressing'=>$addressing,'return_patch'=>true,'max_operations'=>12]);
            self::assertSame('keep', $result->value->title);
            self::assertCount(1, $result->value->items);
            self::assertSame('b', $result->value->items[0]->id);
            self::assertSame('Lina', $result->value->items[0]->name);
            self::assertSame($hash, JsonValue::hash($target));
            self::assertLessThanOrEqual(12, $result->operationCount);
        }
    }
}
