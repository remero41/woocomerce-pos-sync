# Spec TDD — Categorías sincronizadas en los dos sentidos (Woo ↔ TPV)

Fecha: 2026-10-14
Repos: `woocomerce-pos-sync` (plugin) y `api_tpv` (API). El TPV (`tpv`) no se toca.
Caso real: lulubeauty (14-10-2026). «Las categorías no sincronizan». Es para **todas** las tiendas conectadas, no solo para ella.

---

## 0. Reglas del trabajo

Las de siempre: ley anti-vanidad (rojo, verde y muerde al mutar), arnés `tests/run.php` con WordPress y la API en memoria en el plugin, suites de `api_tpv` contra BD real. Diseño simple: sin botones nuevos, sin simulaciones, sin opciones de configuración.

## 1. El invariante

> **Una tienda conectada y su TPV tienen el mismo conjunto de categorías, enlazadas una a una, y cada producto enlazado tiene las mismas categorías en los dos lados.**

- El enlace es **por número**: cada término `product_cat` de Woo guarda el `category_id` del TPV en el termmeta `tpv_category_id` (ya existe: `TPV_Sync_Product_Sync::TPV_CATEGORY_TERM_META`). El nombre solo se usa una vez, en la unión inicial (§4).
- La posición en el árbol (categoría madre) se copia en cada alta y en cada cambio. La unión inicial no la iguala (§4).

## 2. Estado actual verificado (código y producción, 14-10-2026)

| # | Hecho | Sitio |
|---|------|-------|
| E1 | La tienda **no se suscribe** a `category.created/updated/deleted`: no están en la lista desde el commit inicial. La API los admite, el bridge del TPV los emite y el handler de Woo los trata, pero no llegan nunca | `class-admin.php:3594` (`eventosSuscritos`); `WebhookController.php:45`; `bridge.php:703-750`; `class-webhook-handler.php:591-598` |
| E2 | Woo → TPV no manda categorías: ni el guardado suelto, ni `/products/bulk`, ni `/batch` leen `product_cat`. La API **solo lee** categorías (`GET /categories` en árbol y solo activas; `GET /categories/{id}/products`). El mensaje del panel «🏷️ Sincronizando categorías…» no es verdad | `push_wc_product_to_tpv`; `routes.php:254-260`; `class-admin.php:1651` |
| E3 | TPV → Woo por producto (`sync_categories`): casa **por nombre**, sin categoría madre (todo en la raíz) y sin el termmeta. Renombrar en el TPV crea otro término; dos subcategorías con el mismo nombre se funden | `class-product-sync.php:2750` |
| E4 | `wp_set_object_terms` sin `append`: las categorías del TPV **sustituyen** a las de Woo, pero solo si el TPV trae alguna (`!empty`). Quitar todas en el TPV no se refleja | `class-product-sync.php:490`, `:2768` |
| E5 | La API sí asigna categorías a un producto (`category_ids` en POST y PATCH; en PATCH sustituye). Solo la plantilla de sector crea categorías, en la raíz y sin `category_path` | `ProductController.php:537`, `:1155`; `SectorTemplate.php:157` |
| E6 | El TPV borra en cascada (`cat_del` → `category_son_del`) y avisa `on_category_deleted` de cada una. No borra las filas de `product_to_category` (quedan huérfanas; la API las oculta con el JOIN) | `tpv/admin/classes/category.php:323-366` |
| E7 | lulubeauty: el TPV tiene 81 categorías con varios niveles, creadas por ella desde el 18-09; su webhook (id 1, 29-09) no incluye `category.*` ⇒ 39 altas, 17 cambios y 16 bajas nunca llegaron. 814 de los 816 productos que vinieron de Woo no tienen categoría en el TPV. La web tiene 116 categorías: su árbol propio + las del TPV creadas en la raíz por E3. Los productos 7944 y 7962 solo tienen ya la categoría del TPV en la web (probable pérdida por E4) | medido 14-10 |

## 3. Comportamiento objetivo — marcha normal

Activo cuando la tienda tiene la marca `tpv_sync_categorias_unidas` (§4). Antes de esa marca, los avisos `category.*` se registran y no se aplican, y los productos se comportan como hoy (la unión lo recoge todo después).

### C1 — TPV → Woo: categorías

- La tienda se suscribe a `category.created`, `category.updated` y `category.deleted` (se añaden a `eventosSuscritos`). Las tiendas ya conectadas los reciben al actualizar gracias a `faltanEventos()`, sin hacer nada.
- `created`/`updated`: se busca el término por `tpv_category_id`; si no existe, se crea. Se ponen nombre y categoría madre (la madre se resuelve por su `tpv_category_id`; si no existe aún en Woo, se trae con `GET /categories/{id}` y se crea antes, recursivamente). El estado inactivo del TPV no borra nada en Woo (Woo no tiene estado de categoría).
- `deleted`: se borra el término enlazado. El TPV ya manda un aviso por cada subcategoría (E6), así que no hace falta cascada en Woo.

### C2 — Woo → TPV: categorías

- Hooks `created_product_cat`, `edited_product_cat` → `POST /categories` o `PATCH /categories/{id}` con nombre, categoría madre (por su `tpv_category_id`, asegurándola antes si no está enlazada) y estado 1. Se guarda el `category_id` devuelto en el termmeta.
- Borrado: hook `pre_delete_term` (el termmeta ya no existe cuando se dispara `delete_product_cat`) → `DELETE /categories/{id}`. La API sube las hijas un nivel, igual que hace WordPress (§5).
- La categoría por defecto de Woo (`default_product_cat`, «Sin categorizar») **nunca** se sincroniza: ni se crea en el TPV ni viaja en las categorías de un producto.
- Anti-eco: mientras se escribe algo que llegó del TPV se activa `$GLOBALS['tpv_sync_skip_wc_category_push']` y los hooks no envían nada. En la API, el evento lleva `origin_client_id` y el dispatcher no se lo devuelve a quien lo provocó (ya funciona así desde la auditoría del 07-10).

### C3 — Las categorías de un producto, en los dos sentidos

- **TPV → Woo** (`upsert` desde un aviso): las categorías del producto se resuelven por `category_id` → término (asegurándolo si falta, como en C1) y **sustituyen** a las de Woo, también cuando la lista viene vacía (corrige E3 y E4). Se respeta «Sin categorizar» si WordPress la pone sola.
- **Woo → TPV** (`push_wc_product_to_tpv`, `/products/bulk`, `/batch`): se manda `category_ids` con los `tpv_category_id` de los términos del producto (asegurando los que falten con C2), sin «Sin categorizar». La API ya sustituye en PATCH (E5).
- Gana el último cambio. No hay fusión en marcha normal.

## 4. La unión inicial — `unirCategorias()`

Una sola rutina, **solo aditiva**: no mueve, no renombra y no borra nada.

1. Lee los dos árboles: `GET /categories?todas=1` (plano, inactivas incluidas) y los términos `product_cat` (sin «Sin categorizar»).
2. Empareja: primero los ya enlazados por termmeta; después, por nombre normalizado (`trim` + minúsculas, **sin** quitar acentos). Si hay varios con el mismo nombre, se prefiere el que tenga una categoría madre con el mismo nombre normalizado; si no, el de menor id. Cada categoría se empareja como mucho una vez.
3. Lo que solo está en el TPV se crea en Woo; lo que solo está en Woo se crea en el TPV (`POST /categories`). Siempre las madres antes que las hijas, cada una dentro de la madre que tiene en su lado.
4. Las emparejadas se quedan donde están en cada lado, aunque sea en sitios distintos. Se igualan la próxima vez que alguien las mueva (C1/C2).
5. Productos enlazados: **se juntan** las categorías de los dos lados y se escriben en ambos (Woo: `wp_set_object_terms` con la unión; TPV: `PATCH category_ids` con la unión). Juntar no quita nada. Los productos sin enlace no se tocan.

Cuándo se ejecuta:

- **Tiendas ya conectadas** (al actualizar el plugin): como primera fase de `autocurar()` (cron cada 5 min, candado y presupuesto de tiempo ya existentes), por tandas con cursor: primero el árbol, después los productos enlazados. Solo empieza si la API anuncia la capacidad `categories_write` en `/health` (mismo patrón que `price_gross_write`). Al terminar, guarda `tpv_sync_categorias_unidas` = fecha.
- **Tiendas nuevas** (asistente de sincronización inicial): el árbol se une como **primer paso**, antes de los productos, en los dos sentidos (`ajax_import` y `ajax_push_all`). Después, los volcados masivos (`import_all` y el bulk/batch de Woo → TPV) **juntan** las categorías de cada producto en vez de sustituirlas. La marca se guarda al terminar el asistente.

Regla para recordarla: **los volcados y la unión juntan; los avisos sustituyen.**

## 5. API (`api_tpv`)

| Ruta | Qué hace |
|---|---|
| `GET /categories?todas=1` | Lista plana con `category_id`, `parent_id`, `name`, `status`, inactivas incluidas. Sin `todas`, igual que hoy (árbol, activas) |
| `GET /categories/{id}` | Una categoría (para asegurar madres que aún no están en Woo) |
| `POST /categories` | `{name, parent_id, status?}`. Crea `category`, `category_description` (idioma `API_LANGUAGE_ID`), `category_to_store` y `category_path` como el TPV (`category.php` alta). **Idempotente:** si ya existe ese nombre bajo esa madre, devuelve la existente con 200 (el TPV prohíbe duplicados en la misma madre: `name_allowed`). 201 al crear |
| `PATCH /categories/{id}` | Nombre, madre, estado. Rehace `category_path` como `re_path_categories` del TPV. 409 si el nombre choca con una hermana; 422 si la madre es ella misma o una descendiente |
| `DELETE /categories/{id}` | Borra **solo esa** categoría: sus hijas suben a su madre (como WordPress), con `category_path` rehecho; se quitan sus filas de `product_to_category`. 404 si no existe |

- Ámbitos: `categories:read` (ya existe) y `categories:write` (nuevo; se añade al conector de WooCommerce).
- Cada escritura emite `category.created/updated/deleted` con `source='api'` y `origin_client_id` (invalida la caché `category:*` como hoy). Las hijas que suben emiten `category.updated`.
- `/health` anuncia `categories_write`.

## 6. Tests

**Plugin (`tests/`):**
- `faltanEventos` / `eventosSuscritos` incluyen `category.*`.
- Unión, como función pura (`planUnion(arbolTpv, arbolWoo)` → enlaces + altas en cada lado, con orden madre→hija): emparejado por nombre sin acentos plegados («Lencería» ≠ «LENCERIA»), desempate por madre, cada uno una vez, nada se mueve. Caso fijo con los datos de lulubeauty: **57 emparejadas, 24 altas en Woo, 59 altas en el TPV**.
- Unión de productos: el resultado es la suma de los dos lados sin repetidos y sin «Sin categorizar».
- C1/C3: un aviso con categoría desconocida la trae y la crea con su madre; una lista vacía quita todas; renombrar en el TPV no crea término nuevo.
- C2: los hooks mandan POST/PATCH/DELETE; con la guarda activa no mandan nada; «Sin categorizar» nunca sale.
- Antes de la marca, los avisos `category.*` no se aplican y la sustitución por producto no se activa.
- Sin la capacidad `categories_write`, la unión no empieza.

**API (suite `categories_write`):** alta con `category_path` correcto en 1, 2 y 3 niveles; alta repetida → la misma categoría (200); PATCH mueve y rehace la ruta de la categoría y de sus descendientes; 409/422; DELETE sube las hijas y limpia `product_to_category`; eventos con `origin_client_id`; `/health` con la capacidad.

## 7. Despliegue y lulubeauty

Orden: **API → release del plugin.** Sin la API, el plugin espera (§4).

lulubeauty está en la **2.9.0**: no tiene la actualización forzada (entró en la 2.10.0), así que necesita **una actualización manual**. La release arrastra además lo que hay en `main` sin publicar (PVP fase 2, auditoría del 07-10, productos a peso).

Lo esperado tras actualizar (datos del 14-10): 57 categorías emparejadas, 24 creadas en la web, 59 creadas en el TPV, categorías juntadas en los 907 productos enlazados. Quedan parejas casi iguales que la unión no junta (COSMETICA/CÓSMETICA, LENCERIA/Lencería, ROPA NFANTIL/ROPA INFANTIL) y las categorías que el plugin viejo dejó en la raíz de la web: las ordena ella. **Avisarla antes**: a partir de entonces, borrar una categoría en un lado la borra en el otro.

## 8. Decisiones

- **D1 (14-10):** los dos sentidos, no «manda uno».
- **D2 (14-10):** el borrado se propaga siempre, también con productos dentro («a lo hecho pecho»).
- **D3 (14-10):** nada de botones, simulaciones ni ganadores: la unión es automática y solo aditiva, porque es para toda la flota.

Fuera de esta spec: el conector de PrestaShop (podrá usar las mismas rutas de la API), imágenes y descripciones de categoría, y multi-idioma.
