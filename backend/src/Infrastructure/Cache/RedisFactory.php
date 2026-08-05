<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Infrastructure\Config\Settings;
use Predis\Client;

final readonly class RedisFactory
{
    public function __construct(private Settings $settings)
    {
    }

    public function create(): Client
    {
        return new Client($this->settings->redisUrl);
    }
}
