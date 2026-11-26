<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use InvalidArgumentException;
use LogicException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\Context\Helper\ConversationSigner;
use Phore\AiHarness\ResumeOptions;
use Phore\AiHarness\ResumeStateException;
use PHPUnit\Framework\TestCase;

final class ConversationSigningTest extends TestCase
{
    private const KEY = 'test/token+with=punctuation!';

    public function testSigningAuthenticatesPurposePayloadAndBinding(): void
    {
        $signer = new ConversationSigner(self::KEY, ['user' => 7, 'site' => 'demo']);
        $token = $signer->sign('resp_example', 'response');
        self::assertSame('resp_example', $signer->verify($token, 'response'));

        foreach ([
            [new ConversationSigner(self::KEY, ['user' => 8, 'site' => 'demo']), $token, 'response'],
            [new ConversationSigner(self::KEY, ['user' => 7, 'site' => 'other']), $token, 'response'],
            [new ConversationSigner('different-secret', ['user' => 7, 'site' => 'demo']), $token, 'response'],
            [$signer, $token, 'state'],
            [$signer, substr($token, 0, -1) . (str_ends_with($token, '0') ? '1' : '0'), 'response'],
            [$signer, 'resp_example', 'response'],
        ] as [$candidate, $input, $purpose]) {
            try {
                $candidate->verify($input, $purpose);
                self::fail('Expected invalid token to be rejected.');
            } catch (ResumeStateException) {
                self::assertTrue(true);
            }
        }
    }

    public function testExpiredTokenIsRejected(): void
    {
        $signer = new ConversationSigner(self::KEY, [], 1);
        // Reproduce an authenticated old token without a sleep or clock dependency.
        $master = hash('sha256', self::KEY, true);
        $derived = hash_hmac('sha256', "phore-ai-harness:conversation:v1\0[]", $master, true);
        $body = 'aih1.response.' . (time() - 10) . '.cmVzcF9vbGQ';
        $token = $body . '.' . hash_hmac('sha256', $body, $derived);
        $this->expectException(ResumeStateException::class);
        $this->expectExceptionMessage('expired');
        $signer->verify($token, 'response');
    }

    public function testArbitraryNonEmptySecretsAreHashed(): void
    {
        foreach (['x', "token/+=\0", str_repeat('long-secret', 100)] as $secret) {
            $signer = new ConversationSigner($secret, []);
            $token = $signer->sign('resp_example', 'response');
            self::assertSame('resp_example', (new ConversationSigner($secret, []))->verify($token, 'response'));
        }
        $this->expectException(InvalidArgumentException::class);
        new ConversationSigner('', []);
    }

    public function testInvalidBindingFailsDuringConfiguration(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;
        $resource = fopen('php://memory', 'r+');
        try {
            foreach ([[INF], [NAN], ["\xff"], [new \stdClass()], [static fn () => null], [$resource], $recursive] as $binding) {
                $context = new AiContext();
                try {
                    $context->setSigningKey(self::KEY, $binding);
                    self::fail('Expected unserializable signing data to be rejected.');
                } catch (InvalidArgumentException) {
                    self::assertSame($context, $context->setSigningKey(self::KEY, ['valid' => true]));
                }
            }
        } finally {
            fclose($resource);
        }
    }

    public function testSignedContextRejectsUnsignedCursorAndStateWithoutChangingCursor(): void
    {
        $signer = new ConversationSigner(self::KEY, []);
        $token = $signer->sign('resp_example', 'response');
        $context = (new AiContext())->setSigningKey(self::KEY)->resumeFromResponseId($token);
        $context->setCheckpoint('valid');
        $state = (new AiContext())->exportState();

        foreach ([
            fn () => $context->resumeFromResponseId('resp_forged'),
            fn () => $context->importState($state, new ResumeOptions(onMismatch: ResumeOptions::ON_MISMATCH_RESTART)),
            fn () => $context->importState($token),
            fn () => (new AiContext())->resumeFromResponseId($token),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected authentication failure.');
            } catch (ResumeStateException) {
                self::assertSame('resp_example', $signer->verify($context->getResponseId(), 'response'));
            }
        }
        $context->rollback('valid');
        $context->resumeFromResponseId(null);
        self::assertNull($context->getResponseId());
        $this->expectException(LogicException::class);
        $context->setSigningKey('another-secret');
    }
}
