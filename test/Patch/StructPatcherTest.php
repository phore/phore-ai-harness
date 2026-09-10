<?php

declare(strict_types=1);

use Phore\AiHarness\Patch\{JsonPatch, JsonValue, PatchConflictException, PatchValidationException, StableArrayView, StructPatcher};
use PHPUnit\Framework\TestCase;

final class PatchTestItem
{
    public function __construct(public string $id, public string $name) {}
}

final class PatchTestStruct
{
    /** @var list<PatchTestItem> */
    public array $items;

    public function __construct(public string $title, array $items, public ?string $note = null)
    {
        $this->items = $items;
    }
}

final class PatchTestInvariant
{
    public function __construct(public int $count)
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Sensitive constructor data');
        }
    }
}

final class PatchTestNode
{
    public function __construct(public string $name, public ?PatchTestNode $next = null) {}
}

final class StructPatcherTest extends TestCase
{
    public function testRecursiveStructsAreFullyValidatedBeforeHydration(): void
    {
        $target = new PatchTestNode('root', new PatchTestNode('child'));
        $result = (new StructPatcher())->apply($target, JsonPatch::fromArray([
            ['op'=>'replace','path'=>'/next/name','value'=>'edited'],
        ]));
        self::assertInstanceOf(PatchTestNode::class, $result->value->next);
        self::assertSame('edited', $result->value->next->name);
        self::assertSame('child', $target->next->name);
        $this->expectException(PatchValidationException::class);
        (new StructPatcher())->apply($target, JsonPatch::fromArray([
            ['op'=>'replace','path'=>'/next/name','value'=>false],
        ]));
    }

    public function testNewInstanceWithNestedHydrationAndDryRunMetadata(): void
    {
        $target = new PatchTestStruct('before', [new PatchTestItem('a', 'Ada')], 'optional');
        $result = (new StructPatcher())->apply($target, JsonPatch::fromArray([
            ['op'=>'replace','path'=>'/items/0/name','value'=>'Lin'],
            ['op'=>'remove','path'=>'/note'],
        ]), ['dry_run'=>true,'return_patch'=>true]);
        self::assertSame('dry_run', $result->status);
        self::assertSame(2, $result->operationCount);
        self::assertSame(['/items/0/name','/note'], $result->changedPaths);
        self::assertInstanceOf(PatchTestStruct::class, $result->value);
        self::assertInstanceOf(PatchTestItem::class, $result->value->items[0]);
        self::assertNotSame($target, $result->value);
        self::assertNotSame($target->items[0], $result->value->items[0]);
        self::assertSame('Ada', $target->items[0]->name);
        self::assertSame('Lin', $result->value->items[0]->name);
        self::assertNull($result->value->note);
        self::assertSame(JsonValue::hash($target), $result->oldHash);
        self::assertSame(JsonValue::hash($result->value), $result->newHash);
        self::assertInstanceOf(JsonPatch::class, $result->patch);
    }

    public function testSchemaRejectsUnknownMissingAndWrongTypesAtomically(): void
    {
        $target = new PatchTestStruct('before', [new PatchTestItem('a', 'Ada')]);
        foreach ([
            ['op'=>'add','path'=>'/extra','value'=>true],
            ['op'=>'remove','path'=>'/title'],
            ['op'=>'replace','path'=>'/items/0/name','value'=>7],
            ['op'=>'replace','path'=>'/items','value'=>(object)[]],
            ['op'=>'replace','path'=>'/items/0','value'=>[]],
            ['op'=>'add','path'=>'/items/0/extra','value'=>'secret'],
        ] as $invalid) {
            try {
                (new StructPatcher())->apply($target, JsonPatch::fromArray([
                    ['op'=>'replace','path'=>'/title','value'=>'changed'], $invalid,
                ]));
                self::fail('Expected schema failure');
            } catch (PatchValidationException $exception) {
                self::assertSame('schema_validation_failed', $exception->errorCode);
                self::assertSame('before', $target->title);
                self::assertSame('Ada', $target->items[0]->name);
            }
        }
    }

    public function testStablePatchingUsesStructHashesAndRehydratesLists(): void
    {
        $target = new PatchTestStruct('before', [new PatchTestItem('a','A'),new PatchTestItem('b','B')]);
        $view = (new StableArrayView())->encode($target);
        [$a,$b] = $view->items->{'$order'};
        $result = (new StructPatcher())->apply($target, JsonPatch::fromArray([
            ['op'=>'replace','path'=>'/items/$values/' . $b . '/name','value'=>'changed'],
            ['op'=>'replace','path'=>'/items/$order','value'=>[$b,$a]],
        ]), ['addressing'=>'stable','expected_hash'=>JsonValue::hash($target)]);
        self::assertSame('b', $result->value->items[0]->id);
        self::assertSame('changed', $result->value->items[0]->name);
        self::assertSame('a', $target->items[0]->id);
    }

    public function testStaleHashFails(): void
    {
        $this->expectException(PatchConflictException::class);
        (new StructPatcher())->apply(new PatchTestStruct('before', []), new JsonPatch([]), ['expected_hash'=>str_repeat('0',64)]);
    }

    public function testConstructorFailureDoesNotLeakDataOrMutateTarget(): void
    {
        $target = new PatchTestInvariant(1);
        try {
            (new StructPatcher())->apply($target, JsonPatch::fromArray([['op'=>'replace','path'=>'/count','value'=>-1]]));
            self::fail('Expected hydration failure');
        } catch (PatchValidationException $exception) {
            self::assertSame('hydration_failed', $exception->errorCode);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('Sensitive', $exception->getMessage());
        }
        self::assertSame(1, $target->count);
    }

    public function testNoOpReturnsDetachedInstanceWithSameHash(): void
    {
        $target = new PatchTestStruct('same', []);
        $result = (new StructPatcher())->apply($target, new JsonPatch([]));
        self::assertNotSame($target, $result->value);
        self::assertSame($result->oldHash, $result->newHash);
        self::assertSame([], $result->changedPaths);
    }
}
