# Recurrente Elementor Form

Cobra con **Recurrente** (API oficial) desde los formularios de **Elementor Pro** y envía el correo del formulario **solo cuando el pago se confirma**.

**Autor:** [Jonathan Diaz](https://www.soydiaz.com) · Versión 1.2.0 · Licencia GPL-2.0-or-later

---

## Qué hace

| Necesidad | Cómo lo resuelve |
|---|---|
| Credenciales de Desarrollo y Producción | **Recurrente → Ajustes → Credenciales**: llave `sk_test_` (Sandbox) y `sk_live_` (Producción), secreto de firma del webhook por ambiente y botón **Probar conexión** (`GET /test`) |
| Elegir en cada formulario si cobra y en qué modo | Acción **"Recurrente (cobro)"** en *Acciones después de enviar*, con selector **Desarrollo / Producción** |
| Enviar el correo solo con pago exitoso | Las acciones marcadas (por defecto *Correo* y *Correo 2*) se retienen y se ejecutan cuando la API confirma el checkout como `paid` |
| Si algo falla, no enviar y avisar al cliente | El formulario muestra un error, no ejecuta ninguna acción retenida y queda registrado |
| Registro de cada envío y sus fallos | **Recurrente → Registros**: estado, historial, datos del formulario, petición/respuesta de la API, CSV, verificar pago y reenviar |
| Identificar el pago en la web y en Recurrente | Referencia única (`WEB-000123`) en el concepto del cobro, en la metadata, en los registros, en el correo y en *Envíos* de Elementor |
| Datos de Recurrente en *Envíos* de Elementor | Referencia, estado, monto, checkout, ID de pago y ambiente se guardan en el envío y salen en su exportación CSV |
| Evitar estafas | Confirmación siempre con la API, comparación de monto/moneda/ambiente/metadata, lista de montos permitidos, dominios de pago permitidos y límite por IP |

## Requisitos

- WordPress 5.8+ y PHP 7.4+
- **Elementor Pro 3.5+** (o Pro Elements). Probado con 4.4.
- Cuenta de Recurrente con llaves de API (TEST en un Sandbox y/o LIVE)

## Instalación

1. Sube `recurrente_elementor_form.zip` en **Plugins → Añadir nuevo → Subir plugin**, o copia la carpeta `recurrente_elementor_form` en `wp-content/plugins/`.
2. Actívalo. Se crea la tabla `{prefix}ref_logs` y una tarea programada horaria (`ref_cron_reconcile`).
3. Aparece el menú **Recurrente** en el escritorio (Registros y Ajustes).

## Configuración

### 1. Credenciales (Recurrente → Ajustes → Credenciales)

| Campo | Dónde se obtiene |
|---|---|
| Llave secreta de **Desarrollo (Sandbox)** | Recurrente → Configuración → Desarrolladores → **Abrir Sandbox** → *Crear mi llave TEST* (`sk_test_…`) |
| Llave secreta de **Producción** | Recurrente → Configuración → Desarrolladores (`sk_live_…`) |
| Secreto de firma del webhook | Panel de Webhooks (Svix) de cada ambiente (`whsec_…`) |
| X-ACCOUNT-ID | Opcional. Solo si cobras en nombre de una cuenta hija conectada |

- Pulsa **Probar conexión**: debe responder el nombre de tu cuenta y `sandbox` o `live`. Si la llave es del otro ambiente, avisa.
- Las llaves nunca se muestran completas; deja el campo vacío para conservar la guardada.

### 2. Webhook (recomendado)

Registra en Recurrente → **Configuración → Desarrolladores y API → Webhooks** (en la cuenta LIVE **y** dentro del Sandbox) la URL que aparece en Ajustes:

```
https://TU-SITIO/wp-json/recurrente-ef/v1/webhook
```

Eventos: `intent.succeeded`, `intent.failed`, `intent.pending`, `intent.canceled` (o los legacy `payment_intent.*`). Pega el `whsec_…` de cada ambiente en Ajustes.

> Sin webhook el plugin también funciona: confirma el pago cuando el cliente vuelve del checkout y, si cierra la ventana, en la revisión automática de cada hora.

### 3. El formulario de Elementor

1. Edita el formulario → **Acciones después de enviar** → añade **Recurrente (cobro)**. Mantén también *Correo electrónico* (y *Collect Submissions* si quieres guardar los envíos).
2. Abre la sección **Recurrente** que aparece en el panel:

| Opción | Descripción |
|---|---|
| **Modo** | Desarrollo (Sandbox) o Producción |
| **Origen del monto** | *Monto fijo*, *Tomado de un campo* (p. ej. un campo oculto `monto` que llenan botones) o *Producto/precio existente* (`prod_…` / `price_…`) |
| **Montos permitidos** | Con monto desde un campo: lista `150, 450, 850`. Cualquier otro valor se rechaza. **Recomendado siempre** |
| Cantidad (opcional) | ID de un campo con la cantidad (1–9) |
| Moneda | GTQ o USD (o la predeterminada en Ajustes) |
| Concepto del cobro | Admite shortcodes: `Donación [field id="nombre"]`. Se le añade `· Ref WEB-000123` |
| Imagen, cuotas, métodos de pago | Cuotas 3/6/12/18 (solo GTQ), tarjeta, transferencia, balance… |
| NIT / teléfono / dirección | Qué pide el checkout de Recurrente |
| Expiración | Minutos de validez del enlace de pago (0 = sin expiración) |
| Campo de correo / nombre | Para el registro (vacío = primer campo tipo correo) |
| **Acciones a retrasar hasta el pago** | Por defecto *Correo* y *Correo 2*. También webhook, Mailchimp, etc. |
| URL de gracias / de cancelación | Vacío = la de la acción *Redirigir* o la página del formulario con un aviso |
| Mensaje si no se puede crear el cobro | Lo ve el cliente en el formulario |

3. Consejo: en *Mensajes adicionales* cambia el mensaje de éxito a algo como **"Redirigiendo al pago…"**, porque se muestra un instante antes de abrir el checkout.

### 4. Datos del pago en el correo

Con **Ajustes → General → Datos del pago en el correo** activo, las acciones retenidas reciben estos campos:

| Shortcode | Ejemplo |
|---|---|
| `[field id="recurrente_reference"]` | WEB-000005 |
| `[field id="recurrente_status"]` | Pagado |
| `[field id="recurrente_amount"]` | Q450.00 |
| `[field id="recurrente_payment_id"]` | in_iiyiucoq |
| `[field id="recurrente_checkout_id"]` | ch_i79dqlcbqqczdu7z |

También aparecen en `[all-fields]`. Si la plantilla del correo es HTML propio, agrega las filas que necesites.

## Cómo funciona

```
Cliente envía el formulario
  └─ Elementor valida (requeridos, honeypot, reCAPTCHA)
      └─ Plugin crea el checkout (POST /checkouts) con referencia y metadata
          ├─ Falla → error en el formulario, nada se envía, queda en Registros
          └─ OK → se ejecutan solo las acciones NO retenidas (p. ej. Collect Submissions)
                 └─ El cliente va al checkout de Recurrente
                     └─ Paga → webhook / retorno / cron
                         └─ Plugin consulta GET /checkouts/{id}
                             ├─ paid + controles OK → ejecuta el correo (una sola vez) → "Pagado y enviado"
                             ├─ paid + inconsistencias → "Requiere revisión" (no se envía)
                             └─ unpaid / expired / failed → sigue esperando o se marca
```

### Estados de un registro

| Estado | Significado |
|---|---|
| Esperando pago | Checkout creado; el cliente aún no paga |
| Pago en proceso | Iniciado pero sin confirmar (p. ej. transferencia) |
| Pagado · enviando | Confirmado; ejecutando acciones retenidas |
| **Pagado y enviado** | Confirmado y correo/acciones enviados |
| Pagado · fallo de envío | Se cobró pero falló el correo u otra acción → botón *Reenviar* |
| **Requiere revisión** | Pago con inconsistencias; no se envió nada → revisar y *Aprobar y enviar* |
| Pago rechazado | Recurrente rechazó el cobro (el cliente puede reintentar en el mismo checkout) |
| Cancelado | El cliente volvió con *cancelar* |
| Expirado | El checkout venció sin pago |
| Error al crear cobro | No se pudo iniciar el pago; el cliente vio el error |

## Panel de registros

**Recurrente → Registros**

- Filtros por **formulario**, ambiente, estado y rango de fechas; búsqueda por referencia, correo, nombre, checkout o ID de pago.
- **Exportar CSV** con los filtros aplicados y una columna por cada campo del formulario.
- Detalle de cada registro: pago, datos del formulario, historial de eventos, petición/respuesta de la API, enlace al envío de Elementor.
- Acciones: **Verificar pago en Recurrente**, **Reenviar correo/acciones**, **Aprobar y enviar** (para *Requiere revisión*), eliminar.
- El menú muestra un contador con los registros que requieren atención.

## Integración con Envíos de Elementor

Si el formulario usa **Collect Submissions**, cada envío guarda también:

`Referencia web` · `Estado del pago` · `Monto cobrado` · `Checkout Recurrente` · `ID de pago Recurrente` · `Ambiente Recurrente`

Se actualizan solos cuando cambia el pago y salen en **Elementor → Envíos → Exportar**. El correo retenido aparece en el *Actions Log* del envío como "Correo electrónico · tras pago".

## Identificar un pago en ambos lados

| En la web | En Recurrente |
|---|---|
| Referencia `WEB-000123` (Registros, Envíos, correo) | Concepto del cobro: `… · Ref WEB-000123` y metadata `referencia_web` |
| ID de checkout `ch_…` | Mismo ID de checkout |
| ID de pago `in_…` | Mismo ID del pago (intent) |
| Datos que llenó el cliente | Metadata `campo_nombre`, `campo_email`, `campo_telefono`… |

El prefijo de la referencia se cambia en **Ajustes → General**.

## Seguridad

- **El pago se confirma siempre con la API** (`GET /checkouts/{id}`) usando la llave del ambiente del registro. Ni el navegador ni un webhook sin verificar pueden disparar el correo.
- **Controles antes de enviar:** la metadata `wp_log_id` y `referencia_web` deben coincidir, el ambiente (`live_mode`) debe ser el del formulario, la moneda igual y el monto pagado no menor al esperado. Si falla algo → *Requiere revisión* + alerta.
- **Montos permitidos** para montos que vienen de un campo (evita manipular el campo oculto).
- **Solo se redirige** a `https://app.recurrente.com` (o dominios propios agregados en *Ajustes → Seguridad*).
- Al volver del checkout se valida el `checkout_id` y un token aleatorio de 32 caracteres.
- **Webhook firmado** con Svix (HMAC-SHA256, tolerancia de 5 minutos). Sin secreto, cada aviso se verifica contra la API con límite de frecuencia.
- **Límite de cobros por IP** (5 cada 10 minutos por defecto).
- **Idempotencia**: `Idempotency-Key` al crear el checkout y bloqueo atómico en base de datos para que el correo salga una sola vez.
- Panel solo para administradores (`manage_options`), con nonces en todas las acciones y CSV protegido contra inyección de fórmulas.
- Las llaves se guardan en `wp_options` y nunca se muestran completas ni se registran en el log.

## Pruebas en Sandbox

1. Formulario en modo **Desarrollo** y llave `sk_test_` guardada.
2. Envía el formulario: debes llegar al checkout con la etiqueta **PRUEBA**.
3. Tarjetas: `4242 4242 4242 4242` (aprobada) y `4000 0000 0000 0002` (rechazada); cualquier fecha futura y CVC.
4. Verifica en **Registros** el paso a *Pagado y enviado*, el correo y el envío de Elementor.

Montos mínimos de Recurrente: **Q5** (GTQ) y **$1** (USD).

## Pasar a producción

1. Pega la llave `sk_live_` y pulsa **Probar conexión** (debe decir `live`).
2. Registra el webhook en la cuenta LIVE y guarda su `whsec_`.
3. Cambia el **Modo** del formulario a **Producción**.
4. Desactiva *Forzar modo desarrollo* si lo usaste.
5. Haz un cobro real pequeño y reembólsalo desde Recurrente.

## Hooks para desarrolladores

| Hook | Tipo | Uso |
|---|---|---|
| `ref_checkout_payload` | filtro | Ajustar el cuerpo de `POST /checkouts` (`$payload, $record, $settings, $mode`) |
| `ref_checkout_created` | acción | Checkout creado (`$log_id, $checkout_url, $record`) |
| `ref_payment_completed` | acción | Pago confirmado y acciones ejecutadas (`$log_id, $ok, $results`) |
| `ref_log_updated` | acción | Cambió el estado o el pago de un registro (`$log_id, $data`) |
| `ref_fraud_checks` | filtro | Añadir controles antifraude propios (`$problems, $log, $checkout`) |
| `ref_api_timeout` | filtro | Timeout de la API en segundos (30 por defecto) |

## Solución de problemas

| Síntoma | Revisa |
|---|---|
| "No pudimos iniciar el pago" | Registros → detalle: falta la llave del modo, monto bajo el mínimo, monto no permitido o error de la API |
| Pagado pero sigue en *Esperando pago* | Webhook no registrado o sin el evento `intent.succeeded`; pulsa **Verificar pago** o espera la revisión horaria |
| *Pagado · fallo de envío* | El correo falló (SMTP). Corrige y pulsa **Reenviar** |
| *Requiere revisión* | Lee el detalle (monto, ambiente o metadata). Si es legítimo, **Aprobar y enviar** |
| La llave responde el ambiente equivocado | Las llaves TEST solo existen dentro de un Sandbox; las LIVE en la cuenta de producción |
| No aparecen datos en Envíos de Elementor | El formulario debe tener la acción *Collect Submissions* |

## Estructura

```
recurrente_elementor_form/
├── recurrente_elementor_form.php      Arranque y autoloader
├── uninstall.php                      Limpieza (opcional)
├── readme.txt                         Formato del directorio de WordPress
├── README.md                          Este documento
├── assets/
│   ├── css/admin.css                  Estilos del panel (nativo wp-admin)
│   ├── css/front.css                  Aviso al volver del checkout
│   └── js/admin.js                    Probar conexión
└── includes/
    ├── class-plugin.php               Carga de módulos y activación
    ├── class-settings.php             Ajustes y validaciones
    ├── class-api.php                  Cliente de la API de Recurrente
    ├── class-logger.php               Tabla de registros y consultas
    ├── class-form-handler.php         Crea el checkout y retiene acciones
    ├── class-record-store.php         Guarda/reconstruye el envío para después del pago
    ├── class-deferred-ajax-handler.php Ejecuta acciones de Elementor fuera del AJAX
    ├── class-finalizer.php            Verifica el pago, antifraude y ejecuta acciones
    ├── class-webhook.php              Endpoint REST con firma Svix
    ├── class-return-handler.php       Retorno del checkout y avisos
    ├── class-submission-sync.php      Integración con Envíos de Elementor
    ├── class-notifier.php             Alertas por correo
    ├── class-cron.php                 Conciliación y limpieza horaria
    ├── actions/class-recurrente-action.php  Acción y controles del formulario
    └── admin/                         Panel (ajustes, registros, CSV)
```

## Historial de cambios

**1.2.0**
- Datos de Recurrente en *Envíos* de Elementor y su exportación; correo diferido en el *Actions Log*.
- Antifraude: monto, moneda, ambiente y metadata; estado *Requiere revisión*; dominios de pago permitidos; límite por IP; validación del `checkout_id` al volver.
- Filtro por formulario en Registros y CSV con una columna por campo.
- Panel con componentes nativos de wp-admin y ajustes en pestañas.

**1.1.0**
- Referencia web única en el concepto y la metadata del cobro; datos del formulario en la metadata; referencia en registros, correo y CSV.

**1.0.0**
- Versión inicial: credenciales Dev/Pro, acción de cobro, correo diferido, webhook, conciliación y registros.
