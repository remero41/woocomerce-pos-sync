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
