# Spec TDD — Sincronización WooCommerce → TPV: SKU, variantes, imágenes y reconciliación

Fecha: 2026-09-25
Repos: `woocomerce-pos-sync` (plugin WP), referencia `prestashop-pos-sync`, API `api_tpv`.
Caso real: `pineapplemoda.catinfog.com` (2.510 productos, 1.019 publicados).

---

## 0. Reglas del trabajo

**Ley anti-vanidad.** Un fix solo cuenta si un test lo reproduce ANTES (rojo), pasa DESPUÉS
(verde), y **muerde al mutar** la línea arreglada. Test que sigue verde con el código mutado =
test inservible, se reescribe.

**Arnés.** `woocommerce-conector/tests/run.php`, el que ya existe: PHP plano, sin composer ni
PHPUnit, sin cargar WordPress. Los tests prueban **funciones puras**. Todo lo que este spec
pide extraer se extrae precisamente para poder probarlo sin WP y sin red.

**Orden obligatorio.** F0 (red de seguridad) va PRIMERO. Los fixes de datos (F1–F4) no se
tocan hasta que F0 esté verde, porque cambiar identificadores sin control de dueño es lo que
duplica catálogos.

**Regla dura del usuario: no afectar a Woo.** Ninguna tarea de este spec escribe en el
catálogo de WooCommerce. Woo es la fuente de la verdad en este caso; el plugin lee de Woo y
escribe en el TPV. Verificado hoy: el push no escribe `_sku` en ningún punto
(`generate_sku_from_slug` existe en el fichero pero **no tiene llamadas** — código muerto).
Los únicos post_meta que se escriben son de control interno del plugin (`_tpv_product_id`,
`_tpv_images_sent`), nunca campos de catálogo.

---

## 1. Estado actual verificado (no supuesto)

Leído en código, no inferido:

| # | Síntoma | Causa raíz | Sitio |
|---|---------|-----------|-------|
| 1 | SKU con `__WC__` | Fallback inventado cuando Woo no tiene SKU; se escribe además en el campo `sku` | `class-product-sync.php:1235` y **duplicado** en `:957` |
| 2 | SKU de variante ausente | Sí se manda, pero solo como `barcode` y solo el PRIMERO por combinación | `:1479`, `:1562` |
| 3 | Faltan imágenes | `batch()` trocea de 50 en 50 y concatena resultados; `index` reinicia a 0 en cada trozo pero se indexa contra `$ops` global | `class-api-client.php:365` + `class-product-sync.php:1843` |
| 4 | Producto a 0 € y variantes con todo el precio como extra | `$basePrice = padre->get_regular_price()`, que en un variable de Woo está VACÍO → 0 | `:1467`, `:1518` |
| 5 | Reconciliación sin dueño elegible | `reconcile()` = solo stock, TPV gana cableado. `reconcileBidirectional()` decide por fecha y se dispara **sola** desde el panel | `:649`, `:2223`, `class-admin.php:3385` |

**Hallazgo que abarata la migración.** La API del TPV ya persiste
`api_external_mapping (channel, client_id, external_id → tpv_product_id)` y el plugin ya manda
`client_external_id = post_id` de WP (`ProductController.php:53`). **El vínculo Woo↔TPV NO
depende del SKU.** Por tanto se puede cambiar el SKU sin romper mapeos ni duplicar productos.
Esto invalida el plan inicial de "migrar los `__WC__` a mano".

---

## 2. Cómo lo hacen los conectores que ya funcionan

Contestando a la pregunta directa. Revisado `prestashop-pos-sync` (`TpvSyncProduct.php:1809`) y
el patrón estándar de la industria (Shopify↔ERP, Lightspeed, Vend):

**a) Nadie reconcilia "todo" con un único dueño.** El patrón universal es **dueño por dominio**,
no por plataforma, porque los dominios tienen dueños naturales distintos:

- **Stock** → manda quien vende físicamente (el TPV). Es el único dato que se decrementa por
  un hecho del mundo real. PrestaShop lo tiene igual de cableado que Woo, con el mismo
  comentario ("TPV gana siempre").
- **Catálogo** (nombre, precio, SKU, variantes, imágenes) → manda quien lo mantiene. Aquí, Woo.
- **Pedidos** → no se reconcilian, se acumulan. No hay dueño.

**b) Last-write-wins por fecha es lo que hay hoy, y es la peor opción.** `reconcileBidirectional`
compara `post_modified` vs `date_modified` con margen de 60 s. Los ERP serios lo abandonaron:
las fechas mienten (una reindexación de Woo toca `post_modified` sin que nadie edite nada), y
el resultado no es predecible para el comerciante. Se mantiene solo como modo explícito, nunca
como default.

**c) Lo que sí hacen todos: `dry-run` obligatorio.** Ninguna herramienta seria escribe 2.510
productos sin enseñar antes qué va a cambiar. Es el requisito que falta por completo hoy.

**Conclusión para tu pregunta ("¿reconciliación parcial y decidir quién tiene razón?").** Sí,
pero la decisión no se toma producto a producto a mano — son miles. Se toma **por dominio,
una vez**, y la herramienta enseña el impacto antes de aplicarlo. La reconciliación parcial
existe como *filtro* (solo estos productos), no como *interrogatorio*.

---

## 3. F0 — Reconciliación con dueño explícito (PRIMERO)

### Diseño

Función pura nueva, sin WP ni red, que concentra TODA la decisión:

```php
TPV_Sync_Reconciler::decidir(array $wc, array $tpv, array $politica): array
```

- `$politica = ['stock' => 'tpv'|'woo'|'ninguno', 'catalogo' => 'woo'|'tpv'|'ninguno']`
- Devuelve `['accion' => 'push'|'pull'|'nada', 'campos' => [...], 'motivo' => '...']`

El valor de esto: la decisión deja de estar repartida entre `reconcile()`,
`reconcileBidirectional()` y el panel, y pasa a ser una función que se puede probar exhaustivamente.

### Tests (rojo primero) — `tests/test_reconcile_dueno.php`

| Test | Assert |
|------|--------|
| `politica catalogo=woo NO pisa el nombre de Woo con el del TPV` | `accion === 'push'`, `campos` incluye `name` |
| `politica catalogo=woo NO toca el stock` | `campos` NO contiene `quantity` |
| `politica stock=tpv baja el stock a Woo aunque catalogo sea woo` | dos acciones independientes, una por dominio |
| `politica stock=ninguno + catalogo=ninguno = no hacer nada` | `accion === 'nada'` (modo auditoría) |
| `dry-run no produce ninguna operacion de escritura` | la lista de ops devuelta está vacía y el informe tiene N filas |
| `un producto solo en Woo con catalogo=tpv NO se borra de Woo` | nunca se emite borrado (regla "no afectar a Woo") |
| `el default es stock=tpv, catalogo=woo` | sin config, la política es la sensata |

**Mutantes que deben morir:**
- `'stock' => 'tpv'` → `'woo'` en el default ⇒ debe romper el test del default.
- Quitar el guard de dominio (que catálogo arrastre `quantity`) ⇒ debe romper el test 2.
- `dry-run` que devuelve ops en vez de informe ⇒ debe romper el test 5.

### Implementación

1. `TPV_Sync_Reconciler` nuevo, puro.
2. `reconcile()` y `reconcileBidirectional()` pasan a **delegar** en él. No se duplica lógica.
3. **Desactivar el disparo automático** de `class-admin.php:3385`. Hoy puede machacar Woo con
   los datos malos del TPV sin que nadie lo pida. Pasa a ser un botón explícito.
4. UI: selector de dueño por dominio + botón "Simular" que SIEMPRE se ejecuta antes de aplicar,
   mostrando "voy a cambiar N productos" con 10 ejemplos.

---

## 4. F1 — Precio de variantes

### Regla

Si el producto es variable: `precio_padre = MIN(precios de variantes)` y
`extra_variante = precio_variante − precio_padre` (siempre ≥ 0, prefijo `+`).

Efecto en el caso real: producto a 0 € con 3 variantes a `+24,14` pasa a producto a 24,14 € con
3 variantes a `+0`.

### Tests — `tests/test_precio_variantes.php`

Función pura a extraer: `calcularPrecioBase(array $preciosVariantes, float $precioPadre): float`
y `calcularExtra(float $precioVariante, float $base): array{price, price_prefix}`.

| Test | Assert |
|------|--------|
| `padre sin precio y variantes a 24,14 → base 24,14 y extras a 0` | el caso de la captura |
| `variantes a 20, 25 y 30 → base 20, extras 0, 5 y 10` | el caso general |
| `padre CON precio propio menor que el minimo → gana el del padre` | no se rompe el caso simple |
| `producto sin variantes → base = precio del padre` | sin regresión |
| `nunca se genera un extra negativo` | invariante: `price_prefix` siempre `+` |
| `precios con decimales no acumulan error de centimo` | 19,99 / 29,99 → extra exacto 10,00 |

**Mutantes:** `MIN` → `MAX` ⇒ muere. Quitar el `abs()`/guard de negativo ⇒ muere.

**Decisión pendiente de confirmar contigo:** si luego añades en Woo una variante MÁS BARATA, el
precio base del producto cambia y con él todos los extras. Es correcto pero implica que tocar
una variante reescribe el producto entero en el TPV. Lo asumo salvo que digas lo contrario.

---

## 5. F2 — SKU del producto y de las variantes

### Reglas

**Producto padre:**
- `model` ← GTIN, si no SKU de Woo, si no un técnico. **El TPV lo exige no vacío y único**, así
  que el fallback técnico se queda (sin él, el producto no se puede crear).
- `sku` ← SKU de Woo, y **vacío si Woo no tiene SKU**. Deja de duplicar el fallback. Este es el
  cambio que quita la "mierda" visible.

**Variantes:** hoy solo viaja `barcode` y solo el primero por combinación. Se manda el SKU de
cada variación como código de la variante (el TPV tiene `product_option_value_code`, verificado
en `ProductController.php:1750`), y se deja de perder el de las combinaciones repetidas.

### Migración sin tocar Woo

Gracias a `api_external_mapping` el vínculo no depende del SKU:
- Los productos ya sincronizados conservan `_tpv_product_id` → siguen siendo UPDATE, no INSERT.
- Se emite un PATCH que limpia el `sku` de los que hoy llevan `__WC__…`.
- **En Woo no se escribe nada.** Si un producto no tiene SKU en Woo, sigue sin tenerlo.

### Tests — `tests/test_sku_mapeo.php`

Extraer función pura `mapearIdentificadores(string $gtin, string $sku, int $postId): array`
— y con ella **eliminar la duplicación** entre `:1229` y `:955`, que es la razón de que un fix
aquí pueda aplicarse a medias.

| Test | Assert |
|------|--------|
| `con GTIN y SKU → model=GTIN, sku=SKU` | preferencia correcta |
| `sin GTIN y con SKU → model=SKU, sku=SKU` | |
| `sin GTIN ni SKU → model=tecnico, sku=VACIO` | **el fix**: el sku ya no lleva `__WC__` |
| `el model tecnico es unico por post_id` | sin colisiones |
| `las dos rutas de push producen el MISMO mapeo` | mata la duplicación `:1229`/`:955` |
| `una variante sin SKU no pisa el barcode de su hermana` | el bug del primero-gana |
| `dos variaciones con el mismo nombre conservan ambos codigos` | dedup de `syncOptions` |

**Mutantes:** devolver `$fallback` en el campo `sku` ⇒ muere. Mutar solo UNA de las dos rutas de
push ⇒ el test de paridad muere (este es el que evita el fix a medias).

---

## 6. F3 — Bug de índices en el batch de imágenes

### Causa

`batch()` trocea de 50 en 50 y concatena; cada trozo numera su `index` desde 0. El consumidor
indexa contra `$ops` global ⇒ con >50 imágenes se marcan como subidas URLs que nunca se
enviaron, **y no se reintentan jamás** porque quedan en `_tpv_images_sent`.

### Tests — `tests/test_imagenes_batch.php` (ampliar el existente)

| Test | Assert |
|------|--------|
| `120 imagenes → los resultados se casan con la URL correcta` | el del bug: URL 51 ≠ URL 1 |
| `una imagen fallida NO se marca como enviada` | debe reintentarse |
| `una imagen fallida en el SEGUNDO lote no marca la del primero` | el bug exacto |
| `reintento tras fallo parcial no duplica las ya subidas` | idempotencia |

**Mutante:** volver a indexar por `index` global ⇒ muere.

**Verificación en producción (no destructiva).** El código ya registra cada fallo como `warn` en
`tpv_sync_log`. Con acceso de solo lectura puedo confirmar si este bug ya mordió en
pineapplemoda, contando filas `warn` de imagen. Lo confirmo antes de dar el fix por bueno.

---

## 7. Qué NO entra

- No se escribe en el catálogo de Woo. Regla dura.
- No se toca `syncOptions` de la API salvo que los tests demuestren que el TPV pierde datos que
  el conector sí manda.
- No se borra nada en ninguna de las dos puntas.
- `generate_sku_from_slug` es código muerto: se marca, no se usa. Borrarlo es aparte.

---

## 8. Orden y criterio de "hecho"

1. **F0** reconciliación con dueño + dry-run + desactivar el disparo automático.
2. **F1** precio de variantes.
3. **F2** SKU padre y variantes + deduplicar las dos rutas de push.
4. **F3** índices del batch de imágenes.

Cada bloque: test rojo → fix → verde → mutación que muerde. Nada se da por terminado sin que
**tú lo veas en tu navegador** sobre pineapplemoda y lo confirmes. Sin commit ni push salvo que
lo pidas.
