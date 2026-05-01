<?php
declare(strict_types=1);

/**
 * Sincronización bidireccional de clientes WooCommerce ↔ TPV.
 *
 * Direcciones:
 *   - WC → TPV: hooks user_register / profile_update / delete_user.
 *     Cada cambio se empuja vía POST /customers (idempotente por email),
 *     PATCH /customers/{id}, o DELETE (soft-delete).
 *   - TPV → WC: gestionado por TPV_Sync_Product_Sync::sync_customer_from_tpv()
 *     (vive ahí por razones históricas — el receptor de webhooks delega).
 *
 * Modo principal (tpv_sync_principal):
 *   - 'tpv': el TPV manda. Cambios WC en clientes sincronizados se revierten
 *     re-leyendo del TPV. Cambios en clientes nuevos solo-WC NO se propagan.
 *   - 'wc' o vacío: WC manda. Cambios WC se empujan al TPV libremente.
 *
 * Anti-bucle: $GLOBALS['tpv_sync_skip_wc_customer_push'] lo activa el
 * receptor de webhooks cuando está escribiendo desde TPV → WC.
 */
defined('ABSPATH') || exit;

class TPV_Sync_Customer_Sync
{
    public const TPV_CUSTOMER_META = '_tpv_customer_id';

    private TPV_Sync_API_Client $api;

    public function __construct(TPV_Sync_API_Client $api)
    {
        $this->api = $api;
    }

    // ─── Hooks WC → TPV ────────────────────────────────────────────────────

    /**
     * Hook user_register / profile_update / wp_update_user.
     * Empuja create-or-update al TPV.
     *
     * @param int $userId
     */
    public function push_wc_user_to_tpv(int $userId): void
    {
        if (!empty($GLOBALS['tpv_sync_skip_wc_customer_push'])) return;
        if ($userId <= 0) return;

        // Solo sincronizamos usuarios con rol 'customer' (clientes WC).
        // Excluimos admins, editores, suscriptores genéricos sin rol customer.
        $user = get_user_by('id', $userId);
        if (!$user) return;
        if (!in_array('customer', (array)$user->roles, true)) return;

        // Modo principal=tpv: si el WC user ya tiene mapping, revertir
        // re-leyendo del TPV. Si no tiene mapping (isla WC), no propagar.
        $principal = (string) get_option('tpv_sync_principal', '');
        if ($principal === 'tpv') {
            $tpvId = (int) get_user_meta($userId, self::TPV_CUSTOMER_META, true);
            if ($tpvId === 0) return; // isla WC, no propagar
            // Revertimos al estado del TPV (se gestiona desde el flujo
            // existente: webhook puller). Aquí solo logueamos y abortamos.
            $this->log('skip', $userId, "Modo principal=tpv: cambios WC ignorados para user_id=$userId");
            return;
        }

        $payload = $this->buildPayloadFromUser($user);
        if ($payload === null) return; // sin email válido, no empujamos

        $tpvId = (int) get_user_meta($userId, self::TPV_CUSTOMER_META, true);

        if ($tpvId > 0) {
            $r = $this->api->patch("/customers/$tpvId", $payload);
            // 404 huérfano: el TPV se reseteó. Borrar meta y caer a POST.
            $isNotFound = !empty($r['type']) && str_contains((string)$r['type'], 'not_found');
            if ($isNotFound) {
                delete_user_meta($userId, self::TPV_CUSTOMER_META);
                $this->log('warn', $userId, "PATCH /customers/$tpvId 404 → meta huérfano, recreando");
                $tpvId = 0;
            } elseif (!empty($r['error']) || !empty($r['errors']) || !empty($r['type'])) {
                $this->log('error', $userId, $this->formatApiError($r));
                return;
            } else {
                $this->log('ok', $tpvId, "Customer actualizado en TPV (user_id=$userId)");
                return;
            }
        }

        // POST /customers — la API es idempotente por email: si ya existe
        // un customer con ese email en el TPV, devuelve action='matched'
        // con su customer_id existente.
        $payload['client_external_id'] = (string) $userId;
        $r = $this->api->post('/customers', $payload);
        $newId = (int) ($r['data']['customer_id'] ?? 0);
        if ($newId === 0) {
            $this->log('error', $userId, $this->formatApiError($r));
            return;
        }
        update_user_meta($userId, self::TPV_CUSTOMER_META, $newId);
        $action = (string) ($r['data']['action'] ?? 'unknown');
        $this->log('ok', $newId, "Customer $action en TPV (user_id=$userId, email={$payload['email']})");
    }

    /**
     * Hook delete_user.
     * Soft-delete en TPV (status=0). NO borramos el meta del WP user
     * porque el user ya no existe; el meta cae con la fila wp_usermeta.
     */
    public function push_wc_user_delete_to_tpv(int $userId): void
    {
        if (!empty($GLOBALS['tpv_sync_skip_wc_customer_push'])) return;
        if ($userId <= 0) return;
        $tpvId = (int) get_user_meta($userId, self::TPV_CUSTOMER_META, true);
        if ($tpvId === 0) return; // sin mapping, nada que hacer

        $r = $this->api->delete("/customers/$tpvId");
        if (!empty($r['error']) || !empty($r['errors'])) {
            $this->log('error', $tpvId, "DELETE /customers/$tpvId falló: " . $this->formatApiError($r));
            return;
        }
        $this->log('ok', $tpvId, "Customer soft-deleted en TPV (user_id=$userId)");
    }

    // ─── Bulk push (sincronización inicial WC → TPV) ───────────────────────

    /**
     * Empuja todos los clientes WC al TPV en lotes. Llamado desde la UI
     * admin la primera vez que el cliente conecta el plugin con fuente=WC.
     *
     * Devuelve estadísticas (sent/created/matched/errors).
     */
    public function push_all_wc_users(int $batchSize = 100, int $offset = 0): array
    {
        $stats = ['sent' => 0, 'created' => 0, 'matched' => 0, 'errors' => 0, 'skipped' => 0];
        $users = get_users([
            'role'    => 'customer',
            'number'  => $batchSize,
            'offset'  => $offset,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'fields'  => ['ID', 'user_email', 'display_name'],
        ]);
        if (empty($users)) return $stats;

        foreach ($users as $u) {
            $userId = (int) $u->ID;
            // Saltar si ya tiene mapping (no reenviar lo ya sincronizado).
            $tpvId = (int) get_user_meta($userId, self::TPV_CUSTOMER_META, true);
            if ($tpvId > 0) { $stats['skipped']++; continue; }

            $userObj = get_user_by('id', $userId);
            if (!$userObj) { $stats['errors']++; continue; }

            $payload = $this->buildPayloadFromUser($userObj);
            if ($payload === null) { $stats['skipped']++; continue; }

            $payload['client_external_id'] = (string) $userId;
            $r = $this->api->post('/customers', $payload);
            $newId = (int) ($r['data']['customer_id'] ?? 0);
            if ($newId === 0) { $stats['errors']++; continue; }

            update_user_meta($userId, self::TPV_CUSTOMER_META, $newId);
            $action = (string) ($r['data']['action'] ?? 'unknown');
            if ($action === 'created')      $stats['created']++;
            elseif ($action === 'matched')  $stats['matched']++;
            $stats['sent']++;
        }
        return $stats;
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * Construye el payload de la API desde un WP_User.
     * Devuelve null si el user no tiene email válido (no puede sincronizarse).
     */
    private function buildPayloadFromUser(WP_User $user): ?array
    {
        $email = trim((string) $user->user_email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        $userId    = (int) $user->ID;
        $firstname = trim((string) get_user_meta($userId, 'first_name', true)
            ?: get_user_meta($userId, 'billing_first_name', true));
        $lastname  = trim((string) get_user_meta($userId, 'last_name', true)
            ?: get_user_meta($userId, 'billing_last_name', true));

        if ($firstname === '' && $lastname === '') {
            // Fallback: usar display_name partido por el primer espacio.
            $parts = explode(' ', trim((string)$user->display_name), 2);
            $firstname = $parts[0] ?? '';
            $lastname  = $parts[1] ?? '';
        }

        $telephone = trim((string) get_user_meta($userId, 'billing_phone', true));
        $idTax     = trim((string) (
            get_user_meta($userId, 'billing_vat',     true)
            ?: get_user_meta($userId, 'billing_nif',  true)
            ?: get_user_meta($userId, 'billing_cif',  true)
            ?: get_user_meta($userId, '_billing_vat', true)
        ));

        $payload = [
            'email'      => $email,
            'firstname'  => mb_substr($firstname, 0, 32),
            'lastname'   => mb_substr($lastname, 0, 32),
            'telephone'  => mb_substr($telephone, 0, 32),
            'newsletter' => false, // WC no tiene flag estándar; deja false
        ];
        if ($idTax !== '') $payload['id_tax'] = $idTax;

        // Dirección de facturación (si existe en metas WC)
        $address = $this->buildAddressFromUser($user);
        if ($address !== null) $payload['address'] = $address;

        return $payload;
    }

    /**
     * Lee billing_* metas y construye un array address compatible con la API.
     * Devuelve null si no hay address_1.
     */
    private function buildAddressFromUser(WP_User $user): ?array
    {
        $userId = (int) $user->ID;
        $a1 = trim((string) get_user_meta($userId, 'billing_address_1', true));
        if ($a1 === '') return null;

        return [
            'firstname'    => trim((string) get_user_meta($userId, 'billing_first_name', true)),
            'lastname'     => trim((string) get_user_meta($userId, 'billing_last_name',  true)),
            'company'      => trim((string) get_user_meta($userId, 'billing_company',    true)),
            'address_1'    => $a1,
            'address_2'    => trim((string) get_user_meta($userId, 'billing_address_2',  true)),
            'city'         => trim((string) get_user_meta($userId, 'billing_city',       true)),
            'postcode'     => trim((string) get_user_meta($userId, 'billing_postcode',   true)),
            'country_code' => trim((string) get_user_meta($userId, 'billing_country',    true)),
            'zone_code'    => trim((string) get_user_meta($userId, 'billing_state',      true)),
        ];
    }

    /**
     * Formatea {error, errors:[{field,message}]} como string legible.
     */
    private function formatApiError(array $r): string
    {
        $base = (string) ($r['error'] ?? '');
        if (!empty($r['errors']) && is_array($r['errors'])) {
            $details = [];
            foreach ($r['errors'] as $err) {
                if (is_array($err)) {
                    $f = $err['field'] ?? '?';
                    $m = $err['message'] ?? json_encode($err);
                    $details[] = "$f: $m";
                } else {
                    $details[] = (string)$err;
                }
            }
            $base = ($base !== '' ? $base . ' — ' : '') . implode('; ', $details);
        }
        if ($base === '') $base = substr(json_encode($r), 0, 400);
        return $base;
    }

    private function log(string $status, int $resourceId, string $msg): void
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'tpv_sync_log',
            [
                'event_type'  => 'customer_sync',
                'resource'    => 'customer',
                'resource_id' => $resourceId,
                'status'      => $status,
                'message'     => $msg,
                'created_at'  => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s']
        );
    }
}
