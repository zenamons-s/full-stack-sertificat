<?php

declare(strict_types=1);

namespace App\Http\Action\Certificate;

use App\Application\Certificate\CertificateService;
use App\Domain\Exception\ValidationException;
use App\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuditAction
{
    use CertificateActionSupport;

    public function __construct(private CertificateService $service, private JsonResponder $responder)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        unset($response);
        $this->user($request);
        $params = $request->getQueryParams();
        $errors = [];
        $page = $this->positiveInt($params, 'page', 1, $errors);
        $perPage = $this->positiveInt($params, 'per_page', 20, $errors);
        if ($perPage > 100) {
            $errors['per_page'][] = 'Должно быть не больше 100';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->responder->json($this->service->audit($this->id($args), $page, $perPage));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, list<string>> $errors
     */
    private function positiveInt(array $params, string $name, int $default, array &$errors): int
    {
        if (!array_key_exists($name, $params)) {
            return $default;
        }
        $value = filter_var($params[$name], FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < 1) {
            $errors[$name][] = 'Должно быть целым числом больше нуля';
            return $default;
        }

        return $value;
    }
}
