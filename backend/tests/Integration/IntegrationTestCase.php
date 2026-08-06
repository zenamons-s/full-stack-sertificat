<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\ErrorHandler;
use App\Http\Middleware\RequestIdMiddleware;
use App\Infrastructure\Config\DatabaseUrlParser;
use App\Infrastructure\Config\Settings;
use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

abstract class IntegrationTestCase extends TestCase
{
    protected App $app;
    protected ContainerInterface $container;
    protected Connection $connection;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        self::ensureDatabase();

        $this->app = self::createApp();
        $container = $this->app->getContainer();
        if (!$container instanceof ContainerInterface) {
            self::fail('Application container is not configured.');
        }
        $this->container = $container;
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            self::fail('Doctrine connection is not configured.');
        }
        $this->connection = $connection;
        $this->connection->executeStatement('TRUNCATE certificate_audit, certificates, users RESTART IDENTITY CASCADE');
        $this->invalidateCache();
        $this->connection->beginTransaction();
        $this->seedUser();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection->close();
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $uri, ?array $payload = null, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($payload !== null) {
            $body = (new StreamFactory())->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($body);
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    protected function accessToken(): string
    {
        $response = $this->request('POST', '/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'Password123!',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $payload = $this->json($response);
        self::assertIsString($payload['access_token'] ?? null);

        return $payload['access_token'];
    }

    /**
     * @return array{Authorization: string}
     */
    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->accessToken()];
    }

    protected function createCertificate(string $title = 'Birthday certificate', int $priceMinor = 150000): array
    {
        $response = $this->request('POST', '/api/v1/certificates', [
            'title' => $title,
            'price_minor' => $priceMinor,
            'currency' => 'RUB',
            'expires_at' => '2027-01-01T00:00:00Z',
        ], $this->authHeaders());

        self::assertSame(201, $response->getStatusCode());

        return $this->json($response);
    }

    private static function createApp(): App
    {
        $settingsFactory = require dirname(__DIR__, 2) . '/config/settings.php';
        $settings = $settingsFactory();
        self::assertInstanceOf(Settings::class, $settings);

        $containerFactory = require dirname(__DIR__, 2) . '/config/container.php';
        $container = $containerFactory($settings);
        self::assertInstanceOf(Container::class, $container);

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';
        $routes($app);
        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware(!$settings->isProduction(), true, true);
        $errorMiddleware->setDefaultErrorHandler($container->get(ErrorHandler::class));
        $app->add(RequestIdMiddleware::class);

        return $app;
    }

    private static function ensureDatabase(): void
    {
        if (self::$migrated) {
            return;
        }

        $databaseUrl = getenv('DATABASE_URL');
        if (!is_string($databaseUrl) || $databaseUrl === '') {
            self::fail('DATABASE_URL is required for integration tests.');
        }

        $parser = new DatabaseUrlParser();
        $database = $parser->parse($databaseUrl);
        $admin = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $database->host,
            'port' => $database->port,
            'user' => $database->user,
            'password' => $database->password,
            'dbname' => 'postgres',
        ]);
        $exists = (bool) $admin->fetchOne('SELECT 1 FROM pg_database WHERE datname = :database', ['database' => $database->database]);
        if (!$exists) {
            $admin->executeStatement('CREATE DATABASE ' . $admin->quoteIdentifier($database->database));
        }
        $admin->close();

        $connection = DriverManager::getConnection($database->toDbalParams());
        $configuration = new ConfigurationArray([
            'table_storage' => [
                'table_name' => 'doctrine_migration_versions',
                'version_column_name' => 'version',
                'version_column_length' => 191,
                'executed_at_column_name' => 'executed_at',
                'execution_time_column_name' => 'execution_time',
            ],
            'migrations_paths' => [
                'App\\Migrations' => dirname(__DIR__, 2) . '/migrations',
            ],
            'all_or_nothing' => true,
            'check_database_platform' => true,
        ]);
        $dependencyFactory = DependencyFactory::fromConnection($configuration, new ExistingConnection($connection));
        $dependencyFactory->getMetadataStorage()->ensureInitialized();
        $latest = $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($latest);
        if (count($plan) > 0) {
            $dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
        }
        $connection->close();

        self::$migrated = true;
    }

    private function seedUser(): void
    {
        $this->connection->insert('users', [
            'email' => 'admin@example.com',
            'password_hash' => password_hash('Password123!', PASSWORD_ARGON2ID),
        ]);
    }

    private function invalidateCache(): void
    {
        try {
            $redis = $this->container->get(\Predis\Client::class);
            if ($redis instanceof \Predis\Client) {
                $redis->incr('certificates:gen');
            }
        } catch (\Throwable) {
        }
    }
}
