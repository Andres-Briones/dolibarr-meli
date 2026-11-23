#!/usr/bin/env bash
# The setup screens in a real browser against the fake Mercado Libre: application saved (secret
# encrypted, never shown back), account connected through the real OAuth redirect, listings read,
# a listing linked by hand, another ignored, the other tabs shown.
#
# Usage: DOLI_SRC=/path/to/dolibarr RUN=/path/to/runtime [HTTP_PORT=…] dolibarr-meli/test/http/setup.sh
#   The module must be linked in htdocs/custom of that instance, enabled, and its web server running.
#   SHOTS=<dir>  where the screenshots go (default: a temporary directory).
# Leaves nothing behind: settings are put back as found, the account and fixtures removed. Refuses
# to run when an account is connected on the instance.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
MODULE="$(cd "$HERE/../.." && pwd -P)"
DEV="${DEV:-$(dirname "$MODULE")/dev}"
# shellcheck disable=SC1091
. "$DEV/env.sh"
export DOLI_SRC RUN DEV

WORK="$(mktemp -d)"
SHOTS="${SHOTS:-$WORK/shots}"
mkdir -p "$SHOTS"
FAKE_PID=""
PORT=""
CHECKS=0
BASE="http://127.0.0.1:${HTTP_PORT:-8080}"

helper() { "${PHP_CLI[@]}" "$HERE/helper.php" "$@"; }
ok() { CHECKS=$((CHECKS + 1)); printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1" >&2; exit 1; }
cleanup() {
	helper teardown "$WORK" || printf 'FAIL teardown left records behind (above)\n' >&2
	[ -z "$FAKE_PID" ] || kill "$FAKE_PID" 2>/dev/null || true
	# php -S leaves its workers running when the master is killed; match on the port only.
	[ -z "$PORT" ] || for pid in $(ps -eo pid,args | awk -v p="127.0.0.1:$PORT" '$2 ~ /php$/ && index($0, p) {print $1}'); do kill "$pid" 2>/dev/null || true; done
	rm -rf "$WORK"
}
trap cleanup EXIT

# --- Fake Mercado Libre --------------------------------------------------------
PORT="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); print (int) substr(strrchr((string) stream_socket_get_name($s, false), ":"), 1);')"
FAKE="http://127.0.0.1:$PORT"
MELI_FAKE_STATE="$WORK/fake.json" PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" "$MODULE/test/fake/router.php" >"$WORK/fake.log" 2>&1 &
FAKE_PID=$!
for _ in $(seq 50); do curl -s -o /dev/null "$FAKE/__test/state" && break; sleep 0.1; done
SETUP="$(helper setup "$FAKE" "$WORK")" || fail "settings saved and fixtures created"
REDIRECT="$(jq -r .redirect <<<"$SETUP")"
REF1="$(jq -r '.refs[0]' <<<"$SETUP")"; REF2="$(jq -r '.refs[1]' <<<"$SETUP")"; REF3="$(jq -r '.refs[2]' <<<"$SETUP")"
curl -sf -X POST -d "{\"redirect_uri\":\"$REDIRECT\"}" "$FAKE/__test/config" >/dev/null || fail "fake configured"
ITEM1="$(curl -sf -X POST -d "{\"model\":\"up\",\"sku\":\"$REF1\",\"quantity\":3}" "$FAKE/__test/item" | jq -r .id)"
ITEM2="$(curl -sf -X POST -d "{\"model\":\"up\",\"sku\":\"$REF2\",\"quantity\":1}" "$FAKE/__test/item" | jq -r .id)"
ITEM3="$(curl -sf -X POST -d '{"model":"up","sku":"","quantity":1}' "$FAKE/__test/item" | jq -r .id)"
printf '%s\n' "$ITEM1" "$ITEM2" "$ITEM3" >"$WORK/items"
ok "fake Mercado Libre on $FAKE with 3 listings (2 with the SKU of a product, 1 without SKU)"

# --- Browser -------------------------------------------------------------------
A="$BASE/custom/meli/admin"
cat >"$WORK/steps.json" <<JSON
[{"goto":"$BASE/index.php"},{"type":["#username","${DOLI_ADMIN:-admin}"]},{"type":["#password","${DOLI_ADMIN_PASS:-admin}"]},{"click":"input[type=submit]"},
{"goto":"$A/setup.php"},{"expect":"!!document.querySelector('#meli_fake_banner') && !!document.querySelector('#meli_connect')"},
{"type":["#meli_settings input[name=MELI_APP_ID]","1234567890123456"]},{"type":["#meli_settings input[name=MELI_APP_SECRET]","fake-client-secret"]},
{"click":"#meli_settings input[type=submit]"},
{"expect":"document.querySelector('#meli_settings input[name=MELI_APP_ID]').value === '1234567890123456' && document.querySelector('#meli_settings input[name=MELI_APP_SECRET]').value === '' && document.documentElement.outerHTML.indexOf('fake-client-secret') < 0"},
{"click":"#meli_connect"},
{"expect":"location.pathname.endsWith('/admin/setup.php') && document.querySelector('#meli_status').innerText.indexOf('FAKESELLER') >= 0"},
{"shot":"$SHOTS/setup.png"},
{"click":"#meli_readlistings"},
{"goto":"$A/listings.php"},
{"expect":"document.querySelector('tr[data-item=\"$ITEM1\"] input[name=product_ref]').value === '$REF1'"},
{"expect":"document.querySelector('tr[data-item=\"$ITEM3\"] input[name=product_ref]').value === '' && document.querySelector('tr[data-item=\"$ITEM3\"] .warning') !== null"},
{"type":["tr[data-item=\"$ITEM2\"] input[name=product_ref]","$REF3"]},{"click":"tr[data-item=\"$ITEM2\"] input[type=submit]"},
{"expect":"document.querySelector('tr[data-item=\"$ITEM2\"] input[name=product_ref]').value === '$REF3' && document.querySelector('tr[data-item=\"$ITEM2\"] .fa-hand-paper') !== null"},
{"type":["tr[data-item=\"$ITEM1\"] input[name=buffer]","1"]},{"click":"tr[data-item=\"$ITEM1\"] input[type=submit]"},
{"expect":"document.querySelector('tr[data-item=\"$ITEM1\"] input[name=buffer]').value === '1'"},
{"click":"tr[data-item=\"$ITEM3\"] a[href*=\"action=ignore\"]"},
{"expect":"document.querySelector('tr[data-item=\"$ITEM3\"]') === null"},
{"shot":"$SHOTS/listings.png"},
{"goto":"$A/listings.php?filter=ignored"},{"expect":"document.querySelector('tr[data-item=\"$ITEM3\"]') !== null"},
{"goto":"$A/guide.php"},{"expect":"document.querySelectorAll('table.noborder tr.oddeven').length === 8"},{"shot":"$SHOTS/guide.png"},
{"goto":"$A/sales.php"},{"expect":"!!document.querySelector('#meli_sales')"}]
JSON
node "$DEV/tools/browser.mjs" "$WORK/steps.json" 1280 >"$WORK/browser.log" 2>&1 || { tail -5 "$WORK/browser.log" >&2; fail "browser walk through the setup tabs (log above)"; }
ok "application saved; the secret is not shown back in the page"
ok "connected through the OAuth redirect: status shows FAKESELLER"
ok "listings read: the SKU links the product; the listing without SKU shows why"
ok "a listing linked by hand to another product, marked as such"
ok "a listing's own buffer saved"
ok "a listing ignored: gone from the active list, shown under Ignored"
ok "first steps and sales tabs shown"

[ "$(helper secret)" = "encrypted" ] || fail "the stored secret is encrypted"
ok "the stored secret is encrypted"

LOG="$(curl -sf "$FAKE/__test/state" | jq -r '[.log[] | .method + " " + .path] | join(",")')"
case "$LOG" in *"PUT "*|*"POST /items"*) fail "nothing written to Mercado Libre while read-only ($LOG)";; esac
grep -q "GET /authorization" <<<"$LOG" && grep -q "POST /oauth/token" <<<"$LOG" || fail "authorisation went through the fake ($LOG)"
ok "only reads at the fake: $LOG"

printf '%s checks passed (screenshots in %s)\n' "$CHECKS" "$SHOTS"
