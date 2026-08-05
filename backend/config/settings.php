<?php

declare(strict_types=1);

use App\Infrastructure\Config\DatabaseUrlParser;
use App\Infrastructure\Config\Settings;

return static function (): Settings {
    $env = [];
    foreach ($_ENV as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $env[$key] = $value;
        }
    }
    /** @var array<string, string> $processEnv */
    $processEnv = getenv();
    foreach ($processEnv as $key => $value) {
        $env[$key] = $value;
    }

    return Settings::fromEnv($env, new DatabaseUrlParser());
};
