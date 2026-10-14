<?php
declare(strict_types=1);
/**
 * Un pedido por transferencia que la tienda marca «completado» a mano llega al
 * TPV igual que uno pagado con tarjeta.
 *
 * Reproducido el 14-10-2026 en un WooCommerce 10.6.2 real: el plugin solo oía
 * `woocommerce_payment_complete` y `woocommerce_order_status_processing`. Una
 * pasarela de transferencia deja el pedido en on-hold (class-wc-gateway-bacs.php
 * :408) y, cuando el tendero lo marca completado, WC pasa por
 * status_transition() (class-wc-order.php:432-477), que NUNCA llama a
 * payment_complete(). Resultado: ni una llamada HTTP; la venta no existía en el
 * TPV. Marcarlo «procesando» sí lo enviaba: llegaba o no según el botón.
 *
 * Aquí se repite, hook a hook, la secuencia exacta que dispara WC 10.6.2 en cada
 * caso contra lo que el plugin registra de verdad (fixtures/cargar_plugin_hooks.php).
 */

/** Secuencias de hooks de WooCommerce 10.6.2, tomadas de su código. */
const WC_SECUENCIAS = [
    // WC_Order::payment_complete() (class-wc-order.php:137-187): pasarelas de tarjeta.
    'tarjeta: la pasarela llama a payment_complete()' => [
        'woocommerce_pre_payment_complete',
        'woocommerce_order_status_processing',
        'woocommerce_order_status_pending_to_processing',
        'woocommerce_order_status_changed',
        'woocommerce_payment_complete',
    ],
    // BACS: update_status('on-hold') y luego el admin marca completed.
    'transferencia: on-hold y la tienda lo marca COMPLETADO a mano' => [
        'woocommerce_order_status_on-hold',
        'woocommerce_order_status_pending_to_on-hold',
        'woocommerce_order_status_changed',
        'woocommerce_order_status_completed',
        'woocommerce_order_status_on-hold_to_completed',
        'woocommerce_order_status_changed',
    ],
    'transferencia: on-hold y la tienda lo marca PROCESANDO a mano' => [
        'woocommerce_order_status_on-hold',
        'woocommerce_order_status_pending_to_on-hold',
        'woocommerce_order_status_changed',
        'woocommerce_order_status_processing',
        'woocommerce_order_status_on-hold_to_processing',
        'woocommerce_order_status_changed',
    ],
];

/** Solo hasta on-hold: todavía no hay pago. */
const WC_SECUENCIA_SIN_PAGAR = [
    'woocommerce_order_status_on-hold',
    'woocommerce_order_status_pending_to_on-hold',
    'woocommerce_order_status_changed',
];

function pedido_estado_manual_acciones(): ?array
{
    $salida = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr '
        . escapeshellarg(__DIR__ . '/fixtures/cargar_plugin_hooks.php') . ' 2>/dev/null');
    $r = json_decode((string) $salida, true);
    return is_array($r['acciones'] ?? null) ? $r['acciones'] : null;
}

/** ¿Algún hook de la secuencia tiene enganchado el envío del pedido al TPV? */
function pedido_estado_manual_se_envia(array $acciones, array $secuencia): bool
{
    foreach ($secuencia as $hook) {
        foreach ($acciones[$hook] ?? [] as $cb) {
            if (str_ends_with($cb, '::send_to_tpv')) return true;
        }
    }
    return false;
}

function run_pedido_estado_manual_tests(WooTestRunner $t): void
{
    $t->suite('Pedidos — el estado que marca la tienda a mano también los envía');

    $acciones = pedido_estado_manual_acciones();
    $t->test('el plugin se carga y registra sus hooks', function ($t) use ($acciones) {
        $t->assert($acciones !== null && isset($acciones['woocommerce_payment_complete']),
            'sin el plugin cargado no hay nada que probar');
    });
    if ($acciones === null) { return; }

    foreach (WC_SECUENCIAS as $caso => $secuencia) {
        $t->test($caso . ' → llega al TPV', function ($t) use ($acciones, $secuencia) {
            $t->assert(pedido_estado_manual_se_envia($acciones, $secuencia),
                'ningún hook de la secuencia llama a send_to_tpv: la venta no existe en el TPV');
        });
    }

    $t->test('un pedido on-hold (sin pagar) NO se envía', function ($t) use ($acciones) {
        $t->assert(!pedido_estado_manual_se_envia($acciones, WC_SECUENCIA_SIN_PAGAR),
            'on-hold es «esperando el pago»: enviarlo sería registrar una venta sin cobro');
    });

    $t->test('el cambio de estado sigue enganchado para los pedidos ya enviados', function ($t) use ($acciones) {
        $cbs = $acciones['woocommerce_order_status_changed'] ?? [];
        $t->assert((bool) array_filter($cbs, fn($c) => str_ends_with($c, '::on_wc_status_changed')),
            'sin él, completar/cancelar en Woo no se refleja en el TPV');
    });
}
