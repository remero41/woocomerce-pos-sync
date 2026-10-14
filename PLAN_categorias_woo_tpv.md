# Categorías Woo ↔ TPV — Plan de implementación (TDD)

> **Para quien ejecute:** SUB-SKILL OBLIGATORIA: superpowers:executing-plans (el usuario no quiere subagentes). Pasos con casillas (`- [ ]`). Cada tarea: rojo → verde → mutante que muerde → commit firmado (`git commit -S`, avisar si la YubiKey pide toque). **Sin push ni merge** hasta que el usuario lo pida.

**Objetivo:** categorías sincronizadas en los dos sentidos entre una tienda WooCommerce y su TPV, con unión inicial automática y solo aditiva.

**Arquitectura:** la API gana escritura de categorías (`POST/PATCH/DELETE /categories`, lista plana, una por id) y una tabla de enlaces `api_external_mapping_category`; el plugin de Woo empareja por número (termmeta = caché del enlace), aplica los avisos `category.*`, envía sus cambios y une los dos árboles por fases desde el cron. El TPV y su conector solo se retocan: ámbitos, invalidación de caché y aviso del CSV.

**Tecnología:** PHP 8.x sin frameworks. Tests: runner propio de la API (`tests/run_tests.php --suite=…`, BD real de tpv200), runner del plugin (`tests/run.php`, WordPress simulado), scripts sueltos en `tpv/admin/tests/` y `plugin_wordpress_connector/tests/`.

**Spec:** `SPEC_categorias_woo_tpv.md` (misma carpeta). El plan argumenta desde ella; leer las dos.

## Restricciones globales

- Normalización de nombre ÚNICA, idéntica en la API y en el plugin: `html_entity_decode` → colapsar espacios → `trim` → minúsculas UTF-8 → NFD sin marcas (acentos y tilde de la ñ fuera). «Lencería» = «LENCERIA » = «lenceria».
- La categoría por defecto de Woo (`get_option('default_product_cat')`) nunca se sincroniza.
- Las categorías ignoran `tpv_sync_principal`.
- Marca `tpv_sync_categorias_unidas`: `''` (no tocar categorías en ningún sitio) → `'arbol'` (avisos `category.*` se aplican; avisos de producto JUNTAN; envíos Woo→TPV llevan `category_ids` solo en altas) → `'completa'` (avisos de producto SUSTITUYEN; envíos siempre llevan `category_ids`).
- Regla: **los volcados, la reconciliación y la unión juntan; los avisos sustituyen** (solo en `'completa'`).
- Borrado propagado siempre. Desde la web la API sube las hijas (como WP); desde el TPV, cascada con un aviso por hija.
- Ámbitos nuevos en los conectores del TPV: `customers:write categories:write`. Migración: `categories:write`, `customers:write`, `imports:write` para `channel IN ('woocommerce','prestashop')`.
- Multi-idioma: solo `API_LANGUAGE_ID`. Textos de UI del plugin con `__()` y dominio `tpv-sync` (añadir a los `.po` es/en/fr).
- Commits firmados con `-S`, mensajes en español (Conventional Commits), con la línea `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Foco de revisión

1. **Nombre con `&` o entidades** («Hogar & Cocina»: WP guarda `&amp;`) → debe emparejar con el del TPV. Test en Tarea W1 (`normalizar('Hogar &amp; Cocina') === normalizar('hogar & cocina')`).
2. **Ciclo de madres o madre inexistente** en datos viejos del TPV (parent_id apuntando a una borrada) → la unión no debe colgarse ni crear en bucle. Tests en A2 (`ancestros` corta ciclos) y W2 (`planUnion` con madre desconocida la crea en la raíz).
3. **Token viejo sin el ámbito nuevo** (la migración no cambia los tokens ya emitidos) → la unión recibe 403, lo dice en el panel y reintenta en la siguiente vuelta sin marcar nada. Test en W6.
4. **Aviso de producto con una categoría borrada justo después** (`GET /categories/{id}` → 404) → se ignora esa categoría, el resto se aplica. Test en W4.
5. **Término de Woo hijo de «Sin categorizar»** → en el TPV va en la raíz, y «Sin categorizar» nunca se crea. Test en W2 y W5.

---

## Preparación (una vez)

Worktrees (skill worktree-sesion; el santuario no se toca):

```bash
W=/home/zak/.claude/skills/worktree-sesion/scripts/wt.sh
bash $W add api_tpv feat/categorias-escritura
bash $W add plugin_wordpress_connector feat/categorias-ambitos-cache
bash $W add plugin_prestashop_connector feat/categorias-ambitos
bash $W add tpv feat/csv-avisa-categorias
cd ~/dev/.worktrees/woocomerce-pos-sync/spec-categorias-bidireccional && git switch -c feat/categorias-bidireccional
```

Arnés de la API en su worktree (memoria «worktree con vendor enlazado al santuario»): enlaces a `config.php`, `tpv`, `image` y `secrets.env` de tpv200 como en las sesiones anteriores, y **vendor copiado** (no enlazado) + `composer dump-autoload`, porque el classmap tiene que ver `CategoryPath` y `NombreCategoria` del worktree:

```bash
cd ~/dev/.worktrees/api_tpv/feat-categorias-escritura/api/v1
rm -f vendor && cp -a ~/dev/api_tpv/api/v1/vendor ./vendor
composer dump-autoload -q
php tests/run_tests.php --suite=customers_crud   # línea base: debe pasar como en el santuario
```

---

## Fase A — API (`api_tpv`, worktree `feat-categorias-escritura`, carpeta `api/v1`)

### Tarea A1: tabla de enlaces y migración de ámbitos

**Ficheros:**
- Modificar: `install.sql` (tras el bloque condicional que añade `api_clients.channel`, línea ~518)
- Modificar: `controllers/AdminConnectorsController.php:149-150` (lista por defecto)
- Crear: `tests/suites/test_categories_write.php`
- Modificar: `tests/run_tests.php` (registrar la suite, junto a `customers_crud`, línea ~444)

**Interfaces:**
- Produce: tabla `{p}api_external_mapping_category(tpv_category_id, channel, client_id, external_id)` con `UNIQUE(channel, client_id, external_id)` y `UNIQUE(client_id, tpv_category_id)`; función `run_categories_write_tests(TestRunner $t, DB $db, Auth $auth): void`.

- [ ] **Paso 1: test en rojo**

```php
<?php
declare(strict_types=1);
/**
 * Suite — escritura de categorías (spec SPEC_categorias_woo_tpv.md §5).
 * Uso: php tests/run_tests.php --suite=categories_write
 */
require_once API_ROOT . '/controllers/CategoryController.php';

function run_categories_write_tests(TestRunner $t, DB $db, Auth $auth): void
{
    $p = $db->p('');

    $t->suite('Categorías — esquema y ámbitos');

    $t->test('existe la tabla de enlaces con sus dos claves únicas', function ($t) use ($db, $p) {
        $idx = $db->fetchAll("SHOW INDEX FROM {$p}api_external_mapping_category WHERE Non_unique = 0");
        $nombres = array_values(array_unique(array_column($idx, 'Key_name')));
        sort($nombres);
        $t->assertEquals(['PRIMARY', 'uk_channel_client_external', 'uk_client_categoria'], $nombres);
    });

    $t->test('la migración da los ámbitos que faltan a los clientes de canal y solo a ellos', function ($t) use ($db, $p) {
        $sql = file_get_contents(API_ROOT . '/install.sql');
        foreach (['categories:write', 'customers:write', 'imports:write'] as $s) {
            $t->assert(str_contains($sql, "NOT LIKE '% $s %'"), "install.sql añade $s de forma idempotente");
        }
        $t->assert((bool) preg_match("/WHERE `channel` IN \('woocommerce','prestashop'\)/", $sql),
            'solo a los clientes de canal (no a cp_login ni tpv_self)');
    });
}
```

Registro en `tests/run_tests.php`:

```php
if (in_array($suite, ['all', 'categories_write'])) {
    require_once TEST_ROOT . '/suites/test_categories_write.php';
    run_categories_write_tests($runner, $db, $auth);
}
```

- [ ] **Paso 2: comprobar que falla**

Run: `php tests/run_tests.php --suite=categories_write`
Esperado: FALLA (tabla inexistente: `SQLSTATE[42S02]`; y las tres aserciones de `install.sql`).

- [ ] **Paso 3: implementación mínima** — en `install.sql`, justo después del bloque de `api_clients.channel`:

```sql
-- Enlaces de categorías con los canales (2026-10-14, SPEC_categorias_woo_tpv.md §5.1).
-- Como los de productos y clientes: (canal, cliente, id externo) ↔ category_id.
CREATE TABLE IF NOT EXISTS `2465_api_external_mapping_category` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tpv_category_id` INT UNSIGNED    NOT NULL,
    `channel`         VARCHAR(20)     NOT NULL DEFAULT '',
    `client_id`       VARCHAR(64)     NOT NULL,
    `external_id`     VARCHAR(80)     NOT NULL COMMENT 'term_id de Woo, id_category de PS…',
    `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_channel_client_external` (`channel`, `client_id`, `external_id`),
    UNIQUE KEY `uk_client_categoria` (`client_id`, `tpv_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Mapping multi-canal entre categorías TPV y sistemas externos';

-- Ámbitos (2026-10-14): los conectores del TPV crean el cliente con una lista
-- FIJA que nunca tuvo customers:write ni categories:write, y la API los congela
-- por cliente. Sin esto, toda tienda ya conectada recibe 403 al escribir
-- clientes o categorías. Idempotente: solo añade lo que falta.
UPDATE `2465_api_clients` SET `scopes` = TRIM(CONCAT(`scopes`, ' categories:write'))
 WHERE `channel` IN ('woocommerce','prestashop') AND CONCAT(' ', `scopes`, ' ') NOT LIKE '% categories:write %';
UPDATE `2465_api_clients` SET `scopes` = TRIM(CONCAT(`scopes`, ' customers:write'))
 WHERE `channel` IN ('woocommerce','prestashop') AND CONCAT(' ', `scopes`, ' ') NOT LIKE '% customers:write %';
UPDATE `2465_api_clients` SET `scopes` = TRIM(CONCAT(`scopes`, ' imports:write'))
 WHERE `channel` IN ('woocommerce','prestashop') AND CONCAT(' ', `scopes`, ' ') NOT LIKE '% imports:write %';
```

En `AdminConnectorsController.php:150`, la lista por defecto pasa a terminar en `'categories:read', 'categories:write',`.

Aplicar a la BD de pruebas: `php install.php`.

- [ ] **Paso 4: verde** — `php tests/run_tests.php --suite=categories_write` → PASA. Además `php tests/suites/test_install_sql_sin_tablas_retiradas.php` y la suite aislada del instalador (`--suite=installer`) siguen verdes.
- [ ] **Paso 5: mutante** — quitar `WHERE \`channel\` IN (...)` de un UPDATE → la 2.ª aserción falla. Restaurar.
- [ ] **Paso 6: commit**

```bash
git add install.sql controllers/AdminConnectorsController.php tests/suites/test_categories_write.php tests/run_tests.php
git commit -S -m "feat(categorias): tabla de enlaces de categorías y ámbitos que faltaban a los conectores

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Tarea A2: `NombreCategoria::normalizar` y `CategoryPath`

**Ficheros:**
- Crear: `classes/NombreCategoria.php`, `classes/CategoryPath.php`
- Modificar: `tests/suites/test_categories_write.php` (suite pura nueva al principio)

**Interfaces:**
- Produce: `Api\V1\NombreCategoria::normalizar(string $s): string`; `Api\V1\CategoryPath::ancestros(array $padres, int $id): array` (ids de la raíz hacia abajo, sin `$id`, corta ciclos); `Api\V1\CategoryPath::rehacer(DB $db, int $categoryId): void` (reescribe `category_path` de la categoría y de todas sus descendientes).

- [ ] **Paso 1: tests en rojo** (añadir al principio de `run_categories_write_tests`):

```php
    $t->suite('Categorías — normalización de nombre (la MISMA que el plugin)');

    $t->test('mayúsculas, acentos, ñ, espacios y entidades se pliegan', function ($t) {
        $n = [NombreCategoria::class, 'normalizar'];
        $t->assertEquals('lenceria', $n('Lencería'));
        $t->assertEquals('lenceria', $n('  LENCERIA '));
        $t->assertEquals('nino', $n('NIÑO'));
        $t->assertEquals('cosmetica', $n('CÓSMETICA'));
        $t->assertEquals('hogar & cocina', $n('Hogar &amp; Cocina'));
        $t->assertEquals('tonico facial', $n("TONICO   FACIAL "));
    });

    $t->suite('Categorías — ruta de categoría madre');

    $t->test('ancestros de la raíz hacia abajo', function ($t) {
        $padres = [1 => 0, 2 => 1, 3 => 2];
        $t->assertEquals([1, 2], CategoryPath::ancestros($padres, 3));
        $t->assertEquals([], CategoryPath::ancestros($padres, 1));
    });

    $t->test('un ciclo o una madre inexistente no cuelgan', function ($t) {
        $t->assertEquals([5], CategoryPath::ancestros([4 => 5, 5 => 4], 4));
        $t->assertEquals([], CategoryPath::ancestros([7 => 99], 7));
    });
```

- [ ] **Paso 2: rojo** — `php tests/run_tests.php --suite=categories_write` → FALLA (`Class "NombreCategoria" not found`).

- [ ] **Paso 3: implementación**

`classes/NombreCategoria.php`:

```php
<?php
declare(strict_types=1);

namespace Api\V1;

/**
 * La regla ÚNICA para decir si dos nombres de categoría son «el mismo».
 * Copia exacta de TPV_Sync_Categorias::normalizar() del plugin de Woo: si una
 * cambia, cambia la otra (los dos tests usan los mismos vectores). Se aplica en
 * PHP, nunca con `=` en SQL: la colación de la columna no es un contrato.
 */
final class NombreCategoria
{
    public static function normalizar(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        $s = mb_strtolower($s, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) {
                $s = preg_replace('/\p{Mn}+/u', '', $d) ?? $d;
            }
        } else {
            $s = strtr($s, ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
                'ê' => 'e', 'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o',
                'ô' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c']);
        }
        return $s;
    }
}
```

`classes/CategoryPath.php`:

```php
<?php
declare(strict_types=1);

namespace Api\V1;

/**
 * category_path (tabla de cierre de OpenCart) calculada desde parent_id, como
 * hace el TPV (category.php: alta y re_path_categories). Para C con ancestros
 * A0 (raíz) … An-1: filas (C, Ai, i) y (C, C, n).
 */
final class CategoryPath
{
    /** @param array<int,int> $padres category_id => parent_id */
    public static function ancestros(array $padres, int $id): array
    {
        $out = [];
        $visto = [$id => true];
        $actual = $padres[$id] ?? 0;
        while ($actual > 0 && isset($padres[$actual]) && !isset($visto[$actual])) {
            $visto[$actual] = true;
            array_unshift($out, $actual);
            $actual = $padres[$actual];
        }
        return $out;
    }

    public static function rehacer(DB $db, int $categoryId): void
    {
        $p = $db->p('');
        $padres = [];
        $hijos  = [];
        foreach ($db->fetchAll("SELECT category_id, parent_id FROM {$p}category") as $r) {
            $padres[(int) $r['category_id']] = (int) $r['parent_id'];
            $hijos[(int) $r['parent_id']][] = (int) $r['category_id'];
        }
        $pendientes = [$categoryId];
        $hechos = [];
        while ($pendientes) {
            $id = array_shift($pendientes);
            if (isset($hechos[$id])) { continue; }
            $hechos[$id] = true;
            $cadena = self::ancestros($padres, $id);
            $db->execute("DELETE FROM {$p}category_path WHERE category_id = ?", [$id]);
            foreach ($cadena as $nivel => $anc) {
                $db->execute("INSERT INTO {$p}category_path (category_id, path_id, `level`) VALUES (?, ?, ?)",
                    [$id, $anc, $nivel]);
            }
            $db->execute("INSERT INTO {$p}category_path (category_id, path_id, `level`) VALUES (?, ?, ?)",
                [$id, $id, count($cadena)]);
            foreach ($hijos[$id] ?? [] as $h) { $pendientes[] = $h; }
        }
    }
}
```

`composer dump-autoload -q` (classmap).

- [ ] **Paso 4: verde** — la suite pasa.
- [ ] **Paso 5: mutantes** — (a) quitar el plegado NFD → fallan «lenceria»/«nino»; (b) quitar `!isset($visto[$actual])` → el test del ciclo cuelga/falla (ejecutar con `timeout 10`). Restaurar.
- [ ] **Paso 6: commit** `feat(categorias): una sola regla de nombre y la ruta de categoría madre como el TPV`.

### Tarea A3: lectura — `GET /categories?todas=1` y `GET /categories/{id}`

**Ficheros:**
- Modificar: `controllers/CategoryController.php` (constructor, `getBody`, `index`, nuevos `listarPlano`, `mostrar`, privados `filas`, `existe`)
- Modificar: `routes.php:253-262`
- Modificar: `tests/suites/test_categories_write.php`

**Interfaces:**
- Produce: `CategoryController::__construct(DB $db, Response $response, ?EventLogger $eventLogger = null)`; `protected function getBody(): array`; `listarPlano(string $clientId): void`; `mostrar(int $id, string $clientId): void`; filas `{category_id:int, parent_id:int, status:int, name:string, external_id:?string}`.

- [ ] **Paso 1: test en rojo** — helpers de la suite y tests (añadir tras los de A2):

```php
    // Controlador con cuerpo inyectado (mismo truco que customers_crud).
    $ctrl = function (array $body = []) use ($db): CategoryController {
        return new class($db, new Response(), new EventLogger($db), $body) extends CategoryController {
            public function __construct(DB $db, Response $r, EventLogger $e, private array $cuerpo) { parent::__construct($db, $r, $e); }
            protected function getBody(): array { return $this->cuerpo; }
        };
    };
    // Llama y devuelve [status, json] usando capture_response() del runner.
    $llamar = function (callable $fn): array {
        [$status, $body] = capture_response($fn);
        return [$status, json_decode((string) $body, true)];
    };
    $crearFila = function (string $nombre, int $padre = 0, int $estado = 1) use ($db, $p): int {
        $id = (int) $db->insert("INSERT INTO {$p}category (image, parent_id, `top`, `column`, sort_order, status, date_added, date_modified)
                                 VALUES ('', ?, 0, 1, 0, ?, NOW(), NOW())", [$padre, $estado]);
        $db->execute("INSERT INTO {$p}category_description (category_id, language_id, name, description, meta_title, meta_description, meta_keyword)
                      VALUES (?, ?, ?, '', ?, '', '')", [$id, API_LANGUAGE_ID, $nombre, $nombre]);
        CategoryPath::rehacer($db, $id);
        return $id;
    };
    $borrarFila = function (int $id) use ($db, $p): void {
        foreach (['category', 'category_description', 'category_to_store', 'category_path', 'product_to_category'] as $tb) {
            $db->execute("DELETE FROM {$p}{$tb} WHERE category_id = ?", [$id]);
        }
        $db->execute("DELETE FROM {$p}api_external_mapping_category WHERE tpv_category_id = ?", [$id]);
    };

    $t->suite('Categorías — lectura para el conector');

    $t->test('todas=1: lista plana con inactivas y external_id del cliente', function ($t) use ($ctrl, $llamar, $crearFila, $borrarFila, $db, $p) {
        $madre = $crearFila('ZZ Madre ' . uniqid());
        $hija  = $crearFila('ZZ Hija inactiva', $madre, 0);
        $db->execute("INSERT INTO {$p}api_external_mapping_category (tpv_category_id, channel, client_id, external_id) VALUES (?, 'woocommerce', 'e2e_test', '777')", [$hija]);
        [$st, $j] = $llamar(fn () => $ctrl()->listarPlano('e2e_test'));
        $t->assertEquals(200, $st);
        $porId = array_column($j['data'], null, 'category_id');
        $t->assertEquals(['category_id' => $hija, 'parent_id' => $madre, 'status' => 0, 'name' => 'ZZ Hija inactiva', 'external_id' => '777'], $porId[$hija] ?? null);
        $t->assertEquals(null, $porId[$madre]['external_id'] ?? 'falta', 'sin enlace: external_id null');
        $borrarFila($hija); $borrarFila($madre);
    });

    $t->test('GET /categories/{id}: una, inactiva incluida; 404 si no existe', function ($t) use ($ctrl, $llamar, $crearFila, $borrarFila) {
        $id = $crearFila('ZZ Sola ' . uniqid(), 0, 0);
        [$st, $j] = $llamar(fn () => $ctrl()->mostrar($id, 'e2e_test'));
        $t->assertEquals([200, 0], [$st, $j['data']['status'] ?? null]);
        [$st] = $llamar(fn () => $ctrl()->mostrar(999999999, 'e2e_test'));
        $t->assertEquals(404, $st);
        $borrarFila($id);
    });
```

Antes de escribirlo, comprobar la firma real de `capture_response` en `tests/run_tests.php` (helpers compartidos, línea ~134) y adaptar el desempaquetado `[$status, $body]` a lo que devuelva.

- [ ] **Paso 2: rojo** — FALLA (`listarPlano` no existe).

- [ ] **Paso 3: implementación** en `CategoryController.php`:

```php
    private DB           $db;
    private Response     $response;
    private ?EventLogger $eventLogger;

    public function __construct(DB $db, Response $response, ?EventLogger $eventLogger = null)
    {
        $this->db          = $db;
        $this->response    = $response;
        $this->eventLogger = $eventLogger;
    }

    protected function getBody(): array
    {
        $data = json_decode((string) Input::body(), true);
        return is_array($data) ? $data : [];
    }

    // ─── GET /categories?todas=1 — lista plana para conectores, sin caché ────
    public function listarPlano(string $clientId): void
    {
        $this->response->success($this->filas(null, $clientId));
    }

    // ─── GET /categories/{id} ────────────────────────────────────────────────
    public function mostrar(int $id, string $clientId): void
    {
        $fila = $this->filas($id, $clientId)[0] ?? null;
        if ($fila === null) {
            $this->response->error('not_found', 'Category not found.', 404);
            return;
        }
        $this->response->success($fila);
    }

    /** @return list<array{category_id:int,parent_id:int,status:int,name:string,external_id:?string}> */
    private function filas(?int $id, string $clientId): array
    {
        $p = $this->db->p('');
        $sql = "SELECT c.category_id, c.parent_id, c.status, cd.name, m.external_id
                  FROM {$p}category c
                  JOIN {$p}category_description cd
                    ON cd.category_id = c.category_id AND cd.language_id = ?
             LEFT JOIN {$p}api_external_mapping_category m
                    ON m.tpv_category_id = c.category_id AND m.client_id = ?";
        $params = [API_LANGUAGE_ID, $clientId];
        if ($id !== null) {
            $sql .= ' WHERE c.category_id = ?';
            $params[] = $id;
        }
        $sql .= ' ORDER BY c.category_id';
        return array_map(static fn (array $r): array => [
            'category_id' => (int) $r['category_id'],
            'parent_id'   => (int) $r['parent_id'],
            'status'      => (int) $r['status'],
            'name'        => (string) $r['name'],
            'external_id' => $r['external_id'] !== null ? (string) $r['external_id'] : null,
        ], $this->db->fetchAll($sql, $params));
    }

    private function existe(int $id): bool
    {
        return $id > 0 && $this->db->fetchOne(
            'SELECT 1 FROM ' . $this->db->p('category') . ' WHERE category_id = ?', [$id]) !== null;
    }
```

Rutas (`routes.php`, bloque «Categorías»): la lista plana va ANTES de la caché del árbol, así que no comparte clave:

```php
    $t->get('/categories', function ($app, $p) use ($db, $res, $log) {
        $app->guard('categories:read', true, null, function ($token, $storeId) use ($db, $res, $log) {
            $c = new CategoryController($db, $res, $log);
            if (!empty($_GET['todas'])) { $c->listarPlano((string) $token['sub']); return; }
            $c->index($storeId);
        });
    });
    $t->get('/categories/{id:\d+}', function ($app, $p) use ($db, $res, $log) {
        $app->guard('categories:read', true, null, function ($token, $storeId) use ($db, $res, $log, $p) {
            (new CategoryController($db, $res, $log))->mostrar((int) $p['id'], (string) $token['sub']);
        });
    });
```

- [ ] **Paso 4: verde.** También `--suite=endpoints` sin regresiones en `/categories` (comparar número de fallos con el santuario: los preexistentes no cuentan).
- [ ] **Paso 5: mutante** — quitar `m.client_id = ?` del JOIN → el test de external_id ve enlaces de otro cliente (crear en el test un segundo enlace con `client_id='otro'` para que muerda). Restaurar.
- [ ] **Paso 6: commit** `feat(categorias): lista plana y lectura por id para los conectores`.

### Tarea A4: `POST /categories` y `PATCH /categories/{id}`

**Ficheros:** `controllers/CategoryController.php`, `classes/EventLogger.php` (constantes), `routes.php`, `tests/suites/test_categories_write.php`.

**Interfaces:**
- Consume: `NombreCategoria::normalizar`, `CategoryPath::rehacer/ancestros`, `filas`, `existe`.
- Produce: `crear(int $storeId, string $clientId): void` (201 nueva / 200 existente), `actualizar(int $id, int $storeId, string $clientId): void` (200 / 404 / 409 / 422); `EventLogger::EVENT_CATEGORY_CREATED|UPDATED|DELETED`.

- [ ] **Paso 1: tests en rojo**

```php
    $t->suite('Categorías — alta y cambio');

    $limpiarNombre = function (string $prefijo) use ($db, $p, $borrarFila) {
        foreach ($db->fetchAll("SELECT category_id FROM {$p}category_description WHERE name LIKE ?", [$prefijo . '%']) as $r) {
            $borrarFila((int) $r['category_id']);
        }
    };

    $t->test('alta en 3 niveles con category_path correcto y evento con origen', function ($t) use ($ctrl, $llamar, $limpiarNombre, $db, $p) {
        $limpiarNombre('ZZA ');
        EventLogger::setOriginClient('e2e_test');
        [$s1, $a] = $llamar(fn () => $ctrl(['name' => 'ZZA Uno'])->crear(0, 'e2e_test'));
        [$s2, $b] = $llamar(fn () => $ctrl(['name' => 'ZZA Dos', 'parent_id' => $a['data']['category_id']])->crear(0, 'e2e_test'));
        [$s3, $c] = $llamar(fn () => $ctrl(['name' => '  ZZA   Tres ', 'parent_id' => $b['data']['category_id']])->crear(0, 'e2e_test'));
        $t->assertEquals([201, 201, 201], [$s1, $s2, $s3]);
        $t->assertEquals('ZZA Tres', $c['data']['name'], 'el nombre se guarda recortado');
        $ruta = $db->fetchAll("SELECT path_id, `level` FROM {$p}category_path WHERE category_id = ? ORDER BY `level`", [$c['data']['category_id']]);
        $t->assertEquals([$a['data']['category_id'], $b['data']['category_id'], $c['data']['category_id']], array_map('intval', array_column($ruta, 'path_id')));
        $ev = $db->fetchOne("SELECT origin_client_id FROM {$p}api_events WHERE event_type = 'category.created' AND resource_id = ? ORDER BY event_id DESC LIMIT 1", [$c['data']['category_id']]);
        $t->assertEquals('e2e_test', $ev['origin_client_id'] ?? null);
        $limpiarNombre('ZZA ');
    });

    $t->test('alta repetida: por external_id y por nombre normalizado bajo la misma madre → la misma (200)', function ($t) use ($ctrl, $llamar, $limpiarNombre) {
        $limpiarNombre('ZZB ');
        [, $a] = $llamar(fn () => $ctrl(['name' => 'ZZB Lencería', 'client_external_id' => '501'])->crear(0, 'e2e_test'));
        [$s, $b] = $llamar(fn () => $ctrl(['name' => 'otro nombre', 'client_external_id' => '501'])->crear(0, 'e2e_test'));
        $t->assertEquals([200, $a['data']['category_id']], [$s, $b['data']['category_id']]);
        [$s, $c] = $llamar(fn () => $ctrl(['name' => 'zzb LENCERIA '])->crear(0, 'e2e_test'));
        $t->assertEquals([200, $a['data']['category_id']], [$s, $c['data']['category_id']]);
        [$s, $d] = $llamar(fn () => $ctrl(['name' => 'ZZB LENCERIA', 'parent_id' => $a['data']['category_id']])->crear(0, 'e2e_test'));
        $t->assertEquals(201, $s, 'bajo otra madre es otra categoría');
        $limpiarNombre('ZZB ');
    });

    $t->test('alta inválida: sin nombre o con madre inexistente → 422', function ($t) use ($ctrl, $llamar) {
        [$s] = $llamar(fn () => $ctrl(['name' => '   '])->crear(0, 'e2e_test'));
        [$s2] = $llamar(fn () => $ctrl(['name' => 'ZZC x', 'parent_id' => 999999999])->crear(0, 'e2e_test'));
        $t->assertEquals([422, 422], [$s, $s2]);
    });

    $t->test('PATCH mueve y rehace la ruta de la categoría y de sus descendientes', function ($t) use ($ctrl, $llamar, $crearFila, $limpiarNombre, $db, $p) {
        $limpiarNombre('ZZD ');
        $a = $crearFila('ZZD A'); $b = $crearFila('ZZD B'); $x = $crearFila('ZZD X', $a); $y = $crearFila('ZZD Y', $x);
        [$s] = $llamar(fn () => $ctrl(['parent_id' => $b])->actualizar($x, 0, 'e2e_test'));
        $t->assertEquals(200, $s);
        $ruta = array_map('intval', array_column($db->fetchAll("SELECT path_id FROM {$p}category_path WHERE category_id = ? ORDER BY `level`", [$y]), 'path_id'));
        $t->assertEquals([$b, $x, $y], $ruta);
        $limpiarNombre('ZZD ');
    });

    $t->test('PATCH: 409 hermana con el mismo nombre normalizado, 422 ciclo, 404 inexistente', function ($t) use ($ctrl, $llamar, $crearFila, $limpiarNombre) {
        $limpiarNombre('ZZE ');
        $a = $crearFila('ZZE Uno'); $b = $crearFila('ZZE Dós'); $h = $crearFila('ZZE Hija', $a);
        [$s1] = $llamar(fn () => $ctrl(['name' => 'zze dos'])->actualizar($a, 0, 'e2e_test'));
        [$s2] = $llamar(fn () => $ctrl(['parent_id' => $h])->actualizar($a, 0, 'e2e_test'));
        [$s3] = $llamar(fn () => $ctrl(['name' => 'x'])->actualizar(999999999, 0, 'e2e_test'));
        $t->assertEquals([409, 422, 404], [$s1, $s2, $s3]);
        $limpiarNombre('ZZE ');
    });
```

- [ ] **Paso 2: rojo.**

- [ ] **Paso 3: implementación**

`EventLogger.php`, junto a las de clientes:

```php
    public const EVENT_CATEGORY_CREATED  = 'category.created';
    public const EVENT_CATEGORY_UPDATED  = 'category.updated';
    public const EVENT_CATEGORY_DELETED  = 'category.deleted';
```

`CategoryController.php`:

```php
    // ─── POST /categories ────────────────────────────────────────────────────
    // Idempotente: por client_external_id y, si no viene, por nombre
    // normalizado bajo la misma madre (el TPV no admite dos hermanas iguales).
    public function crear(int $storeId, string $clientId): void
    {
        $body   = $this->getBody();
        $nombre = self::limpiar((string) ($body['name'] ?? ''));
        $padre  = (int) ($body['parent_id'] ?? 0);
        $estado = array_key_exists('status', $body) ? ((int) $body['status'] ? 1 : 0) : 1;
        $ext    = trim((string) ($body['client_external_id'] ?? ''));

        if ($errores = $this->validar($nombre, $padre)) {
            $this->response->validationError($errores);
            return;
        }
        if ($ext !== '' && ($ya = $this->porExterno($clientId, $ext)) !== null) {
            $this->response->success($this->filas($ya, $clientId)[0]);
            return;
        }
        if (($hermana = $this->hermanaConNombre($padre, $nombre, null)) !== null) {
            if ($ext !== '') { $this->enlazar($hermana, $clientId, $ext); }
            $this->response->success($this->filas($hermana, $clientId)[0]);
            return;
        }

        $p = $this->db->p('');
        $this->db->beginTransaction();
        try {
            $id = (int) $this->db->insert(
                "INSERT INTO {$p}category (image, parent_id, `top`, `column`, sort_order, status, date_added, date_modified)
                 VALUES ('', ?, ?, 1, 0, ?, NOW(), NOW())",
                [$padre, $padre === 0 ? 1 : 0, $estado]
            );
            $this->db->execute(
                "INSERT INTO {$p}category_description (category_id, language_id, name, description, meta_title, meta_description, meta_keyword)
                 VALUES (?, ?, ?, '', ?, '', '')",
                [$id, API_LANGUAGE_ID, $nombre, $nombre]
            );
            $this->db->execute("INSERT IGNORE INTO {$p}category_to_store (category_id, store_id) VALUES (?, ?)",
                [$id, max(0, $storeId)]);
            CategoryPath::rehacer($this->db, $id);
            if ($ext !== '') { $this->enlazar($id, $clientId, $ext); }
            $this->emitir(EventLogger::EVENT_CATEGORY_CREATED, $id, $storeId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->response->created($this->filas($id, $clientId)[0], '/categories/' . $id);
    }

    // ─── PATCH /categories/{id} ──────────────────────────────────────────────
    public function actualizar(int $id, int $storeId, string $clientId): void
    {
        $actual = $this->filas($id, $clientId)[0] ?? null;
        if ($actual === null) {
            $this->response->error('not_found', 'Category not found.', 404);
            return;
        }
        $body   = $this->getBody();
        $nombre = array_key_exists('name', $body) ? self::limpiar((string) $body['name']) : $actual['name'];
        $padre  = array_key_exists('parent_id', $body) ? (int) $body['parent_id'] : $actual['parent_id'];
        $estado = array_key_exists('status', $body) ? ((int) $body['status'] ? 1 : 0) : $actual['status'];
        $ext    = trim((string) ($body['client_external_id'] ?? ''));

        if ($errores = $this->validar($nombre, $padre)) {
            $this->response->validationError($errores);
            return;
        }
        if ($padre === $id || in_array($id, CategoryPath::ancestros($this->padres(), $padre), true)) {
            $this->response->error('invalid_parent', 'A category cannot be moved under itself or a descendant.', 422, 'parent_id');
            return;
        }
        if ($this->hermanaConNombre($padre, $nombre, $id) !== null) {
            $this->response->error('duplicate_name', 'A sibling category already has this name.', 409, 'name');
            return;
        }

        $p = $this->db->p('');
        $this->db->beginTransaction();
        try {
            $this->db->execute(
                "UPDATE {$p}category SET parent_id = ?, `top` = ?, status = ?, date_modified = NOW() WHERE category_id = ?",
                [$padre, $padre === 0 ? 1 : 0, $estado, $id]
            );
            $this->db->execute(
                "UPDATE {$p}category_description SET name = ?, meta_title = ? WHERE category_id = ? AND language_id = ?",
                [$nombre, $nombre, $id, API_LANGUAGE_ID]
            );
            if ($padre !== $actual['parent_id']) { CategoryPath::rehacer($this->db, $id); }
            if ($ext !== '') { $this->enlazar($id, $clientId, $ext); }
            $this->emitir(EventLogger::EVENT_CATEGORY_UPDATED, $id, $storeId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->response->success($this->filas($id, $clientId)[0]);
    }

    private static function limpiar(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    private function validar(string $nombre, int $padre): array
    {
        $e = [];
        if ($nombre === '') { $e[] = ['field' => 'name', 'message' => 'name is required.']; }
        if (mb_strlen($nombre) > 255) { $e[] = ['field' => 'name', 'message' => 'name exceeds 255 chars.']; }
        if ($padre < 0 || ($padre > 0 && !$this->existe($padre))) {
            $e[] = ['field' => 'parent_id', 'message' => 'parent category not found.'];
        }
        return $e;
    }

    /** @return array<int,int> */
    private function padres(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT category_id, parent_id FROM ' . $this->db->p('category')) as $r) {
            $out[(int) $r['category_id']] = (int) $r['parent_id'];
        }
        return $out;
    }

    private function hermanaConNombre(int $padre, string $nombre, ?int $excepto): ?int
    {
        $p = $this->db->p('');
        $clave = NombreCategoria::normalizar($nombre);
        foreach ($this->db->fetchAll(
            "SELECT c.category_id, cd.name FROM {$p}category c
               JOIN {$p}category_description cd ON cd.category_id = c.category_id AND cd.language_id = ?
              WHERE c.parent_id = ? ORDER BY c.category_id",
            [API_LANGUAGE_ID, $padre]
        ) as $r) {
            if ((int) $r['category_id'] !== $excepto && NombreCategoria::normalizar((string) $r['name']) === $clave) {
                return (int) $r['category_id'];
            }
        }
        return null;
    }

    private function porExterno(string $clientId, string $ext): ?int
    {
        $p = $this->db->p('');
        $r = $this->db->fetchOne(
            "SELECT m.tpv_category_id FROM {$p}api_external_mapping_category m
               JOIN {$p}category c ON c.category_id = m.tpv_category_id
              WHERE m.client_id = ? AND m.external_id = ? LIMIT 1",
            [$clientId, $ext]
        );
        return $r ? (int) $r['tpv_category_id'] : null;
    }

    private function enlazar(int $categoryId, string $clientId, string $ext): void
    {
        $p = $this->db->p('');
        $this->db->execute(
            "DELETE FROM {$p}api_external_mapping_category WHERE client_id = ? AND (tpv_category_id = ? OR external_id = ?)",
            [$clientId, $categoryId, $ext]
        );
        $canal = (string) ($this->db->fetchOne("SELECT channel FROM {$p}api_clients WHERE client_id = ?", [$clientId])['channel'] ?? '');
        $this->db->execute(
            "INSERT INTO {$p}api_external_mapping_category (tpv_category_id, channel, client_id, external_id) VALUES (?, ?, ?, ?)",
            [$categoryId, $canal, $clientId, $ext]
        );
    }

    private function emitir(string $evento, int $id, int $storeId): void
    {
        if ($this->eventLogger === null) { return; }
        $f = $evento === EventLogger::EVENT_CATEGORY_DELETED ? null : ($this->filas($id, '')[0] ?? null);
        $campos = $f === null
            ? ['category_id' => $id]
            : ['category_id' => $id, 'name' => $f['name'], 'parent_id' => $f['parent_id'], 'status' => $f['status']];
        $this->eventLogger->logEvent($evento, 'category', $id, $campos, EventLogger::SOURCE_API, $storeId);
    }
```

Rutas:

```php
    $t->post('/categories', function ($app, $p) use ($db, $res, $log) {
        $app->guard('categories:write', true, 'POST /categories', function ($token, $storeId) use ($db, $res, $log) {
            (new CategoryController($db, $res, $log))->crear($storeId, (string) $token['sub']);
        });
    });
    $t->patch('/categories/{id:\d+}', function ($app, $p) use ($db, $res, $log) {
        $app->guard('categories:write', true, null, function ($token, $storeId) use ($db, $res, $log, $p) {
            (new CategoryController($db, $res, $log))->actualizar((int) $p['id'], $storeId, (string) $token['sub']);
        });
    });
```

Si `--suite=endpoints` o la suite de contrato de rutas (`test_api_design`, «dos listas de rutas», memoria `reference_dos_listas_de_rutas_se_desincronizan`) exige registrar la ruta en otra lista u OpenAPI, añadirla allí también.

- [ ] **Paso 4: verde.**
- [ ] **Paso 5: mutantes** — (a) `hermanaConNombre` comparando `$r['name'] === $nombre` en vez de normalizado → falla el 200 de «zzb LENCERIA» y el 409; (b) quitar `CategoryPath::rehacer` del PATCH → falla la ruta de Y; (c) `emitir` con `SOURCE_TPV` → sin origen. Restaurar.
- [ ] **Paso 6: commit** `feat(categorias): alta y cambio de categorías por la API, idempotentes y con la ruta del TPV`.

### Tarea A5: `DELETE /categories/{id}`

**Ficheros:** `controllers/CategoryController.php`, `routes.php`, suite.

**Interfaces:** Produce `borrar(int $id, int $storeId, string $clientId): void` (204 / 404). Hijas suben a la madre; eventos `category.updated` por hija y `category.deleted`.

- [ ] **Paso 1: test en rojo**

```php
    $t->test('DELETE: sube las hijas, limpia productos y enlace, avisa', function ($t) use ($ctrl, $llamar, $crearFila, $limpiarNombre, $db, $p) {
        $limpiarNombre('ZZF ');
        $a = $crearFila('ZZF A'); $x = $crearFila('ZZF X', $a); $h = $crearFila('ZZF H', $x); $n = $crearFila('ZZF N', $h);
        $db->execute("INSERT IGNORE INTO {$p}product_to_category (product_id, category_id) VALUES (999999901, ?)", [$x]);
        $db->execute("INSERT INTO {$p}api_external_mapping_category (tpv_category_id, channel, client_id, external_id) VALUES (?, 'woocommerce', 'e2e_test', '9001')", [$x]);
        [$s] = $llamar(fn () => $ctrl()->borrar($x, 0, 'e2e_test'));
        $t->assertEquals(204, $s);
        $t->assertEquals($a, (int) $db->fetchOne("SELECT parent_id FROM {$p}category WHERE category_id = ?", [$h])['parent_id']);
        $ruta = array_map('intval', array_column($db->fetchAll("SELECT path_id FROM {$p}category_path WHERE category_id = ? ORDER BY `level`", [$n]), 'path_id'));
        $t->assertEquals([$a, $h, $n], $ruta, 'la nieta también rehace la ruta');
        $t->assertEquals([0, 0, 0], [
            (int) $db->fetchOne("SELECT COUNT(*) n FROM {$p}category WHERE category_id = ?", [$x])['n'],
            (int) $db->fetchOne("SELECT COUNT(*) n FROM {$p}product_to_category WHERE category_id = ?", [$x])['n'],
            (int) $db->fetchOne("SELECT COUNT(*) n FROM {$p}api_external_mapping_category WHERE tpv_category_id = ?", [$x])['n'],
        ]);
        $tipos = array_column($db->fetchAll("SELECT event_type FROM {$p}api_events WHERE resource = 'category' AND resource_id IN (?, ?) ORDER BY event_id DESC LIMIT 2", [$x, $h]), 'event_type');
        sort($tipos);
        $t->assertEquals(['category.deleted', 'category.updated'], $tipos);
        [$s] = $llamar(fn () => $ctrl()->borrar($x, 0, 'e2e_test'));
        $t->assertEquals(404, $s);
        $limpiarNombre('ZZF ');
    });
```

- [ ] **Paso 2: rojo.**
- [ ] **Paso 3: implementación**

```php
    // ─── DELETE /categories/{id} ─────────────────────────────────────────────
    // Borra SOLO esa: las hijas suben a su madre, como hace WordPress al borrar
    // un término. El borrado en cascada es del TPV (cat_del), no de la API.
    public function borrar(int $id, int $storeId, string $clientId): void
    {
        $p = $this->db->p('');
        $fila = $this->db->fetchOne("SELECT parent_id FROM {$p}category WHERE category_id = ?", [$id]);
        if ($fila === null) {
            $this->response->error('not_found', 'Category not found.', 404);
            return;
        }
        $madre = (int) $fila['parent_id'];
        $hijas = array_map(static fn ($r) => (int) $r['category_id'],
            $this->db->fetchAll("SELECT category_id FROM {$p}category WHERE parent_id = ?", [$id]));

        $this->db->beginTransaction();
        try {
            $this->db->execute("UPDATE {$p}category SET parent_id = ?, `top` = ? WHERE parent_id = ?",
                [$madre, $madre === 0 ? 1 : 0, $id]);
            foreach (['category', 'category_description', 'category_to_store', 'category_path', 'product_to_category'] as $tabla) {
                $this->db->execute("DELETE FROM {$p}{$tabla} WHERE category_id = ?", [$id]);
            }
            $this->db->execute("DELETE FROM {$p}api_external_mapping_category WHERE tpv_category_id = ?", [$id]);
            foreach ($hijas as $h) {
                CategoryPath::rehacer($this->db, $h);
                $this->emitir(EventLogger::EVENT_CATEGORY_UPDATED, $h, $storeId);
            }
            $this->emitir(EventLogger::EVENT_CATEGORY_DELETED, $id, $storeId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->response->noContent();
    }
```

Ruta:

```php
    $t->delete('/categories/{id:\d+}', function ($app, $p) use ($db, $res, $log) {
        $app->guard('categories:write', true, null, function ($token, $storeId) use ($db, $res, $log, $p) {
            (new CategoryController($db, $res, $log))->borrar((int) $p['id'], $storeId, (string) $token['sub']);
        });
    });
```

- [ ] **Paso 4: verde.**
- [ ] **Paso 5: mutantes** — (a) no mover hijas (quitar el UPDATE) → falla parent de H; (b) no rehacer rutas de hijas → falla la ruta de la nieta. Restaurar.
- [ ] **Paso 6: commit** `feat(categorias): borrar una categoría por la API sube sus hijas como WordPress`.

### Tarea A6: `category_ids` en `/products/bulk` y capacidad en `/health`

**Ficheros:** `controllers/ProductController.php` (helper `asignarCategorias`, `update()` línea ~1155, `bulk()` ramas de alta ~930 y actualización ~898), `controllers/HealthController.php:35`, suite.

**Interfaces:** Produce `ProductController::asignarCategorias(int $productId, array $categoryIds): void` (privado; sustituye); `/health` → `capabilities` incluye `'categories_write'`.

- [ ] **Paso 1: tests en rojo**

```php
    $t->suite('Categorías — productos y capacidad');

    $t->test('/health anuncia categories_write', function ($t) use ($db) {
        [$st, $body] = capture_response(fn () => (new HealthController($db, new Response()))->live());
        $j = json_decode((string) $body, true);
        $t->assert(in_array('categories_write', $j['capabilities'] ?? [], true), 'sin esto el plugin no empieza la unión');
    });

    $t->test('el bulk escribe category_ids en altas y los sustituye en actualizaciones', function ($t) use ($db, $p, $crearFila, $limpiarNombre) {
        $limpiarNombre('ZZG ');
        $c1 = $crearFila('ZZG Uno'); $c2 = $crearFila('ZZG Dos');
        $model = 'ZZG-' . uniqid();
        $bulk = function (array $item) use ($db) {
            $ctrl = new class($db, new Response(), new EventLogger($db), ['items' => [$item]]) extends ProductController {
                public function __construct(DB $db, Response $r, EventLogger $e, private array $cuerpo) { parent::__construct($db, $r, $e); }
                protected function getBody(): array { return $this->cuerpo; }
            };
            return capture_response(fn () => $ctrl->bulk(0, 'e2e_test'));
        };
        $bulk(['model' => $model, 'name' => 'ZZG prod', 'price' => 1, 'category_ids' => [$c1]]);
        $pid = (int) $db->fetchOne("SELECT product_id FROM {$p}product WHERE model = ?", [$model])['product_id'];
        $cats = fn () => array_map('intval', array_column($db->fetchAll("SELECT category_id FROM {$p}product_to_category WHERE product_id = ? ORDER BY category_id", [$pid]), 'category_id'));
        $t->assertEquals([$c1], $cats());
        $bulk(['model' => $model, 'name' => 'ZZG prod', 'price' => 1, 'category_ids' => [$c2]]);
        $t->assertEquals([$c2], $cats(), 'actualización: sustituye');
        $bulk(['model' => $model, 'name' => 'ZZG prod', 'price' => 1]);
        $t->assertEquals([$c2], $cats(), 'sin category_ids: no se tocan');
        foreach (['product', 'product_description', 'product_to_store', 'product_to_category'] as $tb) {
            $db->execute("DELETE FROM {$p}{$tb} WHERE product_id = ?", [$pid]);
        }
        $limpiarNombre('ZZG ');
    });
```

`ProductController::getBody` es `private` (línea ~2070): pasarlo a `protected` para poder inyectar el cuerpo (mismo patrón que `CustomerController`). Comprobar la firma real del constructor de `ProductController` y de `HealthController` y ajustar el `new`.

- [ ] **Paso 2: rojo.**
- [ ] **Paso 3: implementación**

```php
    /** Sustituye las categorías del producto (mismo SQL que update()). */
    private function asignarCategorias(int $productId, array $categoryIds): void
    {
        $this->db->execute('DELETE FROM ' . $this->db->p('product_to_category') . ' WHERE product_id = ?', [$productId]);
        foreach (array_unique(array_map('intval', $categoryIds)) as $catId) {
            if ($catId > 0) {
                $this->db->execute('INSERT IGNORE INTO ' . $this->db->p('product_to_category') . ' (product_id, category_id) VALUES (?, ?)',
                    [$productId, $catId]);
            }
        }
    }
```

- `update()` (línea ~1155): sustituir el bloque «Actualizar categorías si se envían» por `if (array_key_exists('category_ids', $body) && is_array($body['category_ids'])) { $this->asignarCategorias($id, $body['category_ids']); }`.
- `bulk()`, rama de actualización: antes de `$bulk->add($i, 200, ['product_id' => $existingId, …])`, añadir `if (array_key_exists('category_ids', $item) && is_array($item['category_ids'])) { $this->asignarCategorias($existingId, $item['category_ids']); }`.
- `bulk()`, rama de alta: tras el INSERT de `product_description`, `if (!empty($item['category_ids']) && is_array($item['category_ids'])) { $this->asignarCategorias($newId, $item['category_ids']); }`.
- `bulk()`: si `validateProductBody` rechaza claves desconocidas, añadir `category_ids` a las permitidas.
- `HealthController.php:35`: `'capabilities' => ['price_gross_write', 'categories_write'],` y ampliar el comentario.

- [ ] **Paso 4: verde**, más `--suite=bulk207` y `--suite=endpoints` sin regresiones nuevas.
- [ ] **Paso 5: mutante** — quitar la línea de la rama de actualización → falla «sustituye». Restaurar.
- [ ] **Paso 6: commit** `feat(categorias): el volcado masivo de productos lleva sus categorías y /health lo anuncia`.

---

## Fase B — Conectores del TPV

### Tarea B1: ámbitos en los dos conectores

**Ficheros:**
- Modificar: `~/dev/.worktrees/plugin_wordpress_connector/feat-categorias-ambitos-cache/wordpress_connector/config.php:207-208`
- Modificar: `~/dev/.worktrees/plugin_prestashop_connector/feat-categorias-ambitos/prestashop_connector/config.php:207-208`
- Crear: `tests/test_ambitos.php` en cada repo

- [ ] **Paso 1: test en rojo** (`plugin_wordpress_connector/tests/test_ambitos.php`; en PrestaShop igual cambiando la carpeta):

```php
<?php
/**
 * El cliente que crea el conector lleva los ámbitos que el plugin de la tienda
 * usa al escribir. Sin customers:write ni categories:write, la API responde 403
 * (los ámbitos se congelan por cliente). SPEC_categorias_woo_tpv.md E7.
 * Uso: php tests/test_ambitos.php
 */
require __DIR__ . '/_arnes.php';
$src = (string) file_get_contents($PLUGIN . '/config.php');
preg_match("/\\\$scopes\s*=\s*'([^']*)'\s*\.\s*'([^']*)'/", $src, $m);
$ambitos = preg_split('/\s+/', trim(($m[1] ?? '') . ($m[2] ?? '')));
foreach (['customers:write', 'categories:read', 'categories:write'] as $a) {
    check("el cliente nuevo lleva $a", in_array($a, $ambitos, true), $fail);
}
fin($fail);
```

(Si el repo de PrestaShop no tiene `_arnes.php`, copiar el de `plugin_wordpress_connector`.)

- [ ] **Paso 2: rojo** — `php tests/test_ambitos.php` → FALLA en `customers:write` y `categories:write`.
- [ ] **Paso 3: implementación** — en los dos `config.php`:

```php
        $scopes = 'products:read products:write stock:read stock:write orders:read orders:write '
                . 'returns:read returns:write webhooks:read webhooks:write customers:read customers:write categories:read categories:write';
```

Registrar el test en `tests/run.php` si el runner los lista uno a uno.

- [ ] **Paso 4: verde** — `php tests/test_ambitos.php` y `php tests/run.php`.
- [ ] **Paso 5: mutante** — quitar `categories:write` → falla. Restaurar.
- [ ] **Paso 6: commit** en cada repo: `fix(conector): el cliente de la API nace con los ámbitos de escritura de clientes y categorías`.

### Tarea B2: el bridge invalida la caché de categorías

**Ficheros:** `plugin_wordpress_connector/wordpress_connector/bridge.php` (hooks `on_category_*`, líneas ~693-753), `tests/test_bridge_categoria_cache.php`.

**Interfaces:** Produce `cnx_invalidar_cache_categorias($db): void` (borra de `{api}api_cache` las filas con etiqueta `,category:*,`; best-effort).

- [ ] **Paso 1: test en rojo**

```php
<?php
/**
 * Un cambio de categoría en el TPV purga la caché de GET /categories.
 * La API cachea 300 s con la etiqueta category:* y solo su EventLogger la
 * invalida; los eventos del TPV los escribe el bridge sin pasar por ahí
 * (SPEC_categorias_woo_tpv.md E8).
 * Uso: php tests/test_bridge_categoria_cache.php
 */
require __DIR__ . '/_arnes.php';
$hooks = require $PLUGIN . '/bridge.php';
foreach (['on_category_created', 'on_category_updated', 'on_category_deleted'] as $h) {
    $db = new class {
        public array $sql = [];
        public function escape($v): string { return addslashes((string) $v); }
        public function query(string $s) { $this->sql[] = $s; return (object) ['row' => [], 'rows' => [], 'num_rows' => 1]; }
    };
    $hooks[$h](7, ['name' => 'X', 'store_id' => 0], $db);
    $purga = array_values(array_filter($db->sql, fn ($s) => preg_match('/DELETE FROM `[^`]*api_cache` WHERE `tags` LIKE \'%,category:\*,%\'/', $s)));
    check("$h purga la caché de categorías", count($purga) === 1, $fail);
}
fin($fail);
```

Antes, mirar cómo los otros tests cargan `bridge.php` (`test_bridge_aviso_producto.php`) y usar la misma forma (si el bridge no devuelve el array con `require`, imitar al test hermano).

- [ ] **Paso 2: rojo.**
- [ ] **Paso 3: implementación** — en `bridge.php`, junto a `cnx_log_event` y dentro del mismo guard `function_exists`:

```php
if (!function_exists('cnx_invalidar_cache_categorias')) {
/**
 * Purga la caché de GET /categories de la API (etiqueta category:*). Solo la
 * capa BD: la copia APCu de la API caduca sola en ≤60 s. Best-effort: si falla,
 * la caché caduca a los 300 s y no debe romper el guardado de la categoría.
 */
function cnx_invalidar_cache_categorias($db): void
{
    try {
        $db->query("DELETE FROM `" . cnx_db_prefix() . "api_cache` WHERE `tags` LIKE '%,category:*,%'");
    } catch (\Throwable $e) { /* best-effort */ }
}
}
```

Y en los tres hooks `on_category_*`, tras `cnx_log_event(...)`: `cnx_invalidar_cache_categorias($db);`.

- [ ] **Paso 4: verde** + `php tests/run.php` (la suite entera del conector).
- [ ] **Paso 5: mutante** — quitarlo de `on_category_deleted` → falla esa línea. Restaurar.
- [ ] **Paso 5b: PrestaShop** — `grep -n "on_category_" ~/dev/.worktrees/plugin_prestashop_connector/feat-categorias-ambitos/prestashop_connector/bridge.php`. Si tiene los mismos hooks, repetir esta tarea allí (mismo test, misma función); si no los tiene, anotarlo en el commit de B1 y seguir (PrestaShop queda fuera de la spec).
- [ ] **Paso 6: commit** `fix(bridge): un cambio de categoría en el TPV purga la caché de categorías de la API`.

---

## Fase C — TPV (`tpv`, worktree `feat-csv-avisa-categorias`)

### Tarea C1: el CSV avisa de las categorías que crea

**Ficheros:** `admin/classes/upload.php` (`to_category`, líneas ~1945-2010; nuevo método `avisar_categoria_creada`), `admin/tests/UploadCsvCategoriaAvisaTest.php`.

**Interfaces:** Produce `Upload::avisar_categoria_creada(int $id, string $nombre, int $padre): void` (dispara `on_category_created` con el mismo contexto que `category.php:284`).

- [ ] **Paso 1: test en rojo**

```php
<?php
declare(strict_types=1);
/**
 * La importación CSV avisa a los plugins de cada categoría que crea.
 * to_category() creaba «A///B» sin on_category_created: la tienda online no
 * se enteraba nunca (SPEC_categorias_woo_tpv.md E6). EJECUTA los métodos
 * reales (extraídos del fuente) con una BD en memoria.
 * Uso: php admin/tests/UploadCsvCategoriaAvisaTest.php
 */
$passed = 0; $failed = 0;
function check(string $n, $got, $exp): void {
    global $passed, $failed;
    if ($got === $exp) { $passed++; echo "  ✓ $n\n"; }
    else { $failed++; echo "  ✗ $n\n      esperado: " . var_export($exp, true) . "\n      obtenido: " . var_export($got, true) . "\n"; }
}
define('DB_PREFIX', 'oc_'); define('POS_STOREID', 0); define('POS_LANGUAGEID', 2);
$GLOBALS['traza'] = [];
final class PluginManager {
    private static ?self $i = null;
    public static function getInstance(): self { return self::$i ??= new self(); }
    public function trigger(string $hook, $id, array $ctx = [], $db = null) { $GLOBALS['traza'][] = [$hook, $id, $ctx]; return []; }
}
/** BD en memoria: ninguna categoría existe; cada INSERT en oc_category da un id nuevo. */
final class DbFalsa {
    private int $id = 500;
    public function escape($v) { return addslashes((string) $v); }
    public function getLastId() { return $this->id; }
    public function query(string $sql) {
        if (str_starts_with($sql, 'INSERT INTO `oc_category`(')) { $this->id++; }
        return (object) ['row' => [], 'rows' => [], 'num_rows' => 0];
    }
}
$src = file_get_contents(__DIR__ . '/../classes/upload.php');
$metodos = '';
foreach (['to_category', 'avisar_categoria_creada'] as $m) {
    if (!preg_match('/\n    (?:public\s+|private\s+)?function ' . $m . '\s*\(.*?\n    \}/s', $src, $mm)) {
        echo "  ✗ no se encontró $m en classes/upload.php\n\n0 passed, 1 failed\n"; exit(1);
    }
    $metodos .= $mm[0] . "\n";
}
eval('final class UploadCat { public $db; ' . $metodos . ' }');

echo "Importación CSV: crear categorías avisa a los plugins\n";
$u = new UploadCat(); $u->db = new DbFalsa();
$u->to_category(['ROPA///ROPA INFANTIL///PIJAMAS'], 900);
$avisos = array_map(fn ($t) => [$t[1], $t[2]['name'], $t[2]['parent_id']],
    array_values(array_filter($GLOBALS['traza'], fn ($t) => $t[0] === 'on_category_created')));
check('un aviso por categoría creada, con su madre', $avisos,
    [[501, 'ROPA', 0], [502, 'ROPA INFANTIL', 501], [503, 'PIJAMAS', 502]]);
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
```

- [ ] **Paso 2: rojo** — `php admin/tests/UploadCsvCategoriaAvisaTest.php` → «no se encontró avisar_categoria_creada».
- [ ] **Paso 3: implementación** — en `upload.php`, nuevo método junto a `to_category`:

```php
    /**
     * Aviso on_category_created, con el mismo contexto que category.php:284.
     * Sin él, una categoría creada por CSV no llegaba nunca a la tienda online.
     */
    private function avisar_categoria_creada(int $id, string $nombre, int $padre): void
    {
        if (class_exists('PluginManager')) {
            PluginManager::getInstance()->trigger('on_category_created', $id, [
                'category_id' => $id,
                'name'        => $nombre,
                'parent_id'   => $padre,
                'status'      => 1,
                'store_id'    => (int) POS_STOREID,
            ], $this->db);
        }
    }
```

y en `to_category`, en los DOS sitios que crean (tras `$mtrz[$i]['level'] = $i;` y antes de `$ban_new = 1;`): `$this->avisar_categoria_creada((int) $new_id, (string) $subcat[$i], (int) $cat_last);` (`$cat_last` es todavía la madre en ese punto).

- [ ] **Paso 4: verde** + `php admin/tests/UploadBorrarPorModeloAvisaTest.php` sigue verde.
- [ ] **Paso 5: mutante** — quitar la llamada de la rama `else` (niveles 2.º y siguientes) → faltan dos avisos. Restaurar.
- [ ] **Paso 6: commit** `fix(csv): crear categorías desde la importación avisa a los plugins`.

---

## Fase W — Plugin de la tienda (`woocomerce-pos-sync`, worktree `spec-categorias-bidireccional`, rama `feat/categorias-bidireccional`, carpeta `woocommerce-conector`)

Todos los tests nuevos se registran en `tests/run.php` (un `require_once` junto a los demás y la llamada `run_…_tests($t)` donde se llaman los otros). Comando: `php tests/run.php` desde `woocommerce-conector`.

### Tarea W1: `TPV_Sync_Categorias::normalizar` y `juntar` (puras)

**Ficheros:** Crear `includes/class-categorias.php`, `tests/test_categorias_puras.php`; modificar `woocommerce-conector.php` (require tras `class-reconciler.php`).

**Interfaces:** Produce `TPV_Sync_Categorias::normalizar(string $s): string` (copia EXACTA de la API) y `TPV_Sync_Categorias::juntar(array ...$listas): array` (ints > 0, únicos, ordenados).

- [ ] **Paso 1: test en rojo**

```php
<?php
declare(strict_types=1);
/** Piezas puras de categorías (SPEC_categorias_woo_tpv.md §1.1). */
require_once dirname(__DIR__) . '/includes/class-categorias.php';

function run_categorias_puras_tests(WooTestRunner $t): void
{
    $t->suite('Categorías — una sola regla de nombre (los MISMOS vectores que la API)');

    $t->test('mayúsculas, acentos, ñ, espacios y entidades se pliegan', function ($t) {
        $n = [TPV_Sync_Categorias::class, 'normalizar'];
        $t->assertEquals('lenceria', $n('Lencería'));
        $t->assertEquals('lenceria', $n('  LENCERIA '));
        $t->assertEquals('nino', $n('NIÑO'));
        $t->assertEquals('cosmetica', $n('CÓSMETICA'));
        $t->assertEquals('hogar & cocina', $n('Hogar &amp; Cocina'));
        $t->assertEquals('tonico facial', $n("TONICO   FACIAL "));
    });

    $t->test('juntar: suma sin repetidos, sin ceros, ordenada', function ($t) {
        $t->assertEquals([3, 5, 9], TPV_Sync_Categorias::juntar([9, 3], [5, 3, 0]));
        $t->assertEquals([], TPV_Sync_Categorias::juntar([], []));
    });
}
```

- [ ] **Paso 2: rojo** — `php tests/run.php` → FALLA (fichero inexistente).
- [ ] **Paso 3: implementación** `includes/class-categorias.php`:

```php
<?php
/**
 * Piezas puras de la sincronización de categorías (SPEC_categorias_woo_tpv.md).
 * Sin WordPress ni API: se prueban sin arnés.
 */
if (!defined('ABSPATH')) exit;

final class TPV_Sync_Categorias
{
    /**
     * La regla ÚNICA para decir si dos nombres son «el mismo». Copia exacta de
     * Api\V1\NombreCategoria::normalizar() (api_tpv): los dos tests usan los
     * mismos vectores. WordPress ya pliega así al comparar (colación).
     */
    public static function normalizar(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        $s = mb_strtolower($s, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) {
                $s = preg_replace('/\p{Mn}+/u', '', $d) ?? $d;
            }
        } else {
            $s = strtr($s, ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
                'ê' => 'e', 'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o',
                'ô' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c']);
        }
        return $s;
    }

    /** @return list<int> */
    public static function juntar(array ...$listas): array
    {
        $todo = [];
        foreach ($listas as $l) {
            foreach ($l as $v) {
                if ((int) $v > 0) { $todo[(int) $v] = true; }
            }
        }
        $out = array_keys($todo);
        sort($out);
        return $out;
    }
}
```

- [ ] **Paso 4: verde.** **Paso 5: mutante** — quitar `html_entity_decode` → falla «hogar & cocina». **Paso 6: commit** `feat(categorias): regla única de nombre y suma de categorías`.

### Tarea W2: `planUnion` (pura)

**Ficheros:** `includes/class-categorias.php`, `tests/test_categorias_puras.php`, fixture `tests/fixtures/lulubeauty_categorias.php` (los dos árboles del 14-10: copiar `tpv.tsv` y `woo1.json`/`woo2.json` del scratchpad de la sesión del 14-10 a arrays PHP; si ya no existen, regenerarlos con las mismas consultas de solo lectura: `a-ro` → `SELECT c.category_id,c.parent_id,cd.name FROM tnt_b2a608bdf14d.t908183c9category c JOIN …category_description cd …` y la Store API pública `https://lulubeauty.es/wp-json/wc/store/v1/products/categories?per_page=100&page=1|2`).

**Interfaces:**
- Entrada: `$tpv` = lista de `{category_id:int, parent_id:int, name:string, external_id:?string}` (la de `GET /categories?todas=1`); `$woo` = lista de `{term_id:int, parent:int, name:string, tpv_id:int}` (0 = sin enlace); `$porDefecto` = term_id de «Sin categorizar».
- Produce: `TPV_Sync_Categorias::planUnion(array $tpv, array $woo, int $porDefecto): array` → `['parejas' => list<array{tpv:int, term:int}>, 'crearEnWoo' => list<int /*category_id, madres antes*/>, 'crearEnTpv' => list<int /*term_id, madres antes*/>]`.

- [ ] **Paso 1: tests en rojo**

```php
    $t->suite('Categorías — plan de unión (solo aditivo)');

    $T = fn (int $id, int $padre, string $n, ?string $ext = null) => ['category_id' => $id, 'parent_id' => $padre, 'name' => $n, 'external_id' => $ext];
    $W = fn (int $id, int $padre, string $n, int $tpv = 0) => ['term_id' => $id, 'parent' => $padre, 'name' => $n, 'tpv_id' => $tpv];

    $t->test('pasadas: enlace (termmeta o external_id) → nombre+madre → solo nombre', function ($t) use ($T, $W) {
        $tpv = [$T(1, 0, 'Ropa'), $T(2, 1, 'Camisetas'), $T(3, 0, 'Niño'), $T(4, 3, 'Camisetas'), $T(5, 0, 'Viejo', '90')];
        $woo = [$W(10, 0, 'ROPA'), $W(20, 0, 'Camisetas'), $W(30, 0, 'NINO'), $W(40, 30, 'camisetas'), $W(90, 0, 'Renombrada'), $W(99, 0, 'Sin categorizar')];
        $plan = TPV_Sync_Categorias::planUnion($tpv, $woo, 99);
        $pares = array_map(fn ($p) => $p['tpv'] . '-' . $p['term'], $plan['parejas']);
        sort($pares);
        $t->assertEquals(['1-10', '2-20', '3-30', '4-40', '5-90'], $pares,
            '4↔40 por nombre+madre; 2↔20 por nombre solo; 5↔90 por external_id aunque se llame distinto');
        $t->assertEquals([], $plan['crearEnWoo']);
        $t->assertEquals([], $plan['crearEnTpv'], 'Sin categorizar nunca se crea en el TPV');
    });

    $t->test('lo que falta se crea, madres antes que hijas; nada se mueve', function ($t) use ($T, $W) {
        $tpv = [$T(7, 6, 'Hija'), $T(6, 0, 'Madre')];
        $woo = [$W(71, 70, 'Nieta web'), $W(70, 0, 'Madre web'), $W(72, 99, 'Bajo sin categorizar')];
        $plan = TPV_Sync_Categorias::planUnion($tpv, $woo, 99);
        $t->assertEquals([6, 7], $plan['crearEnWoo']);
        $t->assertEquals([70, 71, 72], $plan['crearEnTpv']);
    });

    $t->test('cada una se empareja una vez; madre desconocida o ciclo no cuelgan', function ($t) use ($T, $W) {
        $tpv = [$T(1, 0, 'Uno'), $T(2, 0, 'uno'), $T(3, 99, 'Huérfana'), $T(4, 5, 'C1'), $T(5, 4, 'C2')];
        $woo = [$W(10, 0, 'UNO')];
        $plan = TPV_Sync_Categorias::planUnion($tpv, $woo, 0);
        $t->assertEquals([['tpv' => 1, 'term' => 10]], $plan['parejas']);
        $crear = $plan['crearEnWoo'];
        sort($crear);
        $t->assertEquals([2, 3, 4, 5], $crear, 'en un ciclo el orden da igual: que estén todas y que termine');
    });

    $t->test('lulubeauty 14-10: 58 parejas, 23 altas en la web, 58 en el TPV', function ($t) {
        $f = require __DIR__ . '/fixtures/lulubeauty_categorias.php';
        $plan = TPV_Sync_Categorias::planUnion($f['tpv'], $f['woo'], $f['por_defecto']);
        $t->assertEquals([58, 23, 58], [count($plan['parejas']), count($plan['crearEnWoo']), count($plan['crearEnTpv'])]);
    });
```

- [ ] **Paso 2: rojo.**
- [ ] **Paso 3: implementación** (en `TPV_Sync_Categorias`):

```php
    /**
     * Unión inicial, SOLO aditiva (spec §4): empareja y dice qué crear en cada
     * lado. No mueve ni renombra nada. Pasadas: (a) enlace ya conocido
     * (termmeta o external_id de la API), (b) mismo nombre y misma madre por
     * nombre, (c) mismo nombre solo. Cada una se empareja como mucho una vez.
     */
    public static function planUnion(array $tpv, array $woo, int $porDefecto): array
    {
        $woo = array_values(array_filter($woo, fn ($w) => (int) $w['term_id'] !== $porDefecto));
        usort($tpv, fn ($a, $b) => (int) $a['category_id'] <=> (int) $b['category_id']);
        usort($woo, fn ($a, $b) => (int) $a['term_id'] <=> (int) $b['term_id']);

        $nombreTpv = []; $padreTpv = [];
        foreach ($tpv as $c) {
            $nombreTpv[(int) $c['category_id']] = self::normalizar((string) $c['name']);
            $padreTpv[(int) $c['category_id']] = (int) $c['parent_id'];
        }
        $nombreWoo = []; $padreWoo = [];
        foreach ($woo as $w) {
            $nombreWoo[(int) $w['term_id']] = self::normalizar((string) $w['name']);
            $padreWoo[(int) $w['term_id']] = (int) $w['parent'] === $porDefecto ? 0 : (int) $w['parent'];
        }
        $madreTpv = fn (int $id) => $nombreTpv[$padreTpv[$id] ?? 0] ?? '';
        $madreWoo = fn (int $id) => $nombreWoo[$padreWoo[$id] ?? 0] ?? '';

        $par = [];            // category_id => term_id
        $usadoW = [];         // term_id => true
        $emparejar = function (int $c, int $w) use (&$par, &$usadoW) {
            if (!isset($par[$c]) && !isset($usadoW[$w])) { $par[$c] = $w; $usadoW[$w] = true; }
        };
        // (a) enlace conocido
        foreach ($woo as $w) {
            $tid = (int) $w['tpv_id'];
            if ($tid > 0 && isset($nombreTpv[$tid])) { $emparejar($tid, (int) $w['term_id']); }
        }
        foreach ($tpv as $c) {
            $ext = (int) ($c['external_id'] ?? 0);
            if ($ext > 0 && isset($nombreWoo[$ext])) { $emparejar((int) $c['category_id'], $ext); }
        }
        // (b) nombre + madre, (c) solo nombre
        foreach ([true, false] as $conMadre) {
            foreach ($tpv as $c) {
                $cid = (int) $c['category_id'];
                if (isset($par[$cid])) { continue; }
                foreach ($woo as $w) {
                    $wid = (int) $w['term_id'];
                    if (isset($usadoW[$wid]) || $nombreWoo[$wid] !== $nombreTpv[$cid]) { continue; }
                    if ($conMadre && $madreWoo($wid) !== $madreTpv($cid)) { continue; }
                    $emparejar($cid, $wid);
                    break;
                }
            }
        }

        $parejas = [];
        foreach ($par as $c => $w) { $parejas[] = ['tpv' => $c, 'term' => $w]; }
        return [
            'parejas'    => $parejas,
            'crearEnWoo' => self::madresPrimero(array_diff(array_keys($nombreTpv), array_keys($par)), $padreTpv),
            'crearEnTpv' => self::madresPrimero(array_diff(array_keys($nombreWoo), array_keys($usadoW)), $padreWoo),
        ];
    }

    /** Ordena ids para que cada madre (si está en la lista) vaya antes que sus hijas. Corta ciclos. */
    private static function madresPrimero(array $ids, array $padres): array
    {
        $pendiente = array_flip(array_map('intval', $ids));
        ksort($pendiente);
        $out = [];
        $poner = function (int $id, array $camino) use (&$poner, &$pendiente, &$out, $padres) {
            if (!isset($pendiente[$id]) || isset($camino[$id])) { return; }
            $camino[$id] = true;
            $madre = $padres[$id] ?? 0;
            if ($madre > 0) { $poner($madre, $camino); }
            if (isset($pendiente[$id])) { unset($pendiente[$id]); $out[] = $id; }
        };
        foreach (array_keys($pendiente) as $id) { $poner((int) $id, []); }
        return $out;
    }
```

- [ ] **Paso 4: verde** (incluido el caso fijo 58/23/58; si no cuadra, revisar el fixture antes que el código: la simulación del 14-10 usó la misma regla).
- [ ] **Paso 5: mutantes** — (a) quitar la pasada `conMadre=true` → falla `4-40`; (b) quitar el filtro de `$porDefecto` → «Sin categorizar» aparece en `crearEnTpv`; (c) quitar `isset($camino[$id])` → el ciclo C1/C2 desborda (ejecutar con `timeout 10`). Restaurar.
- [ ] **Paso 6: commit** `feat(categorias): plan de unión inicial solo aditivo`.

### Tarea W3: adaptador de términos `TPV_Sync_Terminos` y su doble en memoria

**Ficheros:** Crear `includes/class-terminos.php`, `tests/fixtures/terminos_en_memoria.php`, `tests/test_terminos_en_memoria.php`; require en `woocommerce-conector.php`.

**Interfaces (producidas; las usan W4-W7):**

```php
class TPV_Sync_Terminos
{
    public const META = 'tpv_category_id';
    public string $ultimoError = '';
    /** @return list<array{term_id:int,parent:int,name:string,tpv_id:int}> */
    public function todos(): array;
    public function porTpvId(int $tpvId): int;            // 0 si no hay
    public function tpvIdDe(int $termId): int;            // 0 si no hay
    public function nombreDe(int $termId): string;
    public function padreDe(int $termId): int;
    public function enlazar(int $termId, int $tpvId): void;
    public function crear(string $nombre, int $padre): int; // 0 y $ultimoError si WP lo rechaza
    public function actualizar(int $termId, string $nombre, int $padre): bool;
    public function borrar(int $termId): void;
    /** @return list<int> */ public function deProducto(int $postId): array;
    public function ponerAProducto(int $postId, array $termIds): void;
    public function porDefecto(): int;
    public function olvidarEnlaces(): void;
}
```

- [ ] **Paso 1: test en rojo** — el doble tiene que comportarse como WP en lo que importa (rechazo de hermana duplicada por nombre normalizado):

```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/class-categorias.php';
require_once dirname(__DIR__) . '/includes/class-terminos.php';
require_once __DIR__ . '/fixtures/terminos_en_memoria.php';

function run_terminos_en_memoria_tests(WooTestRunner $t): void
{
    $t->suite('Términos en memoria: se portan como WordPress');
    $t->test('WP rechaza una hermana con el mismo nombre plegado; en otra madre, no', function ($t) {
        $m = new TerminosEnMemoria();
        $a = $m->crear('Lencería', 0);
        $t->assertEquals(0, $m->crear('LENCERIA', 0));
        $t->assertEquals('term_exists', $m->ultimoError);
        $t->assert($m->crear('LENCERIA', $a) > 0, 'bajo otra madre sí');
    });
    $t->test('enlazar, buscar por tpv_id y olvidar', function ($t) {
        $m = new TerminosEnMemoria();
        $a = $m->crear('Uno', 0);
        $m->enlazar($a, 55);
        $t->assertEquals([$a, 55], [$m->porTpvId(55), $m->tpvIdDe($a)]);
        $m->olvidarEnlaces();
        $t->assertEquals(0, $m->porTpvId(55));
    });
    $t->test('borrar sube las hijas y quita el término de los productos', function ($t) {
        $m = new TerminosEnMemoria();
        $a = $m->crear('A', 0); $b = $m->crear('B', $a); $c = $m->crear('C', $b);
        $m->ponerAProducto(1, [$b, $c]);
        $m->borrar($b);
        $t->assertEquals([$a, [$c]], [$m->padreDe($c), $m->deProducto(1)]);
    });
}
```

- [ ] **Paso 2: rojo.**
- [ ] **Paso 3: implementación**

`includes/class-terminos.php` (lo único que habla con la taxonomía de WP):

```php
<?php
/**
 * Las categorías de producto de WooCommerce (taxonomía product_cat), detrás de
 * una interfaz pequeña para poder probar la sincronización sin WordPress.
 * El termmeta tpv_category_id es una CACHÉ del enlace; la fuente es la API
 * (api_external_mapping_category). SPEC_categorias_woo_tpv.md §1.
 */
if (!defined('ABSPATH')) exit;

class TPV_Sync_Terminos
{
    public const META = 'tpv_category_id';
    public const TAX  = 'product_cat';
    public string $ultimoError = '';

    public function todos(): array
    {
        $out = [];
        $terms = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false]);
        foreach (is_array($terms) ? $terms : [] as $t) {
            $out[] = ['term_id' => (int) $t->term_id, 'parent' => (int) $t->parent,
                      'name' => (string) $t->name, 'tpv_id' => (int) get_term_meta($t->term_id, self::META, true)];
        }
        return $out;
    }

    public function porTpvId(int $tpvId): int
    {
        if ($tpvId <= 0) return 0;
        $ids = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'fields' => 'ids', 'number' => 1,
                          'meta_query' => [['key' => self::META, 'value' => $tpvId]]]);
        return is_array($ids) && $ids ? (int) $ids[0] : 0;
    }

    public function tpvIdDe(int $termId): int { return (int) get_term_meta($termId, self::META, true); }

    public function nombreDe(int $termId): string
    {
        $t = get_term($termId, self::TAX);
        return $t && !is_wp_error($t) ? (string) $t->name : '';
    }

    public function padreDe(int $termId): int
    {
        $t = get_term($termId, self::TAX);
        return $t && !is_wp_error($t) ? (int) $t->parent : 0;
    }

    public function enlazar(int $termId, int $tpvId): void { update_term_meta($termId, self::META, $tpvId); }

    public function crear(string $nombre, int $padre): int
    {
        $r = wp_insert_term($nombre, self::TAX, ['parent' => $padre]);
        if (is_wp_error($r)) { $this->ultimoError = $r->get_error_code(); return 0; }
        return (int) $r['term_id'];
    }

    public function actualizar(int $termId, string $nombre, int $padre): bool
    {
        $r = wp_update_term($termId, self::TAX, ['name' => $nombre, 'parent' => $padre]);
        if (is_wp_error($r)) { $this->ultimoError = $r->get_error_code(); return false; }
        return true;
    }

    public function borrar(int $termId): void { wp_delete_term($termId, self::TAX); }

    public function deProducto(int $postId): array
    {
        $ids = wp_get_object_terms($postId, self::TAX, ['fields' => 'ids']);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    public function ponerAProducto(int $postId, array $termIds): void
    {
        wp_set_object_terms($postId, array_values(array_map('intval', $termIds)), self::TAX, false);
    }

    public function porDefecto(): int { return (int) get_option('default_product_cat', 0); }

    public function olvidarEnlaces(): void { delete_metadata('term', 0, self::META, '', true); }
}
```

`tests/fixtures/terminos_en_memoria.php`:

```php
<?php
/** Doble en memoria de TPV_Sync_Terminos que se porta como WordPress en lo que importa. */
final class TerminosEnMemoria extends TPV_Sync_Terminos
{
    /** @var array<int,array{parent:int,name:string,tpv_id:int}> */
    public array $t = [];
    /** @var array<int,list<int>> post_id => term_ids */
    public array $p = [];
    private int $sig = 100;
    public int $defecto = 0;

    public function todos(): array
    {
        $o = [];
        foreach ($this->t as $id => $x) { $o[] = ['term_id' => $id] + $x; }
        return $o;
    }
    public function porTpvId(int $tpvId): int
    {
        foreach ($this->t as $id => $x) { if ($tpvId > 0 && $x['tpv_id'] === $tpvId) return $id; }
        return 0;
    }
    public function tpvIdDe(int $termId): int { return $this->t[$termId]['tpv_id'] ?? 0; }
    public function nombreDe(int $termId): string { return $this->t[$termId]['name'] ?? ''; }
    public function padreDe(int $termId): int { return $this->t[$termId]['parent'] ?? 0; }
    public function enlazar(int $termId, int $tpvId): void { if (isset($this->t[$termId])) $this->t[$termId]['tpv_id'] = $tpvId; }
    public function crear(string $nombre, int $padre): int
    {
        foreach ($this->t as $x) {
            if ($x['parent'] === $padre && TPV_Sync_Categorias::normalizar($x['name']) === TPV_Sync_Categorias::normalizar($nombre)) {
                $this->ultimoError = 'term_exists';
                return 0;
            }
        }
        $id = ++$this->sig;
        $this->t[$id] = ['parent' => $padre, 'name' => trim($nombre), 'tpv_id' => 0];
        return $id;
    }
    public function actualizar(int $termId, string $nombre, int $padre): bool
    {
        if (!isset($this->t[$termId])) return false;
        $this->t[$termId]['name'] = trim($nombre);
        $this->t[$termId]['parent'] = $padre;
        return true;
    }
    public function borrar(int $termId): void
    {
        $madre = $this->t[$termId]['parent'] ?? 0;
        foreach ($this->t as $id => $x) { if ($x['parent'] === $termId) $this->t[$id]['parent'] = $madre; }
        unset($this->t[$termId]);
        foreach ($this->p as $pid => $ids) { $this->p[$pid] = array_values(array_diff($ids, [$termId])); }
    }
    public function deProducto(int $postId): array { return $this->p[$postId] ?? []; }
    public function ponerAProducto(int $postId, array $termIds): void { $this->p[$postId] = array_values(array_map('intval', $termIds)); }
    public function porDefecto(): int { return $this->defecto; }
    public function olvidarEnlaces(): void { foreach ($this->t as $id => $x) { $this->t[$id]['tpv_id'] = 0; } }
}
```

- [ ] **Paso 4: verde.** **Paso 5: mutante** — en el doble, comparar nombres sin `normalizar` → falla el rechazo de «LENCERIA». **Paso 6: commit** `feat(categorias): adaptador de términos de Woo y su doble en memoria`.

### Tarea W4: `TPV_Sync_Categorias_Sync` — TPV → Woo (avisos y productos)

**Ficheros:** Crear `includes/class-categorias-sync.php`, `tests/test_categorias_tpv_a_woo.php`, `tests/fixtures/api_categorias_falsa.php`; require en `woocommerce-conector.php`.

**Interfaces:**
- Consume: `TPV_Sync_API_Client` (`get/post/patch/delete`, respuestas con `_ok`, `_status`, `data`), `TPV_Sync_Terminos`, `TPV_Sync_Categorias::juntar`.
- Produce:

```php
final class TPV_Sync_Categorias_Sync
{
    public const MARCA = 'tpv_sync_categorias_unidas';   // '' | 'arbol' | 'completa'
    public const AVISOS = 'tpv_sync_categorias_avisos';  // list<string>, últimos 20
    public function __construct(TPV_Sync_API_Client $api, TPV_Sync_Terminos $terminos);
    public static function fase(): string;
    public function asegurarEnWoo(int $tpvId, int $profundidad = 0): int;   // term_id o 0
    public function aplicarAviso(string $evento, int $tpvId): void;
    public function ponerCategoriasDesdeTpv(int $postId, array $tpvIds, bool $juntar): void;
    public function avisar(string $texto): void;
}
```

- [ ] **Paso 1: tests en rojo**

`tests/fixtures/api_categorias_falsa.php`:

```php
<?php
/** API del TPV en memoria para categorías: GET/POST/PATCH/DELETE /categories. */
class ApiCategoriasFalsa extends TPV_Sync_API_Client
{
    /** @var array<int,array{parent_id:int,name:string,status:int,external_id:?string}> */
    public array $c = [];
    public array $llamadas = [];
    public int $sig = 1000;
    public int $statusForzado = 0;   // p. ej. 403
    /** @var array<int,list<int>> tpv product_id => category_ids (para GET /products/{id}) */
    public array $productos = [];

    public function __construct() { parent::__construct(); }
    private function r(int $s, $data = null): array { return ['data' => $data, '_status' => $s, '_ok' => $s >= 200 && $s < 300]; }
    private function fila(int $id): array { return ['category_id' => $id] + $this->c[$id]; }

    public function get(string $path, array $params = []): array
    {
        $this->llamadas[] = "GET $path";
        if ($this->statusForzado) return $this->r($this->statusForzado);
        if ($path === '/categories' && !empty($params['todas'])) {
            return $this->r(200, array_map(fn ($id) => $this->fila($id), array_keys($this->c)));
        }
        if (preg_match('#^/categories/(\d+)$#', $path, $m)) {
            return isset($this->c[(int) $m[1]]) ? $this->r(200, $this->fila((int) $m[1])) : $this->r(404);
        }
        if (preg_match('#^/products/(\d+)$#', $path, $m) && isset($this->productos[(int) $m[1]])) {
            return $this->r(200, ['product_id' => (int) $m[1],
                'categories' => array_map(fn ($c) => ['category_id' => $c], $this->productos[(int) $m[1]])]);
        }
        return $this->r(404);
    }
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        $this->llamadas[] = "POST $path " . json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($this->statusForzado) return $this->r($this->statusForzado);
        $ext = (string) ($body['client_external_id'] ?? '');
        foreach ($this->c as $id => $x) {
            if ($ext !== '' && $x['external_id'] === $ext) return $this->r(200, $this->fila($id));
            if ($x['parent_id'] === (int) ($body['parent_id'] ?? 0)
                && TPV_Sync_Categorias::normalizar($x['name']) === TPV_Sync_Categorias::normalizar((string) $body['name'])) {
                if ($ext !== '') $this->c[$id]['external_id'] = $ext;
                return $this->r(200, $this->fila($id));
            }
        }
        $id = ++$this->sig;
        $this->c[$id] = ['parent_id' => (int) ($body['parent_id'] ?? 0), 'name' => trim((string) $body['name']),
                         'status' => 1, 'external_id' => $ext !== '' ? $ext : null];
        return $this->r(201, $this->fila($id));
    }
    public function patch(string $path, array $body = []): array
    {
        $this->llamadas[] = "PATCH $path " . json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($this->statusForzado) return $this->r($this->statusForzado);
        if (preg_match('#^/categories/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            if (!isset($this->c[$id])) return $this->r(404);
            if (isset($body['name'])) $this->c[$id]['name'] = (string) $body['name'];
            if (isset($body['parent_id'])) $this->c[$id]['parent_id'] = (int) $body['parent_id'];
            if (isset($body['client_external_id'])) $this->c[$id]['external_id'] = (string) $body['client_external_id'];
            return $this->r(200, $this->fila($id));
        }
        return $this->r(200, []);   // PATCH /products/{id}: se registra en $llamadas
    }
    public function delete(string $path): array
    {
        $this->llamadas[] = "DELETE $path";
        if (preg_match('#^/categories/(\d+)$#', $path, $m) && isset($this->c[(int) $m[1]])) {
            unset($this->c[(int) $m[1]]);
            return $this->r(204);
        }
        return $this->r(404);
    }
}
```

`tests/test_categorias_tpv_a_woo.php`:

```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/class-categorias.php';
require_once dirname(__DIR__) . '/includes/class-terminos.php';
require_once dirname(__DIR__) . '/includes/class-api-client.php';
require_once dirname(__DIR__) . '/includes/class-categorias-sync.php';
require_once __DIR__ . '/fixtures/terminos_en_memoria.php';
require_once __DIR__ . '/fixtures/api_categorias_falsa.php';

function run_categorias_tpv_a_woo_tests(WooTestRunner $t): void
{
    $t->suite('Categorías TPV → Woo');

    $t->test('aviso con categoría desconocida: la trae con su madre y enlaza', function ($t) {
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = 'arbol';
        $api = new ApiCategoriasFalsa(); $m = new TerminosEnMemoria();
        $api->c = [5 => ['parent_id' => 0, 'name' => 'CALZADO', 'status' => 1, 'external_id' => null],
                   6 => ['parent_id' => 5, 'name' => 'BOTAS', 'status' => 0, 'external_id' => null]];
        (new TPV_Sync_Categorias_Sync($api, $m))->aplicarAviso('category.created', 6);
        $botas = $m->porTpvId(6); $calzado = $m->porTpvId(5);
        $t->assert($botas > 0 && $calzado > 0, 'crea las dos (la inactiva también)');
        $t->assertEquals($calzado, $m->padreDe($botas));
        $t->assertEquals((string) $botas, $api->c[6]['external_id'], 'registra el enlace en la API');
    });

    $t->test('renombrar en el TPV actualiza el término, no crea otro', function ($t) {
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = 'arbol';
        $api = new ApiCategoriasFalsa(); $m = new TerminosEnMemoria();
        $api->c = [5 => ['parent_id' => 0, 'name' => 'Viejo', 'status' => 1, 'external_id' => null]];
        $s = new TPV_Sync_Categorias_Sync($api, $m);
        $s->aplicarAviso('category.created', 5);
        $api->c[5]['name'] = 'Nuevo';
        $s->aplicarAviso('category.updated', 5);
        $t->assertEquals([1, 'Nuevo'], [count($m->t), $m->nombreDe($m->porTpvId(5))]);
    });

    $t->test('borrado en el TPV borra el término enlazado', function ($t) {
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = 'arbol';
        $api = new ApiCategoriasFalsa(); $m = new TerminosEnMemoria();
        $id = $m->crear('X', 0); $m->enlazar($id, 9);
        (new TPV_Sync_Categorias_Sync($api, $m))->aplicarAviso('category.deleted', 9);
        $t->assertEquals([], $m->t);
    });

    $t->test('sin marca no se toca nada', function ($t) {
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = '';
        $api = new ApiCategoriasFalsa(); $m = new TerminosEnMemoria();
        $api->c = [5 => ['parent_id' => 0, 'name' => 'X', 'status' => 1, 'external_id' => null]];
        (new TPV_Sync_Categorias_Sync($api, $m))->aplicarAviso('category.created', 5);
        $t->assertEquals([], $m->t);
    });

    $t->test('hermana duplicada en Woo: no se enlaza a la de otra y se avisa', function ($t) {
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = 'arbol';
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::AVISOS] = [];
        $api = new ApiCategoriasFalsa(); $m = new TerminosEnMemoria();
        $ya = $m->crear('Lencería', 0); $m->enlazar($ya, 171);
        $api->c = [170 => ['parent_id' => 0, 'name' => 'LENCERIA', 'status' => 1, 'external_id' => null]];
        (new TPV_Sync_Categorias_Sync($api, $m))->aplicarAviso('category.created', 170);
        $t->assertEquals([0, 171], [$m->porTpvId(170), $m->tpvIdDe($ya)]);
        $t->assert(count($GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::AVISOS]) === 1, 'queda en «Categorías sin pareja»');
    });

    $t->test('producto: sustituye (lista vacía incluida) o junta; 404 se ignora; Sin categorizar se respeta', function ($t) {
        $api = new ApiCategoriasFalsa(); $m = new TerminosEnMemoria();
        $api->c = [5 => ['parent_id' => 0, 'name' => 'A', 'status' => 1, 'external_id' => null]];
        $s = new TPV_Sync_Categorias_Sync($api, $m);
        $web = $m->crear('Solo web', 0);
        $m->ponerAProducto(1, [$web]);
        $s->ponerCategoriasDesdeTpv(1, [5, 404404], true);
        $a = $m->porTpvId(5);
        $t->assertEquals(TPV_Sync_Categorias::juntar([$web, $a]), TPV_Sync_Categorias::juntar($m->deProducto(1)), 'junta; la 404 no rompe');
        $s->ponerCategoriasDesdeTpv(1, [5], false);
        $t->assertEquals([$a], $m->deProducto(1), 'sustituye');
        $s->ponerCategoriasDesdeTpv(1, [], false);
        $t->assertEquals([], $m->deProducto(1), 'lista vacía: quita todas');
    });
}
```

Si `TPV_Sync_API_Client::__construct()` lee opciones o secretos que el arnés no tiene, mirar cómo lo resuelven `ApiAutocuraFalsa`/`ApiGuardadoFalsa` en `test_autocuracion.php` y copiar ese arranque.

- [ ] **Paso 2: rojo.**
- [ ] **Paso 3: implementación** `includes/class-categorias-sync.php`:

```php
<?php
/**
 * Categorías Woo ↔ TPV (SPEC_categorias_woo_tpv.md). Enlace por número: la API
 * guarda (cliente, term_id) ↔ category_id; el termmeta es su caché local.
 * Marca TPV_Sync_Categorias_Sync::MARCA: '' no toca nada; 'arbol' aplica avisos
 * de categoría y JUNTA las de producto; 'completa' sustituye.
 */
if (!defined('ABSPATH')) exit;

final class TPV_Sync_Categorias_Sync
{
    public const MARCA  = 'tpv_sync_categorias_unidas';
    public const AVISOS = 'tpv_sync_categorias_avisos';
    public const GUARDA = 'tpv_sync_skip_wc_category_push';
    private const PROFUNDIDAD_MAX = 20;

    public function __construct(private TPV_Sync_API_Client $api, private TPV_Sync_Terminos $terminos) {}

    public static function fase(): string
    {
        $f = (string) get_option(self::MARCA, '');
        return in_array($f, ['arbol', 'completa'], true) ? $f : '';
    }

    public function avisar(string $texto): void
    {
        $l = (array) get_option(self::AVISOS, []);
        $l[] = gmdate('Y-m-d H:i') . ' — ' . $texto;
        update_option(self::AVISOS, array_slice($l, -20), false);
    }

    /** term_id de la categoría del TPV, creándola (con sus madres) si falta. 0 si no se pudo. */
    public function asegurarEnWoo(int $tpvId, int $profundidad = 0): int
    {
        if ($tpvId <= 0 || $profundidad > self::PROFUNDIDAD_MAX) return 0;
        $r = $this->api->get("/categories/$tpvId");
        if (!TPV_Sync_API_Client::fueBien($r) || empty($r['data'])) return 0;
        return $this->escribirEnWoo((array) $r['data'], $profundidad);
    }

    /** Crea o actualiza el término de una fila de la API. Anti-eco activo. */
    private function escribirEnWoo(array $c, int $profundidad): int
    {
        $tpvId = (int) $c['category_id'];
        $madre = (int) ($c['parent_id'] ?? 0) > 0 ? $this->terminos->porTpvId((int) $c['parent_id']) : 0;
        if ((int) ($c['parent_id'] ?? 0) > 0 && $madre === 0) {
            $madre = $this->asegurarEnWoo((int) $c['parent_id'], $profundidad + 1);
        }
        $nombre = trim((string) $c['name']);
        $GLOBALS[self::GUARDA] = true;
        try {
            $termId = $this->terminos->porTpvId($tpvId);
            if ($termId > 0) {
                $this->terminos->actualizar($termId, $nombre, $madre);
                return $termId;
            }
            $termId = $this->terminos->crear($nombre, $madre);
            if ($termId === 0) {
                $this->avisar(sprintf(__('Categoría del TPV «%s» sin pareja en la tienda: ya hay otra igual al mismo nivel (%s).', 'tpv-sync'),
                    $nombre, $this->terminos->ultimoError));
                return 0;
            }
            $this->terminos->enlazar($termId, $tpvId);
        } finally {
            $GLOBALS[self::GUARDA] = false;
        }
        $this->api->patch("/categories/$tpvId", ['client_external_id' => (string) $termId]);
        return $termId;
    }

    public function aplicarAviso(string $evento, int $tpvId): void
    {
        if (self::fase() === '' || $tpvId <= 0) return;
        if ($evento === 'category.deleted') {
            $termId = $this->terminos->porTpvId($tpvId);
            if ($termId > 0) {
                $GLOBALS[self::GUARDA] = true;
                try { $this->terminos->borrar($termId); } finally { $GLOBALS[self::GUARDA] = false; }
            }
            return;
        }
        $this->asegurarEnWoo($tpvId);
    }

    /** Las categorías de un producto que llegan del TPV: juntar o sustituir. */
    public function ponerCategoriasDesdeTpv(int $postId, array $tpvIds, bool $juntar): void
    {
        $terms = [];
        foreach ($tpvIds as $tid) {
            $termId = $this->terminos->porTpvId((int) $tid) ?: $this->asegurarEnWoo((int) $tid);
            if ($termId > 0) { $terms[] = $termId; }
        }
        $actuales = $this->terminos->deProducto($postId);
        $final = $juntar ? TPV_Sync_Categorias::juntar($actuales, $terms) : TPV_Sync_Categorias::juntar($terms);
        if ($final === TPV_Sync_Categorias::juntar($actuales)) return;
        $GLOBALS[self::GUARDA] = true;
        try { $this->terminos->ponerAProducto($postId, $final); } finally { $GLOBALS[self::GUARDA] = false; }
    }
}
```

- [ ] **Paso 4: verde.**
- [ ] **Paso 5: mutantes** — (a) `aplicarAviso` sin el corte de `fase()` → falla «sin marca»; (b) `ponerCategoriasDesdeTpv` con `$juntar` ignorado → falla «junta»; (c) en `escribirEnWoo`, enlazar a la hermana existente si `crear` devuelve 0 → falla el test de hermana duplicada. Restaurar.
- [ ] **Paso 6: commit** `feat(categorias): del TPV a la tienda por número, con madres y sin pisar hermanas`.

### Tarea W5: Woo → TPV (hooks de términos y `category_ids` de producto)

**Ficheros:** `includes/class-categorias-sync.php`, `tests/test_categorias_woo_a_tpv.php`.

**Interfaces:** Produce `asegurarEnTpv(int $termId, int $profundidad = 0): int`, `alGuardarTermino(int $termId): void`, `antesDeBorrarTermino(int $termId, string $taxonomia): void`, `categoryIdsParaTpv(int $postId): array`, `paraPayload(int $postId, bool $esAlta): array` (`['category_ids' => …]` o `[]`).

- [ ] **Paso 1: tests en rojo**

```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/class-categorias.php';
require_once dirname(__DIR__) . '/includes/class-terminos.php';
require_once dirname(__DIR__) . '/includes/class-api-client.php';
require_once dirname(__DIR__) . '/includes/class-categorias-sync.php';
require_once __DIR__ . '/fixtures/terminos_en_memoria.php';
require_once __DIR__ . '/fixtures/api_categorias_falsa.php';

function run_categorias_woo_a_tpv_tests(WooTestRunner $t): void
{
    $t->suite('Categorías Woo → TPV');
    $nuevo = function (): array {
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = 'completa';
        $GLOBALS[TPV_Sync_Categorias_Sync::GUARDA] = false;
        $api = new ApiCategoriasFalsa(); $m = new TerminosEnMemoria(); $m->defecto = 1;
        $m->t[1] = ['parent' => 0, 'name' => 'Sin categorizar', 'tpv_id' => 0];
        return [$api, $m, new TPV_Sync_Categorias_Sync($api, $m)];
    };

    $t->test('alta en la web: crea en el TPV con su madre (madre primero) y enlaza', function ($t) use ($nuevo) {
        [$api, $m, $s] = $nuevo();
        $a = $m->crear('Uñas', 0); $b = $m->crear('Fresas', $a);
        $s->alGuardarTermino($b);
        $t->assert($m->tpvIdDe($a) > 0 && $m->tpvIdDe($b) > 0, 'las dos enlazadas');
        $t->assertEquals($m->tpvIdDe($a), $api->c[$m->tpvIdDe($b)]['parent_id']);
        $t->assertEquals((string) $b, $api->c[$m->tpvIdDe($b)]['external_id']);
    });

    $t->test('cambio en la web: PATCH; si el TPV ya no la tiene (404), se recrea y reenlaza', function ($t) use ($nuevo) {
        [$api, $m, $s] = $nuevo();
        $a = $m->crear('X', 0); $s->alGuardarTermino($a);
        $viejo = $m->tpvIdDe($a);
        unset($api->c[$viejo]);
        $m->actualizar($a, 'X2', 0);
        $s->alGuardarTermino($a);
        $t->assert($m->tpvIdDe($a) > 0 && $m->tpvIdDe($a) !== $viejo, 'nuevo enlace');
        $t->assertEquals('X2', $api->c[$m->tpvIdDe($a)]['name']);
    });

    $t->test('borrar en la web: DELETE; 404 cuenta como hecho', function ($t) use ($nuevo) {
        [$api, $m, $s] = $nuevo();
        $a = $m->crear('X', 0); $s->alGuardarTermino($a); $tid = $m->tpvIdDe($a);
        $s->antesDeBorrarTermino($a, 'product_cat');
        $t->assertEquals(false, isset($api->c[$tid]));
        $s->antesDeBorrarTermino($a, 'product_cat');   // segunda vez: 404, sin excepción
        $n = count($api->llamadas);
        $s->antesDeBorrarTermino($a, 'category');       // otra taxonomía: nada
        $t->assertEquals($n, count($api->llamadas), 'otra taxonomía no llama a la API');
    });

    $t->test('anti-eco y Sin categorizar: no se envía nada', function ($t) use ($nuevo) {
        [$api, $m, $s] = $nuevo();
        $a = $m->crear('X', 0);
        $GLOBALS[TPV_Sync_Categorias_Sync::GUARDA] = true;
        $s->alGuardarTermino($a);
        $GLOBALS[TPV_Sync_Categorias_Sync::GUARDA] = false;
        $s->alGuardarTermino(1);
        $hijo = $m->crear('Bajo defecto', 1);
        $s->alGuardarTermino($hijo);
        $t->assertEquals(0, $m->tpvIdDe($a), 'con la guarda no sale');
        $t->assertEquals(0, $m->tpvIdDe(1), 'Sin categorizar nunca');
        $t->assertEquals(0, $api->c[$m->tpvIdDe($hijo)]['parent_id'], 'su hija va a la raíz');
    });

    $t->test('payload de producto según la fase', function ($t) use ($nuevo) {
        [$api, $m, $s] = $nuevo();
        $a = $m->crear('A', 0); $m->ponerAProducto(7, [$a, 1]);
        $con = $s->paraPayload(7, false);
        $t->assertEquals([$m->tpvIdDe($a)], $con['category_ids'], 'completa: siempre, sin Sin categorizar');
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = 'arbol';
        $t->assertEquals([], $s->paraPayload(7, false), 'arbol: actualización sin category_ids');
        $t->assertEquals([$m->tpvIdDe($a)], $s->paraPayload(7, true)['category_ids'], 'arbol: alta con category_ids');
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = '';
        $t->assertEquals([], $s->paraPayload(7, true), 'sin marca: nunca');
    });
}
```

- [ ] **Paso 2: rojo.**
- [ ] **Paso 3: implementación** (en `TPV_Sync_Categorias_Sync`):

```php
    /** category_id del término, creándolo (con sus madres) en el TPV si falta. 0 si no se pudo. */
    public function asegurarEnTpv(int $termId, int $profundidad = 0): int
    {
        $defecto = $this->terminos->porDefecto();
        if ($termId <= 0 || $termId === $defecto || $profundidad > self::PROFUNDIDAD_MAX) return 0;
        $ya = $this->terminos->tpvIdDe($termId);
        if ($ya > 0) return $ya;
        return $this->escribirEnTpv($termId, $profundidad);
    }

    private function escribirEnTpv(int $termId, int $profundidad): int
    {
        $padreWoo = $this->terminos->padreDe($termId);
        $madre = ($padreWoo > 0 && $padreWoo !== $this->terminos->porDefecto())
            ? $this->asegurarEnTpv($padreWoo, $profundidad + 1) : 0;
        $cuerpo = ['name' => $this->terminos->nombreDe($termId), 'parent_id' => $madre, 'client_external_id' => (string) $termId];
        $tpvId = $this->terminos->tpvIdDe($termId);
        if ($tpvId > 0) {
            $r = $this->api->patch("/categories/$tpvId", $cuerpo);
            if ((int) ($r['_status'] ?? 0) === 404) { $tpvId = 0; }
            elseif ((int) ($r['_status'] ?? 0) === 409) {
                $this->avisar(sprintf(__('La categoría «%s» no se pudo renombrar en el TPV: ya hay otra igual al mismo nivel.', 'tpv-sync'), $cuerpo['name']));
                return $tpvId;
            } elseif (TPV_Sync_API_Client::fueBien($r)) { return $tpvId; }
            else { return $tpvId; }   // fallo transitorio: el enlace sigue
        }
        $r = $this->api->post('/categories', $cuerpo);
        if (!TPV_Sync_API_Client::fueBien($r) || empty($r['data']['category_id'])) return 0;
        $tpvId = (int) $r['data']['category_id'];
        $this->terminos->enlazar($termId, $tpvId);
        return $tpvId;
    }

    /** Hooks created_product_cat / edited_product_cat. */
    public function alGuardarTermino(int $termId): void
    {
        if (!empty($GLOBALS[self::GUARDA]) || self::fase() === '' || $termId === $this->terminos->porDefecto()) return;
        $this->escribirEnTpv($termId, 0);
    }

    /** Hook pre_delete_term: el termmeta aún existe (en delete_product_cat ya no). */
    public function antesDeBorrarTermino(int $termId, string $taxonomia): void
    {
        if ($taxonomia !== TPV_Sync_Terminos::TAX || !empty($GLOBALS[self::GUARDA]) || self::fase() === '') return;
        $tpvId = $this->terminos->tpvIdDe($termId);
        if ($tpvId > 0) { $this->api->delete("/categories/$tpvId"); }   // 404 = ya no estaba: hecho
    }

    /** @return list<int> category_id del TPV de las categorías del producto (sin Sin categorizar). */
    public function categoryIdsParaTpv(int $postId): array
    {
        $out = [];
        foreach ($this->terminos->deProducto($postId) as $termId) {
            $tid = $this->asegurarEnTpv((int) $termId);
            if ($tid > 0) { $out[] = $tid; }
        }
        return TPV_Sync_Categorias::juntar($out);
    }

    /** Lo que se añade al cuerpo de un envío de producto Woo → TPV (spec §3, marca). */
    public function paraPayload(int $postId, bool $esAlta): array
    {
        $f = self::fase();
        if ($f === '' || ($f === 'arbol' && !$esAlta)) return [];
        return ['category_ids' => $this->categoryIdsParaTpv($postId)];
    }
```

Nota: en `alGuardarTermino` se llama a `escribirEnTpv` (no a `asegurarEnTpv`) para que un término ya enlazado haga PATCH.

- [ ] **Paso 4: verde.**
- [ ] **Paso 5: mutantes** — (a) quitar el chequeo de la guarda → falla anti-eco; (b) `paraPayload` sin el caso `'arbol' && !$esAlta` → falla; (c) en `escribirEnTpv`, no tratar el 404 → no se recrea. Restaurar.
- [ ] **Paso 6: commit** `feat(categorias): de la tienda al TPV con sus madres, sin eco y sin «Sin categorizar»`.

### Tarea W6: unión por fases (`unirArbol`, `unirProductos`, `pasoDeUnion`) y comprobación de capacidad y ámbito

**Ficheros:** `includes/class-categorias-sync.php`, `includes/class-api-client.php` (generalizar `aceptaPrecioConIva` en `tieneCapacidad(string)`), `tests/test_categorias_union.php`.

**Interfaces:**
- Produce: `TPV_Sync_API_Client::tieneCapacidad(string $cap): bool` (`aceptaPrecioConIva()` pasa a `return $this->tieneCapacidad('price_gross_write');`); `unirArbol(): bool` (true si terminó y puso `'arbol'`); `unirProductos(int $lote): bool` (true si acabó y puso `'completa'`; cursor en option `tpv_sync_categorias_cursor`); `pasoDeUnion(int $lote = 100): void` (lo que llama el cron); `olvidar(): void`.
- Para recorrer productos enlazados: `TPV_Sync_Product_Sync` ya expone el meta `_tpv_product_id` (`self::TPV_ID_META`). La consulta de la tanda va en un método inyectable para el test: `protected function productosEnlazados(int $desdePostId, int $lote): array` → `list<array{post_id:int, tpv_id:int}>` (WP: `$wpdb` sobre `postmeta` con `meta_key='_tpv_product_id' AND meta_value > 0 AND post_id > ? ORDER BY post_id LIMIT ?`). Como la clase es `final`, quitar `final` o pasar un `callable $productosEnlazados` opcional al constructor: **elegir el callable** (constructor `__construct(TPV_Sync_API_Client $api, TPV_Sync_Terminos $terminos, ?callable $productosEnlazados = null)`), con el `$wpdb` por defecto.

- [ ] **Paso 1: tests en rojo**

```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/class-categorias.php';
require_once dirname(__DIR__) . '/includes/class-terminos.php';
require_once dirname(__DIR__) . '/includes/class-api-client.php';
require_once dirname(__DIR__) . '/includes/class-categorias-sync.php';
require_once __DIR__ . '/fixtures/terminos_en_memoria.php';
require_once __DIR__ . '/fixtures/api_categorias_falsa.php';

function run_categorias_union_tests(WooTestRunner $t): void
{
    $t->suite('Categorías — unión inicial por fases');

    $montar = function (array $enlazados = []): array {
        $GLOBALS['__wp_options'] = [];
        $api = new class extends ApiCategoriasFalsa { public array $caps = ['categories_write']; public function tieneCapacidad(string $c): bool { return in_array($c, $this->caps, true); } };
        $m = new TerminosEnMemoria();
        $s = new TPV_Sync_Categorias_Sync($api, $m, fn (int $desde, int $lote) =>
            array_slice(array_values(array_filter($enlazados, fn ($e) => $e['post_id'] > $desde)), 0, $lote));
        return [$api, $m, $s];
    };

    $t->test('árbol: empareja por nombre, crea lo que falta en cada lado, no mueve nada, marca arbol', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $api->c = [1 => ['parent_id' => 0, 'name' => 'Ropa', 'status' => 1, 'external_id' => null],
                   2 => ['parent_id' => 1, 'name' => 'Pijamas', 'status' => 1, 'external_id' => null]];
        $w = $m->crear('ROPA', 0); $u = $m->crear('Uñas', 0); $f = $m->crear('Fresas', $u);
        $t->assertEquals(true, $s->unirArbol());
        $t->assertEquals('arbol', TPV_Sync_Categorias_Sync::fase());
        $t->assertEquals([1, (string) $w], [$m->tpvIdDe($w), $api->c[1]['external_id']]);
        $pij = $m->porTpvId(2);
        $t->assertEquals($w, $m->padreDe($pij), 'Pijamas se crea en Woo dentro de su madre emparejada');
        $t->assertEquals($m->tpvIdDe($u), $api->c[$m->tpvIdDe($f)]['parent_id'], 'Fresas se crea en el TPV dentro de Uñas');
        $t->assertEquals(0, $m->padreDe($w), 'nada se mueve');
    });

    $t->test('productos: junta en los dos lados por tandas y acaba en completa', function ($t) use ($montar) {
        [$api, $m, $s] = $montar([['post_id' => 10, 'tpv_id' => 500], ['post_id' => 11, 'tpv_id' => 501]]);
        $api->c = [1 => ['parent_id' => 0, 'name' => 'Ropa', 'status' => 1, 'external_id' => null]];
        $api->productos = [500 => [1], 501 => []];
        $w = $m->crear('Uñas', 0);
        $m->ponerAProducto(10, [$w]); $m->ponerAProducto(11, [$w]);
        $s->unirArbol();
        $t->assertEquals(false, $s->unirProductos(1), 'primera tanda: queda otra');
        $t->assertEquals(false, $s->unirProductos(1), 'segunda tanda llena: aún no sabe que es la última');
        $t->assertEquals(true, $s->unirProductos(1), 'tanda vacía: fin');
        $t->assertEquals('completa', TPV_Sync_Categorias_Sync::fase());
        $t->assertEquals(TPV_Sync_Categorias::juntar([$w, $m->porTpvId(1)]), TPV_Sync_Categorias::juntar($m->deProducto(10)));
        $patch500 = array_values(array_filter($api->llamadas, fn ($l) => str_starts_with($l, 'PATCH /products/500 ')));
        $t->assertEquals(1, count($patch500));
        $cuerpo = json_decode(substr($patch500[0], strlen('PATCH /products/500 ')), true);
        $t->assertEquals(TPV_Sync_Categorias::juntar([1, $m->tpvIdDe($w)]), $cuerpo['category_ids']);
    });

    $t->test('sin capacidad o con 403: no empieza, lo dice y no marca', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $api->caps = [];
        $s->pasoDeUnion();
        $t->assertEquals('', TPV_Sync_Categorias_Sync::fase());
        $api->caps = ['categories_write']; $api->statusForzado = 403;
        $s->pasoDeUnion();
        $t->assertEquals('', TPV_Sync_Categorias_Sync::fase());
        $avisos = implode("\n", (array) ($GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::AVISOS] ?? []));
        $t->assert(str_contains($avisos, 'categories:write'), 'el panel dice qué ámbito falta');
    });

    $t->test('olvidar: borra termmeta, marca y cursor', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $a = $m->crear('A', 0); $m->enlazar($a, 3);
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = 'completa';
        $s->olvidar();
        $t->assertEquals([0, ''], [$m->tpvIdDe($a), TPV_Sync_Categorias_Sync::fase()]);
    });

    $t->test('reconexión: la unión reconstruye el enlace desde external_id antes que por nombre', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $a = $m->crear('Renombrada en Woo', 0);
        $api->c = [8 => ['parent_id' => 0, 'name' => 'Nombre viejo', 'status' => 1, 'external_id' => (string) $a]];
        $s->unirArbol();
        $t->assertEquals([8, 1], [$m->tpvIdDe($a), count($m->t)]);
    });
}
```

`ApiCategoriasFalsa` (W4) ya trae `$productos` y `GET /products/{id}`. Añadir `delete_metadata`/`get_option`/`update_option`/`delete_option` en el arnés de `run.php` si faltan (`delete_option` → `unset($GLOBALS['__wp_options'][$k])`).

- [ ] **Paso 2: rojo.**
- [ ] **Paso 3: implementación**

En `class-api-client.php`: renombrar el cuerpo de `aceptaPrecioConIva()` a `public function tieneCapacidad(string $cap): bool { …misma caché…; return in_array($cap, $this->capacidades, true); }` y dejar `aceptaPrecioConIva()` como `return $this->tieneCapacidad('price_gross_write');`. Correr `tests/test_pvp.php` (debe seguir verde).

En `TPV_Sync_Categorias_Sync`:

```php
    public const CURSOR = 'tpv_sync_categorias_cursor';
    /** @var callable(int,int):list<array{post_id:int,tpv_id:int}> */
    private $productosEnlazados;

    public function __construct(private TPV_Sync_API_Client $api, private TPV_Sync_Terminos $terminos, ?callable $productosEnlazados = null)
    {
        $this->productosEnlazados = $productosEnlazados ?? static function (int $desde, int $lote): array {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, CAST(meta_value AS UNSIGNED) AS tpv_id FROM {$wpdb->postmeta}
                  WHERE meta_key = '_tpv_product_id' AND CAST(meta_value AS UNSIGNED) > 0 AND post_id > %d
                  ORDER BY post_id LIMIT %d", $desde, $lote), ARRAY_A);
            return array_map(fn ($r) => ['post_id' => (int) $r['post_id'], 'tpv_id' => (int) $r['tpv_id']], (array) $rows);
        };
    }

    /** Lo que llama el cron (autocurar). Sin capacidad o sin ámbito: espera. */
    public function pasoDeUnion(int $lote = 100): void
    {
        if (self::fase() === 'completa') return;
        if (!$this->api->tieneCapacidad('categories_write')) return;
        if (self::fase() === '' && !$this->unirArbol()) return;
        $this->unirProductos($lote);
    }

    public function unirArbol(): bool
    {
        $r = $this->api->get('/categories', ['todas' => 1]);
        if ((int) ($r['_status'] ?? 0) === 403) {
            $this->avisar(__('La conexión con el TPV no tiene el permiso categories:write: las categorías no se sincronizan hasta que el TPV se actualice.', 'tpv-sync'));
            return false;
        }
        if (!TPV_Sync_API_Client::fueBien($r)) return false;
        $tpv = (array) ($r['data'] ?? []);
        $plan = TPV_Sync_Categorias::planUnion($tpv, $this->terminos->todos(), $this->terminos->porDefecto());
        foreach ($plan['parejas'] as $par) {
            $this->terminos->enlazar($par['term'], $par['tpv']);
            $this->api->patch('/categories/' . $par['tpv'], ['client_external_id' => (string) $par['term']]);
        }
        $porId = array_column($tpv, null, 'category_id');
        foreach ($plan['crearEnWoo'] as $tid) {
            if (isset($porId[$tid])) { $this->escribirEnWoo($porId[$tid], 0); }
        }
        foreach ($plan['crearEnTpv'] as $termId) { $this->asegurarEnTpv($termId); }
        update_option(self::MARCA, 'arbol', false);
        update_option(self::CURSOR, 0, false);
        return true;
    }

    public function unirProductos(int $lote): bool
    {
        $desde = (int) get_option(self::CURSOR, 0);
        $tanda = ($this->productosEnlazados)($desde, $lote);
        foreach ($tanda as $e) {
            $r = $this->api->get('/products/' . $e['tpv_id']);
            if (!TPV_Sync_API_Client::fueBien($r)) { continue; }
            $deTpv = array_map(fn ($c) => (int) ($c['category_id'] ?? 0), (array) ($r['data']['categories'] ?? []));
            $this->ponerCategoriasDesdeTpv($e['post_id'], $deTpv, true);
            $suma = TPV_Sync_Categorias::juntar($deTpv, $this->categoryIdsParaTpv($e['post_id']));
            if ($suma !== TPV_Sync_Categorias::juntar($deTpv)) {
                $this->api->patch('/products/' . $e['tpv_id'], ['category_ids' => $suma]);
            }
            update_option(self::CURSOR, $e['post_id'], false);
        }
        if (count($tanda) < $lote) {
            update_option(self::MARCA, 'completa', false);
            return true;
        }
        return false;
    }

    public function olvidar(): void
    {
        $this->terminos->olvidarEnlaces();
        update_option(self::MARCA, '', false);
        update_option(self::CURSOR, 0, false);
    }
```

Ojo: `unirArbol` usa `escribirEnWoo` (privado, misma clase) para no hacer un `GET /categories/{id}` por cada una: ya tiene la fila. `escribirEnWoo` asegura la madre por `porTpvId` y, si no está, por `asegurarEnWoo` (GET); como `crearEnWoo` viene con madres primero, la madre ya existe.

En la reconexión, `planUnion` recibe `external_id` en las filas del TPV (pasada (a)): el test lo cubre.

- [ ] **Paso 4: verde.**
- [ ] **Paso 5: mutantes** — (a) en `unirProductos`, `juntar=false` → falla «junta en los dos lados»; (b) `pasoDeUnion` sin el corte de capacidad → falla «no marca»; (c) poner `'completa'` en `unirArbol` → falla el test de fases. Restaurar.
- [ ] **Paso 6: commit** `feat(categorias): unión inicial automática por fases, sin ámbito no empieza`.

### Tarea W7: cableado (suscripción, handler, upsert, envíos, hooks, cron, asistente, reset, panel)

**Ficheros:** `woocommerce-conector.php`, `includes/class-admin.php`, `includes/class-webhook-handler.php`, `includes/class-product-sync.php`, `languages/tpv-sync-{es_ES,en_US,fr_FR}.po` (+ `.mo`), tests `tests/test_categorias_cableado.php`.

**Interfaces:**
- Consume todo lo anterior. `TPV_Sync_Product_Sync` gana `public TPV_Sync_Categorias_Sync $categorias` (creado en su constructor con `new TPV_Sync_Terminos()`) y `private bool $juntarCategorias = false` (lo ponen a true `import_all` y `reconcileBidirectional` mientras corren).
- `TPV_Sync_Admin::htmlAvisosCategorias(array $avisos): string` (pura).

- [ ] **Paso 1: tests en rojo** — de cableado, leyendo el fuente (mismo estilo que `test_cron_cableado.php`) y llamando a lo puro:

```php
<?php
declare(strict_types=1);
/** El cableado de categorías está en su sitio (SPEC §3-§4). */
require_once dirname(__DIR__) . '/includes/class-admin.php';

function run_categorias_cableado_tests(WooTestRunner $t): void
{
    $t->suite('Categorías — cableado');
    $raiz = dirname(__DIR__);
    $prin = (string) file_get_contents("$raiz/woocommerce-conector.php");
    $hand = (string) file_get_contents("$raiz/includes/class-webhook-handler.php");
    $prod = (string) file_get_contents("$raiz/includes/class-product-sync.php");
    $adm  = (string) file_get_contents("$raiz/includes/class-admin.php");

    $t->test('la tienda se suscribe a category.*', function ($t) {
        $ev = TPV_Sync_Admin::eventosSuscritos(true);
        foreach (['category.created', 'category.updated', 'category.deleted'] as $e) {
            $t->assert(in_array($e, $ev, true), "suscrita a $e");
        }
    });
    $t->test('la revisión de la suscripción también corre en el cron', function ($t) use ($prin) {
        $t->assert((bool) preg_match("/add_action\('tpv_sync_autocurar'.*revisarSuscripcionWebhook/s", $prin), 'sin abrir el admin');
    });
    $t->test('hooks de términos registrados', function ($t) use ($prin) {
        foreach (['created_product_cat', 'edited_product_cat', 'pre_delete_term'] as $h) {
            $t->assert(str_contains($prin, "'$h'"), "hook $h");
        }
    });
    $t->test('el handler delega los avisos de categoría', function ($t) use ($hand) {
        $t->assert(str_contains($hand, '->categorias->aplicarAviso('), 'aplicarAviso');
        $t->assert(!str_contains($hand, 'sync_category_from_tpv'), 'fuera el camino viejo por nombre');
    });
    $t->test('upsert usa el enlace por número y se quita el casado por nombre', function ($t) use ($prod) {
        $t->assert(str_contains($prod, '->categorias->ponerCategoriasDesdeTpv('), 'upsert por número');
        $t->assert(!str_contains($prod, "get_term_by('name'"), 'ni rastro de get_term_by por nombre');
        $t->assert(substr_count($prod, '->categorias->paraPayload(') >= 2, 'envío suelto y bulk llevan categorías');
    });
    $t->test('reset y desconexión olvidan los enlaces de categoría', function ($t) use ($adm) {
        $t->assert(substr_count($adm, '->categorias->olvidar()') >= 3, 'reset, disconnect y full_disconnect');
    });
    $t->test('el asistente une el árbol como primer paso', function ($t) use ($adm) {
        $t->assert(substr_count($adm, '->categorias->unirArbol()') >= 2, 'ajax_import y ajax_push_all');
    });
    $t->test('el panel enseña los avisos de categorías, escapados', function ($t) {
        $h = TPV_Sync_Admin::htmlAvisosCategorias(['2026-10-14 10:00 — «<b>X</b>» sin pareja']);
        $t->assert(str_contains($h, '&lt;b&gt;X&lt;/b&gt;'), 'escapa');
        $t->assertEquals('', TPV_Sync_Admin::htmlAvisosCategorias([]));
    });
}
```

(Si `esc_html` no está en los stubs de `run.php`, añadir `function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }`.)

- [ ] **Paso 2: rojo.**
- [ ] **Paso 3: implementación**, punto por punto:

1. `woocommerce-conector.php`: `require_once` de `class-categorias.php`, `class-terminos.php`, `class-categorias-sync.php` antes de `class-product-sync.php`. Junto a los hooks del módulo catálogo (`if (tpv_sync_module_catalog()) { … }`, línea ~142):

```php
            add_action('created_product_cat', [$this->products->categorias, 'alGuardarTermino'], 10, 1);
            add_action('edited_product_cat',  [$this->products->categorias, 'alGuardarTermino'], 10, 1);
            add_action('pre_delete_term',     [$this->products->categorias, 'antesDeBorrarTermino'], 10, 2);
```

   y junto a la línea 417: `add_action('tpv_sync_autocurar', [$tpvAdmin, 'revisarSuscripcionWebhook'], 5);` (prioridad 5: antes de `autocurar`). `revisarSuscripcionWebhook` no debe depender de `current_user_can`: comprobarlo y, si lo hace, separar la parte de permisos.

2. `class-admin.php::eventosSuscritos`: añadir `$catalogo ? 'category.created' : null,`, `…updated`, `…deleted` con un comentario («las categorías van en los dos sentidos: SPEC_categorias_woo_tpv.md»).

3. `class-webhook-handler.php:591-598`: los tres `case` quedan en

```php
                case 'category.created':
                case 'category.updated':
                case 'category.deleted':
                    $this->products->categorias->aplicarAviso($eventType, $resourceId);
                    break;
```

4. `class-product-sync.php`:
   - constructor: `$this->categorias = new TPV_Sync_Categorias_Sync($api, new TPV_Sync_Terminos());`
   - `upsert` (línea ~489), el bloque «Categorías» pasa a:

```php
        // Categorías por número (SPEC_categorias_woo_tpv.md §3 C3): sin marca no
        // se tocan; en 'arbol' y en los volcados se juntan; en 'completa' un
        // aviso las sustituye, lista vacía incluida.
        $fase = TPV_Sync_Categorias_Sync::fase();
        if ($fase !== '' && isset($p['categories']) && is_array($p['categories'])) {
            $ids = array_map(fn ($c) => (int) ($c['category_id'] ?? 0), $p['categories']);
            $this->categorias->ponerCategoriasDesdeTpv($postId, $ids, $this->juntarCategorias || $fase !== 'completa');
        }
```

   - borrar `sync_categories()`, `sync_category_from_tpv()` y `delete_wc_category_by_tpv_id()` (y su uso en el handler).
   - `import_all()` y `reconcileBidirectional()`: `$this->juntarCategorias = true;` al empezar y `false` en un `finally`.
   - `push_wc_product_to_tpv`: tras `$payload += $this->rebajaParaTpv($product, $postId);`, `$payload += $this->categorias->paraPayload($postId, $tpvId <= 0);` (usar la variable que ya indica si es alta).
   - `buildPushPayload`: antes del `return`, `$payload += $this->categorias->paraPayload($postId, (int) get_post_meta($postId, self::TPV_ID_META, true) <= 0);`.
   - `autocurar()`: tras el corte de módulo/configuración, `$this->categorias->pasoDeUnion();`.

5. `class-admin.php`:
   - `ajax_import` y `ajax_push_all`, dentro de `if ($reset) { … }`: `if (TPV_Sync_Categorias_Sync::fase() === '') { TPV_Sync::instance()->products->categorias->unirArbol(); }` (ajustar el acceso a `products` según cómo lo haga el resto del método).
   - `ajax_reset_sync`, `ajax_disconnect`, `ajax_full_disconnect`: `TPV_Sync::instance()->products->categorias->olvidar();` junto al borrado de `_tpv_product_id`.
   - Nuevo método puro y su uso en la tarjeta «Estado de la sincronización» (junto al bloque del cuadre PVP):

```php
    public static function htmlAvisosCategorias(array $avisos): string
    {
        if (!$avisos) return '';
        $li = '';
        foreach (array_reverse($avisos) as $a) { $li .= '<li>' . esc_html((string) $a) . '</li>'; }
        return '<div class="cc-avisos-categorias"><strong>' . esc_html__('Categorías sin pareja o sin permiso', 'tpv-sync')
             . '</strong><ul>' . $li . '</ul></div>';
    }
```

   (añadir `esc_html__` a los stubs si falta).

6. Traducciones: los textos nuevos (`__()` de W4-W7) en los tres `.po` y regenerar los `.mo` con `msgfmt` (memoria `feedback_multiidioma_obligatorio`).

- [ ] **Paso 4: verde** — `php tests/run.php` entero (los 702+ de antes siguen verdes).
- [ ] **Paso 5: mutantes** — (a) quitar `category.deleted` de `eventosSuscritos`; (b) quitar `paraPayload` de `buildPushPayload`; (c) quitar `olvidar()` de `ajax_full_disconnect`. Cada uno debe tumbar su test. Restaurar.
- [ ] **Paso 6: commit** `feat(categorias): cableado de la sincronización de categorías en los dos sentidos`.

### Tarea W8: versión y CHANGELOG

- [ ] Subir la versión del plugin (cabecera de `woocommerce-conector.php`, `TPV_SYNC_VERSION`, `readme.txt` Stable tag) a la siguiente menor y añadir en `CHANGELOG.md` la entrada: categorías en los dos sentidos, unión automática, avisos en el panel. `php tests/test_actualizador.php` (o la suite que valide versión) verde. Commit `chore(release): categorías sincronizadas en los dos sentidos`. **Sin etiqueta ni release**: lo decide el usuario.

---

## Verificación final (antes de pedir prueba al usuario)

- [ ] API: `php tests/run_tests.php --suite=categories_write`, `--suite=customers_crud`, `--suite=bulk207`, `--suite=endpoints` (mismos fallos preexistentes que el santuario, ni uno más).
- [ ] Conectores: `php tests/run.php` en los dos.
- [ ] TPV: `php admin/tests/UploadCsvCategoriaAvisaTest.php` y `UploadBorrarPorModeloAvisaTest.php`.
- [ ] Plugin: `php tests/run.php` entero.
- [ ] Despliegue de prueba **solo con destino indicado por el usuario** (skill worktree-sesion, `deploy` sin `--yes` primero): API + conector + TPV a tpv200 y el plugin a la tienda de pruebas (`tpv99wp` en la auditoría del 07-10; preguntar). Pedir al usuario que pruebe: crear/renombrar/mover/borrar una categoría en cada lado, asignar categorías a un producto en cada lado, y ver la unión en una tienda con categorías previas.
- [ ] No hacer push, merge, etiqueta ni release hasta que el usuario lo pida.
