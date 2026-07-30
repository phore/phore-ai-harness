<?php

declare(strict_types=1);

use Phore\AiHarness\Keystore\Keystore;
use PHPUnit\Framework\TestCase;

final class KeystoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Keystore::resetInstance();
    }

    public function testCanAddAndReadDefaultOpenAiKey(): void
    {
        Keystore::resetInstance();

        $keystore = Keystore::instance()->addKey('manual-key');

        self::assertTrue($keystore->hasKey());
        self::assertSame('manual-key', $keystore->getKey());
    }

    public function testLoadsOpenAiKeyFromEnvironmentByDefault(): void
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=env-key');
        Keystore::resetInstance();

        try {
            self::assertSame('env-key', Keystore::instance()->getKey('open_ai'));
        } finally {
            if (is_string($previous)) {
                putenv('OPENAI_API_KEY=' . $previous);
            } else {
                putenv('OPENAI_API_KEY');
            }
            Keystore::resetInstance();
        }
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API key must not be empty');

        Keystore::instance()->addKey('');
    }

    public function testRejectsEmptyProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider must not be empty');

        Keystore::instance()->addKey('key', '');
    }
}
