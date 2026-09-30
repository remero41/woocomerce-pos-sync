# Spec TDD — Pedidos y devoluciones de WooCommerce hacia el TPV

Fecha: 2026-09-29
Repos: `woocomerce-pos-sync` (plugin WP). La API (`api_tpv`) no se toca salvo que la decisión D1 lo pida.
Caso real: `lulubeauty.catinfog.com`. Volcado del 28-09 con los simples sin `_tpv_product_id`. Desde el TPV no se ve ningún `POST /orders`.

---

## 0. Reglas del trabajo

Son las mismas de `SPEC_sync_woo_tpv.md`: ley anti-vanidad (rojo antes, verde después, y el test **muerde al mutar**), el arnés `woocommerce-conector/tests/run.php` y la prueba contra WordPress y la API en memoria (patrón de `test_volcado_bulk.php`).

**Dinero.** Nada de este spec inventa semántica de dinero. Lo que no está decidido está en §4 y **se pregunta antes de programar**.

---

## 0.1 Principios decididos (usuario, 29/30-09-2026)

- **El amo de un pedido online es la tienda (Woo/PS).** El TPV **recibe** la venta y lo que la corrige desde la tienda (cancelación y reembolso). **No la gestiona**: nada de estados, envío ni seguimiento desde el TPV.
- **Todo lo que se vende en la tienda existe en el TPV**, en cualquier modo. «Quién manda» decide quién gana cuando los dos lados discrepan en un producto que existe en ambos; no decide si un producto existe.
- **El TPV factura lo online.** Toda venta de la tienda que entra en el TPV es una factura del TPV y va a Hacienda al entrar. La tienda no emite facturas propias.
- **Compra online y devolución en tienda física: sin cablear** de momento.

---

## 1. Estado actual verificado (leído en código)

| # | Síntoma | Causa | Sitio |
|---|---------|-------|-------|
| P1 | Pedido que llega al TPV **incompleto**: faltan líneas, el total y el stock no cuadran | La línea cuyo producto no tiene `_tpv_product_id` se descarta con `continue`, sin aviso | `class-order-sync.php:106-107` |
| P2 | Pedido que **nunca** llega | Si ninguna línea tiene enlace: `log('skip')` y `return`. No se encola y no se reintenta, ni siquiera cuando el enlace se repara | `:133-135` |
| P3 | La cola **se multiplica** | La cola reintenta `order.send` llamando a `send_to_tpv()`, que si falla **encola otra fila**; `enqueue()` no evita duplicados. Cada fila fallida engendra otra con `attempts=0`: nunca se abandona y el número de filas crece con cada reintento | `:220-227` + `class-queue.php:85-102` y `:229-234` |
| P4 | Lo mismo con las devoluciones | `on_wc_refund()` reencola `refund.send` al fallar una línea | `:328-335` |
| P5 | **Doble devolución** posible | Al reintentar se reenvía la devolución **entera**. Las líneas que ya entraron solo están protegidas por su clave de idempotencia, que caduca a las **24 h** (`IdempotencyStore`). El 6.º reintento de la cola cae a unas **29 h** (1+5+15+60+240+1440 min) | `:303-306` + `class-queue.php:34` |
| P6 | Devolución **incompleta** | Una línea devuelta cuyo producto no tiene enlace se salta con `continue`, y aun así la devolución se marca `_tpv_refund_synced` | `:296-297` |
| P7 | Dos líneas del mismo producto (dos tallas) en una devolución: **la segunda se pierde** | La clave `wc-refund-<refund>-<producto>` es la misma para las dos, así que la API devuelve la respuesta de la primera | `:305` |
| P8 | La devolución de una talla repone el stock del **padre** | La línea de devolución no manda la variante (`options`), a diferencia del pedido | `:307-316` |

Ya existe y está bien:
- La clave de idempotencia del pedido (`wc-order-<id>`) protege de duplicados dentro de las 24 h.
- `insufficient_stock` pone el pedido «en espera» y no reintenta.

---

## 2. Comportamiento objetivo

### F1 — Un pedido nunca llega a medias (P1, P2)

**F1.1 Asegurar el producto en el TPV en el momento** (`asegurarEnTpv`). Al enviar el pedido, para cada línea sin `_tpv_product_id`:
1. se busca su pareja con `resolverEnlace()` (la misma regla que el guardado y la autocuración: `external_id`, luego model, luego sku, sin robar uno ya enlazado a otro post). Si la hay, **solo se enlaza**: nada de PATCH, que en modo «manda el TPV» sobrescribiría el TPV;
2. si no existe, **se crea en el TPV** con el mismo alta que el guardado de un producto, en cualquier modo (principio 0.1).

La línea viaja normal.

**F1.1b Guardar un producto en modo «manda el TPV».** Un producto que solo existe en Woo (una «isla») ya no se ignora: se asegura en el TPV (F1.1). Si ya estaba enlazado, sigue revirtiéndose al estado del TPV como hasta ahora.

**F1.2 Si queda alguna línea sin asegurar, no se manda nada.** Por ejemplo, un precio negativo que el TPV rechaza. El pedido queda **pendiente de enlace**:
- meta `_tpv_order_pendiente` con la fecha y los productos que faltan;
- **una** nota en el pedido de Woo, no una por cada intento, que diga qué productos faltan;
- una línea en el registro.

**F1.3 Reintento sin tope de tiempo.** El mismo cron de la autocuración (cada 5 min) reintenta hasta 10 pedidos pendientes de enlace por pasada (`TPV_Sync_Order_Sync::reintentarPendientes`). Es barato porque solo mira los pedidos con el meta. No va por la cola: la cola abandona a las ~29 h y un enlace puede tardar más.

**F1.4 Al enviarse**, se borra `_tpv_order_pendiente` y se añade la nota de siempre («Registrado en TPV»).

**F1.5 Visibilidad.** El panel de salud cuenta los pedidos pendientes de enlace.

### F2 — La cola no se multiplica (P3, P4)

**F2.1** `enqueue()` no crea una fila si ya hay otra **pendiente** con la misma operación y el mismo payload: devuelve la existente.

**F2.2** Con F2.1, cuando la cola ejecuta un envío que falla y `send_to_tpv()` vuelve a encolar, recibe la fila que ya está en curso: no nace ninguna nueva y `attempts` sube. Así el abandono tras `MAX_ATTEMPTS` vuelve a funcionar. No hace falta más código que F2.1; T7 lo fija.

### F3 — Devoluciones completas y sin dobles (P5–P8)

**F3.1** Cada línea devuelta con éxito se recuerda en un meta del reembolso (`_tpv_refund_lineas`). Un reintento **solo manda las que faltan**, así que no depende de que la idempotencia no haya caducado.

**F3.2** La clave de idempotencia es **por línea de reembolso** (`wc-refund-<refund>-<item_id>`), no por producto.

**F3.3** Una línea de talla manda su variante (`options` con `product_option_value_id`, igual que `idsDeLinea()` del pedido).

**F3.4** Una línea sin enlace se intenta enlazar (F1.1). Si sigue sin enlace, la devolución **no se marca sincronizada**: queda pendiente, con nota, y la reintenta la autocuración (como F1.3).

### F4 — Recuperar los pedidos ya perdidos (P2 del pasado), automático (D2)

Se pasa **una vez** por los pedidos que el plugin registró como `skip` «Sin productos mapeados al TPV» (tabla `tpv_sync_log`). Están después de la conexión por definición y nunca se enviaron. A los que siguen sin `_tpv_order_id` y están pagados (processing/completed) se les pone el meta pendiente (F1.2) y F1.3 los envía. Si la comerciante ya los metió a mano, se duplicarán: decisión aceptada por el usuario.

### F5 — La venta online se factura en Hacienda al entrar (API + plugin)

Verificado en código:
- La API numera la factura en la serie del TPV al crear un pedido con medio de pago (`OrderController.php:921-930`, `pedido_asignar_factura`), pero **no dispara `on_order_complete`**, que es el hook del que cuelga VeriFactu (`plugin_verifactu/verifactu/bridge.php`). Solo lo disparan el cobro en caja y los `receipt*.php` al abrir o imprimir el ticket. Hoy, que una venta de Woo llegue a Hacienda depende de que alguien la imprima.
- La devolución por API **sí** dispara `on_refund_complete` (`ReturnController.php:503`): puede salir una rectificativa de una factura nunca registrada.

Objetivo:
- **F5.1** La API dispara `on_order_complete` con `pedido_contexto_fiscal()` tras el COMMIT de un alta con cobro, igual que la rectificativa: best-effort, y un fallo se registra sin romper el alta. Idempotente: el plugin fiscal no registra dos veces el mismo pedido.
- **F5.2** El plugin de la tienda avisa en su panel: «Las facturas de tus ventas online las emite el TPV. Desactiva la facturación de WooCommerce.»
- **Bloqueante:** no se activa VeriFactu en ninguna tienda con conector hasta que F5 esté hecho y probado en `ta`.

### Deuda anotada (fuera de este spec)

- **HPOS**: el plugin no declara compatibilidad y guarda los metas del pedido en `postmeta` por su ID. Es coherente consigo mismo, pero hay que migrar a `$order->get_meta()`/`update_meta_data()`.
- **TPV→Woo de estados de pedido**, cableado a medias y muerto (`update_wc_status`, y el puente del panel): quitarlo (principio 0.1).
- **Panel del TPV**: ocultar Gestionar, envío y seguimiento en los pedidos de conector, distinguidos por un **origen guardado en el pedido** (la API conoce el canal), no por el texto del comentario.

**Fuera de alcance, solo se detecta:** los pedidos que **ya llegaron a medias** (P1 del pasado). Corregirlos exige editar pedidos del TPV. Como mucho se lista cuáles son (se sabe comparando las líneas del pedido de Woo con las del TPV) y los revisa una persona.

---

## 3. Tests (cada uno con el cambio que lo haría fallar)

| Test | Rompe si… |
|------|-----------|
| T1 pedido con 1 línea enlazada y 1 sin enlace, que tampoco se puede enlazar: **no hay `POST /orders`**, sí meta pendiente y 1 nota | se vuelve al `continue` que descarta la línea |
| T2 la misma línea sin enlace **que sí casa** por external_id: se enlaza y el pedido sale con las 2 líneas | no se llama a resolverEnlace al enviar |
| T3 pedido pendiente: la 2.ª pasada de la autocuración, con el producto ya enlazado, lo envía y borra el meta pendiente | la autocuración no revisa los pedidos pendientes |
| T4 dos pasadas sin enlace: sigue habiendo **1 sola** nota en el pedido | se añade la nota en cada intento |
| T5 pedido sin ninguna línea enlazada: pendiente, no `skip` silencioso | se deja el `return` con `skip` |
| T6 `enqueue` dos veces lo mismo: 1 fila | falta la deduplicación |
| T7 la cola ejecuta un `order.send` que falla: no aparece una fila nueva y `attempts` sube | send_to_tpv reencola dentro de la cola |
| T8 devolución de 2 líneas donde la 2.ª falla; reintento: **solo se reenvía la 2.ª** | se reenvía la devolución entera |
| T9 devolución de 2 tallas del mismo producto: 2 `POST /returns` con claves distintas y cada uno con su variante | la clave es por producto, o no va la variante |
| T10 devolución con una línea sin enlace: no queda marcada como sincronizada | se vuelve al `continue` |
| T11 la recuperación marca como pendientes solo los pedidos con `skip` «Sin productos mapeados» | se recuperan otros pedidos, o ninguno |
| T12 línea cuyo producto no existe en el TPV: se crea con `POST /products` y el pedido sale completo | no se crea al vuelo |
| T13 en modo «manda el TPV», una línea que casa con un producto del TPV **solo se enlaza**: ningún PATCH | asegurarEnTpv hace PATCH |
| T14 en modo «manda el TPV», guardar una isla la crea en el TPV | se vuelve al `return false` de la isla |
| T15 la creación falla (el TPV rechaza el producto): el pedido queda pendiente con su nota | se manda el pedido sin esa línea |

Mutantes mínimos: uno por fila de la tabla. Criterio de cierre: la suite entera del plugin en verde.

---

## 3.1 Estado de la entrega

- **F1 + F2: HECHO** (rama `fix/pedidos-sin-enlace`). Tests T1-T7 y T12-T15, más: pedido sin líneas (no se retiene), cursor de reintento que vuelve a empezar y variación nunca enlazada ni dada de alta suelta. Suite 547/547; 14 mutantes muertos.
- **F3: HECHO** (worktree `fix/devoluciones-completas`, sin commit). Hallazgo nuevo **P9**: desde el 22-08 (api_tpv `bb2b474`) la API rechaza `return_status_id` ≠ 3 y el plugin mandaba 1, así que **ningún reembolso de Woo llegaba al TPV**. Arreglado sin mandar el campo. Cada línea se devuelve a su `order_product_id` (talla por `product_option_value_id`, sacado de `GET /orders/{id}`). La clave es por línea del reembolso, se recuerda lo ya devuelto (`_tpv_refund_lineas`) y un reintento solo manda lo que falta. Una línea que el TPV no acepta queda pendiente con nota; una línea ausente del pedido del TPV (pedido que llegó a medias antes) lleva nota «revísalo a mano» y no se reintenta. Un reembolso de un pedido retenido espera a su pedido. Mutantes: 15 muertos, 1 equivalente eliminado (R13).
- **F4: HECHO** (mismo worktree). `recuperarDescartados()`, una vez por instalación y al arrancar el reintento: solo los `skip` «Sin productos mapeados al TPV» pagados (processing/completed) que siguen sin pedido en el TPV y sin retener; nota «Recuperado». 8 mutantes muertos (Q4 y Q8 sobrevivieron al principio y destaparon un pendiente eterno y una nota duplicada).
- **F5.1: HECHO en la API** (worktree api_tpv `fix/fiscal-venta-online`, sin commit). `OrderController::registrarFiscal()` dispara `on_order_complete` con `pedido_contexto_fiscal()` tras el COMMIT de un alta con cobro; un fallo se registra sin tumbar el alta. `ApiPlugins::manager()` es el punto único y sustituible para tests; el cargador carga ahora `shared/pedido/fiscal.php`. Test de comportamiento real (alta contra BD + gestor de plugins que graba): 3/3, 5 mutantes muertos. VeriFactu ya era idempotente (`already_sent` si hay CSV o huella): el ticket impreso después no registra dos veces.
- **F5.2: HECHO** (worktree del plugin). `TPV_Sync_Admin::avisoFacturacion()` se muestra en «Qué se sincroniza».
- **Pendiente de validar en `ta`** antes de activar VeriFactu en tiendas con conector (F5 sigue siendo bloqueante hasta entonces).

### Hallazgos al margen (sin tocar)

- **El interruptor «Pedidos» del panel miente**: dice «Se crean en el TPV cuando se pagan», pero los pedidos se envían siempre. Solo controla la suscripción a `order.status_changed` (TPV→Woo), que es la mitad muerta a retirar. Propuesta: quitarlo junto con esa mitad.
- **Evento `order.created` duplicado**: la API emite el suyo y el bridge del conector del TPV (`plugin_wordpress_connector`) emite otro en `on_order_complete`. El plugin de Woo solo lo registra: es ruido, no un fallo.
- **PrestaShop** (`prestashop-pos-sync`): mismo P9 (`return_status_id` = 1): arreglado con su test en el worktree `fix/devolucion-status-api`, sin commit. Siguen en PrestaShop P5 (reenvío completo), P7 (clave por producto), la línea sin enlace dada por buena y P1/P2 de pedidos: llevar F1–F4 allí es otro trabajo.

## 4. Decisiones (tomadas)

- **D1** → **(c)**: crear en el TPV en el momento el producto que falte. Si el TPV lo rechaza, el pedido se retiene con aviso; nunca llega incompleto.
- **D2** → **recuperación automática** (F4).
- **D3** → **F1 + F2 primero**; luego F3, F4 y F5. F5 bloquea activar VeriFactu en tiendas con conector.
