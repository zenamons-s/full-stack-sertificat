<?php

declare(strict_types=1);

namespace App\Domain\Certificate;

use App\Domain\Exception\ValidationException;

final readonly class Money
{
    public function __construct(public int $minor, public string $currency)
    {
        $errors = [];
        if ($minor <= 0) {
            $errors['price_minor'][] = 'Должно быть больше нуля';
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'][] = 'Должно быть ISO-кодом из трёх заглавных букв';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    public function formatted(): string
    {
        $major = intdiv($this->minor, 100);
        $minor = $this->minor % 100;
        $amount = number_format($major, 0, ',', ' ') . ',' . str_pad((string) $minor, 2, '0', STR_PAD_LEFT);

        return $this->currency === 'RUB' ? $amount . ' ₽' : $amount . ' ' . $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new ValidationException(['currency' => ['Валюты должны совпадать']]);
        }
    }
}
