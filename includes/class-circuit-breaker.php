<?php
declare(strict_types=1);
/**
 * Circuit breaker client-side.
 *
 * Patrón estándar (Hystrix/resilience4j). Tres estados:
 *   - CLOSED:     Operación normal. Cada fallo incrementa un counter.
 *   - OPEN:       Tras N fallos seguidos, durante X segundos todas las
 *                 llamadas se rechazan inmediatamente. Cero carga a un
 *                 backend caído; los reintentos van a la fallback queue.
 *   - HALF_OPEN:  Tras X segundos en OPEN, se permite UNA llamada de prueba.
 *                 Si OK → CLOSED. Si falla → OPEN otros X segundos.
 *
 * El estado se persiste en transient (caché WP: memcached/redis si configurados,
 * sino opciones transitorias). El counter también.
 *
 * Uso:
 *     $cb = new TPV_Sync_Circuit_Breaker();
 *     if (!$cb->allowRequest()) return ['error' => 'circuit_open'];
 *     $resp = ...;
 *     if ($fail) $cb->recordFailure(); else $cb->recordSuccess();
 */
defined('ABSPATH') || exit;

class TPV_Sync_Circuit_Breaker
{
    public const STATE_CLOSED    = 'closed';
    public const STATE_OPEN      = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    // Configuración (sensata por defecto; ajustable vía filter)
    public const FAILURE_THRESHOLD = 5;   // fallos seguidos para abrir
    public const OPEN_DURATION_SEC = 60;  // segundos en OPEN antes de HALF_OPEN

    private string $namespace;

    public function __construct(string $namespace = 'tpv_sync')
    {
        $this->namespace = $namespace;
    }

    private function stateKey(): string   { return "{$this->namespace}_cb_state"; }
    private function counterKey(): string { return "{$this->namespace}_cb_failures"; }
    private function openedKey(): string  { return "{$this->namespace}_cb_opened_at"; }

    /**
     * ¿Se permite esta request?
     * CLOSED    → siempre sí.
     * OPEN      → no, hasta que pase OPEN_DURATION_SEC → transita a HALF_OPEN.
     * HALF_OPEN → sí (una sola request de prueba; la siguiente será CLOSED u OPEN según resultado).
     */
    public function allowRequest(): bool
    {
        $state = $this->state();
        if ($state === self::STATE_CLOSED)    return true;
        if ($state === self::STATE_HALF_OPEN) return true;

        // OPEN → ¿ha pasado ya la ventana?
        $openedAt = (int)get_transient($this->openedKey());
        if ($openedAt > 0 && (time() - $openedAt) >= self::OPEN_DURATION_SEC) {
            $this->transitionTo(self::STATE_HALF_OPEN);
            return true;
        }
        return false;
    }

    public function state(): string
    {
        $s = get_transient($this->stateKey());
        return $s ?: self::STATE_CLOSED;
    }

    public function recordSuccess(): void
    {
        // Cualquier éxito cierra el circuito y limpia el contador.
        if ($this->state() !== self::STATE_CLOSED) {
            $this->transitionTo(self::STATE_CLOSED);
        }
        delete_transient($this->counterKey());
    }

    public function recordFailure(): void
    {
        $state = $this->state();
        if ($state === self::STATE_HALF_OPEN) {
            // Fallo durante prueba → reabrir inmediatamente.
            $this->transitionTo(self::STATE_OPEN);
            return;
        }
        if ($state !== self::STATE_OPEN) {
            $n = (int)get_transient($this->counterKey());
            $n++;
            set_transient($this->counterKey(), $n, HOUR_IN_SECONDS);
            if ($n >= self::FAILURE_THRESHOLD) {
                $this->transitionTo(self::STATE_OPEN);
            }
        }
    }

    /**
     * Reset manual (para pruebas, panel admin).
     */
    public function reset(): void
    {
        delete_transient($this->stateKey());
        delete_transient($this->counterKey());
        delete_transient($this->openedKey());
    }

    public function stats(): array
    {
        return [
            'state'       => $this->state(),
            'failures'    => (int)get_transient($this->counterKey()),
            'opened_at'   => (int)get_transient($this->openedKey()) ?: null,
            'threshold'   => self::FAILURE_THRESHOLD,
            'open_window' => self::OPEN_DURATION_SEC,
        ];
    }

    private function transitionTo(string $newState): void
    {
        set_transient($this->stateKey(), $newState, DAY_IN_SECONDS);
        if ($newState === self::STATE_OPEN) {
            set_transient($this->openedKey(), time(), DAY_IN_SECONDS);
        } elseif ($newState === self::STATE_CLOSED) {
            delete_transient($this->openedKey());
            delete_transient($this->counterKey());
        }
    }
}
