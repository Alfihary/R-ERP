<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Vcards\VcardService;

final class PublicVcardController
{
    public function __construct(
        private readonly Config $config,
        private readonly VcardService $vcards
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $slug = $this->slugFromParams($params);

        if ($slug === '') {
            return $this->notFound();
        }

        $vcard = $this->vcards->resolverPublicaPorSlug($slug);

        if ($vcard === null) {
            return $this->notFound();
        }

        return $this->withPublicHeaders(Response::html(View::render('vcards/public', [
            'appName' => $this->appName(),
            'canonicalUrl' => $this->canonicalUrl($request),
            'contactAction' => $this->contactAction($vcard['canal_contacto'] ?? null),
            'metaDescription' => $this->metaDescription($vcard),
            'pageTitle' => $this->pageTitle($vcard),
            'vcard' => $vcard,
        ])));
    }

    /**
     * @param array<string, string> $params
     */
    public function photo(Request $request, array $params): Response
    {
        $slug = $this->slugFromParams($params);

        if ($slug === '') {
            return $this->notFound();
        }

        $vcard = $this->vcards->resolverPublicaPorSlug($slug);

        if (
            $vcard === null
            || ($vcard['foto_publica_disponible'] ?? false) !== true
        ) {
            return $this->notFound();
        }

        return $this->notFound();
    }

    private function notFound(): Response
    {
        return $this->withPublicHeaders(Response::html(View::render('vcards/not-found', [
            'appName' => $this->appName(),
        ]), 404))->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * @param array<string, string> $params
     */
    private function slugFromParams(array $params): string
    {
        $slug = $params['slug'] ?? '';

        return is_string($slug) ? $slug : '';
    }

    private function appName(): string
    {
        $appName = (string) $this->config->get('app.name', 'SoporteGR ERP');
        $appName = trim($appName, " \t\n\r\0\x0B\"'");

        return $appName !== '' ? $appName : 'SoporteGR ERP';
    }

    private function canonicalUrl(Request $request): string
    {
        $baseUrl = rtrim((string) $this->config->get('app.url', ''), '/');

        if ($baseUrl === '') {
            return $request->path();
        }

        return $baseUrl . $request->path();
    }

    /**
     * @param array<string, mixed> $vcard
     */
    private function pageTitle(array $vcard): string
    {
        $title = (string) ($vcard['titulo_publico'] ?? '');
        $name = (string) ($vcard['nombre'] ?? '');
        $pageTitle = trim($title !== '' ? $title : $name);

        return $pageTitle !== '' ? $pageTitle : 'Contacto';
    }

    /**
     * @param array<string, mixed> $vcard
     */
    private function metaDescription(array $vcard): string
    {
        $description = trim((string) ($vcard['descripcion_publica'] ?? ''));

        if ($description !== '') {
            return $this->limit($description, 160);
        }

        $parts = array_filter([
            $vcard['puesto'] ?? null,
            $vcard['empresa'] ?? null,
            $vcard['ubicacion'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
        $fallback = trim(implode(' · ', $parts));

        return $fallback !== ''
            ? $this->limit($fallback, 160)
            : 'vCard pública';
    }

    /**
     * @param array{tipo?: mixed, valor?: mixed}|mixed $channel
     * @return array{label: string, href: string}|null
     */
    private function contactAction(mixed $channel): ?array
    {
        if (!is_array($channel)) {
            return null;
        }

        $type = $channel['tipo'] ?? null;
        $value = $channel['valor'] ?? null;

        if (!is_string($type) || !is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return match ($type) {
            'correo' => [
                'label' => 'Enviar correo',
                'href' => 'mailto:' . rawurlencode($value),
            ],
            'telefono_fijo', 'telefono_movil' => [
                'label' => 'Llamar',
                'href' => 'tel:' . preg_replace('/[^0-9+]/', '', $value),
            ],
            'whatsapp' => [
                'label' => 'Contactar por WhatsApp',
                'href' => 'https://wa.me/' . preg_replace('/\D/', '', $value),
            ],
            default => null,
        };
    }

    private function limit(string $value, int $max): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $max
                ? rtrim(mb_substr($value, 0, $max - 1, 'UTF-8')) . '…'
                : $value;
        }

        return strlen($value) > $max
            ? rtrim(substr($value, 0, $max - 1)) . '…'
            : $value;
    }

    private function withPublicHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-Frame-Options', 'DENY');
    }
}
