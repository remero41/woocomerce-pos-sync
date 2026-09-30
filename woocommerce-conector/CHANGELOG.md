## Sin publicar

### El mismo precio en la web y en la tienda física

- **El TPV cobraba de más con algunas configuraciones de impuestos de
  WooCommerce.** El conector calculaba el precio sin IVA a partir de los
  ajustes de Woo y, si no eran los ideales (precios introducidos sin
  impuestos, sin tarifa para España...), la caja cobraba hasta un 21 % mas que
  la web. Ahora el conector manda lo que la web cobra al cliente y el TPV
  calcula el IVA con su propia configuracion. Necesita la API del TPV
  actualizada; con una anterior, todo sigue como antes.

- **Las rebajas de la tienda llegan al TPV**, y se quitan cuando terminan.

- **Editar un producto en el TPV ya no borra la rebaja de la web.**

### Los pedidos entran aunque el producto se haya borrado en el TPV

- **Si se borraba en el TPV un producto que seguía a la venta en la web, sus
  pedidos no llegaban nunca al TPV.** El TPV rechazaba el pedido entero y el
  conector lo reintentaba igual una y otra vez. Ahora el conector lo detecta,
  vuelve a enlazar el producto (o lo da de alta en el TPV si ya no está) y
  manda el pedido completo. Si lo borrado era una talla, la venta se apunta
  al producto.

### Las versiones nuevas se instalan solas

- Hasta ahora el conector avisaba de que habia una version nueva, pero solo se
  instalaba sola si en la tienda estaba activado «Actualizaciones
  automaticas» para el plugin. Si no, la tienda se quedaba en la version que
  tenia hasta que alguien entrara a actualizarla. Ahora el conector se
  actualiza solo (y solo el: los demas plugins siguen como esten).

### La reparación del catálogo no depende de tener muchas visitas

- La reparación automática (enlaces y fotos) avanzaba 25 productos cada vez
  que WordPress ejecutaba sus tareas, y WordPress solo lo hace cuando alguien
  visita la web. En una tienda con pocas visitas tardaba dias. Ahora cada
  pasada trabaja durante un tiempo fijo en vez de un numero fijo de productos:
  los que no tienen nada pendiente se revisan en milisegundos.

## 2.9.0

### Un pedido de la tienda ya nunca llega a medias al TPV

- **Pedidos incompletos o perdidos.** Si un producto del pedido no estaba
  enlazado con el TPV, esa linea se quitaba sin avisar: el pedido llegaba al
  TPV sin ella (con el total y el stock mal) o, si no quedaba ninguna, no
  llegaba nunca ("Sin productos mapeados al TPV"). Ahora el conector enlaza o
  da de alta ese producto en el TPV en el momento y el pedido llega entero.
  Si el TPV no lo acepta (por ejemplo, un precio negativo), el pedido queda
  retenido con una nota que dice que producto falta, y se envia solo en cuanto
  se pueda.

- **Todo lo que vendes online existe en el TPV.** Con el catalogo mandado por
  el TPV, un producto creado solo en la tienda antes se ignoraba; ahora se da
  de alta tambien en el TPV (a partir de ahi manda el TPV sobre el).

- **Los reembolsos de la tienda no llegaban al TPV.** Desde el 22-08 el TPV
  rechazaba todos los que mandaba el conector (un dato que ya no admite).
  Ahora llegan, y ademas: cada talla vuelve a su linea (y a su stock), dos
  tallas del mismo producto ya no se pisan, y un reintento solo manda lo que
  faltaba (antes podia devolver dos veces lo ya devuelto). Si un producto del
  reembolso no existe en el TPV, el reembolso queda pendiente con una nota; si
  el pedido llego incompleto al TPV antes de esta version, la nota pide
  revisarlo a mano.

- **Pedidos que se habian quedado fuera.** Los pedidos pagados que el conector
  descarto por no tener sus productos enlazados se envian ahora solos, una vez.
  Llevan una nota «Recuperado» que lo explica.

- **Los pedidos se gestionan en la tienda, no en el TPV.** El interruptor
  «Pedidos» del panel decia que controlaba el envio de pedidos, y no era
  verdad: se envian siempre. Lo que hacia era dejar que el TPV cambiara el
  estado de un pedido de la tienda y que una devolucion hecha en el TPV creara
  un reembolso en WooCommerce. Se quita: el TPV recibe las ventas, sus
  cancelaciones y sus reembolsos, pero no los cambia.

- **Quien factura.** El panel avisa de que las facturas de las ventas online
  las emite el TPV: no hay que emitirlas tambien desde WooCommerce.

- **Reintentos que se multiplicaban.** Cuando un envio de pedido o de
  reembolso fallaba y se reintentaba, cada intento creaba otro en la cola.
  Ahora hay uno solo, que cuenta sus intentos.

## 2.8.0

### El catalogo se repara solo

- **Enlaces e imagenes que faltan, sin tocar nada.** Hasta ahora, si un
  volcado o una subida de fotos fallaba, el producto se quedaba asi hasta que
  alguien volviera a guardarlo en WooCommerce, o pulsara "Enviar a TPV". Ahora
  el conector repasa el catalogo solo, cada 5 minutos y por tandas: enlaza con
  su producto del TPV los que perdieron el enlace (sin eso sus pedidos no
  llegan al TPV) y sube las fotos que no llegaron. Solo enlaza: no crea ni
  sobrescribe productos en ningun lado. Si el catalogo lo manda el TPV, las
  fotos no se empujan (van del TPV a la tienda).

- **Al guardar un producto sin enlace, se reconoce aunque cambiara el SKU.**
  El TPV recuerda que producto de la tienda es cada uno; antes solo se buscaba
  por SKU y, si habia cambiado, se daba de alta otro producto repetido.

- **El registro dice por que no subio una imagen**, no solo el codigo de error.

## 2.7.0

### El primer volcado dejaba el TPV sin fotos, sin stock y sin enlace

- **Los pedidos de WooCommerce no llegaban al TPV.** El volcado inicial manda
  los productos simples en bloque, y desde julio la API contesta a ese envio
  con otro formato. El conector no lo entendia y no guardaba el enlace entre
  el producto de la tienda y el del TPV, asi que al vender ese producto en la
  tienda el pedido se descartaba ("Sin productos mapeados al TPV"). Ahora se
  guarda el enlace. Hay que volver a pulsar "Enviar a TPV" una vez para
  recuperar el de los productos ya volcados.

- **Sin imagenes.** Los productos que iban en bloque no subian sus fotos.

- **Stock a 0 en el TPV.** El bloque no mandaba el stock. Ahora un producto
  nuevo llega con el stock de la tienda, y un producto que ya existe en el
  TPV conserva el suyo (el que se ha ido vendiendo en caja).

- **Productos sin gestion de inventario que acababan "Agotado".** Si en
  WooCommerce no llevas la cuenta de unidades ("Hay existencias"), el TPV
  ahora tampoco la lleva para ese producto: se vende sin descontar y nunca
  lo marca como agotado en la tienda. Antes cualquier cambio en el TPV, una
  venta en caja o la revision semanal podian dejarlo "Agotado" online.

- **"Enviar a TPV" solo enviaba los primeros 100 productos.** Ahora recorre
  el catalogo entero y enseña el avance.

## 2.6.0

### El catalogo llegaba al TPV con precios a cero y codigos inventados

- **Un producto con variantes viajaba a 0 EUR y cada variante cargaba el
  precio entero como sobreprecio.** En WooCommerce un producto variable no
  tiene precio propio -- el precio vive en cada variacion -- y el conector
  leia el del padre, que viene vacio. Resultado: el producto quedaba a 0,00
  en el TPV y quien lo vendiera sin elegir variante cobraba cero. Ahora el
  producto vale lo que su variante mas barata y cada variante suma solo la
  diferencia.

- **Al sobreprecio de las variantes no se le quitaba el IVA.** El precio del
  producto si viajaba neto, asi que el TPV volvia a aplicar el impuesto
  encima del sobreprecio. Ahora los dos se miden igual, con las reglas
  fiscales reales de cada variacion.

- **El TPV mostraba SKU que nadie habia escrito**, del tipo `__WC__48853`.
  Cuando el producto no tenia SKU en WooCommerce, el conector se inventaba
  uno y lo ponia tanto en el campo Modelo (que el TPV necesita) como en el
  SKU (que es el que se ve). Ahora el SKU llega vacio si en la tienda esta
  vacio. Hay un boton para limpiar los que ya se subieron.

- **Variantes sin codigo de barras.** Solo se guardaba el codigo de la
  primera variante de cada combinacion, asi que sus hermanas se quedaban con
  la columna vacia. Y cuando una variacion no tenia ni EAN ni SKU no se
  mandaba nada, cuando el numero que WooCommerce le asigna (#48907) es
  justamente el que muchas tiendas imprimen en la etiqueta. Ahora el orden
  es: EAN, si no el SKU que hayas escrito, si no el numero de la variacion.

- **Imagenes que no subian y no se reintentaban nunca.** Al sincronizar mas
  de 50 imagenes, las del segundo grupo en adelante se daban por subidas sin
  haberlo hecho, y como quedaban marcadas no se volvia a intentar. El mismo
  fallo afectaba a los productos con mas de 50 variantes, que podian guardar
  el vinculo en el producto equivocado.

### Reconciliar ya no puede machacar el lado bueno

- **La reconciliacion se disparaba sola al reconectar** tras una pausa, y
  decidia quien ganaba por la fecha de modificacion. Si el catalogo del TPV
  estaba mal, esos datos bajaban a la tienda sin que nadie lo pidiera. Ya no
  se lanza sola: la lanzas tu cuando quieras.

- **Ahora eliges quien manda, y por separado para stock y catalogo.** Lo
  normal es que el stock lo lleve el TPV (es donde se vende) y el catalogo
  quien lo mantenga. Un unico interruptor para las dos cosas no vale: "manda
  la tienda" aplicado al stock borraria lo que se acaba de vender en caja.

- **Nada se aplica sin verlo antes.** El boton de aplicar esta apagado hasta
  que simulas, y la simulacion te dice cuantos productos cambiarian y te
  enseña ejemplos sin tocar nada.

## 2.5.0

### Las devoluciones del TPV reponen el stock

- **Una devolucion hecha en caja no devolvia el stock a la tienda, y ni
  siquiera lo intentaba.** El aviso del TPV se descartaba en silencio
  porque el codigo esperaba un importe que ese aviso no trae; y aunque
  hubiera pasado, el reembolso se creaba sin pedir la reposicion, que en
  WooCommerce esta desactivada por defecto. Resultado: se devolvia el
  dinero y el stock se quedaba perdido, acumulando error con cada
  devolucion. Ahora se repone la cantidad exacta de la linea devuelta.

### La revision periodica recorre todo el catalogo

- **La reconciliacion semanal revisaba siempre los mismos 100 productos.**
  Con un catalogo de 2.499 eso es el 4%, y siempre el mismo: un producto
  desincronizado mas alla de esa primera pagina no se corregia nunca.
  Ahora continua por donde se quedo y cubre el catalogo entero.

## 2.4.3

- **El conector rechazaba la version de aviso que el TPV manda** (error 426).
  La firma ya se validaba bien, pero el siguiente control solo aceptaba la
  version 1 y el TPV manda la 2. Comprobado que el formato nuevo trae todo
  lo que el plugin necesita antes de aceptarlo.

## 2.4.2

- **Las entregas del TPV seguian rechazandose con error 401.** El conector
  exigia una cabecera `X-Webhook-Timestamp` que el TPV no manda: el
  timestamp viaja DENTRO de la firma (`t=...`), que es justo lo que la hace
  anti-replay. Ahora se lee de ahi. La proteccion anti-replay de +-5 minutos
  no se relaja.

## 2.4.1

- **El aviso de actualizacion tardaba hasta 12 horas en aparecer.** El
  plugin guardaba la respuesta de GitHub medio dia y no volvia a
  preguntar, ni pulsando «Comprobar de nuevo» (ese boton limpia la cache
  de WordPress, no la del plugin). Ahora se guarda una hora y el boton
  tambien la limpia.

## 2.4.0

### Las entregas del TPV ya se aceptan

- **El conector rechazaba TODOS los avisos del TPV con un error de firma.**
  Los dos lados firmaban de forma incompatible: el TPV usa el formato v2
  (`t=<ts>,v1=<mac>`) y el conector esperaba `sha256=<mac>`, ademas con un
  separador distinto. Medido en produccion: el TPV entregaba de verdad y la
  tienda devolvia 401 en cada intento. Ahora se acepta el formato v2, y
  tambien el antiguo para no romper TPVs sin actualizar.

## 2.3.0

### La sincronizacion TPV -> tienda ya se puede activar

Tres fallos encadenados impedian que el TPV avisara a la tienda de nada.
Tenian que caer los tres.

- **El endpoint que RECIBE los avisos daba 404.** La ruta /tpv-webhook/ se
  declara al arrancar, pero los enlaces permanentes se regeneraban en la
  activacion, ANTES de que la regla existiera: se regeneraban sin ella. Y
  al actualizar sobrescribiendo el ZIP, ese momento ni siquiera ocurre.
  Ahora el refresco se hace cuando la regla ya esta puesta.

- **Quedaba una segunda lista de eventos** sin el aviso de stock por talla,
  usada al re-registrar el webhook tras un fallo de firma. Eliminada: la
  lista vive en un solo sitio.

- El tercero estaba en el TPV: crear el webhook devolvia siempre error.
  **Requiere el TPV actualizado.**

## 2.2.0

### El stock por talla ya cruza en las dos direcciones

- **Vender una talla en caja ahora baja el stock en la tienda.** El TPV
  avisaba con `variant.stock_adjusted`, pero el conector no estaba
  suscrito y el aviso del producto padre no sirve: en WooCommerce el
  padre de un producto variable no gestiona stock, lo gestiona cada
  variacion. Medido antes del arreglo: vendias y el stock online seguia
  igual. ⚠️ Requiere el TPV actualizado (el evento no se podia suscribir).

- **Una venta online dice ahora que talla se ha vendido.** Cada linea
  viajaba a nombre del producto padre; la variacion, aunque estaba
  mapeada, no se mencionaba. El TPV descontaba del total sin saber cual
  salia. Verificado con una venta real: el stock de la talla baja y la
  venta queda atribuida a ella.

### Actualizaciones automaticas

- **El plugin se actualiza solo.** Hasta ahora WordPress no se enteraba
  de que habia version nueva (solo vigila wordpress.org) y habia que
  entrar a cada tienda a subir el ZIP a mano. Ahora aparece el aviso de
  siempre en Plugins y se actualiza con un clic, sin desinstalar y sin
  perder la configuracion.

### Correcciones

- **La caja del asistente prometia menos productos de los que subia**
  (1028 frente a 2499): contaba solo los publicados y el volcado sube
  tambien los borradores, que llegan al TPV como ocultos.

## 2.1.0

### Rendimiento del volcado inicial

- **Las imagenes viajan agrupadas.** Se subian de una en una: en un volcado
  medido en produccion, 625 de 782 peticiones eran imagenes (el 80%), y un
  producto con 8 imagenes se llevaba 8 peticiones. Ahora van en `POST /batch`
  (50 por llamada): esas 625 salen en 13. Medido: 6 imagenes = 1 peticion.

- **Las altas de productos con variantes tambien se agrupan.** El endpoint
  `/products/bulk` no acepta `options`, asi que cada producto con tallas o
  colores iba suelto — en una tienda de ropa, casi el catalogo entero. Ahora
  las altas se agrupan en `/batch`. Medido: 25 productos = 1 peticion.

### Honestidad

- **El contador del TPV ya no dice "0 productos" cuando falla.** Leia
  `meta.total ?? 0`, y ese `?? 0` convertia cualquier error en un cero
  creible: con la API devolviendo 400, la caja decia "0 productos" teniendo
  186 en el TPV. Ahora muestra "—" cuando no se puede saber.

- **Una imagen solo se marca como subida si el TPV lo confirma con 2xx.**
  Antes bastaba con que la respuesta trajera `data`. Si el batch no sale, no
  se marca nada y se reintenta en la siguiente pasada en vez de perderse.

- **Lo que el batch no contesta cuenta como error**, no como enviado.

# Changelog

Todas las versiones notables de este plugin. Formato: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versionado: [SemVer](https://semver.org/).

## [2.0.0] - 2026-04-24

### Fixed
- **BUG-WC-003** (MEDIA): race condition en idempotencia de webhooks. `get_transient + set_transient` no era atómico: con 10 webhooks concurrentes del mismo `idempotency_key`, 2-3 se procesaban como "nuevos". Ahora se usa una tabla dedicada `wp_tpv_sync_webhook_idem` con PK UNIQUE y `INSERT IGNORE`. Solo un proceso gana la inserción; los demás reciben `affected_rows=0` y responden con `duplicate: true`. Verificado con 10 webhooks concurrentes → 1 nuevo + 9 duplicados.

### Added
- Tabla `wp_tpv_sync_webhook_idem` creada automáticamente en activation hook y en `plugins_loaded` (con sentinela `tpv_sync_idem_table_v1`) para migrar instalaciones existentes sin downtime.
- Purga automática de entradas de idempotencia > 48 h integrada en el cron `tpv_sync_queue_purge` (diario).
- Endpoint e2e dev-only (`tests/e2e_api.php`) con 35+ acciones para suites de test externas. Requiere `TPV_SYNC_E2E_ENABLED=true` + header `X-Test-Secret`.
- Suite de tests e2e `cazabugs_200_wp.sh` (200 tests / 20 áreas) + `bugs_focused.sh` (TDD para bugs concretos).

### Changed
- `declare(strict_types=1)` añadido en todas las clases del plugin (`includes/*.php` y `woocommerce-conector.php`). Mejora el tipado estático y detección de bugs en PHPStan.
- Estructura de proyecto madurada: `composer.json` (dev-tools), `phpstan.neon` (level 5), `phpcs.xml` (WordPress-Extra), CI en `.github/workflows/ci.yml` (lint + static analysis + e2e).

### API del TPV (cambio no incluido en el plugin, documentado para trazabilidad)
- **BUG-WC-001** arreglado en la BD del TPV (`2465_api_clients.scopes`): añadido scope `stock:read` al client `woocommerce`. Antes: `GET /products/{id}/stock → 403 Forbidden`, causando que los cambios manuales de stock en WC se encolaran pero nunca llegaran al TPV.

## [1.x]

Desarrollo inicial. Ver historia en git.
