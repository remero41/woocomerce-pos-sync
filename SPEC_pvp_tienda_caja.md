# Spec TDD — El mismo precio final en la tienda online y en la caja

Fecha: 2026-09-30
Repos: `woocomerce-pos-sync` (plugin), `api_tpv` (API). Luego `prestashop-pos-sync`, con la misma regla.
Caso real: lulubeauty (30-09-2026). La web enseña «9,92 € PVP IVA incl.», el TPV cobra 12,00 € y la clienta quiere 9,92 €.

---

## 0. Reglas del trabajo

Las de siempre: ley anti-vanidad (rojo, verde y muerde al mutar), arnés `tests/run.php` con WordPress y la API en memoria, y nada de inventar semántica de dinero (lo no decidido está en §5).

## 1. El invariante

> **Para un mismo producto, el precio final que paga un cliente de España es el mismo en la web y en la caja.**

- El precio que tiene que cuadrar es el **PVP** (con IVA), no el precio sin IVA. Ni la clienta ni el cliente final saben nada del precio sin IVA.
- **El IVA lo pone el TPV**, que es quien factura (decisión del 30-09): el precio sin IVA se calcula en el TPV con su clase de impuesto, no en el plugin a partir de la configuración de Woo.

## 2. Estado actual verificado (leído en código y medido en lulubeauty)

| # | Hecho | Sitio |
|---|------|-------|
| E1 | Woo → TPV: el precio sin IVA lo calcula el plugin con `wc_get_price_excluding_tax()`. Depende de la configuración de impuestos de Woo **y de sus tarifas**: sin tarifas, o con precios introducidos sin IVA, devuelve el precio tal cual | `class-product-sync.php:1138` (y en el bulk, el singular y las variantes: `:1048`, `:1543`, `:1786`, `:1837`, `:1934`) |
| E2 | TPV → Woo: el plugin pide `X-Price-Format: net` si Woo tiene los impuestos desactivados o los precios introducidos sin IVA, y `gross` si no. Con los impuestos **desactivados** la web recibe el precio sin IVA y vende un 21 % **más barato** que la caja | `class-api-client.php:443-452` |
| E3 | La API solo aplica el IVA al **leer** (`applyTax` si `X-Price-Format: gross`). Al **escribir** solo acepta precios sin IVA | `ProductController.php:1528`, `:1610-1625` |
| E4 | Las rebajas solo van TPV → Woo. Una actualización desde el TPV **sin** precio especial **borra** la rebaja de Woo; de Woo al TPV no viajan nunca. lulubeauty: 135 productos rebajados en la web se venden a precio normal en la tienda | `class-product-sync.php:350`, `:374-377` |
| E5 | lulubeauty: en los 487 simples enlazados, el precio sin IVA del TPV es igual al precio de Woo. Woo tiene «precios introducidos sin impuestos», «mostrar con impuestos», el aviso de WooCommerce «Ajustes de impuestos inconsistentes» y, por lo que enseña la web, no aplica el IVA al visitante | medido 30-09 |

## 3. Comportamiento objetivo

### P1 — Woo → TPV: el plugin manda el PVP y el TPV calcula el precio sin IVA

- El plugin calcula el **PVP que Woo cobra a un cliente de España**: `wc_get_price_including_tax()` con la ubicación de la tienda, o el precio tal cual si Woo tiene los impuestos desactivados (entonces el precio de Woo **es** el final).
- Lo manda como `price` con `X-Price-Format: gross` **también al escribir**. La API calcula el precio sin IVA con la tarifa de la clase de impuesto del producto en el TPV (`round(pvp / (1 + tasa), 4)`).
- Resultado: el TPV cobra exactamente el PVP de la web, con cualquier configuración de Woo.

### P2 — TPV → Woo: se entrega el PVP en el formato que Woo espera

- Woo con impuestos desactivados → el PVP (`gross`), no el precio sin IVA (corrige E2).
- Woo con precios introducidos **con** IVA → el PVP (`gross`).
- Woo con precios introducidos **sin** IVA → el precio sin IVA calculado con la **tasa del TPV** (`net`). Solo cuadra si la tarifa de Woo es la misma: lo vigila P4.

### P3 — Rebajas en los dos sentidos, sin borrarse

- Woo → TPV: la rebaja de Woo viaja como `special_price` (PVP, mismo criterio que P1), con sus fechas si las tiene.
- TPV → Woo: una actualización sin precio especial **no borra** una rebaja de Woo salvo que el catálogo lo mande el TPV (política del reconciliador).

### P4 — Diagnóstico en el panel: «¿cobran lo mismo la web y la caja?»

- Una comprobación periódica (y un botón) compara, producto a producto, el PVP de Woo para España con el PVP del TPV (`GET /products` con `gross`).
- El panel muestra «N productos cobran distinto en la web y en la tienda», con ejemplos. Así se ve el primer día y no cuando se queja la clienta.
- Avisa también de las configuraciones que no pueden cuadrar: impuestos activos sin tarifa para España, o una tarifa distinta de la del TPV en la clase enlazada.

### P5 — Corrección de lo ya volcado

Tras P1, «Enviar a TPV» reescribe los precios del TPV con la regla buena (el bulk actualiza `price` y no toca el stock). En lulubeauty, **antes** hay que dejar su Woo coherente con lo que quiere cobrar (9,92 € con IVA: «precios introducidos con impuestos» = Sí y tarifa ES 21 %).

## 4. Tests: la matriz

Para cada combinación, el PVP esperado de la web y del TPV tiene que ser el mismo:

| Woo: impuestos | Precios introducidos | Tarifa ES 21 % | Web cobra | TPV debe cobrar |
|---|---|---|---|---|
| desactivados | — | — | precio | precio |
| activos | con IVA | sí | precio | precio |
| activos | con IVA | **no** | precio | precio (hoy: +21 %) |
| activos | sin IVA | sí | precio × 1,21 | precio × 1,21 |
| activos | sin IVA | **no** | precio | precio (hoy: +21 %) |
| activos | con IVA | 10 % en Woo y 21 % en el TPV | precio | precio, **y aviso P4** |

Además: una variante con precio propio; una rebaja con y sin fechas; ida y vuelta (Woo → TPV → Woo) sin deriva de céntimos; y la API calculando el precio sin IVA con la clase de cada producto (21 %, 10 %, 4 %, 0 %).

## 4.1 Estado (30-09-2026)

**Fase 1: HECHA, sin commit.**

- **API** (worktree api_tpv `fix/precio-con-iva-escritura`):
  - `PrecioEntrada`: con **`X-Price-Input: gross`** (cabecera NUEVA), `create`, `update`, `bulk`, las diferencias de talla, el lote de variantes y `createSpecial` reciben el PVP y guardan la base con la clase del TPV, usando `TaxResolver`, el mismo cálculo que la caja.
  - **Por qué una cabecera nueva y no `X-Price-Format`**: los conectores hasta la 2.9.0 mandan `X-Price-Format: gross` en TODO, también al escribir, con el cuerpo sin IVA. Con esa cabecera, desplegar la API les habría quitado el IVA dos veces (test «conector VIEJO»).
  - La **lectura** con IVA usa ahora el cálculo de la caja (zona fiscal); antes, la primera tarifa de la clase.
  - El volcado en bloque aplica `special_price` (antes lo ignoraba). Número = rebaja, null = quitarla, ausente = no tocar.
  - Se corrige `update`, que comparaba la rebaja (sin IVA) con el precio leído con IVA.
  - `/health` anuncia `capabilities: ["price_gross_write"]`.
  - Tests: 16/16 y 16 mutantes muertos. Suites de productos, volcado y pedidos iguales con y sin el cambio.
- **Plugin** (este worktree):
  - `TPV_Sync_Precio_Pvp::deWoo()` es la regla pura, con la matriz.
  - El cliente pregunta `/health` una vez (se recuerda 1 h) y solo entonces escribe con `X-Price-Input: gross`.
  - `priceForTpv()` devuelve el PVP con esa misma condición: número y unidad nunca van desparejados. Con una API vieja o caída, todo sigue como siempre.
  - Rebajas de Woo → TPV (D1), en el guardado y en el volcado; «se acabó» → null; «nunca hubo» → nada.
  - Una actualización desde el TPV sin especial ya no borra la rebaja de Woo, salvo con el catálogo mandado por el TPV.
  - Tests: 617/617 y 17 mutantes muertos (los que sobrevivieron al principio sacaron dos huecos: el producto exento y la rebaja en el volcado).

**Fuera de la fase 1, pendiente de decisión**:

- **P2, lectura TPV → Woo con los impuestos desactivados en Woo.** El spec pedía `gross`. Pero el código tiene una nota del **28-04-2026**: «el plugin siempre pedía gross aunque WC tuviera impuestos OFF, resultando en precios inflados un 21 %». Fue una decisión tomada por un caso real, y cambiarla movería precios visibles en webs que hoy no se quejan. No se toca hasta que el usuario decida.

**Fase 2: HECHA (30-09-2026, sin commit)**. Decisión del usuario: **sin freno de cambios masivos, sin aviso al CP y sin deshacer** («si la cliente se equivoca subiendo impuestos, que se joda»). La web manda.

- La comparación va dentro de `autocurar()` (cursor, candado y presupuesto de tiempo ya existían), **una vuelta cada 6 h**: leer los precios cuesta el catálogo entero del TPV (`getConIva`, `X-Price-Format: gross`, sea cual sea la configuración de Woo).
- Regla pura `TPV_Sync_Precio_Pvp::cuadra()`: el precio normal coincide siempre; la rebaja, solo si la web está rebajada (una promoción puesta solo en caja no es un descuadre).
- Si no cuadra, se reenvía el producto por el guardado de la fase 1 (PVP + rebaja). Solo simples, con la API nueva y el catálogo mandado por la tienda.
- Sin bucles: `_tpv_pvp_corregido` guarda los precios de la web con los que se corrigió; si siguen iguales y no cuadra, no se repite y cuenta como «no se pudo igualar».
- Panel («Estado de la sincronización»): resultado de la última vuelta con revisión (corregidos, los que no se pudieron igualar con ejemplos) y aviso de clases de impuesto de Woo sin tarifa para España.
- Tests: 702/702 y ~30 mutantes muertos.
- **Queda fuera:** el aviso «tu web enseña un precio distinto del que cobra» (lo que ve un visitante sin dirección frente a lo que se cobra a España): no se puede calcular con fiabilidad desde el cron; los productos variables; PrestaShop.

## 5. Decisiones del usuario (antes de programar)

- **D1 — Rebajas de Woo al TPV** (P3): **SÍ** (usuario, 30-09). Implementado sin fechas: Woo avisa al terminar la rebaja programada (guardado) y viaja el null.
- **D2 — La web y la caja no cuadran** (P4): **nada de botón** (el usuario: «el cliente no sabe y la puede liar»). La corrección es automática y segura porque la referencia es lo que Woo COBRA. Frenos: solo con la configuración coherente, un freno de cambios masivos que avisa al CP, y se puede deshacer. A la clienta, solo avisos de qué cambiar en su Woo.
- **D3 — Tiendas ya volcadas con la regla vieja**: **SÍ**, corrección automática al actualizar, con los frenos de D2 (fase 2).
