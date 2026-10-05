# Frave Perú

Plugin de WordPress con los requisitos comerciales de Perú para la tienda Frave. Vive fuera del tema porque guarda datos de pedidos: si el tema cambia, los pedidos y su comprobante se conservan.

## Requisitos

- WordPress 6.9+, PHP 8.1+ y WooCommerce 10.8+ (validado con WooCommerce 11.1.2 y PHP 8.3.30).
- Checkout **clásico** (`[woocommerce_checkout]`). Si la página usa el bloque de Checkout, el plugin muestra un aviso en el administrador y los campos no aparecen.
- Compatible con HPOS (tablas de pedidos de alto rendimiento).

## Comprobante de pago en el checkout

El cliente elige **Boleta** o **Factura** en los datos de facturación:

| Comprobante | Campos obligatorios | Validación |
| --- | --- | --- |
| Boleta | Tipo de documento y número | DNI: 8 dígitos. Carné de extranjería: 8–12 letras o números. Pasaporte: 6–12 letras o números. |
| Factura | RUC y razón social | RUC: 11 dígitos, prefijo 10, 15, 16, 17 o 20 y dígito verificador (módulo 11 de SUNAT). |

- Los números se normalizan antes de validarse: se quitan espacios, puntos y guiones y se pasan a mayúsculas.
- La validación se hace en el servidor, así que funciona aunque JavaScript no cargue. Con JavaScript, solo se muestran los campos del comprobante elegido.
- El pedido guarda únicamente los datos del comprobante elegido, en los metadatos `_billing_voucher_type`, `_billing_id_type`, `_billing_id_number`, `_billing_ruc` y `_billing_business_name`.
- El perfil del cliente conserva ambos grupos de datos (`billing_*`), de modo que el siguiente checkout se rellena solo, elija boleta o factura.
- Los datos aparecen en la página de pedido recibido, en **Mi cuenta → Pedidos**, en los correos del pedido y en la pantalla de edición del pedido, donde también pueden corregirse.
- Los pedidos pueden buscarse en el administrador por DNI, RUC o razón social.

La validación comprueba el **formato** y el dígito verificador. No consulta a RENIEC ni a SUNAT si el documento existe o si el RUC está activo; eso corresponde al proveedor de facturación electrónica (OSE/PSE) que se elija.

## Libro de Reclamaciones virtual

El formulario sigue la estructura de la Hoja de Reclamación del Código de Protección y Defensa del Consumidor y su reglamento: datos del proveedor, identificación del consumidor reclamante, bien contratado, detalle y pedido, y observaciones del proveedor. **Frave debe validar el texto y el flujo con su asesoría legal** y revisar si hubo cambios normativos recientes.

### Puesta en marcha

1. Crea una página publicada (por ejemplo, «Libro de Reclamaciones») con el shortcode `[frave_libro_reclamaciones]`.
2. En **Reclamaciones → Ajustes**, completa razón social, RUC, domicilio y la página del libro. Opcionalmente, un correo de avisos distinto del correo del administrador.
3. En **Ajustes → Generales**, define la zona horaria **America/Lima**: las fechas de las hojas y los plazos se calculan con ella.
4. El tema Frave muestra el enlace «Libro de Reclamaciones» en el pie de todas las páginas, incluida la portada, cuando la página está configurada y publicada.
5. Configura el envío de correo del hosting (SMTP) y comprueba que llegan la constancia y la respuesta: el consumidor debe recibir la copia de su hoja.

Mientras falte algún dato, el administrador muestra un aviso con lo pendiente.

### Qué hace

- **Numeración correlativa por año** (`000001-2026`), única aunque dos personas envíen a la vez.
- **Constancia inmediata:** al registrar la hoja, el consumidor ve la confirmación con la hoja completa (imprimible o guardable en PDF) y recibe una copia por correo. Si el correo falla, la página se lo indica y el administrador puede reenviarla.
- **Aviso a la tienda** con enlace a la hoja; responder al correo escribe directamente al consumidor.
- **Plazo de respuesta:** 15 días hábiles contados desde el día siguiente, sin sábados, domingos ni feriados. La lista de feriados es conservadora (fijos nacionales, Jueves y Viernes Santo): si falta un feriado, el plazo calculado vence antes, nunca después. Añade los feriados confirmados de cada año con el filtro `frave_peru_holidays`; por ejemplo, confirma el 7 de junio, el 23 de julio, el 6 de agosto y el 9 de diciembre.
- **Respuesta desde el administrador:** en cada hoja se escribe la respuesta y se envía por correo al guardar. La hoja queda como «Respondida» con la fecha de comunicación, y el texto enviado pasa a la sección 4. El listado marca en rojo las hojas vencidas.
- **Conservación:** las hojas no pueden crearse, editarse ni borrarse desde el administrador; solo se registra la respuesta. Los datos del proveedor se copian en cada hoja al registrarla. Desactivar o borrar el plugin no elimina las hojas.
- **Accesibilidad y robustez:** funciona sin JavaScript, conserva lo escrito cuando hay errores, lista los errores con enlaces a cada campo y marca los campos inválidos.
- **Antispam sin afectar a la caché:** campo trampa, tiempo mínimo de 3 segundos firmado con la sal de WordPress y un máximo de 10 hojas por hora por IP. No usa nonce, que caducaría en páginas cacheadas. La página del libro envía cabeceras `no-cache` y define `DONOTCACHEPAGE`.
- Si el cliente inició sesión, se rellenan sus datos de contacto y el documento guardado en el checkout.

### Almacenamiento

Cada hoja es una entrada privada del tipo `frave_reclamo`. Metadatos: `_frave_claim` (envío original, no se modifica), `_frave_claim_number`, `_frave_claim_type`, `_frave_claim_status` (`pendiente` o `respondida`), `_frave_claim_due`, `_frave_claim_response`, `_frave_claim_response_date`, `_frave_claim_response_draft` y `_frave_claim_receipt_sent`. El contador anual vive en la opción `frave_peru_claims_counter_{año}`.

## Formulario de contacto

Shortcode `[frave_contacto]`. El tema Frave lo añade solo en las páginas con la plantilla **Contacto** (filtro `frave_peru_is_contact_page`).

- Campos: nombre, correo, teléfono (opcional), tema, número de pedido (opcional), mensaje y consentimiento de datos con enlace a la política de privacidad publicada. Los temas se cambian con el filtro `frave_peru_contact_subjects`.
- Cada mensaje se guarda en **Mensajes** (tipo `frave_mensaje`, privado) y se envía a la dirección de **Mensajes → Ajustes** o, si está vacía, al correo del administrador. Responder al correo escribe al remitente. El listado indica si el aviso por correo falló.
- No se envía copia al remitente: permitiría usar el formulario para mandar correo a cualquier dirección.
- Los mensajes se pueden borrar (por ejemplo, ante una solicitud de eliminación de datos); no se pueden crear ni editar desde el administrador.
- Mismo antispam que el Libro de Reclamaciones (`includes/antispam.php`), con un límite de 5 mensajes por hora por IP. Si el cliente inició sesión, se rellenan su nombre, correo y teléfono.

## Términos y condiciones en el checkout

WooCommerce muestra la casilla «Acepto los términos» en cuanto hay una página de términos asignada, aunque esté en borrador. El plugin la oculta, junto con su validación, hasta que la página esté publicada.

## Decisión pendiente para Frave

El plugin exige documento también para la boleta. SUNAT solo obliga a identificar al comprador en boletas de más de S/ 700, pero pedir el documento siempre simplifica la entrega y la emisión. Si Frave prefiere que sea opcional por debajo de ese importe, hay que ajustar `frave_peru_validate_checkout()` en `includes/checkout-fields.php`.

## Instalación local

La carpeta del plugin está enlazada con una unión de directorio (*junction*) en `wp-content/plugins/frave-peru`, igual que el tema:

```powershell
New-Item -ItemType Junction -Path 'D:\Frave-web\.local-wordpress\wordpress\wp-content\plugins\frave-peru' -Target 'D:\Frave-web\frave-peru'
```

En producción, sube la carpeta `frave-peru` a `wp-content/plugins/` y actívala en **Plugins**.

## Estructura

| Ruta | Responsabilidad |
| --- | --- |
| `frave-peru.php` | Cabecera del plugin, compatibilidad declarada con WooCommerce y carga de módulos. |
| `includes/documents.php` | Tipos de comprobante y documento, normalización y validación de DNI, CE, pasaporte y RUC. |
| `includes/checkout-fields.php` | Campos del checkout, validación, limpieza del pedido y presentación en pedido, correos y administrador. |
| `includes/antispam.php` | Campo trampa, tiempo mínimo firmado y límite por IP compartidos por los formularios públicos. |
| `includes/contact-form.php`, `includes/contact-admin.php` | Formulario de contacto, almacenamiento de mensajes, aviso por correo y administración. |
| `includes/claims.php` | Libro de Reclamaciones: tipo de entrada, numeración, plazos, hoja HTML y correos. |
| `includes/claims-form.php` | Shortcode `[frave_libro_reclamaciones]`, validación, antispam y confirmación. |
| `includes/claims-admin.php` | Ajustes, listado de hojas, respuesta al consumidor y avisos del administrador. |
| `assets/checkout-fields.js` | Muestra los campos del comprobante elegido y adapta el teclado del número de documento. |

Los estilos del formulario del libro están en el tema (`assets/src/css/app.css`, bloque «Libro de Reclamaciones»). Con otro tema, el formulario sigue siendo usable como HTML semántico.
