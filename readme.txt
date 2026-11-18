=== Recurrente Elementor Form ===
Contributors: soydiaz
Tags: elementor, recurrente, pagos, guatemala, formularios
Requires at least: 5.8
Requires PHP: 7.4
Tested up to: 6.8
Stable tag: 1.2.0
License: GPLv2 or later

Cobra con Recurrente desde los formularios de Elementor Pro y envía el correo solo cuando el pago se confirma.

== Descripción ==

Autor: Jonathan Diaz — https://www.soydiaz.com

* Panel «Recurrente» con credenciales de Desarrollo (Sandbox, sk_test_) y Producción (sk_live_), botón «Probar conexión» (GET /test) y secreto de firma del webhook por ambiente.
* Acción «Recurrente (cobro)» en «Acciones después de enviar» con modo por formulario (Desarrollo/Producción).
* Las acciones elegidas (por defecto Correo y Correo 2) se retienen y se ejecutan solo cuando Recurrente confirma el pago.
* Si no se puede crear el cobro, el formulario muestra un error al cliente, no se envía nada y queda registrado.
* Registro de cada envío con historial (checkout creado, webhook, retorno, pago, envío, fallos), filtros por formulario, ambiente, estado y fecha, detalle, CSV, verificación manual y reenvío.
* Referencia única (WEB-000123) en el concepto y la metadata del cobro, en los registros, en el correo y en «Envíos» de Elementor (también en su exportación).
* Antifraude: el pago se confirma siempre con la API; se comparan monto, moneda, ambiente y metadata; montos permitidos; solo se redirige a dominios de Recurrente; límite de cobros por IP.

Documentación completa en README.md.

== Instalación ==

1. Sube la carpeta `recurrente_elementor_form` a `/wp-content/plugins/` y activa el plugin.
2. Recurrente → Ajustes: pega la llave TEST (Sandbox) y/o LIVE y pulsa «Probar conexión».
3. Copia la URL del webhook (`/wp-json/recurrente-ef/v1/webhook`) y regístrala en Recurrente (cuenta LIVE y dentro del Sandbox). Pega el signing secret (whsec_…) de cada uno.
4. En el formulario de Elementor: «Acciones después de enviar» → añade «Recurrente (cobro)» (mantén también «Correo electrónico»).
5. En la sección «Recurrente» elige el modo, el monto y qué acciones retener hasta el pago.
6. Prueba en Desarrollo con la tarjeta 4242 4242 4242 4242 (éxito) o 4000 0000 0000 0002 (rechazo).

== Cómo se confirma el pago ==

Nunca se confía solo en el navegador ni en el webhook: el plugin consulta GET /checkouts/{id} y solo con `status = paid` ejecuta las acciones retenidas. Ocurre en lo que llegue primero: webhook, retorno del cliente a la URL de éxito o cron horario de conciliación. Un bloqueo atómico en base de datos evita envíos duplicados.

== Hooks para desarrolladores ==

* `ref_checkout_payload` (filtro): ajustar el cuerpo de POST /checkouts (p. ej. discount_code, customer_id).
* `ref_checkout_created` (acción): `$log_id, $checkout_url, $record`.
* `ref_payment_completed` (acción): `$log_id, $ok, $results`.
* `ref_log_updated` (acción): `$log_id, $data`.
* `ref_fraud_checks` (filtro): `$problems, $log, $checkout`.
* `ref_api_timeout` (filtro): segundos de timeout de la API.

== Changelog ==

= 1.2.0 =
* Datos de Recurrente (referencia, estado, monto, checkout, ID de pago) en «Envíos» de Elementor y su exportación; el correo diferido aparece en su Actions Log.
* Antifraude: se comparan monto, moneda, ambiente y metadata antes de enviar; estado «Requiere revisión»; solo se redirige a dominios de Recurrente; límite de cobros por IP; validación del checkout_id al volver.
* Filtro por formulario en registros y CSV con una columna por campo.
* Panel con componentes nativos de wp-admin.

= 1.1.0 =
* Referencia web única (WEB-000123) en el concepto y la metadata del cobro, datos del formulario enviados a Recurrente, validación cruzada de metadata, referencia en registros, correo y CSV.

= 1.0.0 =
* Versión inicial.
