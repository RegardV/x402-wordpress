<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use X402\Search;

final class SearchTest extends TestCase
{
    public function testNaturalQueryBecomesOrTokensWithPrefixWildcards(): void
    {
        $this->assertSame('why* pods* restart* crashloop*', Search::boolean_query('why pods restart crashloop?'));
    }

    public function testBooleanOperatorsAndQuotesAreStrippedNotInterpreted(): void
    {
        $this->assertSame('kube* evil* term*', Search::boolean_query('+kube -evil "term" (*)'));
    }

    public function testSingleCharTokensDropAndUnicodeSurvives(): void
    {
        $this->assertSame('scheduler* étcd*', Search::boolean_query('a scheduler étcd'));
    }

    public function testGarbageYieldsEmptyString(): void
    {
        $this->assertSame('', Search::boolean_query('?!@ () --'));
        $this->assertSame('', Search::boolean_query(''));
    }
}