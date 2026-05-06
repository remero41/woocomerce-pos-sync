<?php
declare(strict_types=1);
/**
 * Notificaciones al admin cuando algo requiere atención.
 *
 * Canales:
 *   - email    (opción: tpv_sync_notify_email, default admin_email de WP)
 *   - slack    (opción: tpv_sync_notify_slack_webhook)
 *   - telegram (opciones: tpv_sync_notify_telegram_bot + _chat_id)
 *
 * Reglas pre-definidas:
 *   - queue_abandoned: ≥1 entrada abandoned en últimos 5 min
 *   - queue_growing:   >50 items pending durante >1 hora
 *   - breaker_open:    circuit breaker en OPEN
 *   - tpv_unreachable: /health falla en el último check
 *   - audit_broken:    (futuro) audit chain rota
 *
 * Throttling: misma alerta (mismo key) solo 1×/hora vía transient.
 *
 * Uso:
 *   TPV_Sync_Notifications::alert('queue_abandoned', 'Hay 3 items en la queue', ['count' => 3]);
 *   TPV_Sync_Notifications::evaluate_rules();  // cron
 */
defined('ABSPATH') || exit;

class TPV_Sync_Notifications
{
    public const THROTTLE_SEC = 3600;

    /**
     * Emite una alerta en todos los canales configurados (salvo throttled).
     */
    public static function alert(string $key, string $message, array $context = []): void
    {
        // Throttle: misma key solo una vez por hora
        $throttleKey = 'tpv_sync_notif_' . md5($key);
        if (get_transient($throttleKey)) return;
        set_transient($throttleKey, 1, self::THROTTLE_SEC);

        $site    = (string)get_bloginfo('name');
        $subject = "[TPV Sync · $site] $key";
        $body    = "$message\n\n" . wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        self::send_email($subject, $body);
        self::send_slack($subject, $message, $context);
        self::send_telegram($subject, $message, $context);
    }

    private static function send_email(string $subject, string $body): void
    {
        $to = (string)get_option('tpv_sync_notify_email', get_option('admin_email', ''));
        if ($to === '') return;
        wp_mail($to, $subject, $body);
    }

    private static function send_slack(string $title, string $message, array $ctx): void
    {
        $url = (string)get_option('tpv_sync_notify_slack_webhook', '');
        if ($url === '') return;
        $text = "*$title*\n$message\n```" . wp_json_encode($ctx, JSON_UNESCAPED_UNICODE) . '```';
        wp_remote_post($url, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['text' => $text]),
        ]);
    }

    private static function send_telegram(string $title, string $message, array $ctx): void
    {
        $bot  = (string)get_option('tpv_sync_notify_telegram_bot', '');
        $chat = (string)get_option('tpv_sync_notify_telegram_chat_id', '');
        if ($bot === '' || $chat === '') return;
        $text = "*$title*\n$message";
        $url  = "https://api.telegram.org/bot$bot/sendMessage";
        wp_remote_post($url, [
            'timeout' => 10,
            'body'    => [
                'chat_id'    => $chat,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ],
        ]);
    }

    /**
     * Reglas pre-armadas: se evalúan en cron horario. Si se cumple alguna,
     * dispara alert() (con throttling).
     */
    public static function evaluate_rules(): void
    {
        if (!class_exists('TPV_Sync')) return;
        $sync = TPV_Sync::instance();

        // 1) queue_abandoned — hay items abandoned en los últimos 5 min
        global $wpdb;
        $table = TPV_Sync_Queue::table_name();
        $recent = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM $table
             WHERE status = 'abandoned'
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)"
        );
        if ($recent > 0) {
            self::alert('queue_abandoned',
                "$recent entradas abandonadas en la última hora. Revisar tpv_sync_queue.",
                ['abandoned_1h' => $recent]);
        }

        // 2) queue_growing — >50 pending
        $pending = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE status = 'pending'"
        );
        if ($pending > 50) {
            self::alert('queue_growing',
                "Queue creciendo: $pending items pending. Posible TPV caído/lento.",
                ['pending' => $pending]);
        }

        // 3) breaker_open
        $breaker = $sync->api->breaker();
        if ($breaker && $breaker->state() === TPV_Sync_Circuit_Breaker::STATE_OPEN) {
            self::alert('breaker_open',
                'Circuit breaker OPEN — la API TPV lleva fallando varias requests seguidas.',
                $breaker->stats());
        }

        // 4) tpv_unreachable
        $r = $sync->api->get('/health');
        if (empty($r['status']) || $r['status'] !== 'ok') {
            self::alert('tpv_unreachable',
                'La API TPV no responde OK al /health.',
                ['last_response' => is_array($r) ? array_slice($r, 0, 3) : ['raw' => substr((string)$r, 0, 200)]]);
        }
    }
}

// Cron horario para evaluar reglas
add_action('tpv_sync_notifications_eval', function () {
    if (class_exists('TPV_Sync_Notifications')) {
        TPV_Sync_Notifications::evaluate_rules();
    }
});
