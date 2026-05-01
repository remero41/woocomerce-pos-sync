#!/usr/bin/env bash
# Tests focalizados que reproducen BUG-WC-001 y BUG-WC-003.
# Antes del fix: ambos FAIL. Después: ambos PASS.
#
# Variables de entorno requeridas: ver dev-tools/tests/cazabugs_200_wp.sh
set -u

BASE="${TPV_SYNC_E2E_BASE:?Set TPV_SYNC_E2E_BASE}"
SECRET="${TPV_SYNC_E2E_SECRET:?Set TPV_SYNC_E2E_SECRET}"
WEBHOOK_URL="${TPV_SYNC_WEBHOOK_URL:?Set TPV_SYNC_WEBHOOK_URL}"
WP_ABSPATH="${TPV_SYNC_WP_ABSPATH:?Set TPV_SYNC_WP_ABSPATH}"
DB_USER="${TPV_SYNC_DB_USER:?Set TPV_SYNC_DB_USER}"
DB_PASS="${TPV_SYNC_DB_PASS:?Set TPV_SYNC_DB_PASS}"
DB_NAME="${TPV_SYNC_DB_NAME:?Set TPV_SYNC_DB_NAME}"

# Leemos el secret en runtime para no descuadrarnos cuando el plugin
# re-registra el webhook (self-healing, reconectar, regen…).
WEBHOOK_SECRET=$(php -r "
define('ABSPATH','$WP_ABSPATH');
\$_SERVER['HTTP_HOST']=parse_url('$BASE', PHP_URL_HOST);
\$_SERVER['REQUEST_URI']='/wp-admin/';
require ABSPATH.'wp-load.php';
echo (string)get_option('tpv_sync_webhook_secret','');
" 2>/dev/null)
[ -z "$WEBHOOK_SECRET" ] && WEBHOOK_SECRET="${TPV_SYNC_WEBHOOK_SECRET_FALLBACK:-}"

call() { curl -sk -H "X-Test-Secret: $SECRET" "$BASE?$1" --max-time 60 2>/dev/null; }
psql_n() { mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "$1" 2>/dev/null; }
psql_r() { mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$1" 2>/dev/null; }
jq_f() { echo "$1" | jq -r "$2" 2>/dev/null; }

send_webhook_raw() {
    local BODY="$1"; local IDEM="$2"
    local FULL_BODY=$(printf '%s' "$BODY" | sed "s/__IDEM__/$IDEM/g")
    local SIG="sha256=$(printf '%s' "$FULL_BODY" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" -hex | awk '{print $NF}')"
    curl -sk -X POST "$WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -H "X-Webhook-Signature: $SIG" \
        -H "X-Webhook-Version: 1" \
        --data "$FULL_BODY" -w '\n%{http_code}' --max-time 30 2>/dev/null
}

PASS=0; FAIL=0
record() {
    local id="$1"; local d="$2"; local r="$3"; local m="${4:-}"
    if [ "$r" = "PASS" ]; then PASS=$((PASS+1)); printf '  \e[32m✔\e[0m %s %s\n' "$id" "$d"
    else FAIL=$((FAIL+1)); printf '  \e[31m✖\e[0m %s %s  — %s\n' "$id" "$d" "$m"; fi
}

echo "================================================"
echo "  Tests focalizados: BUG-WC-001 + BUG-WC-003"
echo "================================================"

# ═══════════════════════════════════════════════════════════════════════════
# BUG-WC-001 — El client 'woocommerce' debe poder GET /products/{id}/stock.
# ═══════════════════════════════════════════════════════════════════════════
echo ""
echo "── BUG-WC-001: scope stock:read ──"

# 001.1 — crear producto via WC, disparar push_wc_stock_change
R=$(call "action=create_simple_product&name=Bug001&price=5&qty=10&sku=e2e-bug001")
PID=$(jq_f "$R" '.post_id'); TID=$(jq_f "$R" '.tpv_id')
if [ -z "$TID" ] || [ "$TID" = "0" ]; then record "001.0" "setup create product" FAIL "$R"; exit 1; fi

# 001.2 — cambiar stock via WC API con set_stock_quantity
SINCE=$(psql_n "SELECT IFNULL(MAX(id),0) FROM wp_tpv_sync_log")
call "action=update_stock&post_id=$PID&qty=42" >/dev/null
sleep 2

# 001.3 — NO debe haber errores "GET /products/.../stock → 403"
E403=$(psql_n "SELECT COUNT(*) FROM wp_tpv_sync_log WHERE id>$SINCE AND message LIKE '%/stock → 403%'")
if [ "$E403" -eq 0 ]; then record "001.1" "push_wc_stock_change sin 403 en /stock" PASS
else record "001.1" "BUG-WC-001: 403 en GET /stock" FAIL "count=$E403"; fi

# 001.4 — NO debe quedar nada encolado "por 403"
QUEUE_403=$(psql_n "SELECT COUNT(*) FROM wp_tpv_sync_queue WHERE operation='stock.push' AND last_error LIKE '%403%' AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)")
if [ "$QUEUE_403" -eq 0 ]; then record "001.2" "sin jobs encolados por 403" PASS
else record "001.2" "jobs encolados por 403" FAIL "count=$QUEUE_403"; fi

# 001.5 — El stock se debe haber propagado al TPV (tras el fix)
#   NOTA: requiere ejecutarse tras activar stock:read en el scope del cliente.
sleep 1
STOCK_TPV=$(call "action=verify_tpv&tpv_id=$TID" | jq -r '.quantity // "null"')
if [ "$STOCK_TPV" = "42" ] || [ "$STOCK_TPV" = "42.0" ]; then
    record "001.3" "stock propagado WC→TPV (tpv quantity=42)" PASS
else
    record "001.3" "stock NO propagado al TPV" FAIL "tpv.quantity=$STOCK_TPV (esperado 42)"
fi

# cleanup
call "action=delete_product&post_id=$PID" >/dev/null

# ═══════════════════════════════════════════════════════════════════════════
# BUG-WC-003 — Idempotency webhook NO atómica (race).
# ═══════════════════════════════════════════════════════════════════════════
echo ""
echo "── BUG-WC-003: race idempotency ──"

# 003.1 — 10 webhooks paralelos con mismo idem_key → DEBE procesar 1, duplicar 9
call "action=clear_webhook_idem" >/dev/null
IDEM="bug003-$(date +%s)-$$"
TS="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
BODY="{\"event_type\":\"stock.adjusted\",\"resource_id\":999000,\"changed_fields\":{},\"idempotency_key\":\"__IDEM__\",\"timestamp\":\"$TS\"}"

PIDS=()
TMP=$(mktemp -d)
for i in 1 2 3 4 5 6 7 8 9 10; do
    (send_webhook_raw "$BODY" "$IDEM" >"$TMP/r_$i" 2>&1) &
    PIDS+=($!)
done
wait

NEW=0; DUP=0
for i in 1 2 3 4 5 6 7 8 9 10; do
    if grep -q '"duplicate":true' "$TMP/r_$i" 2>/dev/null; then DUP=$((DUP+1))
    elif grep -q '"ok":true' "$TMP/r_$i" 2>/dev/null; then NEW=$((NEW+1)); fi
done
rm -rf "$TMP"

if [ "$NEW" -eq 1 ] && [ "$DUP" -eq 9 ]; then
    record "003.1" "10 concurrentes: 1 nuevo, 9 dup" PASS
else
    record "003.1" "BUG-WC-003: race idempotency" FAIL "new=$NEW dup=$DUP (esperado 1/9)"
fi

# 003.2 — solo 1 entrada marca persistida
MARK=$(psql_n "SELECT COUNT(*) FROM wp_options WHERE option_name = '_transient_tpv_sync_idem_$IDEM'")
MARK2=$(psql_n "SELECT COUNT(*) FROM wp_tpv_sync_webhook_idem WHERE idempotency_key = '$IDEM'" 2>/dev/null)
TOTAL=$((MARK + ${MARK2:-0}))
if [ "$TOTAL" -ge 1 ]; then record "003.2" "marca de idempotencia persistida ($TOTAL)" PASS
else record "003.2" "sin marca idem" FAIL "t=0"; fi

# 003.3 — 2ª tanda distinta: solo 1 procesa "nuevo"
call "action=clear_webhook_idem" >/dev/null
IDEM2="bug003b-$(date +%s%N)-$$"
NEW2=0; DUP2=0
TMP=$(mktemp -d)
for i in 1 2 3 4 5; do
    (send_webhook_raw "$BODY" "$IDEM2" >"$TMP/rb_$i" 2>&1) &
done
wait
for i in 1 2 3 4 5; do
    if grep -q '"duplicate":true' "$TMP/rb_$i" 2>/dev/null; then DUP2=$((DUP2+1))
    elif grep -q '"ok":true' "$TMP/rb_$i" 2>/dev/null; then NEW2=$((NEW2+1)); fi
done
rm -rf "$TMP"
if [ "$NEW2" -eq 1 ] && [ "$DUP2" -eq 4 ]; then
    record "003.3" "5 concurrentes 2ª tanda: 1/4" PASS
else
    record "003.3" "5 concurrentes: $NEW2/$DUP2 (esperado 1/4)" FAIL "race"
fi

echo ""
echo "================================================"
printf "RESULTADO: \e[32m%d PASS\e[0m / \e[31m%d FAIL\e[0m\n" "$PASS" "$FAIL"
echo "================================================"
exit $FAIL
