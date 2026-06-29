---
name: erp-ui-admin-reviewer
description: Usar para diseñar o revisar interfaces administrativas del ERP: layouts, tablas, filtros, formularios, acciones, estados, sidebar, topbar, temas, CSS modular y UX empresarial. Evita diseños tipo landing page o tarjetas inapropiadas para catálogos grandes.
---

# ERP UI Admin Reviewer

## Misión

Diseñar y revisar interfaces administrativas profesionales para un ERP PHP/MySQL modular.

## Enfoque visual

El ERP debe sentirse como sistema administrativo empresarial, no como landing page.

Priorizar:

- Claridad.
- Consistencia.
- Tablas eficientes.
- Filtros visibles.
- Acciones compactas.
- Formularios ordenados.
- Estados claros.
- Permisos visibles.
- Sidebar funcional.
- Topbar limpio.
- Responsive útil.
- Sistema de temas.
- Densidad adecuada para trabajo administrativo.

## Reglas obligatorias

- No usar Bootstrap como framework visual.
- No usar componentes tipo landing page para módulos operativos.
- No usar tarjetas para catálogos grandes si dificultan operación.
- No ocultar acciones importantes.
- No depender de JavaScript para permisos reales.
- No mezclar CSS enorme en vistas PHP.
- Usar CSS modular.
- Usar tokens CSS.
- Usar Tailwind compilado localmente si aplica.
- Respetar public/css/core, public/css/modules y public/css/themes.
- Mantener consistencia entre módulos.

## Componentes ERP esperados

- Layout autenticado.
- Sidebar.
- Topbar.
- Breadcrumbs.
- Filtros.
- Tablas.
- Paginación.
- Acciones por fila.
- Acciones masivas si aplica.
- Formularios.
- Selects.
- Inputs.
- Textareas.
- Estados.
- Badges.
- Alerts.
- Modales.
- Toasts.
- Cards informativas.
- Empty states.
- Confirmaciones.
- Loading states.

## Reglas para tablas

Las tablas deben tener:

- Encabezados claros.
- Filtros arriba.
- Buscador si aplica.
- Acciones compactas.
- Estados visuales.
- Paginación.
- Columnas útiles.
- No saturar información.
- Responsive horizontal si aplica.
- Acciones con icono + texto cuando convenga.

## Reglas para formularios

Los formularios deben tener:

- Secciones claras.
- Labels visibles.
- Mensajes de ayuda.
- Validaciones visibles.
- Errores por campo.
- Acciones primarias y secundarias claras.
- CSRF incluido.
- No depender solo de validación frontend.

## Sistema de temas

- Solo admin administra temas.
- Usuarios normales no cambian tema desde perfil.
- Temas modifican tokens, no reescriben UI.
- CSS de temas en public/css/themes/.
- Layout aplica tema de forma centralizada.

## Resultado esperado

Cuando se invoque esta skill, entregar:

1. Diagnóstico UX/UI.
2. Riesgos de diseño.
3. Recomendación visual.
4. Componentes necesarios.
5. Archivos CSS afectados.
6. Archivos JS afectados si aplica.
7. Criterios de aceptación.
8. Qué NO hacer.
































































