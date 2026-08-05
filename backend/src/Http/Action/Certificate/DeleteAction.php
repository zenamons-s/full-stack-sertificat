<?php

declare(strict_types=1);

namespace App\Http\Action\Certificate;

use App\Application\Certificate\CertificateService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DeleteAction
{
    use CertificateActionSupport;

    public function __construct(private CertificateService $service, private ResponseFactoryInterface $responseFactory)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        unset($response);
        $user = $this->user($request);
        $this->service->delete($this->id($args), $user->id, (string) $request->getAttribute('request_id'));

        return $this->responseFactory->createResponse(204);
    }
}
