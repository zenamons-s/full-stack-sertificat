<?php

declare(strict_types=1);

namespace Tests\Integration;

final class AuthTest extends IntegrationTestCase
{
    public function testLoginReturns200ForValidCredentials(): void
    {
        $response = $this->request('POST', '/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'Password123!',
        ]);

        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Bearer', $payload['token_type']);
        self::assertIsString($payload['access_token']);
    }

    public function testLoginReturns401ForInvalidCredentials(): void
    {
        $response = $this->request('POST', '/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }
}
