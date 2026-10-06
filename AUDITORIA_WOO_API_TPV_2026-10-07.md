# Auditoría Woo ↔ API ↔ TPV (07-10-2026)

Alcance: conector de WooCommerce (`woocomerce-pos-sync`), API (`api_tpv`) y TPV
(`tpv`, importación CSV). Objetivo: que una tienda que crea, edita y sube
productos por el TPV, por CSV y por WooCommerce a la vez se mantenga
sincronizada, se autocure y no pierda dinero, stock ni datos.

Método: cada hallazgo se reprodujo antes de arreglarlo, contra datos vivos
(WordPress 6 + WooCommerce 10.6.2 con HPOS en `tpv99wp`, API y BD de `tpv200`)
o con el código real en proceso. Cada arreglo tiene un test que estaba rojo
con el código anterior y mutantes que lo vuelven a romper.

## Hallazgos confirmados y arreglados

| # | Dónde | Qué pasaba | Arreglo |
|---|---|---|---|
| W1 | plugin | Un aviso del TPV (aunque fuera solo de stock) rehacía el producto variable: Talla×Color pasaba a un atributo «Talla-Color», se duplicaban las variaciones (las originales quedaban como comodín) y, tras reconectar a otro TPV, se borraban las variaciones con su sku, rebaja e imagen. | Las variaciones se emparejan (enlace, código, nombre). La estructura de Woo se respeta si no es la del TPV. Nunca se borra una variación. |
| W2 | plugin | Privado, pendiente o programado pasaban a borrador con cada aviso del TPV. | El estado de Woo se conserva; solo publicado↔borrador sigue al TPV. |
| W3 | plugin + API | Cancelar o reembolsar en Woo mandaba los estados 7/11/2; la API los rechaza (422) y el plugin registraba «ok». La venta seguía viva en el TPV, con su factura y el stock restado. | Se hace una devolución de lo que quede, línea a línea, idempotente y con cola. «Completado» va como 5. |
| W4 | plugin | Un cambio de stock en Woo con el TPV caído se encolaba con delta 0 y se daba por aplicado. El stock de Woo se perdía sin aviso. | La cola calcula el delta contra el stock real del TPV en el momento del reintento. |
| W5 | plugin | La firma antigua (sin marca de tiempo) se podía reenviar para siempre poniendo una cabecera de tiempo nueva. Con `WP_DEBUG` activo tampoco se exigía marca de tiempo. | Solo valen firmas con marca de tiempo firmada. |
| W6 | plugin | Con HPOS (el almacén de pedidos de serie desde Woo 8.2) el NIF del pedido nunca llegaba a la factura del TPV. | Lectura por el CRUD del pedido y compatibilidad HPOS declarada. |
| W7 | plugin | Un CSV en el TPV disparaba el volcado completo del catálogo del TPV sobre la tienda, sin mirar quién manda. | Se repasa solo el stock, por páginas encadenadas, con la política del comerciante. |
| W8 | plugin | Con más de 10.000 productos el índice del catálogo se cortaba y el resto se daba de alta duplicado (mismo código de barras dos veces en la caja). | Índice completo y búsqueda exacta antes de un alta. |
| A1 | API | Cada evento volvía también al cliente que lo provocó: un cambio hecho en Woo regresaba por su webhook y la tienda reescribía su ficha (el motor de W1). | `api_events.origin_client_id`; el dispatcher no devuelve el evento a su origen. **Necesita la migración** `2026_10_07_api_events_origin_client.sql` (sin ella el código funciona como antes). |
| A2 | API | Con el IVA mandado por línea (todos los pedidos del conector) el desglose fiscal guardaba tipo 0 % con cuota. VeriFactu declararía la base al 0 % con IVA. | El tipo se deduce de cuota/base y se ajusta al tipo del TPV. |
| T1 | TPV | Una fila del CSV que creaba o cambiaba un producto no avisaba a ningún plugin ni dejaba rastro en el libro de stock. El alta guardaba «L\'Oréal» con barra. Con el fichero inexistente salía igualmente un aviso de importación. | Aviso por fila (alta/cambio/stock), apunte `csv_import` en el libro, escapado correcto y aviso de resumen solo si se importó algo. |

## Pruebas

| Repo | Suite | Resultado |
|---|---|---|
| plugin | `php tests/run.php` | 782/782 (antes 702) |
| plugin | mutantes de los arreglos | 14 muertos de 14 |
| API | `--suite=sin_eco_tipo_iva` (nueva) | 5/5, 5 mutantes muertos |
| TPV | `CsvEventosTest` (nuevo) | 4/4, rojo con el código anterior, 4 mutantes muertos |
| TPV | `CsvImportesTest`, `CsvSignoVarianteTest` | 20/20 y 9/9 |

## Lo que queda (decisiones y riesgos)

- **Reposición de stock al cancelar en Woo.** La devolución del TPV repone
  stock solo si la tienda tiene «reponer en devoluciones» activo
  (`refund_in_stock`; en tpv200 está a 0). Woo sí repone al cancelar. Con el
  ajuste a 0, el TPV y la web divergen en stock tras una cancelación online.
  Hay que decidir si una cancelación online (mercancía que no salió) repone
  siempre.
- **Entrega de webhooks dentro del proceso PHP.** Tras responder, el mismo
  worker de PHP entrega los webhooks en serie con 10 s de tiempo máximo por
  destino. Una tienda lenta retiene workers de la API. Recomendado: que la
  entrega sea solo del worker.
- **Autocuración del stock lenta.** La reconciliación semanal revisa 100
  productos por semana (25 semanas para 2.500). Recomendado: diaria o cada
  pocas horas.
- **La API acepta dos productos con el mismo `model`.** El conector ya no los
  crea, pero otro cliente podría.
- **Sin prueba HTTP de extremo a extremo WordPress↔API.** No se pudo
  configurar el WordPress de pruebas contra tpv200 (escritura de credenciales
  denegada). Todo lo de arriba se probó en proceso con el código real.

## Despliegue (nada desplegado)

Orden: migración de la API → API → TPV → release del plugin.
