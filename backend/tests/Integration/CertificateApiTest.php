<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

final class CertificateApiTest extends IntegrationTestCase
{
    public function testCrudHappyPath(): void
    {
        $created = $this->createCertificate();
        $headers = $this->authHeaders();

        $show = $this->request('GET', '/api/v1/certificates/' . $created['id'], null, $headers);
        self::assertSame(200, $show->getStatusCode());
        self::assertSame('Birthday certificate', $this->json($show)['title']);

        $updated = $this->request('PATCH', '/api/v1/certificates/' . $created['id'], [
            'title' => 'Updated certificate',
            'price_minor' => 175000,
            'version' => $created['version'],
        ], $headers);
        self::assertSame(200, $updated->getStatusCode());
        $updatedPayload = $this->json($updated);
        self::assertSame('Updated certificate', $updatedPayload['title']);
        self::assertSame(2, $updatedPayload['version']);

        $deleted = $this->request('DELETE', '/api/v1/certificates/' . $created['id'], null, $headers);
        self::assertSame(204, $deleted->getStatusCode());

        $missingAfterDelete = $this->request('GET', '/api/v1/certificates/' . $created['id'], null, $headers);
        self::assertSame(404, $missingAfterDelete->getStatusCode());
    }

    #[DataProvider('invalidCreatePayloads')]
    public function testCreateReturns422ForEachValidationRule(array $payload, string $field): void
    {
        $response = $this->request('POST', '/api/v1/certificates', $payload, $this->authHeaders());
        $problem = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey($field, $problem['errors']);
    }

    public function testUpdateReturns409ForStaleVersion(): void
    {
        $created = $this->createCertificate();
        $headers = $this->authHeaders();

        $first = $this->request('PATCH', '/api/v1/certificates/' . $created['id'], [
            'title' => 'First update',
            'version' => $created['version'],
        ], $headers);
        self::assertSame(200, $first->getStatusCode());

        $stale = $this->request('PATCH', '/api/v1/certificates/' . $created['id'], [
            'title' => 'Stale update',
            'version' => $created['version'],
        ], $headers);
        $problem = $this->json($stale);

        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('First update', $problem['current_state']['title']);
    }

    public function testShowReturns404ForUnknownId(): void
    {
        $response = $this->request('GET', '/api/v1/certificates/999999', null, $this->authHeaders());

        self::assertSame(404, $response->getStatusCode());
    }

    public function testPaginationOutOfRangeReturnsEmptyDataAndMeta(): void
    {
        $this->createCertificate('One');
        $this->createCertificate('Two');

        $response = $this->request('GET', '/api/v1/certificates?page=3&per_page=2', null, $this->authHeaders());
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $payload['data']);
        self::assertSame(['page' => 3, 'per_page' => 2, 'total' => 2, 'total_pages' => 1], $payload['meta']);
    }

    public function testSearchByTitleSubstringIsCaseInsensitive(): void
    {
        $this->createCertificate('Summer SPA Day');
        $this->createCertificate('Book Shop');

        $response = $this->request('GET', '/api/v1/certificates?search=spa', null, $this->authHeaders());
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $payload['data']);
        self::assertSame('Summer SPA Day', $payload['data'][0]['title']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidCreatePayloads(): iterable
    {
        $valid = [
            'title' => 'Valid',
            'price_minor' => 1000,
            'currency' => 'RUB',
            'expires_at' => '2027-01-01T00:00:00Z',
        ];

        yield 'blank title' => [array_replace($valid, ['title' => '']), 'title'];
        yield 'non-positive price' => [array_replace($valid, ['price_minor' => 0]), 'price_minor'];
        yield 'bad currency' => [array_replace($valid, ['currency' => 'rub']), 'currency'];
        yield 'past expiration' => [array_replace($valid, ['expires_at' => '2020-01-01T00:00:00Z']), 'expires_at'];
        yield 'status rejected' => [array_replace($valid, ['status' => 'active']), 'status'];
    }
}
