<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use Phore\AiHarness\Interactive\ContinueKey;
use Phore\AiHarness\Interactive\ContinueKeyGenerator;
use Phore\AiHarness\Keystore\Keystore;
use PHPUnit\Framework\TestCase;

final class ContinueKeyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('Interactive mode requires sodium.');
        }
        Keystore::resetInstance();
    }

    protected function tearDown(): void
    {
        Keystore::resetInstance();
    }

    public function testExplicitSecretAndProviderSeparation(): void
    {
        $store = Keystore::instance()->setSigningKey('example-only-stable-secret');
        self::assertSame($store->getSigningKey('open_ai', 'first'), $store->getSigningKey('open_ai', 'second'));
        self::assertNotSame($store->getSigningKey('open_ai'), $store->getSigningKey('other'));
        self::assertSame(32, strlen($store->getSigningKey('open_ai')));
    }

    public function testEncryptedStateRoundTripAndFreshNonce(): void
    {
        $codec = new ContinueKeyGenerator(random_bytes(32), 'fixture');
        $key = new ContinueKey('resp_1', 'contract', ['text' => 'private draft']);
        $token = $codec->encode($key);
        self::assertNotSame($token, $codec->encode($key));
        self::assertStringNotContainsString('private draft', $token);
        self::assertEquals($key, $codec->decode($token));
    }

    public function testCompletedKeyRetainsOnlyConversationCursor(): void
    {
        $codec = new ContinueKeyGenerator(random_bytes(32), 'fixture');
        $key = new ContinueKey('resp_final', '', completed: true);
        $decoded = $codec->decode($codec->encode($key));
        self::assertTrue($decoded->completed);
        self::assertSame('resp_final', $decoded->responseId);
        self::assertSame([], $decoded->state);
        self::assertSame('', $decoded->contract);
    }

    public function testRejectsTampering(): void
    {
        $codec = new ContinueKeyGenerator(random_bytes(32), 'fixture');
        $token = $codec->encode(new ContinueKey('resp_1', 'contract'));
        $token[10] = $token[10] === 'A' ? 'B' : 'A';
        $this->expectException(\InvalidArgumentException::class);
        $codec->decode($token);
    }

    public function testRejectsWrongScope(): void
    {
        $secret = random_bytes(32);
        $token = (new ContinueKeyGenerator($secret, 'first'))->encode(new ContinueKey('resp_1', 'contract'));
        $this->expectException(\InvalidArgumentException::class);
        (new ContinueKeyGenerator($secret, 'second'))->decode($token);
    }
}
