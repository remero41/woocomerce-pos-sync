# Spec TDD — Categorías sincronizadas en los dos sentidos (Woo ↔ TPV)

Fecha: 2026-10-14 (v2, tras la auditoría de la v1 el mismo día)
Repos: `woocomerce-pos-sync` (plugin de la tienda), `api_tpv` (API), `plugin_wordpress_connector` y `plugin_prestashop_connector` (conectores del lado TPV: ámbitos y bridge) y un retoque mínimo en `tpv` (§5.4).
Caso real: lulubeauty (14-10-2026). «Las categorías no sincronizan». Es para **todas** las tiendas conectadas, no solo para ella.

---

## 0. Reglas del trabajo

Las de siempre: ley anti-vanidad (rojo, verde y muerde al mutar), arnés `tests/run.php` con WordPress y la API en memoria en el plugin, suites de `api_tpv` contra BD real. Diseño simple: sin botones nuevos, sin simulaciones, sin opciones de configuración.

## 1. El invariante

> **Una tienda conectada y su TPV tienen el mismo conjunto de categorías, enlazadas una a una, y cada producto enlazado tiene las mismas categorías en los dos lados.**

- El enlace vive **en la API**, en `api_external_mapping_category` (canal, cliente, id externo ↔ `category_id`), igual que productos (`api_external_mapping`) y clientes (`api_external_mapping_customer`). El termmeta `tpv_category_id` de Woo es una **caché local** del enlace: se reconstruye desde la API y nunca es la fuente.
- El nombre solo se usa en la unión inicial (§4) y con **una sola regla de normalización** en los dos lados (§1.1).
- La posición en el árbol (categoría madre) se copia en cada alta y en cada cambio. La unión inicial no la iguala (§4).
- Las categorías van **siempre en los dos sentidos**, con independencia de `tpv_sync_principal` («quién manda» solo gobierna catálogo y stock).

### 1.1 Normalización de nombre (la misma función en el plugin y en la API)

`normalizar(nombre)` = recortar espacios de los extremos → minúsculas → **quitar acentos y diacríticos** (NFD y fuera las marcas) → colapsar espacios interiores. «Lencería», «LENCERIA » y «lenceria» son el mismo nombre. Es la regla que WordPress ya aplica de hecho (`term_exists` por colación), y la API la aplica **en PHP** sobre las hermanas cargadas, nunca con `=` en SQL (la colación de la columna no es un contrato).

## 2. Estado actual verificado (código y producción, 14-10-2026)

| # | Hecho | Sitio |
|---|------|-------|
| E1 | La tienda **no se suscribe** a `category.created/updated/deleted`: no están en la lista desde el commit inicial. La API los admite, el bridge del TPV los emite y el handler de Woo los trata, pero no llegan nunca | `class-admin.php:3594` (`eventosSuscritos`); `WebhookController.php:45`; `bridge.php:703-750`; `class-webhook-handler.php:591-598` |
| E2 | Woo → TPV no manda categorías: ni el guardado suelto, ni `/products/bulk`, ni `/batch` leen `product_cat`. La API **solo lee** categorías (`GET /categories` en árbol y solo activas; `GET /categories/{id}/products`). `/products/bulk` no escribe `product_to_category` ni lee `category_ids`. El mensaje del panel «🏷️ Sincronizando categorías…» no es verdad | `push_wc_product_to_tpv`; `routes.php:254-260`; `ProductController.php:906-930`; `class-admin.php:1651` |
| E3 | TPV → Woo por producto (`sync_categories`): casa **por nombre** con `get_term_by('name')` (colación: ciego a mayúsculas y acentos), sin categoría madre (todo en la raíz) y sin el termmeta. Renombrar en el TPV crea otro término; dos subcategorías con el mismo nombre se funden | `class-product-sync.php:2750`; también el fallback por nombre de `sync_category_from_tpv` (`:143`) |
| E4 | `wp_set_object_terms` sin `append`: las categorías del TPV **sustituyen** a las de Woo, pero solo si el TPV trae alguna (`!empty`). Quitar todas en el TPV no se refleja | `class-product-sync.php:490`, `:2768` |
| E5 | La API asigna categorías a un producto en POST y PATCH (`category_ids`; en PATCH sustituye), **no en el bulk**. Solo la plantilla de sector crea categorías, en la raíz, sin `category_path` y comparando el nombre con `=` (colación) | `ProductController.php:537`, `:1155`; `SectorTemplate.php:157` |
| E6 | El TPV borra en cascada (`cat_del` → `category_son_del`) y avisa `on_category_deleted` de cada una. No borra las filas de `product_to_category` (quedan huérfanas; la API las oculta con el JOIN). El CSV del TPV **crea categorías sin disparar `on_category_created`** | `tpv/admin/classes/category.php:323-366`; `upload.php:1971`, `:1987` |
| E7 | Los **ámbitos se congelan por cliente** al crearlo, y **los escribe fijos el conector del TPV**, no la API: `products:read products:write stock:read stock:write orders:read orders:write returns:read returns:write webhooks:read webhooks:write customers:read categories:read`. Nunca han incluido `customers:write` ni `categories:write` (este ya existe en `Auth::SCOPES`, «reservado»). El cliente de lulubeauty (`wc_8414e1062261`, `channel='woocommerce'`) tiene exactamente esa lista. Un ámbito nuevo da 403 en toda tienda ya conectada | `plugin_wordpress_connector/wordpress_connector/config.php:207`; `plugin_prestashop_connector/prestashop_connector/config.php:207`; `Auth.php:56`; `api_clients.scopes` en prod |
| E8 | `GET /categories` se cachea 300 s con etiqueta `category:*`, que solo invalida `EventLogger` (eventos `source=api`). Los eventos del TPV los escribe el bridge directo en `api_events` → una categoría creada en el TPV tarda hasta 5 min en salir en `GET /categories`. La clave de caché se construye con `['lang']` fijo, no con la query real | `CategoryController.php:24`, `:69`; `Cache.php`; `bridge.php:106` |
| E9 | La resuscripción al webhook (`revisarSuscripcionWebhook`, `faltanEventos`) corre solo en `admin_init` (cuando alguien abre el admin de WP) y una vez por versión. `ajax_reset_sync` borra `_tpv_product_id` y nada más | `woocommerce-conector.php:417`; `class-admin.php:3498-3525` |
| E10 | El TPV manda **siempre** la lista entera de campos (`CAMPOS_FICHA`) en `product.updated`: no se puede saber si cambiaron las categorías | `tpv/admin/classes/product.php:1864` |
| E11 | lulubeauty: el TPV tiene 81 categorías con varios niveles, creadas por ella desde el 18-09; su webhook (id 1, 29-09) no incluye `category.*` ⇒ 39 altas, 17 cambios y 16 bajas nunca llegaron. 814 de los 816 productos que vinieron de Woo no tienen categoría en el TPV. La web tiene 116 categorías: su árbol propio + las del TPV creadas en la raíz por E3. Los productos 7944 y 7962 solo tienen ya la categoría del TPV en la web (probable pérdida por E4). Nombres con espacio final en el TPV («TONICO », «ZAPATILLAS ») | medido 14-10 |

## 3. Comportamiento objetivo — marcha normal

Activo cuando la tienda tiene la marca `tpv_sync_categorias_unidas = 'arbol'` o `'completa'` (§4). **Sin marca, el plugin no toca categorías en ningún sitio**: ni crea términos, ni sustituye las de un producto (nada de «como hoy», que es E3 + E4). Los avisos `category.*` se aplican desde la marca `'arbol'`; la sustitución por producto (C3) desde `'completa'`. Con `'arbol'`, un aviso de producto del TPV **junta** sus categorías con las de Woo, y un envío Woo → TPV solo lleva `category_ids` en las **altas** (en una actualización se omite y el TPV conserva las suyas).

### C1 — TPV → Woo: categorías

- La tienda se suscribe a `category.created`, `category.updated` y `category.deleted` (se añaden a `eventosSuscritos`). `revisarSuscripcionWebhook` pasa a correr **también desde el cron** (`tpv_sync_autocurar`), no solo en `admin_init`: una tienda ya conectada se suscribe sin que nadie abra el admin.
- `created`/`updated`: **siempre** `GET /categories/{id}` (el payload del bridge y el de la API tienen formas distintas; no se confía en él). Se busca el término por `tpv_category_id`; si no existe, se crea. Se ponen nombre y categoría madre (la madre se resuelve por su `tpv_category_id`; si no está en Woo, se trae y se crea antes, recursivamente). Se registra el enlace en la API (`PATCH /categories/{id}` con `client_external_id`) y en el termmeta.
- Si WordPress rechaza el alta por **duplicada entre hermanas** (`term_exists`): no se enlaza a la hermana (ya tiene dueña); se registra y el panel lo enseña en «Categorías sin pareja». La comerciante las funde.
- Una categoría **inactiva** en el TPV (`status=0`) se sincroniza igual: Woo no tiene estado de categoría; una vacía no se ve en la tienda (`hide_empty`).
- `deleted`: se borra el término enlazado. El TPV ya manda un aviso por cada subcategoría (E6), así que no hace falta cascada en Woo.

### C2 — Woo → TPV: categorías

- Hooks `created_product_cat`, `edited_product_cat` → `POST /categories` o `PATCH /categories/{id}` con nombre, categoría madre (por su `tpv_category_id`, asegurándola antes si no está enlazada) y estado 1. El `category_id` devuelto se guarda en el termmeta.
- `PATCH` → 404 (la del TPV ya no existe): se vuelve a crear con `POST` y se reenlaza. 409 (nombre choca con una hermana en el TPV): se registra y el panel lo enseña; Woo y TPV quedan con nombre distinto hasta que la comerciante lo arregle.
- Borrado: hook `pre_delete_term` (el termmeta ya no existe cuando se dispara `delete_product_cat`) → `DELETE /categories/{id}`; 404 cuenta como éxito. La API sube las hijas un nivel, igual que hace WordPress (§5).
- La categoría por defecto de Woo (`default_product_cat`, «Sin categorizar») **nunca** se sincroniza: ni se crea en el TPV ni viaja en las categorías de un producto. Si una categoría cuelga de ella, en el TPV va en la raíz.
- Anti-eco: mientras se escribe algo que llegó del TPV se activa `$GLOBALS['tpv_sync_skip_wc_category_push']` y los hooks no envían nada. En la API, el evento lleva `origin_client_id` y el dispatcher no se lo devuelve a quien lo provocó (en marcha desde la auditoría del 07-10; la columna existe en lulubeauty).

### C3 — Las categorías de un producto, en los dos sentidos

- **TPV → Woo** (`upsert` desde un aviso): las categorías del producto se resuelven por `category_id` → término (asegurándolo si falta, como en C1) y **sustituyen** a las de Woo, también cuando la lista viene vacía (corrige E3 y E4). Se quitan el fallback por nombre de `sync_category_from_tpv` y `sync_categories` por nombre.
- **Woo → TPV** (`push_wc_product_to_tpv`, `/products/bulk`, `/batch`): se manda `category_ids` con los `tpv_category_id` de los términos del producto (asegurando los que falten con C2), sin «Sin categorizar». La API sustituye en PATCH y **también en el bulk** (§5).
- Gana el último cambio. No hay fusión en marcha normal. **Limitación conocida (E10):** cualquier aviso del TPV (hasta un cambio de precio) reescribe las categorías de Woo; si un `PATCH` de categorías desde Woo falló y no se reintentó, ese aviso lo revierte. El push de producto no tiene cola; no se añade en esta spec.
- `reconcileBidirectional` cuenta como **volcado** (junta), no como aviso.

## 4. La unión inicial — `unirCategorias()`

Una sola rutina, **solo aditiva**: no mueve, no renombra y no borra nada.

1. Lee los dos árboles: `GET /categories?todas=1` (plano, inactivas incluidas, sin caché) y los términos `product_cat` (sin «Sin categorizar»).
2. Empareja, en PHP, con `normalizar()` (§1.1), **en dos pasadas**: (a) los ya enlazados (termmeta o `external_id` en la API); (b) mismo nombre **y** misma madre (por nombre normalizado; raíz = raíz); (c) mismo nombre solo, en orden de `category_id` del TPV, con el término libre de menor `term_id`. Cada categoría se empareja como mucho una vez. Las parejas se registran en la API (`client_external_id`) y en el termmeta.
3. Lo que solo está en el TPV se crea en Woo; lo que solo está en Woo se crea en el TPV (`POST /categories` con `client_external_id`). Siempre las madres antes que las hijas, cada una dentro de la madre que tiene en su lado. Un alta que WordPress rechace como duplicada entre hermanas se registra y se enseña (C1).
4. Las emparejadas se quedan donde están en cada lado, aunque sea en sitios distintos. Se igualan la próxima vez que alguien las mueva (C1/C2).
5. Al acabar 1-4 se guarda la marca `'arbol'`: desde aquí los avisos `category.*` se aplican (no se pierde lo que cambie mientras se hacen los productos).
6. Productos enlazados: **se juntan** las categorías de los dos lados y se escriben en ambos (Woo: `wp_set_object_terms` con la unión; TPV: `PATCH category_ids` con la unión). Juntar no quita nada. Los productos sin enlace no se tocan.
7. Al acabar se guarda la marca `'completa'`.

Cuándo se ejecuta:

- **Tiendas ya conectadas** (al actualizar el plugin): como primera fase de `autocurar()` (cron cada 5 min, candado y presupuesto de tiempo ya existentes), por tandas con cursor: primero el árbol, después los productos enlazados. Solo empieza si `/health` anuncia `categories_write` **y** el token tiene el ámbito (una llamada de prueba `GET /categories?todas=1` con 403 → se registra «falta el ámbito categories:write» en el panel y no se insiste hasta la siguiente vuelta).
- **Tiendas nuevas** (asistente de sincronización inicial): el árbol se une como **primer paso**, antes de los productos, en los dos sentidos (`ajax_import` y `ajax_push_all`), con el `set_time_limit(600)` que ya tienen. Después, los volcados masivos juntan: `import_all` junta en Woo, y el bulk/batch Woo → TPV manda `category_ids` solo en las altas. La marca `'completa'` no la pone el asistente: la pone el cron cuando termina de juntar los productos enlazados (paso 6), igual que en las tiendas ya conectadas. Un solo camino para acabar la unión.

Regla para recordarla: **los volcados, la reconciliación y la unión juntan; los avisos sustituyen.**

**Reset, desconexión y reconexión** (`ajax_reset_sync`, `ajax_disconnect`, `ajax_full_disconnect`): borran los termmeta `tpv_category_id` y la marca, igual que hoy borran `_tpv_product_id`. Al reconectar, la unión reconstruye el termmeta desde los `external_id` de la API antes de emparejar por nombre.

## 5. API (`api_tpv`)

### 5.1 Tabla y ámbito

- `api_external_mapping_category` (`tpv_category_id`, `channel`, `client_id`, `external_id`, fechas; `UNIQUE (channel, client_id, external_id)`), calcada de `api_external_mapping_customer`. En `install.sql`, idempotente.
- Ámbito `categories:write`: ya está en `Auth::SCOPES`. Se añade, con `customers:write`, a la lista fija de los dos conectores del TPV (`config.php:207` de `plugin_wordpress_connector` y de `plugin_prestashop_connector`) y a la lista por defecto de `AdminConnectorsController`. **Migración** idempotente en `install.sql` que añade `categories:write`, `customers:write` e `imports:write` a los clientes con `channel IN ('woocommerce','prestashop')` que no los tengan: sin ella, toda tienda ya conectada recibe 403 (E7).

### 5.2 Rutas

| Ruta | Qué hace |
|---|---|
| `GET /categories?todas=1` | Lista plana con `category_id`, `parent_id`, `name`, `status`, `external_id` (del cliente que pregunta), inactivas incluidas. **Sin caché.** Sin `todas`, igual que hoy (árbol, activas); la clave de caché pasa a incluir la query real |
| `GET /categories/{id}` | Una categoría, inactivas incluidas, con `external_id`. Sin caché |
| `POST /categories` | `{name, parent_id, status?, client_external_id?}`. Idempotente **por `client_external_id`** (si ya hay enlace para ese canal+cliente+id, devuelve esa con 200) y, si no viene, por nombre normalizado bajo la misma madre (comparado en PHP, §1.1; 200). Crea `category`, `category_description` (idioma `API_LANGUAGE_ID`, nombre recortado), `category_to_store` y `category_path` como el TPV (`category.php` alta). 201 al crear. Registra el enlace si viene `client_external_id` |
| `PATCH /categories/{id}` | Nombre, madre, estado, `client_external_id`. Rehace `category_path` como `re_path_categories` del TPV (también en las descendientes). 409 si el nombre normalizado choca con una hermana; 422 si la madre es ella misma o una descendiente; 404 si no existe |
| `DELETE /categories/{id}` | Borra **solo esa** categoría: sus hijas suben a su madre (como WordPress), con `category_path` rehecho; se quitan sus filas de `product_to_category` y su enlace. Sin eventos de producto. 404 si no existe |

- Cada escritura emite `category.created/updated/deleted` con `source='api'` y `origin_client_id` e invalida `category:*`. Las hijas que suben emiten `category.updated`.
- `/health` anuncia `categories_write`.

### 5.3 Productos

- `/products/bulk` acepta `category_ids` por item (alta: inserta; actualización: sustituye), como POST/PATCH.
- `GET /products/{id}` sigue devolviendo `categories[]` con `category_id` y `name`.

### 5.4 TPV (`tpv`), retoque mínimo

- `upload.php` (importación CSV): disparar `on_category_created` al crear una categoría (`:1971`, `:1987`), con el mismo contexto que `category.php:284`. Sin esto, una categoría creada por CSV sin productos enlazados nunca llega a Woo (E6).
- El bridge (`plugin_wordpress_connector/wordpress_connector/bridge.php`, y el de PrestaShop si comparte el patrón), al registrar un evento `category.*`, **borra de `api_cache` las entradas con etiqueta `category:*`** (capa BD; la copia APCu de la API caduca sola en ≤60 s), para que `GET /categories` no sirva un árbol viejo (E8).

## 6. Tests

**Plugin (`tests/`):**
- `faltanEventos` / `eventosSuscritos` incluyen `category.*`; la revisión corre desde el cron.
- `normalizar()`: acentos, mayúsculas, espacios de los extremos e interiores; «Lencería» = «LENCERIA » = «lenceria».
- Unión, como función pura (`planUnion(arbolTpv, arbolWoo)` → parejas + altas en cada lado, con orden madre→hija): las tres pasadas, cada uno una vez, nada se mueve, «Sin categorizar» fuera. Caso fijo con los datos de lulubeauty: **58 emparejadas, 23 altas en Woo, 58 altas en el TPV**; «LENCERIA» (bajo ROPA INTERIOR) no se lleva la «Lencería» de la raíz de la web.
- Unión de productos: la suma de los dos lados sin repetidos y sin «Sin categorizar».
- Marcas: sin marca no se toca nada; con `'arbol'` se aplican avisos y no se sustituye por producto; con `'completa'` se sustituye.
- C1/C3: un aviso con categoría desconocida la trae (`GET /categories/{id}`) y la crea con su madre; una lista vacía quita todas; renombrar en el TPV no crea término nuevo; el payload no se usa.
- C2: los hooks mandan POST/PATCH/DELETE; con la guarda activa no mandan nada; «Sin categorizar» nunca sale; `PATCH` 404 → `POST`; `DELETE` 404 = éxito.
- Reset borra termmeta y marca; la unión reconstruye el termmeta desde `external_id`.
- Sin `categories_write` en `/health`, o con 403, la unión no empieza y el panel lo dice.

**API (suite `categories_write`):** alta con `category_path` correcto en 1, 2 y 3 niveles; alta repetida por `client_external_id` → la misma (200); alta repetida por nombre normalizado bajo la misma madre → la misma (200), y «Lencería» vs «LENCERIA» bajo madres distintas → dos; PATCH mueve y rehace la ruta de la categoría y de sus descendientes; 409/422/404; DELETE sube las hijas, limpia `product_to_category` y el enlace; `todas=1` incluye inactivas y no sirve el árbol cacheado; eventos con `origin_client_id`; `/health` con la capacidad; bulk con `category_ids`; la migración de ámbitos añade solo los que faltan.

**TPV:** el CSV que crea una categoría dispara `on_category_created`.

## 7. Despliegue y lulubeauty

Orden: **API (con la migración de ámbitos en `install.sql`) → conectores del TPV y TPV → release del plugin.** Sin la API, el plugin espera (§4).

lulubeauty está en la **2.9.0**: no tiene la actualización forzada (entró en la 2.10.0), así que necesita **una actualización manual**. La release arrastra además lo que hay en `main` sin publicar (PVP fase 2, auditoría del 07-10, productos a peso).

Lo esperado tras actualizar (datos del 14-10): 58 categorías emparejadas (COSMETICA↔CÓSMETICA y Bolsos↔BOLSOS incluidas), 23 creadas en la web, 58 creadas en el TPV, categorías juntadas en los 907 productos enlazados. Queda «ROPA NFANTIL» (errata) sin pareja, y las categorías que el plugin viejo dejó en la raíz de la web siguen ahí hasta que las mueva. **Avisarla antes**: a partir de entonces, borrar una categoría en un lado la borra en el otro.

## 8. Decisiones

- **D1 (14-10):** los dos sentidos, no «manda uno». Las categorías ignoran `tpv_sync_principal`.
- **D2 (14-10):** el borrado se propaga siempre, también con productos dentro («a lo hecho pecho»).
- **D3 (14-10):** nada de botones, simulaciones ni ganadores: la unión es automática y solo aditiva, porque es para toda la flota.
- **D4 (14-10, auditoría):** una sola normalización de nombre, con acentos plegados.
- **D5 (14-10, auditoría):** el enlace vive en la API (`api_external_mapping_category`); el termmeta es caché.
- **D6 (14-10, auditoría):** se toca el TPV lo mínimo: el CSV avisa de las categorías que crea; el bridge del conector invalida la caché.
- **D7 (14-10, auditoría):** migración que añade los ámbitos que faltan a los clientes de canal existentes.

Fuera de esta spec: el conector de PrestaShop (usará las mismas rutas y la misma tabla), imágenes y descripciones de categoría, multi-idioma y una cola para el push de producto.
