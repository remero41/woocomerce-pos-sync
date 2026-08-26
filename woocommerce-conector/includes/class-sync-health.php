<?php
/**
 * Estado de salud de la sincronización — las señales del panel.
 *
 * El estándar del nicho (Square/WooCommerce) diagnostica con DOS señales, y
 * la gracia está en cruzarlas:
 *
 *   marca fresca + pendientes creciendo  -> el ejecutor va bien, la cola lo
 *                                           desborda (subir frecuencia/lote)
 *   marca obsoleta                       -> la sincronización está rota
 *                                           (mirar credenciales/cron/TPV)
 *
 * Cada señal por separado no distingue esos dos casos, que piden acciones
 * opuestas. Por eso el panel pide las dos.
 *
 * Los umbrales viven aquí como funciones PURAS, sin WordPress, para poder
 * probarlos: es la única parte con lógica de verdad. El render se comprueba
 * mirando la pantalla.
 */

if (!defined('ABSPATH')) exit;

final class TPV_Sync_Health
{
    /** Marca de sincronización: verde por debajo, ámbar por debajo de la 2ª. */
    public const FRESH_OK_SEC   = 900;   // 15 min
    public const FRESH_WARN_SEC = 3600;  // 1 h

    /** Pendientes en cola a partir de los cuales el estándar pinta ámbar. */
    public const QUEUE_WARN = 50;

    /**
     * Nivel de la marca de última sincronización correcta.
     *
     * @param int|null $ageSeconds segundos desde la última sync OK.
     *                             null = nunca se ha sincronizado, que NO es
     *                             lo mismo que "hace mucho": tratarlo como 0
     *                             daría una antigüedad absurda (desde 1970) y
     *                             el nivel correcto por accidente.
     */
    public static function freshness_level(?int $ageSeconds): string
    {
        if ($ageSeconds === null)                    return 'err';
        if ($ageSeconds < self::FRESH_OK_SEC)        return 'ok';
        if ($ageSeconds < self::FRESH_WARN_SEC)      return 'warn';
        return 'err';
    }

    /**
     * Nivel de la cola.
     *
     * Una entrada abandonada pesa más que mil pendientes: las pendientes se
     * reintentan solas con backoff, las abandonadas ya no. Son dato perdido
     * salvo acción manual, así que mandan sobre el contador.
     */
    public static function queue_level(int $pending, int $abandoned): string
    {
        if ($abandoned > 0)                return 'err';
        if ($pending >= self::QUEUE_WARN)  return 'warn';
        return 'ok';
    }

    /**
     * Cruza las dos señales y devuelve QUÉ pasa, no solo un color.
     *
     * Un color le dice al comerciante que algo va mal; esto le dice qué
     * mirar, que es la diferencia entre un panel y un semáforo.
     *
     * @return array{kind:string,level:string}
     *   kind: healthy | backlog | stalled | dropped
     */
    public static function diagnose(?int $ageSeconds, int $pending, int $abandoned): array
    {
        $fresh = self::freshness_level($ageSeconds);
        $queue = self::queue_level($pending, $abandoned);

        // Abandonadas primero: es lo único que ya no se arregla solo.
        if ($abandoned > 0) {
            return ['kind' => 'dropped', 'level' => 'err'];
        }

        // Si el ejecutor está parado, un backlog es CONSECUENCIA, no la causa:
        // mandar al comerciante a mirar el tamaño del lote sería mandarlo mal.
        if ($fresh === 'err') {
            return ['kind' => 'stalled', 'level' => 'err'];
        }

        if ($queue === 'warn') {
            return ['kind' => 'backlog', 'level' => 'warn'];
        }

        if ($fresh === 'warn') {
            return ['kind' => 'stalled', 'level' => 'warn'];
        }

        return ['kind' => 'healthy', 'level' => 'ok'];
    }

    // ─── Lectura de las señales (esto ya toca WordPress) ─────────────────────

    /**
     * Estados del log que significan "esto NO salió bien".
     *
     * El criterio va por la lista de FALLOS, no por la de éxitos, y eso es
     * deliberado: la columna `status` de tpv_sync_log es texto libre y cada
     * llamante escribe lo que quiere — hay 9 valores en uso ('ok', 'error',
     * 'warn', 'skip', 'reconcile_fix', 'reconcile_done', 'stock', 'batch',
     * 'reconcile_error'). Definir la marca como status='ok' dejaría fuera
     * 'reconcile_done', 'stock' o 'batch' y daría "nunca sincronizado" en una
     * instalación que va perfectamente. Verificado en el banco vivo, donde
     * NO hay ni una fila 'ok' y sí un 'reconcile_done' reciente.
     *
     * Los fallos, en cambio, son un conjunto cerrado y conocido.
     */
    private const FAILURE_STATUSES = ['error', 'reconcile_error', 'abandoned'];

    /** Fragmento SQL con la lista de estados de fallo, ya escapada. */
    private static function failure_sql_list(): string
    {
        return "'" . implode("','", self::FAILURE_STATUSES) . "'";
    }

    /**
     * Segundos desde la última sincronización CORRECTA, o null si no hay.
     *
     * Se lee de tpv_sync_log, que ya existía y nadie consultaba para esto.
     * 'skip' cuenta como correcta: el sistema decidió no hacer nada, que es
     * una decisión, no un fallo.
     */
    public static function last_ok_age(): ?int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'tpv_sync_log';
        $ts = $wpdb->get_var(
            "SELECT created_at FROM $table
             WHERE status NOT IN (" . self::failure_sql_list() . ")
             ORDER BY id DESC LIMIT 1"
        );
        if (!$ts) return null;
        $age = time() - (int) mysql2date('U', $ts, false);
        return max(0, $age);
    }

    /** Último error registrado: mensaje y cuándo. null si no hay ninguno. */
    public static function last_error(): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'tpv_sync_log';
        $row = $wpdb->get_row(
            "SELECT message, event_type, created_at FROM $table
             WHERE status IN (" . self::failure_sql_list() . ")
             ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );
        return $row ?: null;
    }

    /** Las cuatro señales del panel, listas para pintar. */
    public static function snapshot(): array
    {
        global $wpdb;
        $queueTable = $wpdb->prefix . 'tpv_sync_queue';

        $pending   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $queueTable WHERE status = 'pending'");
        $abandoned = (int) $wpdb->get_var("SELECT COUNT(*) FROM $queueTable WHERE status = 'abandoned'");
        $age       = self::last_ok_age();

        $breakerState = 'closed';
        if (class_exists('TPV_Sync_Circuit_Breaker')) {
            $breakerState = (new TPV_Sync_Circuit_Breaker())->state();
        }

        return [
            'age'            => $age,
            'freshness'      => self::freshness_level($age),
            'pending'        => $pending,
            'abandoned'      => $abandoned,
            'queue_level'    => self::queue_level($pending, $abandoned),
            'breaker'        => $breakerState,
            'breaker_level'  => $breakerState === 'open' ? 'err'
                              : ($breakerState === 'half_open' ? 'warn' : 'ok'),
            'last_error'     => self::last_error(),
            'diagnosis'      => self::diagnose($age, $pending, $abandoned),
        ];
    }
}
