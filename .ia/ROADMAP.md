# Technical Roadmap — `enrol_mentorsubscription`
**Plugin de Suscripciones con Modelo Mentor para Moodle 4.5+**


Folder o direccion del plugin "enrol\mentorsubscription"
---

| Campo | Valor |
|---|---|
| Plugin Name | `enrol_mentorsubscription` |
| Plugin Type | Enrolment (`enrol`) |
| Moodle Version | 4.5+ |
| PHP Version | 8.1+ |
| Pasarela de Pago | Stripe (integración propia) |
| Arquitectura | Alternativa 3: `enrol` + Scheduled Tasks + Events |
| Versión Documento | v1.0 — 2026 |
| Equipo | ArchitectLMS — Software Architecture, Engineer, Data Structure, Product |
| Estado | Pre-desarrollo — Pendiente aprobación cliente |

---

## Tabla de Contenidos

1. [Resumen Ejecutivo](#1-resumen-ejecutivo)
2. [Casos de Uso Principales](#2-casos-de-uso-principales)
3. [Arquitectura del Sistema](#3-arquitectura-del-sistema)
4. [Modelo de Base de Datos](#4-modelo-de-base-de-datos)
5. [Flujos Principales del Sistema](#5-flujos-principales-del-sistema)
6. [Roadmap de Desarrollo](#6-roadmap-de-desarrollo)
7. [Estructura de Archivos del Plugin](#7-estructura-de-archivos-del-plugin)
8. [Análisis de Rendimiento y Complejidades](#8-análisis-de-rendimiento-y-complejidades)
9. [Dependencias y Tecnologías](#9-dependencias-y-tecnologías)
10. [Estado de Aprobaciones](#10-estado-de-aprobaciones)

---

## 1. Resumen Ejecutivo

El plugin `enrol_mentorsubscription` es una solución de enrolment para Moodle 4.5+ que implementa un modelo de suscripción basado en la relación Mentor-Mentorado. El mentor adquiere una suscripción mensual o anual mediante Stripe, y el sistema gestiona automáticamente el acceso de sus mentorados a los cursos definidos por el administrador.

El sistema se apoya en el mecanismo nativo de Moodle (Parent Role / `CONTEXT_USER`) para establecer la relación mentor-mentorado de forma programática, garantizando trazabilidad completa, auditoría financiera y control granular de acceso.

### Principios de Diseño

- Un mentor = una suscripción activa simultánea
- Un mentorado = un solo mentor en todo el sistema
- Des-matriculación inmediata al desactivar o eliminar un mentorado
- Stripe es la fuente de verdad del pago; la DB local replica el estado
- Historial de pagos inmutable: un registro nuevo por cada ciclo de facturación
- Precios personalizables por mentor mediante tabla de overrides separada
- Cero SQL directo; 100% uso de la `$DB` API de Moodle

---

## 2. Casos de Uso Principales

| ID | Actor | Caso de Uso | Prioridad |
|---|---|---|---|
| CU-01 | Mentor | Suscribirse a un plan mensual o anual mediante Stripe Checkout | CRÍTICO |
| CU-02 | Mentor | Registrar un nuevo mentorado hasta el límite de su suscripción | CRÍTICO |
| CU-03 | Mentor | Activar o desactivar acceso de un mentorado mediante radio button | CRÍTICO |
| CU-04 | Mentor | Visualizar dashboard: plan activo, cuota usada y lista de mentorados | ALTO |
| CU-05 | Mentor | Recibir notificación de vencimiento N días antes (configurable) | ALTO |
| CU-06 | Mentor | Ver card de upgrade al alcanzar el límite de su suscripción | ALTO |
| CU-07 | Administrador | Definir precio mensual/anual, límite global y cursos incluidos | CRÍTICO |
| CU-08 | Administrador | Crear convenio personalizado (precio y límite) para un mentor específico | ALTO |
| CU-09 | Administrador | Consultar historial completo de pagos de un mentor | MEDIO |
| CU-10 | Sistema | Renovar suscripción automáticamente vía Stripe Webhook | CRÍTICO |
| CU-11 | Sistema | Des-matricular todos los mentorados al cancelar o expirar suscripción | CRÍTICO |
| CU-12 | Sistema | Asignar Parent Role programáticamente al registrar un mentorado | CRÍTICO |

---

## 3. Arquitectura del Sistema

### 3.1 Tipo y Patrón

Se adopta la **Alternativa 3** aprobada en reunión de diseño: un único plugin de tipo `enrol` con arquitectura event-driven interna, Scheduled Tasks para automatización y Stripe Webhooks como fuente de verdad del estado de pago.

| Decisión Arquitectónica | Elección | Justificación |
|---|---|---|
| Tipo de plugin | `enrol` (único artefacto) | Integración nativa con sistema de matriculación de Moodle; un solo despliegue |
| Relación mentor-mentorado | `role_assign()` en `CONTEXT_USER` | Mecanismo nativo de Parent Role; no se reinventa infraestructura existente |
| Matriculación reactiva | Event Observers | Desacoplado, testeable, idiomático en Moodle 4.x |
| Unicidad mentorado | `UNIQUE(menteeid)` en DB | Enforceado a nivel DB + PHP; no solo validación de UI |
| Notificaciones | Messaging API de Moodle | Respeta preferencias de notificación del usuario |
| Renovación de suscripción | Stripe Webhooks + cron fallback | Source of truth en Stripe; cron garantiza consistencia ante pérdida de webhooks |
| Historial de pagos | Registro inmutable por ciclo | Permite auditoría financiera y trazabilidad completa sin pérdida de datos |
| Precios personalizados | Tabla `overrides` separada | No contamina el historial; permite convenios temporales con `valid_from/valid_until` |

### 3.2 Mapa de Componentes

| Módulo / Clase | Ubicación | Responsabilidad |
|---|---|---|
| `enrol_mentorsubscription_plugin` | `lib.php` | Clase principal del plugin; implementa API enrol de Moodle |
| `subscription_manager` | `classes/subscription/` | Ciclo de vida: crear, renovar, cancelar, expirar suscripciones |
| `pricing_manager` | `classes/subscription/` | Resuelve precio y límite aplicando override chain |
| `stripe_handler` | `classes/subscription/` | Integración con Stripe Checkout y procesamiento de webhooks |
| `mentorship_manager` | `classes/mentorship/` | CRUD de mentorados; validación de límite; toggle estado |
| `role_manager` | `classes/mentorship/` | Creación programática de Parent Role; assign/unassign en `CONTEXT_USER` |
| `enrolment_sync` | `classes/mentorship/` | Matriculación y des-matriculación en cursos definidos por admin |
| `check_expiring_subscriptions` | `classes/task/` | Scheduled Task diario: detecta vencimientos y envía notificaciones |
| `sync_stripe_subscriptions` | `classes/task/` | Scheduled Task horario: sincroniza estados con Stripe API (fallback) |
| `observer` | `classes/observer.php` | Escucha eventos `mentee_enrolled`, `unenrolled`, `status_changed` |
| `notification_manager` | `classes/` | Envía mensajes vía Messaging API de Moodle |
| `privacy/provider` | `classes/privacy/` | Cumplimiento GDPR: exportar y eliminar datos de usuario |
| `mentor_dashboard` (Renderable) | `classes/output/` | Lógica de presentación del panel del mentor |
| `admin_subscription_panel` | `classes/output/` | Lógica de presentación del panel de administración |
| `webhook.php` | Raíz del plugin | Endpoint HTTP público para recibir eventos de Stripe |

---

## 4. Modelo de Base de Datos

El modelo usa **5 tablas** con responsabilidades bien separadas. Se adopta el principio de **snapshot inmutable** en `subscriptions`: el precio y límite cobrado en cada ciclo queda fijo al momento del pago, garantizando integridad del historial financiero.

```
enrol_mentorsub_sub_types        ← plantilla global (admin configura)
         │
         ├── enrol_mentorsub_sub_overrides   ← convenios por mentor
         │
         └── enrol_mentorsub_subscriptions  ← historial de ciclos de pago

enrol_mentorsub_mentees          ← mentorados por mentor
enrol_mentorsub_courses          ← cursos incluidos (definidos por admin)
enrol_mentorsub_notifications    ← log de notificaciones enviadas
```

---

### 4.1 `enrol_mentorsub_sub_types`

Define los tipos de suscripción globales configurados por el administrador. Es la plantilla de la que heredan los registros de pago.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | `BIGINT PK` | Identificador único |
| `name` | `VARCHAR(100) NOT NULL` | "Mensual", "Anual" |
| `billing_cycle` | `VARCHAR(10) NOT NULL` | Valores: `monthly` \| `annual` |
| `price` | `DECIMAL(10,2) NOT NULL` | Precio base público |
| `default_max_mentees` | `SMALLINT NOT NULL` | Límite base de mentorados (ej: 10) |
| `stripe_price_id` | `VARCHAR(255) NOT NULL` | ID del Price object en Stripe |
| `description` | `TEXT NULL` | Texto descriptivo visible al mentor |
| `features` | `TEXT NULL` | JSON con features extra para renderizar en UI |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` | Permite deshabilitar sin eliminar |
| `sort_order` | `TINYINT NOT NULL DEFAULT 0` | Orden de presentación en UI |
| `timecreated` | `BIGINT NOT NULL` | Unix timestamp de creación |
| `timemodified` | `BIGINT NOT NULL` | Unix timestamp de última modificación |

**Índices:** `INDEX(is_active, sort_order)` — lista tipos activos ordenados en UI.

---

### 4.2 `enrol_mentorsub_sub_overrides`

Convenios personalizados del administrador para un mentor específico. No genera registro de pago. Define condiciones que sobreescriben los valores del tipo cuando aplican.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | `BIGINT PK` | Identificador único |
| `userid` | `BIGINT NOT NULL FK→mdl_user` | El mentor beneficiario del convenio |
| `subtypeid` | `BIGINT NOT NULL FK→sub_types` | Tipo de suscripción afectado |
| `price_override` | `DECIMAL(10,2) NULL` | `NULL` = usa `price` del type |
| `max_mentees_override` | `SMALLINT NULL` | `NULL` = usa `default_max_mentees` del type |
| `stripe_price_id_override` | `VARCHAR(255) NULL` | Price ID custom en Stripe para este mentor |
| `admin_notes` | `TEXT NULL` | Razón del convenio (uso interno) |
| `valid_from` | `BIGINT NOT NULL` | Timestamp desde cuándo aplica el override |
| `valid_until` | `BIGINT NULL` | `NULL` = indefinido; permite convenios temporales |
| `created_by` | `BIGINT NOT NULL FK→mdl_user` | Qué administrador creó el override |
| `timecreated` | `BIGINT NOT NULL` | Unix timestamp de creación |
| `timemodified` | `BIGINT NOT NULL` | Unix timestamp de última modificación |

**Índices:** `UNIQUE(userid, subtypeid)` — un override por tipo por mentor. `INDEX(userid, valid_from, valid_until)` — lookup eficiente en override chain.

---

### 4.3 `enrol_mentorsub_subscriptions`

Registro **inmutable** de cada ciclo de facturación. Se crea un registro nuevo en cada renovación. El anterior cambia a `status='superseded'`. Es el **ledger financiero** del sistema.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | `BIGINT PK` | Identificador único |
| `userid` | `BIGINT NOT NULL FK→mdl_user` | El mentor propietario |
| `subtypeid` | `BIGINT NOT NULL FK→sub_types` | Snapshot del tipo contratado |
| `overrideid` | `BIGINT NULL FK→sub_overrides` | Override aplicado en este ciclo (si aplica) |
| `billed_price` | `DECIMAL(10,2) NOT NULL` | Precio real cobrado **(snapshot inmutable)** |
| `billed_max_mentees` | `SMALLINT NOT NULL` | Límite vigente en este ciclo **(snapshot)** |
| `billing_cycle` | `VARCHAR(10) NOT NULL` | `monthly` \| `annual` |
| `status` | `VARCHAR(20) NOT NULL` | `pending` \| `active` \| `past_due` \| `superseded` \| `cancelled` \| `expired` |
| `stripe_subscription_id` | `VARCHAR(255) NULL` | `sub_xxxxx` — ID de suscripción en Stripe |
| `stripe_customer_id` | `VARCHAR(255) NOT NULL` | `cus_xxxxx` — ID de cliente en Stripe |
| `stripe_payment_intent_id` | `VARCHAR(255) NULL` | `pi_xxxxx` — trazabilidad de cada pago |
| `stripe_invoice_id` | `VARCHAR(255) NULL` | `in_xxxxx` — factura de Stripe |
| `stripe_price_id_used` | `VARCHAR(255) NOT NULL` | Price ID real usado en este ciclo |
| `period_start` | `BIGINT NOT NULL` | Inicio del ciclo de facturación |
| `period_end` | `BIGINT NOT NULL` | Fin del ciclo de facturación |
| `cancelled_at` | `BIGINT NULL` | Timestamp de cancelación (si aplica) |
| `cancel_at_period_end` | `TINYINT(1) NOT NULL DEFAULT 0` | `1` = cancelar al vencer sin renovar |
| `timecreated` | `BIGINT NOT NULL` | Unix timestamp de creación del registro |
| `timemodified` | `BIGINT NOT NULL` | Unix timestamp de última modificación |

**Índices:**
- `INDEX(userid, status)` — suscripción activa por mentor
- `INDEX(status, period_end)` — scheduled task de vencimientos
- `INDEX(stripe_subscription_id)` — lookup desde webhooks
- `INDEX(stripe_payment_intent_id)` — conciliación de pagos

**Diagrama de estados:**

```
          checkout init
              │
         ┌────▼────┐
         │ pending │
         └────┬────┘
              │ invoice.paid (primer pago)
         ┌────▼────┐
    ┌────│  active │────────────────────────┐
    │    └────┬────┘                        │
    │         │                             │ invoice.paid
pago│   cancel│          period             │ (renovación)
falla│  pedido│          termina            │
    │         │              │       ┌──────▼──────┐
    ▼         ▼              ▼       │  superseded │
┌────────┐ ┌──────────┐ ┌─────────┐ └─────────────┘
│past_due│ │cancelled │ │ expired │
└────────┘ └──────────┘ └─────────┘
```

---

### 4.4 `enrol_mentorsub_mentees`

Registro de mentorados asignados a cada mentor con control de estado activo/inactivo.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | `BIGINT PK` | Identificador único |
| `mentorid` | `BIGINT NOT NULL FK→mdl_user` | El mentor |
| `menteeid` | `BIGINT NOT NULL FK→mdl_user` | El mentorado |
| `subscriptionid` | `BIGINT NOT NULL FK→subscriptions` | Ciclo activo al momento del registro |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` | `1` = activo (matriculado), `0` = inactivo (des-matriculado) |
| `timecreated` | `BIGINT NOT NULL` | Unix timestamp de creación |
| `timemodified` | `BIGINT NOT NULL` | Unix timestamp de última modificación |

**Índices:** `UNIQUE(mentorid, menteeid)` — sin duplicados. `UNIQUE(menteeid)` — un mentorado solo puede tener un mentor. `INDEX(mentorid, is_active)` — COUNT de activos eficiente.

---

### 4.5 `enrol_mentorsub_courses`

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | `BIGINT PK` | Identificador único |
| `courseid` | `BIGINT NOT NULL FK→mdl_course` | Curso incluido en el sistema |
| `sortorder` | `SMALLINT DEFAULT 0` | Orden de presentación |

**Índices:** `UNIQUE(courseid)` — sin duplicados.

---

### 4.6 `enrol_mentorsub_notifications`

Evita el envío de notificaciones duplicadas al mismo mentor para el mismo evento.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | `BIGINT PK` | Identificador único |
| `subscriptionid` | `BIGINT NOT NULL FK→subscriptions` | Suscripción asociada |
| `type` | `VARCHAR(50) NOT NULL` | Tipo: `expiry_warning` |
| `days_before` | `TINYINT NOT NULL` | Días antes del vencimiento en que se envió |
| `timesent` | `BIGINT NOT NULL` | Unix timestamp de envío |

**Índices:** `UNIQUE(subscriptionid, type, days_before)` — evita notificaciones duplicadas.

---

## 5. Flujos Principales del Sistema

### 5.1 Flujo de Suscripción y Pago

| Paso | Actor | Acción | Respuesta del Sistema |
|---|---|---|---|
| 1 | Mentor | Accede a la página de suscripción y selecciona ciclo mensual o anual | Muestra tipos activos desde `sub_types` |
| 2 | Mentor | Confirma la suscripción | `pricing_manager::resolve()` aplica override si existe y está vigente |
| 3 | Sistema | Redirige a Stripe Checkout | `stripe_handler` crea Checkout Session con Price ID resuelto |
| 4 | Stripe | Procesa el pago | Emite evento `checkout.session.completed` |
| 5 | Sistema | Webhook recibido en `webhook.php` | Crea registro `status=active` en `subscriptions` con snapshot de precio y límite |
| 6 | Stripe | Emite `invoice.paid` en cada renovación | `process_renewal()`: cierra ciclo anterior (`superseded`), abre nuevo registro |
| 7 | Stripe | Emite `invoice.payment_failed` | Cambia `status` a `past_due`; mentor notificado vía Messaging API |
| 8 | Stripe | Emite `customer.subscription.deleted` | `status=expired`; des-matriculación masiva de todos los mentorados |

---

### 5.2 Flujo de Registro de Mentorado

| Paso | Validación | Resultado Exitoso | Resultado Fallido |
|---|---|---|---|
| 1 | ¿Mentor tiene suscripción `active`? | Continúa | Error: suscripción requerida |
| 2 | ¿`activos < billed_max_mentees`? | Continúa | Muestra card de upgrade |
| 3 | ¿El usuario existe en Moodle? | Continúa | Error: usuario no encontrado |
| 4 | ¿`UNIQUE(menteeid)` no violada? | `INSERT` en `mentees` | Error: mentorado ya tiene mentor asignado |
| 5 | Asignar Parent Role | `role_assign()` en `CONTEXT_USER` del mentorado | Log de error; rollback de INSERT |
| 6 | Matricular en cursos | `enrol_user()` en cada curso de la lista del admin | Log de error; alerta al admin |
| 7 | Disparar evento | `mentee_enrolled` emitido y procesado por observer | N/A |
| 8 | Notificación | Mentor y mentorado reciben mensaje vía Messaging API | N/A |

---

### 5.3 Flujo de Toggle Activo / Inactivo

| Acción del Mentor | Validación | Efecto en DB | Efecto en Moodle |
|---|---|---|---|
| Desactivar mentorado | Ninguna — siempre permitido | `is_active = 0` | `unenrol_user()` en todos los cursos del plugin |
| Activar mentorado | ¿`activos < billed_max_mentees`? | `is_active = 1` | `enrol_user()` en todos los cursos del plugin |
| Activar (límite alcanzado) | `activos >= billed_max_mentees` | Sin cambio | Muestra card de upgrade; sugiere desactivar otro mentorado |

---

### 5.4 Override Chain — Resolución de Precio y Límite

```
pricing_manager::resolve(userid, subtypeid)
        │
        ├─► ¿Existe override activo? (valid_from <= now <= valid_until)
        │     ├─► SÍ: aplicar campos NOT NULL del override sobre el type
        │     └─► NO: usar valores base del sub_type
        │
        └─► Retorna: { billed_price, billed_max_mentees, stripe_price_id, overrideid }
```

| Condición | Precio usado | Límite usado |
|---|---|---|
| Sin override activo | `sub_types.price` | `sub_types.default_max_mentees` |
| Override con `price_override = NULL` | `sub_types.price` | `override.max_mentees_override` |
| Override con todos los campos | `override.price_override` | `override.max_mentees_override` |
| Override vencido (`valid_until < now`) | `sub_types.price` (vuelve al base) | `sub_types.default_max_mentees` |

---

### 5.5 Diagrama General del Sistema

```
MENTOR
  │
  ├─► Compra suscripción (mensual/anual)
  │     └─► Stripe Checkout
  │           └─► Webhook → crea subscription en DB
  │                          (snapshot: billed_price, billed_max_mentees)
  │
  ├─► Agrega mentorado
  │     ├─► Valida: suscripción active
  │     ├─► Valida: activos < billed_max_mentees
  │     ├─► Valida: UNIQUE(menteeid)
  │     ├─► INSERT mentees (is_active = 1)
  │     ├─► role_assign() → Parent Role en CONTEXT_USER del mentorado
  │     └─► enrol_user() → todos los cursos de enrol_mentorsub_courses
  │
  ├─► Radio button → inactivo
  │     ├─► UPDATE is_active = 0
  │     └─► unenrol_user() → des-matriculación inmediata
  │
  └─► Radio button → activo
        ├─► Valida: activos < billed_max_mentees
        ├─► UPDATE is_active = 1
        └─► enrol_user() → re-matriculación

ADMINISTRADOR
  ├─► Define: sub_types (precio mensual/anual, límite global, stripe_price_id)
  ├─► Define: cursos en enrol_mentorsub_courses
  └─► Override por mentor: price_override, max_mentees_override, valid_from/until

STRIPE (Webhooks)
  ├─► checkout.session.completed → crea registro active
  ├─► invoice.paid               → process_renewal() → nuevo ciclo
  ├─► invoice.payment_failed     → status = past_due
  └─► subscription.deleted       → status = expired → des-matricula todos

SCHEDULED TASKS
  ├─► Diario 8am  → check_expiring_subscriptions → notificación N días antes
  └─► Cada hora   → sync_stripe_subscriptions → fallback de sincronización
```

---

## 6. Roadmap de Desarrollo

> **Progreso global al 24/Feb/2026:** M-0 ✅ M-1 ✅ M-2 ✅ M-3 ✅ M-4 ✅ M-5 ✅ M-6 🔴
> **Pendiente crítico:** Todos los PHPUnit y Behat (M-6.7–M-6.11), auditorías de seguridad (M-6.1–6.5), documentación (M-6.12–13).

### Resumen de Hitos

| Hito | Nombre | Duración | Dependencias | Estado |
|---|---|---|---|---|
| M-0 | Fundación del Plugin | Semana 1 | Ninguna | ✅ Completo |
| M-1 | Parent Role Programático | Semana 1–2 | M-0 | ✅ Completo |
| M-2 | Suscripción y Pago Stripe | Semana 2–3 | M-0, M-1 | ✅ Completo |
| M-3 | Gestión de Mentorados | Semana 3–4 | M-0, M-1, M-2 | ✅ Completo |
| M-4 | Interfaces de Usuario | Semana 4–5 | M-2, M-3 | ✅ Completo |
| M-5 | Automatización y Notificaciones | Semana 5 | M-2, M-3 | ✅ Completo |
| M-6 | Hardening, Testing y Entrega | Semana 6 | M-0 al M-5 | 🔴 Pendiente |

---

### M-0 — Fundación del Plugin ✅
`Semana 1` · Dependencias: Ninguna · **Estado: Completo**

**Objetivo:** Crear la estructura base del plugin que instale correctamente en Moodle 4.5+ y sirva de base para todos los hitos siguientes.

| Sub-Hito | Tarea | Criterio de Aceptación | Estado |
|---|---|---|---|
| M-0.1 | Crear `version.php` | Plugin aparece en lista de plugins; `component="enrol_mentorsubscription"` correcto | ✅ |
| M-0.2 | Crear `lib.php` con clase base | Plugin aparece en lista de métodos de enrolment en configuración de curso | ✅ |
| M-0.3 | Crear `db/install.xml` con 5 tablas | Tablas creadas con índices y constraints; sin errores en upgrade | ✅ |
| M-0.4 | Crear `db/access.php` — capabilities | 3 capabilities visibles en admin: `managesubscription`, `managementees`, `viewdashboard` | ✅ |
| M-0.5 | Crear `db/tasks.php` | 2 scheduled tasks visibles en Site Admin > Server > Scheduled tasks | ✅ |
| M-0.6 | Crear `db/events.php` + `observer.php` stub | 3 eventos registrados; observer mapeado; sin errores de carga | ✅ |
| M-0.7 | Crear `db/services.php` — AJAX endpoints | Servicios externos declarados con capabilities correctas | ✅ |
| M-0.8 | Crear `settings.php` — configuración global | Admin define: precio mensual, precio anual, límite global, días aviso, IDs cursos, rol estudiante | ✅ |
| M-0.9 | Crear `lang/en/enrol_mentorsubscription.php` | Todas las strings del plugin definidas; sin warnings de strings faltantes | ✅ |
| M-0.10 | Crear estructura de carpetas `classes/` con stubs | Autoload funcional; plugin instala sin errores de carga de clases | ✅ |

---

### M-1 — Parent Role Programático ✅
`Semana 1–2` · Dependencias: M-0 · **Estado: Completo**

**Objetivo:** Implementar la creación y asignación del rol Parent de forma completamente programática, siguiendo la documentación oficial de Moodle y garantizando idempotencia.

| Sub-Hito | Tarea | Criterio de Aceptación | Estado |
|---|---|---|---|
| M-1.1 | `role_manager::ensure_parent_role_exists()` | Rol `parent` creado con shortname correcto si no existe; idempotente en re-ejecución | ✅ |
| M-1.2 | Configurar `contextlevels` del rol | `set_role_contextlevels()` restringe el rol a `CONTEXT_USER` únicamente | ✅ |
| M-1.3 | Asignar capabilities al rol | `moodle/user:viewdetails`, `viewalldetails`, `gradereport/user:view`, `moodle/grade:viewall` asignadas vía `assign_capability()` | ✅ |
| M-1.4 | `role_manager::assign_mentor_as_parent()` | `role_assign()` ejecutado en `CONTEXT_USER` del mentorado; registro visible en `mdl_role_assignments` | ✅ |
| M-1.5 | `role_manager::unassign_mentor_as_parent()` | `role_unassign()` elimina asignación; sin errores si el rol ya no existía | ✅ |
| M-1.6 | Test de idempotencia | Llamar `assign_mentor_as_parent()` dos veces no duplica el `role_assignment` en DB | ✅ |
| M-1.7 | PHPUnit: `role_manager` | Tests cubren: crear rol, asignar, desasignar, idempotencia, rol inexistente | 🔴 Pendiente M-6 |

---

### M-2 — Suscripción y Pago Stripe ✅
`Semana 2–3` · Dependencias: M-0, M-1 · **Estado: Completo**

**Objetivo:** Implementar el flujo completo de adquisición de suscripción, integración con Stripe Checkout, procesamiento de webhooks y manejo del ciclo de vida de pagos con historial inmutable.

| Sub-Hito | Tarea | Criterio de Aceptación | Estado |
|---|---|---|---|
| M-2.1 | CRUD de `sub_types` en panel admin | Admin crea tipo "Mensual" con precio, límite y `stripe_price_id`; registro en `sub_types` | ✅ |
| M-2.2 | `pricing_manager::resolve()` | Retorna precio del type si no hay override; retorna override si existe y está vigente | ✅ |
| M-2.3 | Override admin: crear y editar convenio | Admin asigna `price_override` y `max_mentees_override` con `valid_from/until` | ✅ |
| M-2.4 | `stripe_handler`: crear Checkout Session | Mentor inicia pago; redirigido a Stripe con Price ID correcto resuelto por `pricing_manager` | ✅ |
| M-2.5 | `webhook.php`: `checkout.session.completed` | Crea registro en `subscriptions` con `status=active` y snapshot de precio y límite | ✅ |
| M-2.6 | `webhook.php`: `invoice.paid` (renovación) | `process_renewal()`: anterior pasa a `superseded`; nuevo registro `active` creado en transacción | ✅ |
| M-2.7 | `webhook.php`: `invoice.payment_failed` | `status` cambia a `past_due`; mentor recibe notificación vía Messaging API | ✅ |
| M-2.8 | `webhook.php`: `customer.subscription.deleted` | `status=expired`; `unenrol_mentee()` ejecutado para todos los mentorados en transacción | ✅ |
| M-2.9 | `subscription_manager::get_history()` | Admin consulta todos los ciclos de un mentor ordenados por `timecreated DESC` | ✅ |
| M-2.10 | Verificación de firma Stripe | Webhook rechaza requests sin firma HMAC válida; retorna `HTTP 400` | ✅ |
| M-2.11 | PHPUnit: `subscription_manager` + `pricing_manager` | Tests: snapshot inmutable, renovación, override chain, historial, todos los estados | 🔴 Pendiente M-6 |

---

### M-3 — Gestión de Mentorados ✅
`Semana 3–4` · Dependencias: M-0, M-1, M-2 · **Estado: Completo**

**Objetivo:** Implementar toda la lógica de negocio de gestión de mentorados incluyendo registro, validación de límite, control activo/inactivo y sincronización automática de matriculaciones.

| Sub-Hito | Tarea | Criterio de Aceptación | Estado |
|---|---|---|---|
| M-3.1 | `mentorship_manager::add_mentee()` | Valida suscripción, límite y unicidad; INSERT + role_assign + enrol_user en una transacción | ✅ |
| M-3.2 | Validación de límite al agregar | Exception `limiterreached` si `activos >= billed_max_mentees`; mensaje claro al mentor | ✅ |
| M-3.3 | Validación de unicidad `menteeid` | Exception `menteealreadyassigned` si `UNIQUE(menteeid)` violada | ✅ |
| M-3.4 | `mentorship_manager::toggle_mentee_status()` | Desactivar: siempre permitido. Activar: valida límite; retorna `{success, reason, limit, active}` | ✅ |
| M-3.5 | `enrolment_sync::enrol_mentee()` | Mentorado matriculado en todos los cursos de `enrol_mentorsub_courses`; instancia creada si no existe | ✅ |
| M-3.6 | `enrolment_sync::unenrol_mentee()` | Des-matriculación inmediata solo de cursos gestionados por este plugin; no toca otras matriculaciones | ✅ |
| M-3.7 | Des-matriculación masiva al expirar | Al `status=expired`, todos los mentorados des-matriculados en una sola transacción | ✅ |
| M-3.8 | Eventos: `mentee_enrolled`, `unenrolled`, `status_changed` | Eventos visibles en `mdl_logstore_standard_log` con datos correctos | ✅ |
| M-3.9 | `observer.php`: reacciona a eventos | Observer recibe eventos y ejecuta acciones de sincronización; sin side effects duplicados | ✅ |
| M-3.10 | PHPUnit: `mentorship_manager` + `enrolment_sync` | Tests: agregar, límite, unicidad, toggle, enrol, unenrol, des-matriculación masiva | 🔴 Pendiente M-6 |

---

### M-4 — Interfaces de Usuario ✅
`Semana 4–5` · Dependencias: M-2, M-3 · **Estado: Completo**

**Objetivo:** Implementar todas las interfaces de usuario para mentor y administrador usando Renderables y templates Mustache, siguiendo los Moodle Development Standards de Moodle 4.x.

| Sub-Hito | Tarea | Criterio de Aceptación | Estado |
|---|---|---|---|
| M-4.1 | Panel Mentor: resumen de suscripción | Muestra: tipo, ciclo, fecha vencimiento, precio, barra de progreso activos/límite | ✅ |
| M-4.2 | Panel Mentor: lista de mentorados | Cards por mentorado con nombre, email, avatar, radio button activo/inactivo | ✅ |
| M-4.3 | Radio button con validación AJAX | JS llama endpoint AJAX; respuesta actualiza estado sin recargar página | ✅ |
| M-4.4 | Card de límite alcanzado | Aparece solo cuando `activos >= billed_max_mentees`; CTA para contactar admin | ✅ |
| M-4.5 | Formulario: agregar mentorado | Búsqueda con autocomplete de usuario Moodle; muestra avatar y nombre antes de confirmar | ✅ |
| M-4.6 | Panel Admin: configuración global | Admin define precio mensual/anual, límite global, IDs de cursos, días de aviso | ✅ |
| M-4.7 | Panel Admin: CRUD de tipos de suscripción | Gestión completa de `sub_types` con `stripe_price_id`; activar/desactivar sin eliminar | ✅ |
| M-4.8 | Panel Admin: lista de mentores activos | Tabla con mentor, tipo, ciclo, activos/límite, período actual, fecha próximo cobro | ✅ |
| M-4.9 | Panel Admin: override por mentor | Formulario edita `price_override`, `max_mentees_override`, `valid_from/until`, `admin_notes` | ✅ |
| M-4.10 | Panel Admin: historial de pagos por mentor | Lista de ciclos con fecha, precio cobrado, límite, `stripe_invoice_id`, estado | ✅ |
| M-4.11 | Todos los templates en Mustache | Sin PHP en templates; datos vía Renderable; compatible con Boost y Classic themes | ✅ |
| M-4.12 | Endpoints AJAX con AJAX API de Moodle | Todos los servicios declarados en `db/services.php` con capabilities correctas | ✅ |

---

### M-5 — Automatización y Notificaciones ✅
`Semana 5` · Dependencias: M-2, M-3 · **Estado: Completo**

**Objetivo:** Implementar las tareas programadas y el sistema de notificaciones para garantizar continuidad de servicio y comunicación proactiva con el mentor.

| Sub-Hito | Tarea | Criterio de Aceptación | Estado |
|---|---|---|---|
| M-5.1 | Task: `check_expiring_subscriptions` (diaria 8am) | Detecta suscripciones con `period_end <= now + N días`; no envía duplicados por `UNIQUE` constraint | ✅ |
| M-5.2 | Días de aviso configurables desde admin | Admin define N días en `settings.php`; task lee el valor en cada ejecución | ✅ |
| M-5.3 | `notification_manager`: envío vía Messaging API | Mentor recibe mensaje en Moodle; respeta preferencias de notificación del usuario | ✅ |
| M-5.4 | Notificación al agregar mentorado | Mentor y mentorado reciben mensaje con nombre del otro y link al curso | ✅ |
| M-5.5 | Notificación al desactivar mentorado | Mentorado notificado de pérdida de acceso temporal | ✅ |
| M-5.6 | Task: `sync_stripe_subscriptions` (horaria) | Consulta Stripe API; sincroniza `status` si difiere de DB; log de cambios detectados | ✅ |
| M-5.7 | Manejo de `past_due`: período de gracia | Suscripción `past_due` mantiene acceso N días configurables antes de marcar `expired` | ✅ |
| M-5.8 | PHPUnit: `notification_manager` + tasks | Tests: no duplicar notificación, envío correcto, task detecta suscripciones correctamente | 🔴 Pendiente M-6 |

---

### M-6 — Hardening, Testing y Entrega 🔴
`Semana 6` · Dependencias: M-0 al M-5 · **Estado: Pendiente**

**Objetivo:** Garantizar calidad, seguridad y cumplimiento normativo antes de la entrega al cliente. Cero vulnerabilidades conocidas.

| Sub-Hito | Tarea | Criterio de Aceptación | Estado |
|---|---|---|---|
| M-6.1 | Auditoría SQL injection | 0 SQL directo en todo el plugin; 100% uso de `$DB` API de Moodle | ⏳ Pendiente |
| M-6.2 | Auditoría XSS | Todo output usa `format_text()` / `format_string()`; templates Mustache escapan automáticamente | ⏳ Pendiente |
| M-6.3 | Auditoría CSRF | Todos los formularios usan `sesskey`; todos los endpoints AJAX verifican `require_sesskey()` | ⏳ Pendiente |
| M-6.4 | Auditoría capabilities | `require_login()` y `require_capability()` en cada punto de entrada; ningún endpoint sin protección | ⏳ Pendiente |
| M-6.5 | Auditoría parámetros | `required_param()` / `optional_param()` en todos los endpoints; sin `$_GET/$_POST` directos | ⏳ Pendiente |
| M-6.6 | Privacy Provider GDPR | `provider.php` implementado; datos exportables y eliminables desde admin de privacidad | ✅ |
| M-6.7 | PHPUnit cobertura core | >80% cobertura en `subscription_manager`, `mentorship_manager`, `role_manager`, `enrolment_sync` | ⏳ Pendiente |
| M-6.8 | Behat: flujo E2E suscripción | Mentor compra → sistema activa → mentor ve dashboard correcto con límite | ⏳ Pendiente |
| M-6.9 | Behat: flujo E2E mentorado | Agregar mentorado → ve cursos → mentor desactiva → pierde acceso inmediato | ⏳ Pendiente |
| M-6.10 | Behat: flujo E2E límite | Agregar hasta límite → card de upgrade aparece → no se puede agregar más | ⏳ Pendiente |
| M-6.11 | Behat: flujo E2E expiración | Suscripción expira → todos los mentorados des-matriculados automáticamente | ⏳ Pendiente |
| M-6.12 | Documentación: `.lms_dev/design_notes.md` | Decisiones arquitectónicas documentadas con justificaciones y alternativas consideradas | ⏳ Pendiente |
| M-6.13 | Documentación: `.lms_dev/api_map.md` | Todos los endpoints AJAX, webhooks y servicios externos mapeados con firma y respuesta | ⏳ Pendiente |
| M-6.14 | Code review final | Sin warnings PHP 8.1; sin deprecated API Moodle 4.5; code style conforme a Moodle CS | ⏳ Pendiente |

---

## 7. Estructura de Archivos del Plugin

```
enrol/mentorsubscription/
│
├── version.php                                      # Metadata: component, version, requires, maturity
├── lib.php                                          # Clase enrol_mentorsubscription_plugin
├── settings.php                                     # Config global admin
├── webhook.php                                      # Endpoint Stripe webhooks (firma HMAC)
│
├── classes/
│   ├── subscription/
│   │   ├── subscription_manager.php                 # Ciclo de vida; process_renewal(); get_history()
│   │   ├── pricing_manager.php                      # Override chain; resolve(userid, subtypeid)
│   │   └── stripe_handler.php                       # Checkout Session + procesamiento webhooks
│   │
│   ├── mentorship/
│   │   ├── mentorship_manager.php                   # add_mentee(); toggle_mentee_status()
│   │   ├── role_manager.php                         # ensure_parent_role; assign/unassign
│   │   └── enrolment_sync.php                       # enrol_mentee(); unenrol_mentee()
│   │
│   ├── task/
│   │   ├── check_expiring_subscriptions.php         # Scheduled: diaria 8am
│   │   └── sync_stripe_subscriptions.php            # Scheduled: horaria (fallback)
│   │
│   ├── event/
│   │   ├── mentee_enrolled.php                      # Evento: mentorado registrado
│   │   ├── mentee_unenrolled.php                    # Evento: mentorado eliminado
│   │   └── mentee_status_changed.php                # Evento: toggle activo/inactivo
│   │
│   ├── output/
│   │   ├── mentor_dashboard.php                     # Renderable: panel mentor
│   │   └── admin_subscription_panel.php             # Renderable: panel admin
│   │
│   ├── form/
│   │   ├── add_mentee_form.php                      # Moodleform: agregar mentorado
│   │   └── admin_subscription_form.php              # Moodleform: override por mentor
│   │
│   ├── observer.php                                 # Callbacks de eventos
│   ├── notification_manager.php                     # Envío Messaging API
│   └── privacy/
│       └── provider.php                             # GDPR compliance
│
├── db/
│   ├── install.xml                                  # Schema 5 tablas + índices
│   ├── access.php                                   # Capabilities
│   ├── tasks.php                                    # Scheduled tasks
│   ├── events.php                                   # Mapa eventos → observers
│   └── services.php                                 # AJAX external functions
│
├── templates/
│   ├── mentor_dashboard.mustache                    # Vista principal mentor
│   ├── mentee_card.mustache                         # Card mentorado + radio button
│   ├── limit_reached_card.mustache                  # Card upgrade al alcanzar límite
│   └── admin_panel.mustache                         # Panel administrador
│
└── lang/
    └── en/
        └── enrol_mentorsubscription.php             # Strings en inglés
```

---

## 8. Análisis de Rendimiento y Complejidades

| Operación | Complejidad | Índice Utilizado | Notas |
|---|---|---|---|
| ¿Mentor tiene suscripción activa? | O(log n) | `INDEX(userid, status)` | 1 query; índice compuesto resuelve ambos filtros |
| COUNT de mentorados activos | O(log n) | `INDEX(mentorid, is_active)` | COUNT sin full scan |
| ¿El mentorado ya tiene mentor? | O(1) | `UNIQUE(menteeid)` | Detectado en INSERT por constraint |
| Cursos a matricular | O(k) | Tabla `enrol_mentorsub_courses` | k = cantidad de cursos; tabla pequeña |
| Suscripciones próximas a vencer | O(log n) | `INDEX(status, period_end)` | Scheduled task sin full scan de historial |
| Lookup webhook por `stripe_sub_id` | O(log n) | `INDEX(stripe_subscription_id)` | Resolución directa en procesamiento |
| Historial de pagos de un mentor | O(log n) | `INDEX(userid)` + sort | Volumen pequeño por mentor |
| Override vigente para un mentor | O(log n) | `INDEX(userid, valid_from, valid_until)` | Máximo un resultado por `UNIQUE(userid, subtypeid)` |
| Des-matriculación masiva al expirar | O(m × k) | `INDEX(mentorid)` + loop cursos | m = mentorados, k = cursos; transacción única |

---

## 9. Dependencias y Tecnologías

| Tecnología / API | Versión | Uso en el Plugin |
|---|---|---|
| Moodle Core | 4.5+ | `$DB`, `$OUTPUT`, `$PAGE`, `$USER`, enrol, events, messaging, privacy |
| PHP | 8.1+ | Lógica del plugin; readonly properties, match expressions, named arguments |
| Stripe PHP SDK | v10+ | Checkout Session, Webhook verification, Subscription retrieval |
| Moodle enrol API | 4.5 | `enrol_user()`, `unenrol_user()`, instancias de enrolment |
| Moodle role API | 4.5 | `create_role()`, `role_assign()`, `role_unassign()`, `assign_capability()` |
| Moodle Messaging API | 4.5 | `message_send()`; respeta preferencias del usuario |
| Moodle Scheduled Tasks | 4.5 | Interfaz `\core\task\scheduled_task` |
| Moodle Events API | 4.5 | `\core\event\base`; observer pattern nativo |
| Moodle Privacy API | 4.5 | `\core_privacy\local\metadata\provider` |
| Moodle AJAX API | 4.5 | `external_function_parameters`; `Ajax::call()` en JS |
| Mustache Templates | 4.5 | Rendering sin PHP; auto-escape de output |
| PHPUnit | 9+ | Testing unitario de clases core |
| Behat | 3.x | Testing end-to-end de flujos de usuario |
| MySQL / MariaDB | 8.0 / 10.4+ | Transacciones InnoDB; soporte de constraints UNIQUE |

---

## 10. Estado de Aprobaciones

| Sección | Responsable | Estado | Fecha |
|---|---|---|---|
| Requisitos y Casos de Uso | Steve Jobs (Product Leader) | ✅ APROBADO | 2026 |
| Arquitectura General (Alternativa 3) | Software Architecture | ✅ APROBADO | 2026 |
| Schema de Base de Datos (3 tablas + 2 aux) | Data Structure Engineer | ✅ APROBADO | 2026 |
| Flujos de Negocio y Override Chain | Software Engineer | ✅ APROBADO | 2026 |
| Roadmap y Estimaciones | ArchitectLMS Team | ✅ APROBADO | Feb 2026 |
| Autorización inicio desarrollo M-0 | Cliente / Product Owner | ✅ EN DESARROLLO | Feb 2026 |
| Entrega Final (M-6 completo) | Cliente / Product Owner | ⏳ PENDIENTE | — |

---

*ArchitectLMS Team — Documento de Arquitectura Interna — v1.0 — 2026*
*Confidencial — Uso interno de desarrollo*