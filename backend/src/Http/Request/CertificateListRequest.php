<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Certificate\Query\ListCertificatesQuery;
use App\Domain\Certificate\CertificateStatus;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

final class CertificateListRequest
{
    private const TRASHED = ['none', 'with', 'only'];
    private const SORTS = ['created_at', '-created_at', 'expires_at', '-expires_at', 'price_minor', '-price_minor', 'title', '-title'];

    public function toQuery(ServerRequestInterface $request, int $userId): ListCertificatesQuery
    {
        $params = $request->getQueryParams();
        $errors = [];

        $search = isset($params['search']) && is_scalar($params['search']) ? trim((string) $params['search']) : null;
        if ($search === '') {
            $search = null;
        }

        $status = isset($params['status']) && is_scalar($params['status']) ? (string) $params['status'] : null;
        if ($status !== null && CertificateStatus::tryFrom($status) === null) {
            $errors['status'][] = 'Допустимые значения: ' . implode(', ', CertificateStatus::values());
        }

        $trashed = isset($params['trashed']) && is_scalar($params['trashed']) ? (string) $params['trashed'] : 'none';
        if (!in_array($trashed, self::TRASHED, true)) {
            $errors['trashed'][] = 'Допустимые значения: none, with, only';
        }

        $page = $this->intParam($params, 'page', 1, $errors);
        if ($page < 1) {
            $errors['page'][] = 'Должно быть не меньше 1';
        }

        $perPage = $this->intParam($params, 'per_page', 20, $errors);
        if ($perPage < 1) {
            $errors['per_page'][] = 'Должно быть не меньше 1';
        } elseif ($perPage > 100) {
            $errors['per_page'][] = 'Должно быть не больше 100';
        }

        $sort = isset($params['sort']) && is_scalar($params['sort']) ? (string) $params['sort'] : '-created_at';
        if (!in_array($sort, self::SORTS, true)) {
            $errors['sort'][] = 'Допустимые значения: ' . implode(', ', self::SORTS);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new ListCertificatesQuery($search, $status, $trashed, $page, $perPage, $sort, $userId);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, list<string>> $errors
     */
    private function intParam(array $params, string $name, int $default, array &$errors): int
    {
        if (!array_key_exists($name, $params)) {
            return $default;
        }

        $value = filter_var($params[$name], FILTER_VALIDATE_INT);
        if (!is_int($value)) {
            $errors[$name][] = 'Должно быть целым числом';
            return $default;
        }

        return $value;
    }
}
