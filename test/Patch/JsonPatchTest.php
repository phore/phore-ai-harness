<?php

declare(strict_types=1);

use Phore\AiHarness\Patch\{JsonPatch, JsonPatchApplier, JsonPatchOperation, JsonPointer, JsonValue, PatchApplyOptions, PatchConflictException, PatchLimitException, PatchTestFailedException, PatchValidationException};
use PHPUnit\Framework\TestCase;

final class JsonPatchTest extends TestCase
{
    private function apply(string $document, array $operations, ?PatchApplyOptions $options = null): mixed
    {
        return (new JsonPatchApplier())->apply(JsonValue::decode($document), JsonPatch::fromArray($operations), $options)->value;
    }

    public function testRfc6902AppendixExamples(): void
    {
        $all = new PatchApplyOptions(allowedOperations: ['add', 'remove', 'replace', 'move', 'copy', 'test'], allowRootReplacement: true);
        $cases = [
            ['{"foo":"bar"}', [['op'=>'add','path'=>'/baz','value'=>'qux']], '{"foo":"bar","baz":"qux"}'],
            ['{"foo":["bar","baz"]}', [['op'=>'add','path'=>'/foo/1','value'=>'qux']], '{"foo":["bar","qux","baz"]}'],
            ['{"baz":"qux","foo":"bar"}', [['op'=>'remove','path'=>'/baz']], '{"foo":"bar"}'],
            ['{"foo":["bar","qux","baz"]}', [['op'=>'remove','path'=>'/foo/1']], '{"foo":["bar","baz"]}'],
            ['{"baz":"qux","foo":"bar"}', [['op'=>'replace','path'=>'/baz','value'=>'boo']], '{"baz":"boo","foo":"bar"}'],
            ['{"foo":{"bar":"baz","waldo":"fred"},"qux":{"corge":"grault"}}', [['op'=>'move','from'=>'/foo/waldo','path'=>'/qux/thud']], '{"foo":{"bar":"baz"},"qux":{"corge":"grault","thud":"fred"}}'],
            ['{"foo":["all","grass","cows","eat"]}', [['op'=>'move','from'=>'/foo/1','path'=>'/foo/3']], '{"foo":["all","cows","eat","grass"]}'],
            ['{"baz":"qux","foo":["a",2,"c"]}', [['op'=>'test','path'=>'/baz','value'=>'qux'],['op'=>'test','path'=>'/foo/1','value'=>2]], '{"baz":"qux","foo":["a",2,"c"]}'],
            ['{"foo":"bar"}', [['op'=>'add','path'=>'/child','value'=>(object)['grandchild'=>(object)[]]]], '{"foo":"bar","child":{"grandchild":{}}}'],
            ['{"foo":"bar"}', [['op'=>'add','path'=>'/baz','value'=>'qux','xyz'=>123]], '{"foo":"bar","baz":"qux"}'],
            ['{"/":9,"~1":10}', [['op'=>'test','path'=>'/~01','value'=>10]], '{"/":9,"~1":10}'],
            ['{"foo":["bar"]}', [['op'=>'add','path'=>'/foo/-','value'=>['abc','def']]], '{"foo":["bar",["abc","def"]]}'],
            ['{"a":{"x":1}}', [['op'=>'copy','from'=>'/a','path'=>'/b'],['op'=>'replace','path'=>'/b/x','value'=>2]], '{"a":{"x":1},"b":{"x":2}}'],
            ['{"a":1}', [['op'=>'replace','path'=>'','value'=>null]], 'null'],
            ['[]', [['op'=>'add','path'=>'/-','value'=>(object)[]]], '[{}]'],
        ];
        foreach ($cases as [$before, $patch, $after]) {
            self::assertTrue(JsonValue::equal(JsonValue::decode($after), $this->apply($before, $patch, $all)), JsonValue::encode($patch));
        }
    }

    public function testPointerEscapingAndEmptyKeys(): void
    {
        $document = JsonValue::decode('{"":0,"a/b":1,"m~n":2,"0":"object key"}');
        self::assertSame(0, JsonPointer::get($document, '/'));
        self::assertSame(1, JsonPointer::get($document, '/a~1b'));
        self::assertSame(2, JsonPointer::get($document, '/m~0n'));
        self::assertSame('object key', JsonPointer::get($document, '/0'));
        self::assertSame($document, JsonPointer::get($document, ''));
    }

    public function testAtomicFailureIncludesIndexWithoutValues(): void
    {
        $target = JsonValue::decode('{"a":{"secret":"original"}}');
        try {
            (new JsonPatchApplier())->apply($target, JsonPatch::fromArray([
                ['op'=>'replace','path'=>'/a/secret','value'=>'sensitive-new'],
                ['op'=>'test','path'=>'/a/secret','value'=>'wrong'],
            ]));
            self::fail('Expected failure');
        } catch (PatchTestFailedException $exception) {
            self::assertSame(1, $exception->operationIndex);
            self::assertSame('test', $exception->op);
            self::assertSame('/a/secret', $exception->path);
            self::assertStringNotContainsString('sensitive', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertSame('original', $target->a->secret);
    }

    public function testInvalidOperationsAndPointersFailClosed(): void
    {
        $cases = [
            [['op'=>'add','path'=>'/a']],
            [['op'=>'remove','path'=>'/missing']],
            [['op'=>'replace','path'=>'/missing','value'=>null]],
            [['op'=>'add','path'=>'/missing/child','value'=>1]],
            [['op'=>'remove','path'=>'/a/01']],
            [['op'=>'remove','path'=>'/a/-']],
            [['op'=>'remove','path'=>'/a/+1']],
            [['op'=>'remove','path'=>'/a/999999999999999999999']],
            [['op'=>'remove','path'=>'/bad~2']],
            [['op'=>'remove','path'=>'bad']],
            [['op'=>'move','from'=>'/a','path'=>'/a/0/child']],
            [['op'=>'copy','from'=>'/missing','path'=>'/b']],
            [['op'=>'test','path'=>'/a/0','value'=>'1']],
            [['op'=>'remove','path'=>'/a','value'=>1]],
        ];
        foreach ($cases as $operations) {
            try {
                $this->apply('{"a":[1,2]}', $operations, new PatchApplyOptions(allowedOperations: ['add','remove','replace','move','copy','test']));
                self::fail('Expected invalid patch: ' . JsonValue::encode($operations));
            } catch (PatchValidationException $exception) {
                self::assertSame(0, $exception->operationIndex);
            }
        }
    }

    public function testNullValuesAndObjectArrayDistinction(): void
    {
        self::assertNull($this->apply('{"x":1}', [['op'=>'replace','path'=>'/x','value'=>null]])->x);
        self::assertTrue(JsonValue::equal(JsonValue::decode('{"b":2,"a":1}'), JsonValue::decode('{"a":1.0,"b":2}')));
        self::assertFalse(JsonValue::equal((object)[], []));
        if (PHP_INT_SIZE === 8) {
            self::assertFalse(JsonValue::equal(PHP_INT_MAX, (float) PHP_INT_MAX));
        }
        self::assertTrue(JsonValue::equal(1, 1.0));
        self::assertSame(JsonValue::hash((object)['b'=>2,'a'=>1]), JsonValue::hash((object)['a'=>1,'b'=>2]));
        self::assertNotSame(JsonValue::hash((object)[]), JsonValue::hash([]));
    }

    public function testOperationValuesAreDetached(): void
    {
        $value = (object)['x'=>1];
        $operation = new JsonPatchOperation('add', '/a', $value);
        $value->x = 2;
        $exposed = $operation->value();
        $exposed->x = 3;
        self::assertSame(1, $operation->value()->x);
    }

    public function testRootDeletionAndRecreation(): void
    {
        $policy = new PatchApplyOptions(allowRootReplacement: true);
        $result = (new JsonPatchApplier())->apply((object)[], JsonPatch::fromArray([['op'=>'remove','path'=>'']]), $policy);
        self::assertFalse($result->documentExists);
        self::assertNull($result->value);
        self::assertSame([1], $this->apply('{}', [['op'=>'remove','path'=>''],['op'=>'add','path'=>'','value'=>[1]]], $policy));
        $this->expectException(PatchValidationException::class);
        $this->apply('{}', [['op'=>'remove','path'=>'']]);
    }

    public function testPoliciesLimitsAndProtectedArrayShifts(): void
    {
        $cases = [
            [new PatchApplyOptions(maxOperations: 0), [['op'=>'test','path'=>'/items/0','value'=>1]], PatchLimitException::class],
            [new PatchApplyOptions(maxPatchBytes: 5), [], PatchLimitException::class],
            [new PatchApplyOptions(expectedHash: str_repeat('0', 64)), [], PatchConflictException::class],
            [new PatchApplyOptions(forbiddenPaths: ['/items/1']), [['op'=>'add','path'=>'/items/0','value'=>3]], PatchValidationException::class],
            [new PatchApplyOptions(forbiddenPaths: ['/items/1']), [['op'=>'replace','path'=>'/items','value'=>[]]], PatchValidationException::class],
            [new PatchApplyOptions(requireTests: 'arrays'), [['op'=>'remove','path'=>'/items/0']], PatchValidationException::class],
            [new PatchApplyOptions(maxDocumentBytes: 5), [], PatchLimitException::class],
            [new PatchApplyOptions(maxDepth: 1), [], PatchLimitException::class],
        ];
        // Use a nonempty patch in the byte-limit case.
        $cases[1][1] = [['op'=>'test','path'=>'','value'=>null]];
        foreach ($cases as [$policy, $operations, $exceptionClass]) {
            try {
                $this->apply('{"items":[1,2]}', $operations, $policy);
                self::fail('Expected policy failure');
            } catch (PatchValidationException $exception) {
                self::assertInstanceOf($exceptionClass, $exception);
            }
        }
        self::assertSame([2], $this->apply('{"items":[1,2]}', [
            ['op'=>'test','path'=>'/items/0','value'=>1], ['op'=>'remove','path'=>'/items/0'],
        ], new PatchApplyOptions(requireTests: 'arrays'))->items);
    }

    public function testIdentityGuardAndValidationCallback(): void
    {
        $result = $this->apply('{"items":[{"id":"a"},{"id":"b"}]}', [
            ['op'=>'test','path'=>'/items/0/id','value'=>'a'], ['op'=>'remove','path'=>'/items/0'],
        ], new PatchApplyOptions(requireTests: 'arrays'));
        self::assertSame('b', $result->items[0]->id);
        $this->expectException(PatchValidationException::class);
        (new JsonPatchApplier())->apply((object)['a'=>1], new JsonPatch([]), validator: static fn () => false);
    }
}
