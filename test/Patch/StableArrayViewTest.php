<?php

declare(strict_types=1);

use Phore\AiHarness\Patch\{JsonPatch, JsonPatchApplier, JsonValue, PatchValidationException, StableArrayView};
use PHPUnit\Framework\TestCase;

final class StableArrayViewTest extends TestCase
{
    public function testRoundtripWithNestedEmptyScalarAndDuplicateIdentityLists(): void
    {
        $value = JsonValue::decode('{"items":[{"id":"a","nested":[null,{},[]]},{"id":"a"},{"name":"no id"}],"empty":[],"object":{}}');
        $codec = new StableArrayView();
        $view = $codec->encode($value);
        self::assertTrue(JsonValue::equal($value, $codec->decode($view)));
        self::assertCount(3, array_unique($view->items->{'$order'}));
        self::assertSame(JsonValue::encode($view), JsonValue::encode($codec->encode($value)));
    }

    public function testCombinedStableEditsDoNotShiftOtherReferences(): void
    {
        $codec = new StableArrayView();
        $view = $codec->encode(JsonValue::decode('{"items":[{"id":"a","name":"A"},{"id":"b","name":"B"},{"id":"c","name":"C"}]}'));
        [$a, $b, $c] = $view->items->{'$order'};
        $patch = JsonPatch::fromArray([
            ['op'=>'remove','path'=>'/items/$values/' . $a],
            ['op'=>'remove','path'=>'/items/$values/' . $c],
            ['op'=>'replace','path'=>'/items/$values/' . $b . '/name','value'=>'changed'],
            ['op'=>'add','path'=>'/items/$values/new','value'=>(object)['id'=>'d','name'=>'D']],
            ['op'=>'replace','path'=>'/items/$order','value'=>['new',$b]],
        ]);
        $result = $codec->decode((new JsonPatchApplier())->apply($view, $patch)->value);
        self::assertSame(['d','b'], array_map(static fn ($item) => $item->id, $result->items));
        self::assertSame('changed', $result->items[1]->name);
        self::assertCount(3, $codec->decode($view)->items);
    }

    public function testExplicitIdentityPointer(): void
    {
        $codec = new StableArrayView(['/items'=>'/meta/key']);
        $a = $codec->encode(JsonValue::decode('{"items":[{"meta":{"key":"a"}}]}'));
        $b = $codec->encode(JsonValue::decode('{"items":[{"meta":{"key":"b"}},{"meta":{"key":"a"}}]}'));
        self::assertSame($a->items->{'$order'}[0], $b->items->{'$order'}[1]);
        $this->expectException(PatchValidationException::class);
        $codec->encode(JsonValue::decode('{"items":[{"meta":{"key":"a"}},{"meta":{"key":"a"}}]}'));
    }

    public function testInvalidTransportFailsClosed(): void
    {
        foreach ([
            '{"$order":["a","a"],"$values":{"a":1}}',
            '{"$order":["a"],"$values":{}}',
            '{"$order":[],"$values":{"a":1}}',
            '{"$order":[],"$values":[]}',
            '{"$order":[],"$values":{},"extra":1}',
            '{"items":[1]}',
        ] as $json) {
            try {
                (new StableArrayView())->decode(JsonValue::decode($json));
                self::fail('Expected invalid view');
            } catch (PatchValidationException $exception) {
                self::assertNotSame('', $exception->errorCode);
            }
        }
        $this->expectException(PatchValidationException::class);
        (new StableArrayView())->encode((object)['$order'=>[]]);
    }
}
