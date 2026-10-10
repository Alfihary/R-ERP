<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Domain\Security\PermissionService;

final class SidebarNavigationService
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    /** @return array<string, mixed> */
    public function forUser(int $userId): array
    {
        return self::forPermissions(
            fn (string $permission): bool => $this->permissions->allows($userId, $permission)
        );
    }

    /**
     * Pure projection used by the view and regression tests. The callback is
     * the existing application's permission decision; no second RBAC exists.
     *
     * @param callable(string): bool $allows
     * @return array<string, mixed>
     */
    public static function forPermissions(callable $allows): array
    {
        $permissions = [];
        $groups = [];

        foreach (self::definitions() as $item) {
            $permission = $item['permission'];
            $allowed = $permissions[$permission] ??= (bool) $allows($permission);
            if (!$allowed) {
                continue;
            }

            $groupId = $item['group'];
            $groups[$groupId]['items'][] = $item;
        }

        foreach (self::groupDefinitions() as $groupId => $group) {
            if (!isset($groups[$groupId])) {
                continue;
            }
            $groups[$groupId] = ['id' => $groupId, 'label' => $group['label']] + $groups[$groupId];
        }

        return [
            'root' => ['id' => 'home', 'label' => 'Inicio', 'href' => '/app', 'icon' => '⌂'],
            'groups' => array_values($groups),
            'permissions' => $permissions,
        ];
    }

    /** @return array<string, array{label: string}> */
    public static function groupDefinitions(): array
    {
        return [
            'operation' => ['label' => 'Operación'],
            'inventory' => ['label' => 'Inventario'],
            'catalogs' => ['label' => 'Catálogos'],
            'organization' => ['label' => 'Organización'],
            'administration' => ['label' => 'Administración'],
            'account' => ['label' => 'Mi cuenta'],
        ];
    }

    /** @return list<array{group: string, id: string, label: string, href: string, icon: string, permission: string}> */
    public static function definitions(): array
    {
        return [
            ['group' => 'operation', 'id' => 'product-tickets', 'label' => 'Tickets de producto', 'href' => '/tickets/productos', 'icon' => '✉', 'permission' => 'tickets_productos.ver'],
            ['group' => 'inventory', 'id' => 'products', 'label' => 'Productos', 'href' => '/productos', 'icon' => '▤', 'permission' => 'productos.acceder'],
            ['group' => 'inventory', 'id' => 'product-prices', 'label' => 'Precios por producto', 'href' => '/precios/productos', 'icon' => '$', 'permission' => 'precios.productos.acceder'],
            ['group' => 'inventory', 'id' => 'inventory', 'label' => 'Movimientos', 'href' => '/inventario/movimientos', 'icon' => '⇄', 'permission' => 'inventario.movimientos.acceder'],
            ['group' => 'inventory', 'id' => 'inventory-stock', 'label' => 'Existencias', 'href' => '/inventario/existencias', 'icon' => '≡', 'permission' => 'inventario.existencias.acceder'],
            ['group' => 'inventory', 'id' => 'inventory-serial-stock', 'label' => 'Existencias por serie', 'href' => '/inventario/existencias-series', 'icon' => '#', 'permission' => 'inventario.existencias_series.acceder'],
            ['group' => 'inventory', 'id' => 'inventory-kardex', 'label' => 'Kardex', 'href' => '/inventario/kardex', 'icon' => '↕', 'permission' => 'inventario.kardex.acceder'],
            ['group' => 'inventory', 'id' => 'inventory-serial-kardex', 'label' => 'Kardex por serie', 'href' => '/inventario/kardex-series', 'icon' => '⌁', 'permission' => 'inventario.kardex_series.acceder'],
            ['group' => 'inventory', 'id' => 'inventory-transfers', 'label' => 'Transferencias', 'href' => '/inventario/transferencias', 'icon' => '⇆', 'permission' => 'inventario.transferencias.acceder'],
            ['group' => 'inventory', 'id' => 'configuration-price-lists', 'label' => 'Listas de precios', 'href' => '/configuracion/listas-precios', 'icon' => '$', 'permission' => 'precios.listas.acceder'],
            ['group' => 'catalogs', 'id' => 'catalogs', 'label' => 'Catálogos', 'href' => '/catalogos', 'icon' => '▦', 'permission' => 'catalogos.acceder'],
            ['group' => 'organization', 'id' => 'configuration-companies', 'label' => 'Empresas', 'href' => '/configuracion/empresas', 'icon' => '▧', 'permission' => 'configuracion.empresas.acceder'],
            ['group' => 'organization', 'id' => 'configuration-warehouses', 'label' => 'Almacenes', 'href' => '/configuracion/almacenes', 'icon' => '▣', 'permission' => 'configuracion.almacenes.acceder'],
            ['group' => 'administration', 'id' => 'configuration-folios', 'label' => 'Folios', 'href' => '/configuracion/folios', 'icon' => '№', 'permission' => 'configuracion.folios.acceder'],
            ['group' => 'administration', 'id' => 'configuration-mail', 'label' => 'Correo', 'href' => '/admin/correo', 'icon' => '@', 'permission' => 'configuracion.correo.administrar'],
            ['group' => 'administration', 'id' => 'mail-outbox', 'label' => 'Cola de correo', 'href' => '/admin/correo/cola', 'icon' => '✉', 'permission' => 'correos.cola.ver'],
            ['group' => 'administration', 'id' => 'audit', 'label' => 'Auditoría', 'href' => '/auditoria', 'icon' => '!', 'permission' => 'auditoria.ver'],
            ['group' => 'administration', 'id' => 'admin-users', 'label' => 'Usuarios', 'href' => '/admin/usuarios', 'icon' => '◉', 'permission' => 'usuarios.acceder'],
            ['group' => 'account', 'id' => 'profile', 'label' => 'Mi perfil', 'href' => '/perfil', 'icon' => '◌', 'permission' => 'perfil.ver'],
            ['group' => 'account', 'id' => 'credential', 'label' => 'Mi credencial', 'href' => '/perfil/credencial', 'icon' => '▣', 'permission' => 'credencial.ver'],
        ];
    }
}
