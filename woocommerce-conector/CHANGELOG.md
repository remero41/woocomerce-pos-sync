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
