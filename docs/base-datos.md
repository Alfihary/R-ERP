# Estrategia de base de datos

## Estado del documento

- Fase: `DOCS-0`.
- Estado: diseño conceptual; no ejecutable.
- Motor objetivo: MySQL con InnoDB.
- Esta fase no crea migraciones, seeds, tablas ni SQL.

## Objetivo

Definir cómo construir una sola base de datos para múltiples empresas y
almacenes sin producir un esquema monolítico imposible de revisar. Cada fase de
BD tendrá migración, seeds cuando apliquen, DB-TEST, criterios de aceptación y
autorización independiente.

## Decisiones de diseño

- Motor InnoDB.
- Charset `utf8mb4`.
- Collation base propuesta `utf8mb4_unicode_ci`, compatible con entornos de
  hosting compartido; se confirmará contra la versión real de MySQL.
- Llaves primarias y foráneas explícitas.
- Restricciones e índices definidos por caso de uso.
- Sin cascadas destructivas en tablas críticas.
- Estados lógicos en información que deba conservar trazabilidad.
- Fechas almacenadas en una zona horaria técnica coherente; la estrategia exacta
  queda pendiente.
- Importes y cantidades usarán tipos decimales con precisión aprobada por
  dominio.
- No se guardarán secretos de aplicación o SMTP en la BD.

## Separación multiempresa y multialmacén

Hay tres clases de datos:

### Globales

Catálogos compartidos por diseño, por ejemplo productos, unidades, marcas,
monedas, impuestos y claves SAT en su versión inicial. No llevan `empresa_id`
solo por costumbre; la decisión se documenta por tabla.

### Por empresa

Datos cuyo propietario lógico es una empresa. Deben incluir `empresa_id`, índice
acorde con sus consultas y validación de alcance.

### Por empresa y almacén

Existencias, movimientos y otras operaciones físicas incluyen `empresa_id` y
`almacen_id` cuando aplique. La relación del almacén con la empresa debe
validarse y no se aceptan combinaciones inconsistentes.

La ausencia de una columna de alcance solo será válida si la tabla es realmente
global o hereda el alcance de una relación obligatoria y verificable.

## Auditoría estándar

Cuando aplique:

- `creado_en`
- `actualizado_en`
- `creado_por`
- `actualizado_por`

Para estado y eliminación lógica:

- `activo`
- `eliminado_en`
- `eliminado_por`

Las llaves hacia usuarios deben considerar la conservación histórica. La regla
de nulabilidad y `ON DELETE` se decidirá por tabla; no se adoptará cascada
destructiva como valor por defecto.

## Modelo conceptual inicial

Los tipos siguientes son categorías de diseño, no sentencias SQL.

### `usuarios`

Propósito: identidad, autenticación y estado de cuenta.

Campos conceptuales:

- `id`: entero positivo.
- `email`: cadena normalizada y única.
- `password_hash`: cadena de longitud suficiente.
- `estado`: enumeración controlada o catálogo aprobado.
- `ultimo_acceso_en`: fecha/hora nullable.
- Campos de auditoría.

No contendrá roles, empresas, almacenes o datos extensos de perfil.

### `usuario_perfiles`

Propósito: información extendida del usuario.

Campos conceptuales:

- `id`.
- `usuario_id`: relación uno a uno y única.
- `nombre`, `apellido_paterno`, `apellido_materno`.
- `telefono`, `movil`, `puesto`, `departamento`.
- Referencias controladas a foto o firma, nunca rutas públicas arbitrarias.
- Preferencias visuales no relacionadas con permisos.
- Campos de auditoría.

El perfil no autoriza acciones ni define alcance.

### `roles`

Propósito: agrupación administrable de permisos.

Campos conceptuales:

- `id`.
- `codigo`: único, estable y sin acentos.
- `nombre`.
- `descripcion`.
- `es_sistema`: evita cambios indebidos en roles estructurales.
- `activo`.
- Campos de auditoría.

### `permisos`

Propósito: representar una acción autorizable.

Campos conceptuales:

- `id`.
- `codigo`: único, por ejemplo `usuarios.editar`.
- `modulo`.
- `nombre` y `descripcion`.
- `es_sistema`.
- `activo`.
- Campos de auditoría.

Cada permiso se entregará mediante seed en la fase del módulo propietario.

### `usuario_roles`

Propósito: relación muchos a muchos entre usuarios y roles.

Campos conceptuales:

- `usuario_id`.
- `rol_id`.
- Campos de asignación y auditoría.

Debe impedir asignaciones duplicadas.

### `rol_permisos`

Propósito: relación muchos a muchos entre roles y permisos.

Campos conceptuales:

- `rol_id`.
- `permiso_id`.
- Campos de asignación y auditoría.

Debe impedir asignaciones duplicadas.

### `auditoria_logs`

Propósito: trazabilidad de acciones críticas.

Campos conceptuales:

- `id`.
- `usuario_id` nullable para eventos sin sesión.
- `accion`.
- `modulo`.
- Tipo e identificador del recurso.
- `empresa_id` y `almacen_id` cuando exista contexto.
- Resultado.
- IP y agente de usuario con límites y tratamiento de privacidad.
- Metadatos JSON saneados y limitados.
- Fecha del evento.

No almacenará contraseñas, tokens, secretos ni copias completas de datos
privados.

### `empresas`

Propósito: unidad legal u operativa superior.

Campos conceptuales:

- `id`.
- `codigo`: único.
- `nombre`, `razon_social`, identificador fiscal cuando se apruebe.
- `activo`.
- Campos de auditoría.

### `almacenes`

Propósito: ubicación operativa perteneciente a una empresa.

Campos conceptuales:

- `id`.
- `empresa_id`.
- `codigo`: único dentro de la empresa.
- `nombre`.
- Datos de ubicación aprobados.
- `activo`.
- Campos de auditoría.

La unicidad se evaluará como combinación de empresa y código.

### `usuario_empresas`

Propósito: empresas dentro del alcance de un usuario.

Campos conceptuales:

- `usuario_id`.
- `empresa_id`.
- Nivel de alcance aprobado, si se decide distinguir ver y operar.
- Campos de asignación y auditoría.

Debe impedir duplicados. El alcance global no se inferirá de la ausencia de
filas; tendrá representación explícita.

### `usuario_almacenes`

Propósito: almacenes dentro del alcance de un usuario.

Campos conceptuales:

- `usuario_id`.
- `almacen_id`.
- Nivel de alcance aprobado.
- Campos de asignación y auditoría.

Un almacén asignado debe pertenecer a una empresa permitida o estar cubierto por
una regla global explícita.

### `series_folios`

Propósito: configurar y proteger consecutivos.

Campos conceptuales:

- `id`.
- `empresa_id`.
- `almacen_id` nullable cuando la serie no dependa de almacén.
- `modulo` o tipo de documento.
- `serie`.
- `prefijo`.
- `siguiente_numero`.
- `longitud`.
- `activo`.
- Campos de auditoría.

La combinación que identifica una serie deberá ser única. El incremento se
hará con bloqueo y dentro de la transacción de negocio.

### `ui_temas`

Propósito: catálogo controlado de temas.

Campos conceptuales:

- `id`.
- `codigo`: único y asociado a un asset permitido.
- `nombre`.
- `es_default`.
- `activo`.
- Campos de auditoría.

Solo debe existir un tema default efectivo. No almacenará CSS arbitrario.

### `ui_tema_tokens`

Propósito opcional: valores de tokens permitidos por tema.

Campos conceptuales:

- `id`.
- `ui_tema_id`.
- `token`: perteneciente a whitelist.
- `valor`: validado según tipo.
- Campos de auditoría.

Su necesidad se decidirá en `DB-THEMES-4`.

### `productos`

Propósito futuro: catálogo global inicial de productos.

Debe relacionarse con unidades, marcas, líneas, clasificaciones, impuestos y
claves fiscales aprobadas. No se define su esquema en `DB-CORE-0`.

### `existencias`

Propósito futuro: saldo por producto, empresa y almacén.

Requerirá unicidad por dimensiones operativas, precisión decimal, concurrencia y
reconciliación con movimientos. No se construirá antes de `DB-INVENTARIO-6`.

### `inventario_movimientos`

Propósito futuro: historial inmutable o cancelable mediante contramovimiento.

Requerirá empresa, almacén, producto, concepto, cantidad, referencia, actor,
fecha y auditoría. No se autoriza en esta fase.

### `tickets`

Propósito futuro: solicitudes con ciclo de vida y alcance.

El nombre final, estados, partidas, comentarios y archivos se decidirán en
`DB-TICKETS-7`.

## Relaciones base

```text
usuarios 1---1 usuario_perfiles
usuarios N---M roles mediante usuario_roles
roles N---M permisos mediante rol_permisos
usuarios N---M empresas mediante usuario_empresas
usuarios N---M almacenes mediante usuario_almacenes
empresas 1---N almacenes
empresas 1---N series_folios
almacenes 0..1---N series_folios
usuarios 0..1---N auditoria_logs
```

Las relaciones de catálogos y módulos posteriores se aprobarán en su fase.

## Orden de fases

| Fase | Tablas o ámbito |
|---|---|
| `DB-CORE-0` | usuarios, perfiles, roles, permisos, relaciones y auditoría |
| `DB-SCOPE-1` | empresas, almacenes y alcance |
| `DB-SECURITY-2` | intentos, recuperación y sesiones si se aprueban |
| `DB-FOLIOS-3` | series de folios |
| `DB-THEMES-4` | temas y tokens opcionales |
| `DB-CATALOGOS-5` | productos y catálogos globales |
| `DB-INVENTARIO-6` | existencias, movimientos y conceptos |
| `DB-TICKETS-7` | tickets, partidas, comentarios y archivos |
| `DB-MAIL-8` | plantillas, cola, logs y reglas de notificación |
| `DB-COMPRAS-9` | flujo documental de compras |
| `DB-VENTAS-10` | flujo documental de ventas |
| `DB-CXC-CXP-11` | terceros, cuentas y pagos |
| `DB-REPORTES-12` | configuración o auxiliares estrictamente necesarios |

Ninguna fila de esta tabla autoriza ejecutar la fase.

## Contrato de una fase de BD

Antes de modificar la BD se deberá entregar:

1. Nombre y objetivo.
2. Prerrequisitos aprobados.
3. Tablas exactas.
4. Migración propuesta.
5. Seeds propuestos.
6. Relaciones.
7. Llaves e índices.
8. Restricciones.
9. Campos de auditoría.
10. Reglas de negocio.
11. DB-TEST.
12. Resultado esperado.
13. Criterios de aceptación.
14. Rollback y necesidad de respaldo.
15. Qué no se hará.

Solo después de la autorización se crearán archivos ejecutables.

## Migraciones

- Una migración pertenece a una sola fase.
- Debe ser reproducible en una base vacía compatible.
- Debe tener rollback evaluado.
- No se elimina una migración aplicada.
- Los cambios posteriores se expresan en nuevas migraciones.
- Se evitan operaciones destructivas sin respaldo y ventana aprobada.
- No se mezclan tablas operativas futuras.

El lenguaje o runner de migración se decidirá antes de `DB-CORE-0`.

## Seeds

Categorías:

1. Estructurales: roles o permisos mínimos aprobados.
2. Catálogos: valores iniciales de una fase.
3. DB-TEST: datos exclusivos de prueba.

Los seeds:

- Se versionan.
- No contienen credenciales reales.
- No importan datos productivos.
- Deben ser deterministas o idempotentes conforme al runner aprobado.
- No crean permisos de módulos no autorizados.

## Contrato de DB-TEST

Cada DB-TEST deberá documentar y comprobar:

1. Nombre exacto de la base de prueba.
2. Confirmación de que no es producción.
3. Versión de MySQL.
4. Aplicación de migración y seed de una sola fase.
5. Existencia de tablas.
6. Motor, charset y collation.
7. Llaves primarias y foráneas.
8. Índices y restricciones de unicidad.
9. Registro válido que debe aceptarse.
10. Duplicado que debe fallar.
11. Relación inválida que debe fallar.
12. Nulos o estados inválidos que deben fallar.
13. Datos seed esperados.
14. Consultas de verificación.
15. Limpieza o rollback.
16. Evidencia de resultados.

Los ejemplos de `SELECT` e `INSERT` ejecutables se crearán dentro de la fase de
BD autorizada, no en `DOCS-0`.

## Rollback

Cada fase clasificará su rollback:

- Bajo: objetos nuevos sin datos dependientes.
- Medio: cambio compatible con datos existentes.
- Alto: transformación, eliminación o cambio de significado.

Un rollback no se aprobará si elimina información crítica sin respaldo. Cuando
revertir estructura sea peligroso, se documentará una corrección hacia adelante.

## Reglas obligatorias

- Construir y aprobar la BD por fases.
- Usar InnoDB, `utf8mb4`, llaves, índices y restricciones.
- Mantener permisos, roles y alcance en relaciones normalizadas.
- No guardar alcance dentro de perfiles.
- Aplicar empresa y almacén a datos operativos cuando corresponda.
- Versionar migraciones, seeds y DB-TEST.
- Probar casos válidos e inválidos.
- Evitar cascadas destructivas.
- Mantener trazabilidad de datos críticos.
- Verificar la base destino antes de cualquier DB-TEST.

## Pendiente de aprobar

- Versión exacta de MySQL en local y AwardSpace.
- Collation definitiva.
- Estrategia de IDs.
- Precisión de importes y cantidades.
- Zona horaria de almacenamiento.
- Runner y formato de migraciones.
- Runner y formato de seeds.
- Esquema exacto de `DB-CORE-0`.
- Representación del alcance global.
- Política detallada de eliminación lógica.
- Retención y particionado futuro de auditoría.

## Fuera de alcance de esta fase

- Crear o conectar una base de datos.
- Crear migraciones, seeds o SQL.
- Ejecutar DB-TEST.
- Definir todos los campos de módulos operativos.
- Importar datos de otro ERP.
- Crear usuarios, roles, permisos o empresas.
- Modificar producción.
