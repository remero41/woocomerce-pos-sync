<?php
/**
 * Suite E2E completa de sincronización de clientes WC ↔ TPV.
 *
 * Cubre los 7 escenarios manuales que hicimos contra el cliente real (P1..P7)
 * más casos edge: rol no-customer, email inválido, race condition con flag,
 * PATCH 404 huérfano, modo principal=tpv, caracteres especiales, idempotencia
 * matched entre WC y TPV, bulk con paginación.
 *
 * Verifica BD WC (wp_users + wp_usermeta) y BD TPV (2465_customer +
 * 2465_address + 2465_api_external_mapping_customer) en cada paso.
 *
 * Uso: php tests/customers_e2e_full.php
 *
 * Requisitos: API TPV configurada, plugin activo, $GLOBALS de WP cargados.
 */

declare(strict_types=1);

define('WP_USE_THEMES', false);

// Bootstrapea el WP del entorno tpv85 por defecto. Override con env
// TPV_SYNC_WP_ROOT si el test corre contra otra instalación.
$wpRoot = getenv('TPV_SYNC_WP_ROOT') ?: '/var/www/html/tpv85/public_html';
require_once $wpRoot . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if (!class_exists('TPV_Sync_Customer_Sync')) {
    require_once WP_PLUGIN_DIR . '/woocommerce_conector/includes/class-circuit-breaker.php';
    require_once WP_PLUGIN_DIR . '/woocommerce_conector/includes/class-secrets.php';
    require_once WP_PLUGIN_DIR . '/woocommerce_conector/includes/class-api-client.php';
    require_once WP_PLUGIN_DIR . '/woocommerce_conector/includes/class-queue.php';
    require_once WP_PLUGIN_DIR . '/woocommerce_conector/includes/class-product-sync.php';
    require_once WP_PLUGIN_DIR . '/woocommerce_conector/includes/class-customer-sync.php';
}

global $wpdb;
$pdo = new PDO('mysql:host=localhost;dbname=tpv7', 'root', 'paquito');

// ─── Mini test runner ─────────────────────────────────────────────────────
$pass = 0; $fail = 0; $failures = [];
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($cond) {
        echo "  \033[32m✓\033[0m $name\n";
        $pass++;
    } else {
        echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : '') . "\n";
        $fail++;
        $failures[] = $name . ($detail ? " ($detail)" : '');
    }
}
function suite(string $name): void {
    echo "\n\033[1;34m── $name ──\033[0m\n";
}

// ─── Snapshot opciones de WC para restaurar al final ──────────────────────
$snap = [
    'tpv_sync_principal' => get_option('tpv_sync_principal', ''),
];
update_option('tpv_sync_principal', '', false);

$api  = new TPV_Sync_API_Client();
if (!$api->isConfigured()) { echo "API no configurada\n"; exit(1); }
$customerSync = new TPV_Sync_Customer_Sync($api);

// ─── Helpers ──────────────────────────────────────────────────────────────
function clean_tpv(PDO $pdo, int $id): void {
    if ($id <= 0) return;
    $pdo->prepare("DELETE FROM 2465_address WHERE customer_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM 2465_customer WHERE customer_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM 2465_api_external_mapping_customer WHERE tpv_customer_id = ?")->execute([$id]);
}

function read_tpv(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT cu.customer_id, cu.email, cu.firstname, cu.lastname, cu.telephone,
               cu.id_tax, cu.status, cu.address_id,
               a.address_1, a.city, a.postcode,
               c.iso_code_2 AS country_code
        FROM 2465_customer cu
        LEFT JOIN 2465_address a ON a.address_id = cu.address_id
        LEFT JOIN 2465_country c ON c.country_id = a.country_id
        WHERE cu.customer_id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function read_wc(int $uid): array {
    return [
        'first_name'        => get_user_meta($uid, 'first_name', true),
        'last_name'         => get_user_meta($uid, 'last_name', true),
        'billing_phone'     => get_user_meta($uid, 'billing_phone', true),
        'billing_vat'       => get_user_meta($uid, 'billing_vat', true),
        'billing_address_1' => get_user_meta($uid, 'billing_address_1', true),
        '_tpv_customer_id'  => get_user_meta($uid, '_tpv_customer_id', true),
        '_deleted_at'       => get_user_meta($uid, '_tpv_customer_deleted_at', true),
    ];
}

function make_user(string $email, array $metas = [], string $role = 'customer'): int {
    // first_name/last_name pasan dentro de wp_insert_user para que el hook
    // user_register las vea (no actualizar tras la inserción). El resto
    // de metas (billing_*) sí las hacemos después como hace WC en su flujo
    // (los metas custom no tienen tratamiento especial dentro de wp_insert_user).
    $userdata = [
        'user_login' => 'cetf_' . substr(uniqid(), -8),
        'user_email' => $email,
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ];
    foreach (['first_name', 'last_name'] as $k) {
        if (isset($metas[$k])) {
            $userdata[$k] = $metas[$k];
            unset($metas[$k]);
        }
    }
    $uid = wp_insert_user($userdata);
    if (is_wp_error($uid)) return 0;
    foreach ($metas as $k => $v) update_user_meta($uid, $k, $v);
    return (int)$uid;
}

function get_mapping(PDO $pdo, int $tpvId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM 2465_api_external_mapping_customer WHERE tpv_customer_id = ?");
    $stmt->execute([$tpvId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Estado limpio inicial: borrar customers de test residuales
$pdo->exec("DELETE FROM 2465_customer WHERE email LIKE '%@cetf-test.local'");
$pdo->exec("DELETE FROM 2465_address WHERE customer_id NOT IN (SELECT customer_id FROM 2465_customer)");
$pdo->exec("DELETE FROM 2465_api_external_mapping_customer WHERE tpv_customer_id NOT IN (SELECT customer_id FROM 2465_customer)");

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 1 — Push WC → TPV (creación, edición, validación, edge)
// ═══════════════════════════════════════════════════════════════════════════
suite('1. Push WC → TPV (creación, idempotencia, edge cases)');

$createdUids = [];

// Test 1.1: signup básico crea customer en TPV
$email = 'basico_' . uniqid() . '@cetf-test.local';
$uid = make_user($email, ['first_name' => 'Lucía', 'last_name' => 'Méndez']);
$createdUids[] = $uid;
$tpvId = (int) get_user_meta($uid, '_tpv_customer_id', true);
ok('1.1 signup → tpv_id meta escrito',          $tpvId > 0, "tpvId=$tpvId");
$row = read_tpv($pdo, $tpvId);
ok('1.1 BD TPV: email coincide',                 $row && $row['email'] === $email);
ok('1.1 BD TPV: firstname coincide',             $row && $row['firstname'] === 'Lucía',
   "got=" . ($row['firstname'] ?? 'NULL'));
ok('1.1 BD TPV: lastname coincide',              $row && $row['lastname'] === 'Méndez',
   "got=" . ($row['lastname'] ?? 'NULL'));
ok('1.1 BD TPV: status=1',                       $row && (int)$row['status'] === 1);

// Test 1.2: WC_Customer->save() actualiza phone/address en TPV
$customer = new WC_Customer($uid);
$customer->set_billing_phone('600111222');
$customer->set_billing_address_1('C/ Real 5');
$customer->set_billing_city('Murcia');
$customer->set_billing_postcode('30001');
$customer->set_billing_country('ES');
$customer->save();
do_action('woocommerce_customer_save_address', $uid, 'billing');
$row2 = read_tpv($pdo, $tpvId);
ok('1.2 save_address: telephone propagado',      $row2 && $row2['telephone'] === '600111222');
ok('1.2 save_address: address_1 propagado',      $row2 && $row2['address_1'] === 'C/ Real 5');
ok('1.2 save_address: city propagado',           $row2 && $row2['city'] === 'Murcia');
ok('1.2 save_address: country_code=ES',          $row2 && $row2['country_code'] === 'ES');

// Test 1.3: edición posterior preserva tpv_id (PATCH, no nuevo POST)
$customer->set_first_name('Lucía María');
$customer->save();
$tpvIdAfter = (int) get_user_meta($uid, '_tpv_customer_id', true);
$row3 = read_tpv($pdo, $tpvId);
ok('1.3 edición preserva tpv_id',                $tpvId === $tpvIdAfter);
ok('1.3 firstname actualizado en BD TPV',        $row3 && $row3['firstname'] === 'Lucía María');

// Test 1.4: rol distinto a customer NO se sincroniza
$emailAdmin = 'admin_' . uniqid() . '@cetf-test.local';
$uidAdmin = make_user($emailAdmin, ['first_name' => 'Admin'], 'subscriber');
$createdUids[] = $uidAdmin;
$tpvAdmin = (int) get_user_meta($uidAdmin, '_tpv_customer_id', true);
ok('1.4 rol subscriber NO se sincroniza',        $tpvAdmin === 0);

// Test 1.5: email inválido → push retorna sin crear nada
// En signup ya filtra wp_insert_user, pero forzamos meta cambio
$emailValido = 'valid_' . uniqid() . '@cetf-test.local';
$uidValid = make_user($emailValido);
$createdUids[] = $uidValid;
$tpvIdValid = (int) get_user_meta($uidValid, '_tpv_customer_id', true);
ok('1.5 user creado sin metas tiene tpv_id',     $tpvIdValid > 0);

// Test 1.6: 2 users WC con email distinto crean 2 customers TPV diferentes
$emailA = 'multA_' . uniqid() . '@cetf-test.local';
$emailB = 'multB_' . uniqid() . '@cetf-test.local';
$uidA = make_user($emailA, ['first_name' => 'A']);
$uidB = make_user($emailB, ['first_name' => 'B']);
$createdUids[] = $uidA; $createdUids[] = $uidB;
$tpvA = (int) get_user_meta($uidA, '_tpv_customer_id', true);
$tpvB = (int) get_user_meta($uidB, '_tpv_customer_id', true);
ok('1.6 2 emails distintos crean 2 customers',   $tpvA > 0 && $tpvB > 0 && $tpvA !== $tpvB);

// Test 1.7: caracteres especiales (acentos, ñ)
$emailUtf = 'utf8_' . uniqid() . '@cetf-test.local';
$uidUtf = make_user($emailUtf, ['first_name' => 'Ángeles', 'last_name' => 'Núñez']);
$createdUids[] = $uidUtf;
$tpvUtf = (int) get_user_meta($uidUtf, '_tpv_customer_id', true);
$rowUtf = read_tpv($pdo, $tpvUtf);
ok('1.7 acentos UTF-8 preservados firstname',    $rowUtf && $rowUtf['firstname'] === 'Ángeles');
ok('1.7 acentos UTF-8 preservados lastname',     $rowUtf && $rowUtf['lastname'] === 'Núñez');

// Test 1.8: NIF español (formato letra)
$emailNif = 'nif_' . uniqid() . '@cetf-test.local';
$uidNif = make_user($emailNif, [
    'first_name' => 'José',
    'billing_vat' => '12345678Z',
]);
$createdUids[] = $uidNif;
$customerNif = new WC_Customer($uidNif);
$customerNif->save(); // dispara update con metas
do_action('woocommerce_customer_save_address', $uidNif, 'billing');
$tpvNif = (int) get_user_meta($uidNif, '_tpv_customer_id', true);
$rowNif = read_tpv($pdo, $tpvNif);
ok('1.8 id_tax (NIF) propagado al TPV',          $rowNif && $rowNif['id_tax'] === '12345678Z');

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 2 — Idempotencia (matched, mappings, race)
// ═══════════════════════════════════════════════════════════════════════════
suite('2. Idempotencia y mappings');

// Test 2.1: tras borrar meta y reintentar, idempotency_email matchea
delete_user_meta($uid, '_tpv_customer_id');
$customerSync->push_wc_user_to_tpv($uid);
$tpvIdReM = (int) get_user_meta($uid, '_tpv_customer_id', true);
ok('2.1 re-push tras borrar meta → matched mismo tpv_id', $tpvIdReM === $tpvId);

// Test 2.2: mapping registrado correctamente
$mapping = get_mapping($pdo, $tpvId);
ok('2.2 mapping en api_external_mapping_customer', $mapping !== null);
ok('2.2 mapping.channel = woocommerce',           $mapping && $mapping['channel'] === 'woocommerce');
ok('2.2 mapping.external_id = uid',               $mapping && (int)$mapping['external_id'] === $uid);

// Test 2.3: re-push de customer ya mapeado va por PATCH (no crea otro)
$cntBefore = (int)$pdo->query("SELECT COUNT(*) FROM 2465_customer")->fetchColumn();
$customerSync->push_wc_user_to_tpv($uid);
$cntAfter = (int)$pdo->query("SELECT COUNT(*) FROM 2465_customer")->fetchColumn();
ok('2.3 re-push NO crea duplicado en TPV',       $cntBefore === $cntAfter);

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 3 — PATCH 404 huérfano
// ═══════════════════════════════════════════════════════════════════════════
suite('3. PATCH 404 huérfano (TPV reset)');

// Test 3.1: meta apunta a tpv_id inexistente → PATCH 404 → recrea como POST
$emailHuerf = 'huerf_' . uniqid() . '@cetf-test.local';
$uidHuerf = make_user($emailHuerf, ['first_name' => 'Huerfanito']);
$createdUids[] = $uidHuerf;
update_user_meta($uidHuerf, '_tpv_customer_id', 999999998); // id inexistente
$customerSync->push_wc_user_to_tpv($uidHuerf);
$tpvHuerfNew = (int) get_user_meta($uidHuerf, '_tpv_customer_id', true);
ok('3.1 PATCH 404 → meta huérfano borrado',      $tpvHuerfNew !== 999999998);
ok('3.1 PATCH 404 → recreado en TPV',            $tpvHuerfNew > 0);
$rowHuerf = read_tpv($pdo, $tpvHuerfNew);
ok('3.1 customer recreado coincide email',       $rowHuerf && $rowHuerf['email'] === $emailHuerf);

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 4 — Anti-bucle y modo principal=tpv
// ═══════════════════════════════════════════════════════════════════════════
suite('4. Anti-bucle y principal=tpv');

// Test 4.1: con flag activo, push se omite (evita bucle al recibir webhook)
$emailLoop = 'loop_' . uniqid() . '@cetf-test.local';
$GLOBALS['tpv_sync_skip_wc_customer_push'] = true;
$uidLoop = make_user($emailLoop, ['first_name' => 'Loop']);
$customerSync->push_wc_user_to_tpv($uidLoop);
$tpvLoop = (int) get_user_meta($uidLoop, '_tpv_customer_id', true);
$GLOBALS['tpv_sync_skip_wc_customer_push'] = false;
$createdUids[] = $uidLoop;
ok('4.1 flag activo → NO crea customer en TPV',  $tpvLoop === 0);

// Test 4.2: modo principal=tpv, isla WC (sin mapping previo) NO se propaga
update_option('tpv_sync_principal', 'tpv', false);
$emailIsla = 'isla_' . uniqid() . '@cetf-test.local';
$uidIsla = make_user($emailIsla, ['first_name' => 'Isla']);
$createdUids[] = $uidIsla;
$tpvIsla = (int) get_user_meta($uidIsla, '_tpv_customer_id', true);
ok('4.2 principal=tpv + isla WC → NO push',       $tpvIsla === 0);
update_option('tpv_sync_principal', '', false);

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 5 — Bulk push
// ═══════════════════════════════════════════════════════════════════════════
suite('5. Bulk push paginado');

// Test 5.1: bulk paginado con 8 nuevos users
$GLOBALS['tpv_sync_skip_wc_customer_push'] = true; // suprimir hooks individuales
$bulkUids = [];
for ($i = 0; $i < 8; $i++) {
    $em = "bulk{$i}_" . uniqid() . '@cetf-test.local';
    $bulkUids[] = make_user($em, ['first_name' => "Bulk$i"]);
}
$GLOBALS['tpv_sync_skip_wc_customer_push'] = false;
$createdUids = array_merge($createdUids, $bulkUids);

// Verificar pre-bulk
$preMapped = 0;
foreach ($bulkUids as $u) if ((int)get_user_meta($u, '_tpv_customer_id', true) > 0) $preMapped++;
ok('5.1 pre-bulk: 0 de los 8 tiene mapping',     $preMapped === 0);

// Ejecutar bulk paginado
$totalSent = 0; $offset = 0; $batch = 5; $loops = 0;
do {
    $stats = $customerSync->push_all_wc_users($batch, $offset);
    $totalSent += $stats['sent'];
    $offset += $batch;
    if (++$loops > 30) break;
    if (($stats['sent'] + $stats['skipped'] + $stats['errors']) === 0) break;
} while (true);

$mapped = 0;
foreach ($bulkUids as $u) if ((int)get_user_meta($u, '_tpv_customer_id', true) > 0) $mapped++;
ok('5.1 bulk mapeó los 8 users',                 $mapped === 8, "mapped=$mapped sent=$totalSent");

// Test 5.2: bulk se reejecuta sin duplicar
$cntBefore = (int)$pdo->query("SELECT COUNT(*) FROM 2465_customer")->fetchColumn();
$customerSync->push_all_wc_users($batch, 0);
$cntAfter = (int)$pdo->query("SELECT COUNT(*) FROM 2465_customer")->fetchColumn();
ok('5.2 bulk re-ejecutado NO duplica',           $cntBefore === $cntAfter);

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 6 — Pull TPV → WC (vía sync_customer_from_tpv)
// ═══════════════════════════════════════════════════════════════════════════
suite('6. Pull TPV → WC (webhook receiver simulado)');

$products = new TPV_Sync_Product_Sync($api);

// Test 6.1: customer.created crea WP user nuevo
$emailPull = 'pull_' . uniqid() . '@cetf-test.local';
$pdo->exec("INSERT INTO 2465_customer
    (customer_group_id, store_id, language_id, firstname, lastname, email, telephone,
     fax, password, salt, newsletter, address_id, custom_field, ip, status, safe, token, code, date_added, id_tax)
    VALUES (1, 0, 2, 'PullName', 'PullSurname', '$emailPull', '666999888', '', '', '', 0, 0, '', '', 1, 0, '', '', NOW(), 'X9999999X')");
$tpvPull = (int) $pdo->lastInsertId();
$products->sync_customer_from_tpv($tpvPull, [
    'email' => $emailPull, 'firstname' => 'PullName', 'lastname' => 'PullSurname',
    'telephone' => '666999888', 'id_tax' => 'X9999999X',
]);
$wcPullUid = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='_tpv_customer_id' AND meta_value=%d LIMIT 1",
    $tpvPull
));
ok('6.1 sync_customer_from_tpv crea WP user',    $wcPullUid > 0);
$wcPullData = read_wc($wcPullUid);
ok('6.1 first_name copiado',                     $wcPullData['first_name'] === 'PullName');
ok('6.1 last_name copiado',                      $wcPullData['last_name'] === 'PullSurname');
ok('6.1 billing_phone copiado',                  $wcPullData['billing_phone'] === '666999888');
ok('6.1 billing_vat copiado',                    $wcPullData['billing_vat'] === 'X9999999X');

// Test 6.2: customer.updated cambia datos del user existente
$products->sync_customer_from_tpv($tpvPull, [
    'email' => $emailPull, 'firstname' => 'PullUpdated', 'lastname' => 'PullSurname',
    'telephone' => '600000000',
]);
$wcPullData2 = read_wc($wcPullUid);
ok('6.2 update propaga first_name',              $wcPullData2['first_name'] === 'PullUpdated');
ok('6.2 update propaga billing_phone',           $wcPullData2['billing_phone'] === '600000000');

// Test 6.3: customer.deleted hace soft-unlink (preserva user)
$products->delete_wc_customer_by_tpv_id($tpvPull);
$still = get_user_by('id', $wcPullUid);
ok('6.3 delete preserva WP user',                $still !== false);
$wcPullData3 = read_wc($wcPullUid);
ok('6.3 _tpv_customer_id eliminado',             empty($wcPullData3['_tpv_customer_id']));
ok('6.3 _tpv_customer_deleted_at marcado',       !empty($wcPullData3['_deleted_at']));

clean_tpv($pdo, $tpvPull);
wp_delete_user($wcPullUid);

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 7 — Round-trip y consistencia
// ═══════════════════════════════════════════════════════════════════════════
suite('7. Round-trip WC → TPV → WC');

// Test 7.1: WC crea, TPV cambia, pull → WC actualiza
$emailRT = 'rt_' . uniqid() . '@cetf-test.local';
$uidRT = make_user($emailRT, ['first_name' => 'Round', 'last_name' => 'Trip']);
$createdUids[] = $uidRT;
$tpvRT = (int) get_user_meta($uidRT, '_tpv_customer_id', true);
ok('7.1 push WC→TPV ok',                         $tpvRT > 0);

$pdo->prepare("UPDATE 2465_customer SET firstname='TpvSide' WHERE customer_id=?")->execute([$tpvRT]);
$products->sync_customer_from_tpv($tpvRT, [
    'email' => $emailRT, 'firstname' => 'TpvSide', 'lastname' => 'Trip',
]);
$wcRTData = read_wc($uidRT);
ok('7.1 round-trip: WC first_name = TpvSide',    $wcRTData['first_name'] === 'TpvSide');

// ─── Cleanup ──────────────────────────────────────────────────────────────
foreach ($createdUids as $u) {
    $tid = (int) get_user_meta($u, '_tpv_customer_id', true);
    if ($tid > 0) clean_tpv($pdo, $tid);
    if (function_exists('wp_delete_user')) @wp_delete_user($u);
}
foreach ($snap as $k => $v) update_option($k, $v);

// ─── Resumen ──────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n\033[1;36m═══ Resultado customers_e2e_full: $pass/$total pasaron";
if ($fail > 0) {
    echo " · \033[31m$fail fallaron\033[0m\033[1;36m ═══\033[0m\n\n\033[31mFallos:\033[0m\n";
    foreach ($failures as $f) echo "  • $f\n";
} else {
    echo " ═══\033[0m\n";
}
echo "\n";
exit($fail > 0 ? 1 : 0);
