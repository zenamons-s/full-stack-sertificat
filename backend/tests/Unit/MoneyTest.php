<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Certificate\Money;
use App\Domain\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class MoneyTest extends TestCase
{
    public function testAddsAndSubtractsInMinorUnits(): void
    {
        self::assertSame(1299, (new Money(999, 'RUB'))->add(new Money(300, 'RUB'))->minor);
        self::assertSame(699, (new Money(999, 'RUB'))->subtract(new Money(300, 'RUB'))->minor);
    }

    public function testRejectsSubtractionThatWouldProduceInvalidMoney(): void
    {
        $this->expectException(ValidationException::class);
        (new Money(100, 'RUB'))->subtract(new Money(200, 'RUB'));
    }

    public function testMoneyAmountIsDeclaredAsIntNotFloat(): void
    {
        $property = new ReflectionProperty(Money::class, 'minor');

        self::assertSame('int', (string) $property->getType());
    }
}
