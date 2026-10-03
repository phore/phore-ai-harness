<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use InvalidArgumentException;
use Phore\AiHarness\Edit\TextEditEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextEditEngineTest extends TestCase
{
    public function testAllReplacementsUseTheOriginalSnapshot(): void
    {
        self::assertSame('B C', TextEditEngine::apply('A B', ['A', 'B'], ['B', 'C']));
        self::assertSame('XY', TextEditEngine::apply('ab', ['b', 'a'], ['Y', 'X']));
    }

    public function testEmptyReplacementDeletesAndEmptyBatchDoesNothing(): void
    {
        self::assertSame('ac', TextEditEngine::applyEdits('abc', [['search' => 'b', 'replacement' => '']]));
        self::assertSame('abc', TextEditEngine::applyEdits('abc', []));
    }

    public function testFullRewriteIsExplicitAndMayReturnEmptyText(): void
    {
        self::assertSame('new', TextEditEngine::applyEdits('old', [['search' => null, 'replacement' => 'new']]));
        self::assertSame('', TextEditEngine::apply('old', [null], ['']));
        self::assertSame('new', TextEditEngine::apply('', [null], ['new']));
    }

    public function testUnicodeBomAndLineEndingsOutsideEditsStayUnchanged(): void
    {
        $source = "\xEF\xBB\xBFGr\u{00FC}\u{00DF}e\r\nKaffee\r\n";
        self::assertSame("\xEF\xBB\xBFGr\u{00FC}\u{00DF}e\r\nTee\r\n", TextEditEngine::apply($source, ['Kaffee'], ['Tee']));
    }

    public static function invalidBatches(): iterable
    {
        yield 'mixed rewrite' => [[null, 'a'], ['new', 'b']];
        yield 'two rewrites' => [[null, null], ['new', 'again']];
        yield 'empty search' => [[''], ['x']];
        yield 'absent search' => [['z'], ['x']];
        yield 'overlapping ranges' => [['ab', 'bc'], ['x', 'y']];
        yield 'nested ranges' => [['abc', 'b'], ['x', 'y']];
        yield 'duplicate ranges' => [['a', 'a'], ['x', 'y']];
        yield 'wrong replacement type' => [['a'], [42]];
        yield 'wrong rewrite type' => [[null], [null]];
        yield 'wrong search type' => [[1], ['x']];
        yield 'non-list searches' => [['s' => 'a'], ['x']];
        yield 'non-list replacements' => [['a'], ['r' => 'x']];
        yield 'unequal lengths' => [['a'], []];
    }

    #[DataProvider('invalidBatches')]
    public function testInvalidBatchesFailWithoutReturningAPartialResult(array $searches, array $replacements): void
    {
        $this->expectException(InvalidArgumentException::class);
        TextEditEngine::apply('abc', $searches, $replacements);
    }

    public function testOverlappingOccurrencesCountAsAmbiguous(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('2 matches');
        TextEditEngine::apply('aaa', ['aa'], ['b']);
    }

    public function testMalformedPairIsRejectedRatherThanBecomingARewrite(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TextEditEngine::applyEdits('abc', [['replacement' => 'new']]);
    }
}
