<?php

declare(strict_types=1);

namespace App\Http\Action\Certificate;

use App\Application\Certificate\CertificateService;
use App\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RestoreAction
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
        $user = $this->user($request);
        return $this->responder->json($this->service->restore($this->id($args), $user->id, (string) $request->getAttribute('request_id')));
    }
}
