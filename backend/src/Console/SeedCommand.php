<?php

declare(strict_types=1);

namespace App\Console;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SeedCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct('seed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $seedUserEmail = $this->requiredEnv('SEED_USER_EMAIL');
        $seedUserPassword = $this->requiredEnv('SEED_USER_PASSWORD');
        $workerEmail = $this->requiredEnv('WORKER_API_EMAIL');
        $workerPassword = $this->requiredEnv('WORKER_API_PASSWORD');

        $this->connection->transactional(function () use (
            $seedUserEmail,
            $seedUserPassword,
            $workerEmail,
            $workerPassword,
            $output,
        ): void {
            $userId = $this->upsertUser($seedUserEmail, $seedUserPassword);
            $this->upsertUser($workerEmail, $workerPassword);

            $inserted = 0;
            foreach ($this->certificateRows($userId) as $row) {
                $title = $row['title'];
                if (!is_string($title)) {
                    throw new \RuntimeException('Seed certificate title must be a string.');
                }

                if ($this->certificateExists($title, $userId)) {
                    continue;
                }

                $this->connection->insert('certificates', $row);
                $inserted++;
            }

            $output->writeln(sprintf('Seed completed: users ensured, %d certificates inserted.', $inserted));
        });

        return Command::SUCCESS;
    }

    private function requiredEnv(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException(sprintf('%s is required', $name));
        }

        return $value;
    }

    private function upsertUser(string $email, string $password): int
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        /** @var int|string $id */
        $id = $this->connection->fetchOne(
            <<<'SQL'
            INSERT INTO users (email, password_hash)
            VALUES (:email, :password_hash)
            ON CONFLICT (email) DO UPDATE
                SET password_hash = EXCLUDED.password_hash
            RETURNING id
            SQL,
            ['email' => $email, 'password_hash' => $hash],
        );

        return (int) $id;
    }

    private function certificateExists(string $title, int $createdBy): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM certificates WHERE title = :title AND created_by = :created_by',
            ['title' => $title, 'created_by' => $createdBy],
        ) > 0;
    }

    /**
     * @return iterable<array<string, int|string|null>>
     */
    private function certificateRows(int $createdBy): iterable
    {
        $now = new DateTimeImmutable('2026-08-05 00:00:00', new DateTimeZone('UTC'));
        $futureNames = [
            'Кофейная карта на месяц',
            'Семейный ужин в ресторане',
            'Книжный абонемент',
            'Сертификат в спа-салон',
            'Мастер-класс по керамике',
            'Подарок для коллеги',
            'Абонемент в бассейн',
            'День в фитнес-клубе',
            'Фотосессия в студии',
            'Курс английского языка',
            'Корзина фермерских продуктов',
            'Билет в театр',
            'Подписка на цветы',
            'Сертификат в веломастерскую',
            'Музыкальный урок',
            'Подарочная карта электроники',
            'Детский творческий набор',
            'Гастрономический тур',
            'Сертификат на доставку еды',
            'Прокат снаряжения',
        ];

        foreach ($futureNames as $index => $title) {
            yield $this->row(
                title: $title,
                priceMinor: 150000 + ($index * 27500),
                expiresAt: $this->naturalTime($now->modify(sprintf('+%d days', 7 + ($index * 18))), $index),
                status: 'active',
                createdBy: $createdBy,
                deletedAt: null,
                offsetDays: $index,
            );
        }

        $expiredActiveNames = [
            'Просроченный сертификат на кофе',
            'Просроченный ужин на двоих',
            'Просроченная подписка на книги',
            'Просроченный поход в кино',
            'Просроченный урок рисования',
        ];
        foreach ($expiredActiveNames as $index => $title) {
            yield $this->row(
                title: $title,
                priceMinor: 90000 + ($index * 31000),
                expiresAt: $this->naturalTime($now->modify(sprintf('-%d days', 1 + ($index * 3))), 20 + $index),
                status: 'active',
                createdBy: $createdBy,
                deletedAt: null,
                offsetDays: 20 + $index,
            );
        }

        foreach (['Погашенный сертификат на массаж', 'Погашенная карта в магазин', 'Погашенный семейный завтрак'] as $index => $title) {
            yield $this->row(
                title: $title,
                priceMinor: 210000 + ($index * 45000),
                expiresAt: $this->naturalTime($now->modify(sprintf('+%d days', 30 + ($index * 20))), 25 + $index),
                status: 'redeemed',
                createdBy: $createdBy,
                deletedAt: null,
                offsetDays: 25 + $index,
            );
        }

        $trashedRows = [
            ['title' => 'Удалённый сертификат на экскурсию', 'status' => 'active'],
            ['title' => 'Удалённая карта на покупки', 'status' => 'redeemed'],
        ];
        foreach ($trashedRows as $index => $trashedRow) {
            yield $this->row(
                title: $trashedRow['title'],
                priceMinor: 180000 + ($index * 60000),
                expiresAt: $this->naturalTime($now->modify(sprintf('+%d days', 90 + ($index * 30))), 28 + $index),
                status: $trashedRow['status'],
                createdBy: $createdBy,
                deletedAt: $this->formatUtc($now->modify(sprintf('+%d hours', 1 + $index))),
                offsetDays: 28 + $index,
            );
        }
    }

    /**
     * @return array<string, int|string|null>
     */
    private function row(
        string $title,
        int $priceMinor,
        DateTimeImmutable $expiresAt,
        string $status,
        int $createdBy,
        ?string $deletedAt,
        int $offsetDays,
    ): array {
        $createdAt = (new DateTimeImmutable('2026-07-01 00:00:00', new DateTimeZone('UTC')))
            ->modify(sprintf('+%d days', $offsetDays));

        return [
            'title' => $title,
            'price_minor' => $priceMinor,
            'currency' => 'RUB',
            'expires_at' => $this->formatUtc($expiresAt),
            'status' => $status,
            'version' => 1,
            'created_by' => $createdBy,
            'created_at' => $this->formatUtc($createdAt),
            'updated_at' => $this->formatUtc($createdAt),
            'deleted_at' => $deletedAt,
        ];
    }

    private function naturalTime(DateTimeImmutable $dateTime, int $index): DateTimeImmutable
    {
        $hours = [9, 11, 14, 16, 18, 10, 13, 15, 17, 19];
        $minutes = [0, 15, 30, 45, 10, 25, 40, 5, 20, 35];

        return $dateTime->setTime(
            $hours[$index % count($hours)],
            $minutes[$index % count($minutes)],
        );
    }

    private function formatUtc(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }
}
