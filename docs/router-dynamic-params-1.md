# ROUTER-DYNAMIC-PARAMS-1

## Objetivo

Agregar soporte mínimo para rutas dinámicas con parámetros de segmento completo
en el router del ERP, sin romper las rutas exactas existentes y sin registrar
todavía rutas públicas reales de vCard.

## Cambio realizado

`app/Core/Router.php` conserva el mapa de rutas exactas y agrega una lista
separada de rutas dinámicas por método HTTP.

El orden de resolución es:

1. buscar coincidencia exacta por método y path normalizado;
2. si no existe, evaluar rutas dinámicas registradas para el mismo método;
3. responder 404 si no hay coincidencia.

## Prioridad de rutas exactas

Una ruta exacta siempre gana sobre una ruta dinámica. Por ejemplo, si existen:

- `/v/demo`
- `/v/{slug}`

la petición `GET /v/demo` resuelve la ruta exacta.

## Sintaxis soportada

Se soportan parámetros en segmentos completos:

- `/v/{slug}`
- `/v/{slug}/foto`
- `/usuarios/{id}`
- `/catalogos/{tipo}/{id}`

Cada parámetro debe ocupar todo el segmento. No se soportan parámetros parciales
como `/v/perfil-{slug}`.

## Entrega de parámetros al handler

Los handlers de rutas dinámicas reciben un segundo argumento con parámetros
asociativos:

```php
static fn (Request $request, array $params): Response =>
    Response::html($params['slug'])
```

Los handlers de rutas exactas existentes se siguen invocando únicamente con
`Request`.

## Limitaciones

- El router solo extrae strings.
- No valida reglas de negocio del parámetro.
- No decodifica parámetros.
- `{param}` no captura `/`.
- `.` y `..` se rechazan como segmentos dinámicos especiales.
- La validación de slug, permisos, privacidad o alcance queda en
  controladores/servicios.

## Seguridad

- No hay ejecución dinámica de clases o métodos desde el path.
- No hay resolución de archivos desde parámetros.
- Las rutas dinámicas no tienen prioridad sobre rutas exactas.
- El método HTTP debe coincidir.
- No se crearon rutas públicas reales en esta fase.

## Comandos ejecutados

```bash
php -l app/Core/Router.php
php -l database/router-dynamic-params.php
php -l database/tests/router_dynamic_params_1_test.php
git diff --check
php database/router-dynamic-params.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Evidencia esperada

El test funcional valida:

- rutas exactas existentes declarables y resolubles;
- prioridad de exacta sobre dinámica;
- `/v/{slug}`;
- `/v/{slug}/foto`;
- entrega de parámetros;
- rechazo de segmentos extra;
- rechazo de segmento faltante;
- método HTTP incorrecto;
- dos parámetros;
- query array sin afectar matching;
- ausencia de rutas/controladores/vistas/CSS reales de vCard pública.

## Fuera de alcance

- vCard pública funcional.
- `PublicVcardController`.
- vistas públicas de vCard.
- CSS público de vCard.
- QR.
- VCF.
- credencial visual o verificable.
- productos vCard.
- productos, precios o inventario.
- staging o commit.

## Siguiente fase sugerida

`VCARD-PUBLICO-SEGURIDAD-1`.
