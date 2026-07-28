# PERFIL-VCARD-CONTRATO-1

## 1. Objetivo

Definir el contrato funcional y técnico inicial para construir, por fases, el módulo de perfil de usuario, credencial digital, vCard pública y productos publicados en vCard.

El módulo permitirá, en fases posteriores:

- Perfil autenticado.
- Fotografía de usuario.
- Cambio de contraseña.
- Credencial digital.
- QR.
- vCard pública.
- Descarga VCF.
- Privacidad granular.
- Publicación y despublicación.
- Productos publicados en vCard.

Esta fase solo documenta el contrato. No implementa migraciones, tablas, seeds, DB-TEST, servicios, repositorios, controladores, rutas, vistas, CSS ni JavaScript.

## 2. Usuarios involucrados

### Administradores

Podrán administrar usuarios, permisos, publicación, revocación y reglas de seguridad cuando esas fases sean autorizadas. No se asume todavía un CRUD de usuarios nuevo ni administración de sesiones.

### Usuarios operativos

Podrán consultar y editar su propio perfil según permisos. Podrán administrar su foto, contraseña, vCard, productos publicados y credencial digital cuando las fases correspondientes existan.

### Clientes públicos

Podrán consultar únicamente la información publicada y permitida de una vCard. No tendrán acceso a datos internos, identificadores sensibles, permisos, roles, precios, existencias, costos ni información operativa.

## 3. Separación conceptual

### Datos internos de cuenta

Datos usados para autenticación, autorización y operación interna. Incluyen, como mínimo, usuario, correo interno, estado activo, roles y relaciones de seguridad. No deben exponerse en rutas públicas.

### Datos editables de perfil

Datos personales o laborales que el usuario puede mantener dentro del ERP, como nombre público, puesto, teléfonos, enlaces y foto. No todo dato editable es público.

### Datos públicos de vCard

Subconjunto explícitamente publicado del perfil. Debe resolverse mediante reglas de privacidad y estado de publicación. Nunca debe inferirse desde la cuenta interna sin pasar por el contrato de privacidad.

### Datos de seguridad

Incluyen contraseña, tokens, sesiones, QR verificables, permisos, roles y auditoría. Deben tratarse como privados y no imprimirse en UI pública.

### Productos publicados

Relación controlada entre una vCard y productos activos del catálogo. Esta relación no modifica el maestro de productos.

### Credencial digital

Representación visual privada del usuario autenticado. Puede incluir QR, foto, nombre, puesto y contexto autorizado. La versión verificable futura deberá usar token opaco no predecible.

### Recursos públicos

HTML público, imágenes públicas controladas, QR, VCF y rutas auxiliares. Todo recurso público debe pasar por reglas de publicación y privacidad.

## 4. Reglas generales

- No mezclar perfil interno con vCard pública.
- No exponer campos privados.
- No mostrar precios ni existencias en vCard pública en esta etapa.
- Los productos vCard no modifican el catálogo maestro.
- El QR no debe contener datos sensibles.
- El VCF debe generarse solo con campos permitidos.
- La foto debe tratarse como recurso controlado.
- El slug público debe ser único y seguro.
- Una vCard despublicada debe responder con 404 seguro o página neutra.
- Los endpoints públicos no deben permitir enumeración de usuarios.
- Toda ruta privada futura debe pasar por `AuthMiddleware`.
- Toda acción sensible futura debe pasar por `PermissionMiddleware`.
- Todo POST futuro debe validar CSRF.
- Toda salida en vistas debe escaparse con `e()`.
- No confiar en validación frontend ni en ocultar botones como control de seguridad.

## 5. Decisiones aprobadas iniciales

### DEC-01 — Tipo de credencial

Decisión: implementar ambas por fases.

- Primero credencial visual privada.
- Después credencial verificable con token público no predecible, si se autoriza.

### DEC-02 — Almacenamiento de foto

Decisión: migración progresiva hacia almacenamiento privado/controlado.

- No romper fotos existentes si se detectan en migraciones o importaciones futuras.
- Reutilizar el patrón seguro de imágenes privadas de producto cuando aplique.
- No guardar archivos privados directamente en `public/`.

### DEC-03 — Productos públicos en vCard

Decisión: mostrar solo información comercial básica.

Permitido inicialmente:

- Imagen.
- Código.
- Marca.
- Modelo.
- Descripción corta.
- Botón o contacto autorizado.

No permitido inicialmente:

- Precios.
- Existencias.
- Costos.
- Información interna de inventario.

### DEC-04 — Slug público

Decisión: slug editable con validación fuerte.

Reglas propuestas:

- Minúsculas.
- ASCII.
- Único.
- Longitud limitada.
- Lista de slugs reservados.
- Sin IDs internos.
- Sin datos sensibles.

### DEC-05 — Privacidad granular

Decisión recomendada para implementación futura: tabla normalizada por campo si se prioriza auditoría y claridad.

La decisión técnica final queda abierta para la fase DB. Si se elige JSON, el servicio deberá imponer un contrato estricto y el DB-TEST deberá validar estados permitidos.

### DEC-06 — VCF

Decisión: VCF dinámico generado en servidor.

No guardar archivo VCF persistente en esta primera arquitectura. El VCF debe generarse desde datos vigentes, estado publicado y reglas de privacidad.

## 6. Matriz inicial de permisos

Permisos iniciales propuestos, sin seed en esta fase:

- `perfil.ver`
- `perfil.editar`
- `perfil.password.cambiar`
- `perfil.foto.actualizar`
- `perfil.foto.eliminar`
- `vcard.ver`
- `vcard.editar`
- `vcard.publicar`
- `vcard.privacidad.editar`
- `vcard.productos.administrar`
- `vcard.qr.ver`
- `vcard.vcf.descargar`
- `credencial.ver`
- `credencial.qr.ver`
- `credencial.qr.descargar`

En fase DB se decidirá si se crean todos estos permisos o si se reutilizan permisos existentes. Cualquier permiso nuevo deberá tener seed idempotente y prueba de no duplicados.

## 7. Rutas futuras propuestas

Estas rutas son propuestas documentales. No se crean en esta fase.

### Rutas privadas sugeridas

- `GET /perfil`
- `POST /perfil/actualizar`
- `POST /perfil/foto`
- `POST /perfil/foto/eliminar`
- `POST /perfil/password`
- `GET /perfil/credencial`
- `GET /perfil/credencial/qr`
- `GET /perfil/vcard`
- `POST /perfil/vcard`
- `POST /perfil/vcard/publicar`
- `POST /perfil/vcard/despublicar`
- `GET /perfil/vcard/productos`
- `POST /perfil/vcard/productos/agregar`
- `POST /perfil/vcard/productos/quitar`
- `POST /perfil/vcard/productos/ordenar`

### Rutas públicas sugeridas

- `GET /v/{slug}`
- `GET /v/{slug}/foto`
- `GET /v/{slug}/qr`
- `GET /v/{slug}/vcf`
- `GET /v/{slug}/productos`

### Ruta futura de verificación de credencial

Solo si se autoriza una fase de credencial verificable:

- `GET /credencial/verificar/{token}`

## 8. Datos futuros propuestos

Estas entidades son candidatas documentales. No se crean tablas en esta fase.

### perfiles_usuario

- Responsabilidad: almacenar datos editables de perfil que no pertenecen directamente a autenticación.
- Campos principales: `usuario_id`, nombre público, puesto, teléfonos, enlaces, ubicación textual, preferencias públicas.
- Relaciones: `usuario_id` hacia `usuarios(id)`.
- Reglas de integridad: un perfil por usuario; usuario activo para uso operativo.
- Datos sensibles: teléfonos, correo, ubicación y enlaces si no están publicados.
- Eliminación: preferir borrado lógico o limpieza controlada ligada al usuario.
- Alcance: pertenece al usuario; no debe guardar roles, empresas ni almacenes como fuente de verdad.

### usuarios_fotos

- Responsabilidad: registrar foto actual y, si se autoriza, historial mínimo.
- Campos principales: `id`, `usuario_id`, `ruta_relativa`, `nombre_original`, `mime_type`, `tamano_bytes`, `es_principal`, `activo`.
- Relaciones: `usuario_id` hacia `usuarios(id)`.
- Reglas de integridad: solo imagen principal activa por usuario.
- Datos sensibles: ruta física, nombre original y archivo.
- Eliminación: desactivación lógica más eliminación física controlada si aplica.
- Alcance: privada por usuario; pública solo si privacidad y publicación lo permiten.

### vcards_usuario

- Responsabilidad: definir identidad pública, slug, estado de publicación y datos públicos de vCard.
- Campos principales: `usuario_id`, `slug`, `publicada`, nombre visible, titular, resumen, canal preferido.
- Relaciones: `usuario_id` hacia `usuarios(id)`.
- Reglas de integridad: slug único, seguro y no reservado.
- Datos sensibles: cualquier campo no marcado como público.
- Eliminación: despublicación o borrado lógico.
- Alcance: pertenece al usuario; puede derivar empresa/almacén visible solo si se autoriza.

### vcard_privacidad

- Responsabilidad: controlar visibilidad por campo.
- Campos principales: `vcard_id`, `campo`, `visible`, fechas y auditoría.
- Relaciones: `vcard_id` hacia `vcards_usuario`.
- Reglas de integridad: un registro por campo permitido; campo dentro de catálogo autorizado.
- Datos sensibles: define qué puede salir públicamente.
- Eliminación: preferir actualización de estado visible antes que borrado físico.
- Alcance: privacidad por vCard.

### vcard_productos

- Responsabilidad: asociar productos activos a una vCard pública.
- Campos principales: `vcard_id`, `id_producto`, `orden`, `destacado`, `activo`.
- Relaciones: `vcard_id` hacia `vcards_usuario`; `id_producto` hacia `productos(id_producto)`.
- Reglas de integridad: evitar duplicados; ordenar desde servidor.
- Datos sensibles: no debe incluir precios, existencias ni costos.
- Eliminación: baja lógica de la relación.
- Alcance: relación del usuario con su vCard; no modifica catálogo maestro.

### credenciales_usuario

- Responsabilidad: configurar credencial visual del usuario.
- Campos principales: `usuario_id`, estado, versión visual, fecha de emisión.
- Relaciones: `usuario_id` hacia `usuarios(id)`.
- Reglas de integridad: una credencial activa por usuario si aplica.
- Datos sensibles: no debe guardar secretos; solo referencias y estado.
- Eliminación: desactivación lógica.
- Alcance: privada para usuario autenticado y administradores autorizados.

### credencial_tokens

- Responsabilidad: habilitar verificación pública futura con token opaco.
- Campos principales: `credencial_id`, hash de token, estado, expiración, revocación.
- Relaciones: `credencial_id` hacia `credenciales_usuario`.
- Reglas de integridad: token no predecible, único por hash, revocable.
- Datos sensibles: nunca guardar token plano si se usa verificación pública.
- Eliminación: revocación lógica.
- Alcance: público solo como endpoint de verificación mínima.

### vcard_auditoria

- Responsabilidad: registrar cambios relevantes de publicación, privacidad, slug, productos y credencial si la auditoría transversal no cubre el caso.
- Campos principales: actor, entidad, acción, resultado, metadata mínima.
- Relaciones: preferir reutilizar `auditoria_eventos` si alcanza.
- Reglas de integridad: no guardar secretos ni datos completos innecesarios.
- Datos sensibles: metadata debe ser mínima y sanitizada.
- Eliminación: inmutable salvo política de retención futura.
- Alcance: interno.

## 9. Contrato de privacidad

Un campo privado no puede aparecer en:

- HTML público.
- VCF.
- QR.
- Metadatos.
- Imágenes.
- JSON.
- Rutas auxiliares.
- Mensajes de contacto.
- Logs públicos.

Campos con privacidad granular inicial:

- Foto.
- Correo.
- Teléfono fijo.
- Teléfono móvil.
- Puesto.
- Empresa.
- Almacén.
- Ubicación.
- Sitio web.
- LinkedIn.
- Facebook.
- Instagram.
- WhatsApp.
- Google Maps.
- Productos públicos.

Regla de contacto: debe existir canal público autorizado antes de mostrar botones de contacto. El canal preferido tiene prioridad; canal alterno permitido después; correo público al final; si no hay canal permitido, no se muestra contacto.

## 10. Contrato de productos vCard

- Solo productos activos.
- Solo productos no eliminados.
- La relación es independiente del producto maestro.
- Agregar o quitar productos no modifica el producto.
- Deben evitarse duplicados.
- El orden debe ser controlado por servidor.
- No mostrar precios.
- No mostrar stock.
- No mostrar costos.
- El contacto público debe usar canales autorizados.
- Un producto desactivado o eliminado debe dejar de mostrarse públicamente.
- La búsqueda y paginación deben evitar enumeraciones excesivas.
- La vista pública no debe filtrar IDs internos sensibles ni datos de inventario.

## 11. Contrato de credencial

- La credencial es privada para usuario autenticado.
- Puede mostrar foto o iniciales.
- Puede mostrar nombre, puesto, empresa y almacén si aplica y si está autorizado.
- El QR no debe contener datos sensibles.
- La primera versión puede apuntar a la vCard pública.
- La versión verificable futura debe usar token opaco no predecible.
- La verificación futura debe poder revocarse.
- La verificación futura debe responder de forma neutra si el token no existe, expiró o fue revocado.
- No debe imprimir roles, permisos, hashes, IDs internos sensibles ni secretos.

## 12. Casos límite

- Usuario inexistente: respuesta controlada sin filtrar existencia.
- Usuario inactivo: no concede acceso privado ni publicación activa.
- Perfil incompleto: mostrar estado editable privado y público mínimo si está publicado.
- vCard inexistente: 404 seguro o pantalla neutra.
- vCard despublicada: 404 seguro o pantalla neutra.
- Slug duplicado: rechazo controlado.
- Slug reservado: rechazo controlado.
- Foto inválida: rechazo por MIME real, extensión, tamaño y contenido.
- Foto ausente: usar iniciales o placeholder seguro.
- Producto desactivado: no se muestra públicamente.
- Producto eliminado: no se muestra públicamente.
- Lista vacía de productos publicados: mostrar estado vacío neutro.
- Búsqueda sin resultados: mostrar estado vacío sin error.
- Canal de contacto inexistente: no mostrar botón de contacto.
- Sesión expirada: redirigir a login en rutas privadas.
- Falta de permisos: 403 en rutas privadas.
- CSRF inválido: 419 en acciones POST.
- JavaScript desactivado: formularios básicos deben seguir siendo operables donde aplique.
- Móvil pequeño: layout sin overflow horizontal global.
- Tema claro: contraste y jerarquía conservados.
- Temas oscuros: no depender de colores hardcodeados si existe token.
- Reducción de movimiento: no requerir animaciones para operar.

## 13. Fases siguientes propuestas

### 1. PERFIL-VCARD-DB-1

Objetivo: crear esquema base para perfil, foto, vCard, privacidad, productos vCard y credencial si se autoriza.

Límites: sin UI funcional, sin rutas públicas definitivas, sin QR funcional avanzado.

### 2. PERFIL-SERVICE-1

Objetivo: crear servicios y repositorios para perfil autenticado, cambio de contraseña y foto.

Límites: sin vCard pública funcional si no está autorizada en esa fase.

### 3. PERFIL-UI-1

Objetivo: crear pantalla privada de perfil con foto, datos editables y cambio de contraseña.

Límites: sin administración global de usuarios y sin publicación pública si queda para otra fase.

### 4. VCARD-SERVICE-PRIVACIDAD-1

Objetivo: resolver vCard desde estado publicado y privacidad granular.

Límites: sin diseño público final si se separa UI pública.

### 5. VCARD-PUBLICO-SEGURIDAD-1

Objetivo: crear rutas públicas seguras para vCard, foto, QR y VCF.

Límites: sin precios, sin stock, sin APIs públicas adicionales.

### 6. VCARD-PRODUCTOS-1

Objetivo: administrar productos publicados en vCard con búsqueda, paginación, alta, baja y orden.

Límites: no modifica producto maestro; no publica precios ni existencias.

### 7. CREDENCIAL-DIGITAL-1

Objetivo: crear credencial visual privada con QR permitido.

Límites: sin verificación pública por token si queda para otra fase.

### 8. CREDENCIAL-VERIFICABLE-1

Objetivo: agregar verificación pública segura con token opaco, revocación y respuesta neutra.

Límites: sin exponer datos privados ni internos.

### 9. PERFIL-VCARD-QA-1

Objetivo: regresión integral de seguridad, privacidad, HTTP, DB-TEST, responsive, temas y ausencia de fugas.

Límites: no agregar funcionalidades nuevas durante QA salvo correcciones autorizadas.

## 14. Fuera de alcance de esta fase

- No migraciones.
- No tablas.
- No seeds.
- No DB-TEST.
- No servicios.
- No repositorios.
- No controladores.
- No rutas.
- No vistas.
- No CSS.
- No JavaScript.
- No QR funcional.
- No VCF funcional.
- No credencial funcional.
- No productos vCard funcionales.
- No administración de usuarios.
- No revocación de sesiones.
- No staging.
- No commit.

## 15. Criterios de aceptación

Esta fase queda lista cuando:

- Solo existe `docs/perfil-vcard-contrato-1.md` como archivo nuevo.
- El documento contiene las secciones anteriores.
- `git diff --check` pasa.
- `git status --short --ignored` muestra solo el documento nuevo y los ignorados esperados.
- No se modificó ningún archivo de código.
- No se hizo staging.
- No se hizo commit.
