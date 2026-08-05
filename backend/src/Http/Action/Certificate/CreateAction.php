<?php

declare(strict_types=1);

namespace App\Http\Action\Certificate;

use App\Application\Certificate\CertificateService;
use App\Http\Request\CreateCertificateRequest;
use App\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CreateAction
{
    use CertificateActionSupport;

    public function __construct(
        private CreateCertificateRequest $request,
        private CertificateService $service,
        private JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        unset($response, $args);
        $user = $this->user($request);
        $payload = $this->service->create($this->request->toCommand($request, $user->id));

        return $this->responder->json($payload, 201, ['Location' => '/api/v1/certificates/' . $payload['id']]);
    }
}
