<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Infrastructure\Repositories\CredentialTokenRepository;

final class CredentialTokenService
{
    public function __construct(
        private readonly CredentialService $credentials,
        private readonly CredentialTokenRepository $tokens
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function generarToken(int $usuarioId): array
    {
        $this->credentials->asegurarCredencial($usuarioId);

        $credentialId = $this->tokens->credentialIdByUser($usuarioId);

        if ($credentialId === null) {
            throw new CredentialValidationException([
                'credencial' => 'No fue posible resolver la credencial.',
            ]);
        }

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $tokenPrefix = strtoupper(substr($tokenHash, 0, 12));

        $this->tokens->transaction(function () use ($credentialId, $tokenHash, $tokenPrefix): void {
            $this->tokens->revokeActiveTokens($credentialId);
            $this->tokens->createToken($credentialId, $tokenHash, $tokenPrefix);
        });

        $state = $this->obtenerEstadoToken($usuarioId);

        return [
            'token' => $plainToken,
            'token_prefix' => $tokenPrefix,
            'verification_path' => $this->verificationPath($plainToken),
            'estado' => $state,
        ];
    }

    public function revocarTokenActivo(int $usuarioId): void
    {
        $this->credentials->asegurarCredencial($usuarioId);
        $credentialId = $this->tokens->credentialIdByUser($usuarioId);

        if ($credentialId === null) {
            throw new CredentialValidationException([
                'credencial' => 'No fue posible resolver la credencial.',
            ]);
        }

        $this->tokens->revokeActiveTokens($credentialId);
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerEstadoToken(int $usuarioId): array
    {
        $this->credentials->asegurarCredencial($usuarioId);

        $active = $this->tokens->activeTokenByUser($usuarioId);
        $latest = $active ?? $this->tokens->latestTokenByUser($usuarioId);

        return [
            'activo' => $active !== null,
            'token_prefix' => is_array($latest) ? (string) ($latest['token_prefix'] ?? '') : null,
            'creado_en' => is_array($latest) ? ($latest['creado_en'] ?? null) : null,
            'expira_en' => is_array($latest) ? ($latest['expira_en'] ?? null) : null,
            'revocado_en' => is_array($latest) ? ($latest['revocado_en'] ?? null) : null,
        ];
    }

    public function verificationPath(string $plainToken): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $plainToken) !== 1) {
            throw new CredentialValidationException([
                'token' => 'Token inválido.',
            ]);
        }

        return '/credencial/verificar/' . $plainToken;
    }
}
