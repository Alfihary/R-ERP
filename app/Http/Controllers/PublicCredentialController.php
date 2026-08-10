<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Credentials\CredentialVerificationService;

final class PublicCredentialController
{
    public function __construct(
        private readonly Config $config,
        private readonly CredentialVerificationService $verification
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function verify(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        $ip = $this->clientIp($request);
        $userAgent = $request->header('user-agent');

        if (!$this->verification->allowsPublicVerificationAttempt($ip)) {
            $this->verification->auditPublicRateLimited($ip, $userAgent);

            return $this->secureResponse(
                Response::html(View::render('errors/404'), 429)
            );
        }

        $credential = $this->verification->verificarTokenPublico($token, $ip, $userAgent);

        if ($credential === null) {
            return $this->secureResponse(
                Response::html(View::render('errors/404'), 404)
            );
        }

        return $this->secureResponse(Response::html(View::render('credentials/verify', [
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'credential' => $credential,
            'pageTitle' => 'Verificación de credencial',
            'stylesheets' => ['/css/modules/credential-verify.css'],
        ])));
    }

    private function secureResponse(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY');
    }

    private function clientIp(Request $request): string
    {
        $forwarded = $request->header('x-forwarded-for');

        if (is_string($forwarded) && trim($forwarded) !== '') {
            return trim(explode(',', $forwarded)[0] ?? '');
        }

        $realIp = $request->header('x-real-ip');

        if (is_string($realIp) && trim($realIp) !== '') {
            return trim($realIp);
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($remote) && trim($remote) !== '' ? trim($remote) : '0.0.0.0';
    }
}
