<?php
declare(strict_types=1);

/**
 * Mock in-memory de $wpdb. Suficiente para los unit tests de Queue.
 *
 * NO es un driver SQL real — sólo simula los métodos que Queue usa:
 *   - insert(table, data)
 *   - update(table, data, where)
 *   - get_results(sql, ...)
 *   - prepare(sql, ...args)
 *   - insert_id (property)
 *   - rows_affected (property)
 *   - prefix (property)
 *   - get_charset_collate()
 *
 * Limitaciones documentadas:
 *   - prepare() devuelve un string plano (no escapa — es mock).
 *   - get_results() sólo reconoce el patrón del SELECT de Queue::process.
 *     Si añades un SELECT nuevo, amplía esta clase.
 */
class FakeWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public int $rows_affected = 0;
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];
    private int $nextId = 1;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $sql, ...$args): string
    {
        // Interpolación muy básica — suficiente para tests.
        foreach ($args as $arg) {
            $sql = preg_replace('/%[sd]/', is_numeric($arg) ? (string)$arg : "'" . addslashes((string)$arg) . "'", $sql, 1);
        }
        return $sql;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): bool
    {
        $id = $this->nextId++;
        $row = array_merge(['id' => $id], $data);
        $this->tables[$table] ??= [];
        $this->tables[$table][$id] = $row;
        $this->insert_id = $id;
        $this->rows_affected = 1;
        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $rows = &$this->tables[$table] ?? [];
        $n = 0;
        foreach ($rows as $id => &$row) {
            $match = true;
            foreach ($where as $k => $v) {
                if (($row[$k] ?? null) != $v) { $match = false; break; }
            }
            if ($match) {
                foreach ($data as $k => $v) {
                    $row[$k] = $v;
                }
                $n++;
            }
        }
        $this->rows_affected = $n;
        return $n;
    }

    /**
     * Devuelve filas como objetos stdClass. Sólo reconoce el SELECT de Queue::process:
     * "SELECT * FROM wp_tpv_sync_queue WHERE status = %s AND next_retry_at <= UTC_TIMESTAMP() ORDER BY next_retry_at ASC LIMIT %d"
     *
     * @return array<int, object>
     */
    public function get_results(string $sql): array
    {
        // Extraer tabla y status del SQL interpolado
        if (!preg_match('/FROM\s+(\S+)\s+WHERE\s+status\s*=\s*[\'"]?([a-z]+)[\'"]?/i', $sql, $m)) {
            return [];
        }
        $table = $m[1];
        $status = $m[2];
        $rows = $this->tables[$table] ?? [];

        $out = [];
        foreach ($rows as $row) {
            if (($row['status'] ?? null) !== $status) continue;
            // Simulamos next_retry_at <= NOW: asumimos que todos los pending del mock son procesables
            $out[] = (object)$row;
        }

        // LIMIT N
        if (preg_match('/LIMIT\s+(\d+)/i', $sql, $lm)) {
            $out = array_slice($out, 0, (int)$lm[1]);
        }
        return $out;
    }

    public function query(string $sql): int
    {
        // Usado por DELETE en purge. Match básico.
        if (preg_match('/^DELETE\s+FROM\s+(\S+)\s+WHERE\s+status\s*IN/i', $sql, $m)) {
            $table = $m[1];
            if (!isset($this->tables[$table])) return 0;
            $n = 0;
            foreach ($this->tables[$table] as $id => $row) {
                if (in_array($row['status'] ?? '', ['done', 'abandoned'], true)) {
                    unset($this->tables[$table][$id]);
                    $n++;
                }
            }
            $this->rows_affected = $n;
            return $n;
        }
        return 0;
    }
}
