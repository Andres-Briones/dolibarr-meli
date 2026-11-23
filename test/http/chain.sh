#!/usr/bin/env bash
# Whole chain against test doubles: a sale on the fake Mercado Libre is notified over HTTP to this
# module's webhook, the scheduled jobs import it as an order, invoice it and record the payment,
# send the new quantity back; when the arca module is present, its job gets the Factura C from the
# fake ARCA for that invoice, with no code link between the two modules.
#
# Usage: DOLI_SRC=/path/to/dolibarr RUN=/path/to/runtime [HTTP_PORT=…] dolibarr-meli/test/http/chain.sh
#   The module must be linked in htdocs/custom of that instance, enabled, and its web server running.
#   ARCA_MODULE=<dir>  the arca module (default: ../dolibarr-arca next to this one); its part is skipped
#                      when it is not linked on the instance.
# Puts every MELI_ setting back as found and removes the account, links, fixtures and the run's
# order, invoice and payment. The arca part leaves what arca's own chain.sh leaves (module set as a
# monotributista with punto de venta 5 against nobody, sending started at the run).
# Refuses to run when a Mercado Libre account is connected or listing links exist on the instance.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
MODULE="$(cd "$HERE/../.." && pwd -P)"
DEV="${DEV:-$(dirname "$MODULE")/dev}"
ARCA_MODULE="${ARCA_MODULE:-$(dirname "$MODULE")/dolibarr-arca}"
# shellcheck disable=SC1091
. "$DEV/env.sh"
export DOLI_SRC RUN DEV

WORK="$(mktemp -d)"
PIDS=""
PORTS=""
ARCA=""
CHECKS=0
BASE="http://127.0.0.1:${HTTP_PORT:-8080}"

helper() { "${PHP_CLI[@]}" "$HERE/helper.php" "$@"; }
arca() { "${PHP_CLI[@]}" "$ARCA_MODULE/test/http/chain_helper.php" "$@"; }
ok() { CHECKS=$((CHECKS + 1)); printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1" >&2; exit 1; }
free_port() { php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); print (int) substr(strrchr((string) stream_socket_get_name($s, false), ":"), 1);'; }
cleanup() {
	helper teardown "$WORK" || printf 'FAIL teardown left records behind (above)\n' >&2
	[ -z "$ARCA" ] || arca teardown >/dev/null 2>&1 || printf 'FAIL arca teardown\n' >&2
	for pid in $PIDS; do kill "$pid" 2>/dev/null || true; done
	# php -S leaves its workers running when the master is killed; match on the port only.
	for port in $PORTS; do
		for pid in $(ps -eo pid,args | awk -v p="127.0.0.1:$port" '$2 ~ /php$/ && index($0, p) {print $1}'); do kill "$pid" 2>/dev/null || true; done
	done
	rm -rf "$WORK"
}
trap cleanup EXIT

# --- Fake Mercado Libre, module connected to it ----------------------------------------
PORT="$(free_port)"; PORTS="$PORT"
FAKE="http://127.0.0.1:$PORT"
MELI_FAKE_STATE="$WORK/fake.json" PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" "$MODULE/test/fake/router.php" >"$WORK/fake.log" 2>&1 &
PIDS="$!"
for _ in $(seq 50); do curl -s -o /dev/null "$FAKE/__test/state" && break; sleep 0.1; done
SETUP="$(helper setup "$FAKE" "$WORK")" || fail "settings saved and fixtures created"
REDIRECT="$(jq -r .redirect <<<"$SETUP")"
WEBHOOK="$BASE/custom/meli/public/webhook.php?entity=1"
curl -sf -X POST -d "{\"redirect_uri\":\"$REDIRECT\",\"webhook_url\":\"$WEBHOOK\"}" "$FAKE/__test/config" >/dev/null || fail "fake configured"
CHAIN="$(helper chain "$FAKE" "$WORK")" || fail "account connected through the fake's authorisation"
REF="$(jq -r .ref <<<"$CHAIN")"; TAG="$(jq -r .tag <<<"$CHAIN")"
DOCUMENT="$(jq -r .document <<<"$CHAIN")"; BUYER="$(jq -r .buyer <<<"$CHAIN")"
ok "fake Mercado Libre on $FAKE; account connected; product $REF with 5 in stock; webhook $WEBHOOK"

# --- Listing linked and stock sent by the jobs --------------------------------------------
ITEM="$(curl -sf -X POST -d "{\"model\":\"up\",\"sku\":\"$REF\",\"quantity\":1}" "$FAKE/__test/item")"
ITEM_ID="$(jq -r .id <<<"$ITEM")"; UP="$(jq -r .user_product_id <<<"$ITEM")"
printf '%s\n' "$ITEM_ID" >"$WORK/items"
OUT="$(helper cron nightly)" || fail "nightly job: $OUT"
grep -q "1 linked" <<<"$OUT" || fail "nightly job linked the listing: $OUT"
OUT="$(helper cron queue)" || fail "queue job: $OUT"
upqty() { curl -sf "$FAKE/__test/state" | jq -r --arg up "$UP" '.user_products[$up].locations[] | select(.type == "selling_address") | .quantity'; }
[ "$(upqty)" = 5 ] || fail "quantity on Mercado Libre after the push: $(upqty), expected 5"
ok "nightly job linked $ITEM_ID by SKU; queue job sent 5 to Mercado Libre ($OUT)"

# --- ARCA's side ready before the sale ----------------------------------------------------
if [ -f "$ARCA_MODULE/test/http/chain_helper.php" ] && [ -e "$DOLI_SRC/htdocs/custom/arca" ]; then
	APORT="$(free_port)"; PORTS="$PORTS $APORT"
	ARCA_FAKE_STATE="$WORK/arca.json" PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$APORT" "$ARCA_MODULE/test/fake/router.php" >"$WORK/arca.log" 2>&1 &
	PIDS="$PIDS $!"
	for _ in $(seq 50); do curl -s -o /dev/null "http://127.0.0.1:$APORT/__test/state" && break; sleep 0.1; done
	sleep 1
	arca setup "http://127.0.0.1:$APORT" "$WORK" >/dev/null || fail "arca set up against its fake"
	ARCA=1
	ok "arca module present: set up against the fake ARCA (monotributista, punto de venta 5)"
fi
BEFORE="$(arca maxinvoice 2>/dev/null || echo 0)"
sleep 1

# --- The sale, notified over HTTP -----------------------------------------------------
BODY="{\"items\":[{\"item_id\":\"$ITEM_ID\",\"quantity\":2,\"unit_price\":15000.0,\"sale_fee\":1950.0}],\"buyer\":{\"id\":$BUYER,\"nickname\":\"$TAG\"},"
BODY="$BODY\"billing\":{\"name\":\"$TAG\",\"last_name\":\"Cadena\",\"doc_type\":\"DNI\",\"doc_number\":\"$DOCUMENT\",\"taxpayer_type\":\"Consumidor Final\"},"
BODY="$BODY\"shipment\":{\"status\":\"ready_to_ship\",\"substatus\":\"ready_to_print\",\"receiver_cost\":3500.0},\"notify\":true}"
SALE="$(curl -sf -X POST -d "$BODY" "$FAKE/__test/order")" || fail "sale at the fake"
ORDER_ID="$(jq -r '.order_ids[0]' <<<"$SALE")"
printf '/orders/%s\n/shipments/%s\n' "$ORDER_ID" "$(jq -r .shipment_id <<<"$SALE")" >"$WORK/resources"
STATUS="$(jq -r .status <<<"$SALE")"; MS="$(jq -r .duration_ms <<<"$SALE")"; MISSED="$(jq -r .missed <<<"$SALE")"
[ "$STATUS" = 200 ] && [ "$MISSED" = false ] || fail "webhook answered $STATUS in ${MS} ms (missed: $MISSED)"
awk -v ms="$MS" 'BEGIN { exit !(ms < 500) }' || fail "webhook took $MS ms, Mercado Libre wants under 500"
[ "$(helper inbox)" -ge 1 ] || fail "notification stored in the inbox"
ok "sale $ORDER_ID notified: webhook answered 200 in $MS ms and only stored it"

# --- Imported, invoiced, paid; stock sent again ------------------------------------------
OUT="$(helper cron queue)" || fail "queue job: $OUT"
ROW="$(helper sale "$ORDER_ID")"
[ "$ROW" != null ] || fail "sale imported ($OUT)"
jq -e '.order_status == "1" and .module_source == "meli" and .invoice_status == "2"' <<<"$ROW" >/dev/null || fail "order validated, invoice paid: $ROW"
jq -e '(.total_ttc | tonumber) == 33500 and (.paid_meli | tonumber) == 33500' <<<"$ROW" >/dev/null || fail "33500 invoiced and paid with Mercado Libre: $ROW"
INVOICE_REF="$(jq -r .invoice_ref <<<"$ROW")"
ok "queue job imported it ($OUT): order validated, invoice $INVOICE_REF paid 33500 (2 x 15000 + 3500 shipping) with 'Mercado Libre'"
OUT="$(helper cron queue)" || fail "queue job: $OUT"
[ "$(upqty)" = 3 ] || fail "quantity on Mercado Libre after the sale: $(upqty), expected 3"
ok "quantity on Mercado Libre after the sale: 3 ($OUT)"

# --- Safety nets: nothing new, nothing doubled -------------------------------------------
OUT="$(helper cron polling)" || fail "polling job: $OUT"
OUT2="$(helper cron queue)" || fail "queue job: $OUT2"
[ "$(helper sale "$ORDER_ID" | jq -r .fk_facture)" = "$(jq -r .fk_facture <<<"$ROW")" ] || fail "same invoice after polling"
ok "polling job ($OUT) then queue job ($OUT2): the sale found again is only updated"

# --- ARCA ---------------------------------------------------------------------------------
if [ -n "$ARCA" ]; then
	OUT="$(arca process)" || fail "arca job: $OUT"
	LINE="$(arca vouchers "$BEFORE" | awk -v ref="$INVOICE_REF" '$2 == ref')"
	read -r _ ref source status type pos number cae _ <<<"$LINE"
	[ "$source" = meli ] && [ "$status" = authorised ] && [ "$type" = 11 ] && [ "$pos" = 5 ] && [[ "$cae" =~ ^[0-9]{14}$ ]] || fail "arca voucher of $INVOICE_REF: $LINE"
	ok "arca job ($OUT): $ref from meli is Factura C 00005-$(printf '%08d' "$number"), CAE $cae"
else
	printf 'skip arca not linked on this instance: no Factura C checked\n'
fi

printf '\n%d checks passed against %s\n' "$CHECKS" "$BASE"
