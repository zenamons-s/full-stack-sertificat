<?php

declare(strict_types=1);

use App\Application\Auth\AuthService;
use App\Application\Port\RefreshTokenDenylist;
use App\Application\Port\TokenIssuer;
use App\Domain\User\UserRepository;
use App\Http\RequestContext;
use App\Infrastructure\Cache\RedisFactory;
use App\Infrastructure\Cache\RedisRefreshTokenDenylist;
use App\Infrastructure\Config\Settings;
use App\Infrastructure\Persistence\DbalUserRepository;
use App\Infrastructure\Security\FirebaseJwtTokenIssuer;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;
use Predis\Client;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

use function DI\autowire;
use function DI\get;

return static function (Settings $settings): ContainerInterface {
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);
    $builder->addDefinitions([
        Settings::class => $settings,
        ResponseFactoryInterface::class => autowire(ResponseFactory::class),
        Connection::class => static fn (Settings $settings): Connection => DriverManager::getConnection($settings->database->toDbalParams()),
        Client::class => static fn (RedisFactory $factory): Client => $factory->create(),
        UserRepository::class => autowire(DbalUserRepository::class),
        TokenIssuer::class => autowire(FirebaseJwtTokenIssuer::class),
        RefreshTokenDenylist::class => autowire(RedisRefreshTokenDenylist::class),
        AuthService::class => static fn (
            UserRepository $users,
            TokenIssuer $tokens,
            RefreshTokenDenylist $denylist,
            Settings $settings,
        ): AuthService => new AuthService($users, $tokens, $denylist, $settings->jwtAccessTtl),
        LoggerInterface::class => static function (Settings $settings, RequestContext $context): LoggerInterface {
            $logger = new Logger('backend');
            $level = match (strtolower($settings->logLevel)) {
                'debug' => Level::Debug,
                'info' => Level::Info,
                'notice' => Level::Notice,
                'warning' => Level::Warning,
                'error' => Level::Error,
                'critical' => Level::Critical,
                'alert' => Level::Alert,
                'emergency' => Level::Emergency,
                default => Level::Info,
            };
            $handler = new StreamHandler('php://stdout', $level);
            $handler->setFormatter(new JsonFormatter());
            $logger->pushHandler($handler);
            $logger->pushProcessor(static function (LogRecord $record) use ($context): LogRecord {
                return $record->with(extra: $record->extra + ['request_id' => $context->requestId()]);
            });

            return $logger;
        },
    ]);

    return $builder->build();
};
