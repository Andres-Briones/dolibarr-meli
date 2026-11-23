# Fake Mercado Libre Argentina API for the tests

`router.php` is a router for PHP's built-in server. It plays the parts of the Mercado Libre API
the `meli` module uses: OAuth, users, items, stock, orders, packs, billing info, shipments,
labels and notifications. It never talks to Mercado Libre and needs no real credential: every id,
token and secret in it is made up.

**There is no OpenAPI or WSDL to validate against.** Mercado Libre publishes no machine-readable
contract. The shapes here follow the documented examples as summarised in
`.planning/research/mercado-libre.md` (cited R§n below), plus guesses listed under
[Assumptions](#assumptions-not-verified-against-mercado-libre). Passing against this fake shows
that the module's logic and failure handling work. It does **not** show that Mercado Libre
answers the same way: only the real app with a test user can show that.

## Start

```sh
MELI_FAKE_STATE=/tmp/melifake.json PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8124 test/fake/router.php
```

One router serves both base URLs. Set the module's `MELI_API_URL` and `MELI_AUTH_URL` to
`http://127.0.0.1:8124`.

The state is a JSON file. Each request reads it, changes it and writes it back under `flock`, so
concurrent requests are serialised and no update is lost (two refreshes at the same time: one
wins). The lock is released **before** any injected delay and **before** notifications are
POSTed to the webhook, so a sleeping request never blocks the others. Use at least 2 workers
(4 is better): each delay, and each slow webhook call, holds one.

From PHP tests, use `FakeClient.php`:

- `MeliFakeClient::start()` / `stop()`: free port, temporary state file, workers killed on stop.
- HTTP helpers: `request()`, `requestMany()` (curl_multi), `api()` (Bearer), `decode()`,
  `decodeList()`. Responses carry `status`, lower-case `headers`, `body`, `errno`, `seconds`.
- OAuth: `pkce()`, `authorize()`, `redirectParams()`, `token()`, `codeForm()`,
  `refreshForm()`, `connect()` (whole code flow with PKCE), `refresh()`.
- Control: `state()`, `log()`, `reset()`, `config()`, `seedItem()`, `setStock()`,
  `seedOrder()`, `setShipment()`, `cancelOrder()`, `flagFraud()`, `changeItemId()`,
  `revoke()`, `notify()`, `advanceTime()`, `inject()`.
- `MeliFakeClient::launch()` / `terminate()` start and stop any other `php -S` router.
- `MeliFakeWebhook` (`webhook.php`) is a stand-in for the module's webhook: `/ok` answers 200,
  `/fail` 500, `/slow?ms=700` answers 200 late; `received()` lists what it got.

`FakeSelfTest.php` shows each of them in use:

```sh
cd /workspace/dev/tools && vendor/bin/phpunit -c phpunit.xml /workspace/dolibarr-meli/test/fake/FakeSelfTest.php
```

## Control endpoints (`/__test/`, JSON)

| Endpoint | Body | Effect |
|---|---|---|
| `GET /__test/state` | | Whole state, with `config` (defaults filled in), fake `now`, every table, `injections`, `notifications`, `log`. |
| `POST /__test/reset` | `{"keep_config": true}` optional | Forget everything (or everything except the config). |
| `POST /__test/config` | keys below | Merge into the config. `user` is merged key by key. Unknown keys: 400. |
| `POST /__test/item` | `{"model":"up","sku":"MATE-01","family_name":"Mate","price":15000,"quantity":3}` or `{"model":"legacy","title":"Poncho","variations":[{"sku":"PON-R","quantity":2,"attribute_combinations":[...]}]}` | Create a listing of the configured seller. `up` items get a user product (`user_product_id`, `family_name`, `SELLER_SKU` on the item, `variations: []`); pass an existing `user_product_id` to add a second item to the same UP. `legacy` items get `variations[]` with `id`, `attribute_combinations`, `available_quantity` and `SELLER_SKU` per variation. Also `id`, `status`, `category_id`, `tags`. Returns the item. |
| `POST /__test/stock` | `{"user_product_id":"MLAU…","quantity":7,"type":"selling_address"}` or `{"item_id":"MLA…","variation_id":…,"quantity":1}` | Stock changed on ML's side. A UP change bumps its `x-version` and is propagated to its items. |
| `POST /__test/order` | see below | A sale. One order per line, a pack when there are several lines. Returns `{pack_id, order_ids, shipment_id, billing_info_id, buyer_id}`. |
| `POST /__test/shipment` | `{"id":…,"status":"shipped","substatus":null,"logistic_type":"fulfillment","notify":true}` | Change a shipment. |
| `POST /__test/cancel_order` | `{"order_id":…,"requested_by":"buyer","code":"…","notify":true}` | Order `cancelled`, `cancel_detail` filled, payments `refunded`, `paid` tag removed, `date_last_updated` now. |
| `POST /__test/fraud` | `{"order_id":…,"notify":true}` | Tag `fraud_risk_detected` (and `fraud_risk_detected: true`), `date_last_updated` now. |
| `POST /__test/change_item_id` | `{"item_id":"MLA…","new_ids":["MLA…"]}` | UPtin migration: the item is closed; a new UP item with the same SKU is created (one per variation for a legacy item), tagged `variations_migration_uptin`; an `items` notification is sent for the old and each new id. |
| `POST /__test/revoke` | | Revoke every grant: their access and refresh tokens stop working. |
| `POST /__test/notify` | `{"topic":"orders_v2","resource":"/orders/2000000000000001","duplicates":2,"delay_ms":0,"attempts":1}` | POST the notification to `webhook_url` (1 + `duplicates` identical copies), time each answer. Not 200, or slower than `webhook_max_ms` → kept for `/missed_feeds`. Answers `{status, duration_ms, missed, notifications:[…]}` (first delivery at top level). |
| `POST /__test/advance_time` | `{"seconds":21601}` | Move the fake clock (token expiry, `missed_feeds` retention, dates of new resources). |
| `POST /__test/inject` | `{"mode":"http_429","method":"PUT","path":"/items/","retry_after":2}` or `{"injections":[…]}` | Queue one-shot faults (below). |

Control calls that send notifications (`notify`, `change_item_id`, and the others with
`"notify": true`) add `notifications`, `status`, `duration_ms` and `missed` to their answer.

`/__test/order` body:

```json
{
  "items": [{"item_id": "MLA1000000001", "variation_id": null, "quantity": 2, "unit_price": 15000.0, "sale_fee": 1950.0}],
  "status": "paid",
  "pack": null,
  "buyer": {"id": 300000001, "nickname": "COMPRADOR1"},
  "billing": {"name": "Juana", "last_name": "Prueba", "doc_type": "DNI", "doc_number": "30111222",
              "taxpayer_type": "Consumidor Final", "address": {"street_name": "…", "city_name": "…"}},
  "shipment": {"status": "ready_to_ship", "substatus": "ready_to_print", "logistic_type": "drop_off",
               "receiver_cost": 3500.0, "sender_cost": 1200.0, "address": {"street_name": "…"}},
  "decrement_stock": true,
  "notify": false,
  "date_created": "2026-11-23T10:00:00.000-03:00"
}
```

Everything but `items` is optional. `pack` defaults to "several lines"; `"shipment": false` sells
without shipment (`shipping.id` null); `"billing": false` without billing info. A `paid` sale
decrements the listing's stock (the UP's `selling_address` for UP items, which bumps
`x-version`).

Config keys and defaults: `client_id` (`1234567890123456`), `client_secret`
(`fake-client-secret`), `app_id` (`1234567890123456`), `redirect_uri`
(`https://shop.example.test/custom/meli/public/oauth.php`), `user`
(`{"id":123456789,"nickname":"FAKESELLER","tags":["normal","user_product_seller"]}`),
`expires_in` (21600), `refresh_ttl` (15552000 = 6 months), `code_ttl` (600), `pkce_required`
(true), `authorize_outcome` (`allow` or `deny`), `revoke_on_reuse` (true), `webhook_url` (`""`),
`webhook_max_ms` (500), `webhook_timeout_ms` (5000), `missed_feeds_retention` (172800 = 2 days),
`test_users_max` (10).

```sh
curl -s -X POST -d '{"webhook_url":"http://127.0.0.1:8080/custom/meli/public/webhook.php","expires_in":60}' http://127.0.0.1:8124/__test/config
curl -s -X POST -d '{"model":"up","sku":"MATE-01","quantity":3}' http://127.0.0.1:8124/__test/item
curl -s -X POST -d '{"mode":"http_429","path":"/items/","empty_body":true}' http://127.0.0.1:8124/__test/inject
```

The request log (`state.log`) has one entry per API request: fake time, method, path (no query
string), `auth_header` (whether an Authorization header was sent), status and injected mode.
It never holds a token. The tables in `state` do hold the fake tokens.

### Injection modes

Each injection is used by the next request whose method matches `method` (default `*`) and whose
path starts with `path` (default `*`). Control calls are never matched.

| Mode | Processed? | What the client sees |
|---|---|---|
| `http_429` `{retry_after?, empty_body?}` | no | 429, `Retry-After` when given, JSON error body or an **empty** body (R§1.3: seen in the wild). |
| `http_500` | no | 500 `{"error":"internal_server_error",…}`. |
| `http_503` | no | 503 `{"error":"service_unavailable",…}`. |
| `timeout_before` `{delay_ms}` (2000) | no | Waits, then 504. A client with a shorter timeout sees only its timeout (curl 28). |
| `timeout_after` `{delay_ms}` (2000) | yes | Waits, then the normal answer; a client with a shorter timeout has lost it. |
| `drop_after` | yes | Headers announcing a longer body than sent; curl reports error 18. |
| `forbidden_policies` | no | 403 `{"message":"At least one policy returned UNAUTHORIZED.","error":"PA_UNAUTHORIZED_RESULT_FROM_POLICIES",…}` (R§1.2, missing functional permission). |
| `warnings` `{warnings?}` | yes | The normal answer, plus a `warnings` array when it is a 2xx JSON object (R§3.2). |

Scenarios that are control calls rather than injections: token revoked mid-run (`revoke`),
UPtin id change (`change_item_id`), cancellation or fraud after import (`cancel_order`,
`fraud`), stock changed on ML's side (`stock`).

## Behaviours and where they come from

| Behaviour | Source |
|---|---|
| `GET /authorization?response_type=code&client_id&redirect_uri&state&code_challenge&code_challenge_method=S256` → 302 to `redirect_uri?code=TG-…&state=…`; `deny` → `error=access_denied`; no challenge while PKCE is required → `error=invalid_request`; unknown `client_id` or a `redirect_uri` that is not exactly the registered one → 400 page, no redirect | R§1.2 |
| `POST /oauth/token`, form-encoded body only (parameters in the query string, or a non-form body → 400 `invalid_request`); `client_id`/`client_secret` checked (`invalid_client`) | R§1.2 |
| `authorization_code`: code single use and valid `code_ttl`; `redirect_uri` must equal the one of the authorize call; `code_verifier` checked against the S256 (or plain) challenge; errors 400 `invalid_grant` | R§1.2 |
| `refresh_token`: single use, each refresh returns a new access + refresh pair; reusing an old one → 400 `invalid_grant` and, with `revoke_on_reuse`, the whole grant is revoked (the latest refresh token and the access tokens stop working) | R§1.2 (single use); revocation is the worst case chosen by the plan |
| Answer `{access_token, token_type: Bearer, expires_in, scope: "offline_access read write", user_id, refresh_token}`; access token expires after `expires_in` of fake time | R§1.2 |
| Every other endpoint needs `Authorization: Bearer`; missing, unknown, expired or revoked → 401 (`{"message":"invalid_token","error":"not_found","status":401,"cause":[]}` for a bad token); `access_token` in the query string → 401 | R§1.2 |
| `GET /users/me`, `/users/{id}` (tags from `config.user.tags`), `POST /users/test_user` `{"site_id":"MLA"}` → 201 `{id, nickname, password, site_status, email}`, at most `test_users_max` | R§2.4, R§8 |
| `GET /users/{id}/items/search?status=active,paused&seller_sku&offset&limit` (only the caller's own id; limit ≤ 100; offset + limit ≤ 1000, beyond that `search_type=scan` with `scroll_id`) | R§1.3, R§2.1 |
| `GET /items/bulk?ids=` ≤ 20 → `[{code, body}]`; the old `GET /items?ids=` → 400 `deprecated` | R§1.3 |
| UP item shape (`user_product_id`, `family_name`, `SELLER_SKU` on the item, no variations) and legacy shape (`variations[]` with `id`, `attribute_combinations`, `available_quantity`, `SELLER_SKU`) | R§2.1, R§2.4 |
| `PUT /items/{id}`: `available_quantity` (item) or `variations[{id, available_quantity}]` (legacy); `variations` on a UP item → 400 "The field variations is invalid with family name"; `status` active/paused/closed; `price`; closed items cannot be changed; returns the updated item | R§2.4, R§2.8, R§3.1 |
| Quantity 0 pauses an active item with `sub_status: ["out_of_stock"]`; quantity > 0 reactivates only an item paused that way | R§2.3, R§3.1 |
| `PUT /items` on a UP item writes the UP's `selling_address` (version + 1) and every item of that UP shows it | R§3.1 ("ML propagates the stock to all items of the same user_product_id") |
| `GET /user-products/{id}/stock` → `{locations:[{type, quantity}], user_id, id}` + header `x-version`; `PUT …/stock/type/selling_address` `{"quantity":n}` needs `x-version` (missing → 400, stale → 409), bumps it and returns the new one | R§3.1 |
| `GET /orders/{id}`, order fields: `status`, `pack_id` (null for a single sale), `order_items[{item{id, variation_id, seller_sku}, quantity, unit_price, sale_fee}]`, `total_amount`, `paid_amount`, `payments[]`, `shipping.id`, `buyer{id, nickname, billing_info{id}}`, `tags`, `cancel_detail`, `date_created`, `date_last_updated` | R§4.1 |
| `GET /packs/{id}` → `{id, orders:[{id}], shipment:{id}, buyer, …}`; for an order without pack, `/packs/{order_id}` gives a pack of that one order | R§4.1 |
| `GET /orders/search?seller=&order.date_last_updated.from=&order.date_last_updated.to=&order.status=&offset=&limit=` (seller must be the caller, limit ≤ 51), sorted by `date_last_updated` | R§4.1 |
| `GET /orders/billing-info/MLA/{billing_info_id}` → `buyer.billing_info` with `name`, `last_name`, `identification{type, number}`, `taxes.taxpayer_type.description`, `address` | R§4.3 |
| Cancellation: `status: cancelled`, `cancel_detail{group, code, description, requested_by, date}`, payments refunded | R§4.5 |
| Fraud flag after the sale changes the order and its `date_last_updated` | R§4.1 |
| `GET /shipments/{id}` and `/costs` need `x-format-new: true` (else 400); shipment has `status`, `substatus`, `logistic{mode, type}`, `tracking_number`, `origin`, `destination{receiver_id, shipping_address}`; the address is null until every order of the shipment is paid; costs `{receiver{cost}, senders[{cost}]}` | R§1.3, R§4.3, R§5.2 |
| `GET /shipment_labels?shipment_ids=a,b&response_type=pdf\|zpl2`: ≤ 50 ids; every shipment must be `ready_to_ship` with `ready_to_print` or `printed`, not `fulfillment`; otherwise 400 with one cause per shipment and nothing printed; returns a small valid PDF (one page per shipment) and marks `ready_to_print` as `printed` | R§5.2 |
| Notification body `{_id, resource, user_id, topic, application_id, attempts, sent, received}`; not answered 200 within 500 ms → missed | R§4.2 |
| `GET /missed_feeds?app_id=&topic=&offset=&limit=` → `{messages, total, offset, limit}`, missed notifications of the last 2 days of fake time | R§4.2 |

## Assumptions (not verified against Mercado Libre)

- Error bodies use `{message, error, status, cause}` everywhere except the token endpoint, which
  uses `{error_description, error, status, cause}`. Error codes other than `invalid_grant`,
  `invalid_token`/`not_found`, `PA_UNAUTHORIZED_RESULT_FROM_POLICIES` and `deprecated` are made
  up (`bad_request`, `validation_error`, `conflict`, `forbidden`, …). The module must branch on
  the HTTP status, not on those codes.
- `invalid_client` is answered with 400 (not 401). A wrong `client_secret` is checked before the
  grant.
- A code is burnt by any exchange attempt, even one refused for a bad `code_verifier` or
  `redirect_uri`. Reusing a code does not revoke the tokens issued from it (RFC 6749 says it
  should; ML's behaviour is unknown).
- `revoke_on_reuse` (on by default) is the plan's worst case, not documented behaviour. Old
  access tokens stay valid until they expire after a successful refresh.
- The 4-month idle revocation and password-change revocation are not modelled (use `revoke`).
  Refresh tokens expire after `refresh_ttl`.
- Missing `Authorization` → 401 `{"error":"unauthorized","message":"authorization value not present"}`.
  The token in the query string → 401 even when the header is also sent.
- Scopes and functional permissions are not checked; `forbidden_policies` stands in for them.
- Items search: default statuses (when `status` is not given) are everything but `closed`. The
  scan `scroll_id` is opaque and the last page is an empty `results`. `paging` of a scan has no
  `offset`.
- UP stock: the item's `available_quantity` is the sum of the UP's locations. `PUT /items` writes
  only `selling_address`. Only `selling_address` can be written through
  `/stock/type/…` (`seller_warehouse` → 400). The `PUT` stock answer is the same shape as the
  `GET`.
- Pausing by `status: paused` clears `sub_status` and is never undone by a quantity change. A
  `status: active` with quantity 0 ends up `paused`/`out_of_stock`. `PUT /items` ignores fields
  other than `available_quantity`, `variations`, `status` and `price`.
- `sale_fee` is per unit and defaults to 13 % of the unit price. `paid_amount` includes the
  buyer's shipping cost on the first order of a pack only (R§9.2 cites a meli_oerp issue
  where a cart's `paid_amount` ignored shipping, so do not rely on it). Payments are one approved card payment per order.
- Pack `status` is `released`, or `cancelled` when all its orders are. The module should rely on
  the orders' statuses.
- A cancellation does not give the stock back to the listing.
- Fraud is shown as the tag `fraud_risk_detected` (plus `fraud_risk_detected: true`); the real
  marker was not seen.
- The billing-info answer nests the data under `buyer.billing_info` (`site_id`, `buyer.cust_id`,
  `seller.cust_id` around it); the `x-version: 2` header that meli_oerp sends is neither needed
  nor checked. `billing_info.id` is a string of digits.
- Shipments belong to the seller of the order. Labels of a shipment that is not the caller's are
  reported as `NOT_FOUND`. The cause codes `NOT_PRINTABLE_STATUS`,
  `NOT_PRINTABLE_LOGISTIC_TYPE` and `NOT_FOUND` are made up. `buffered` is not modelled.
  `response_type` defaults to `pdf`; `zpl2` returns one `^XA…^XZ` block per shipment.
- `x-format-new: true` is required on `/shipments/{id}` and `/costs` but not on
  `/shipment_labels`.
- Notifications: duplicates are identical copies (same `_id`, same `attempts`). The fake does not
  retry by itself; `attempts` is whatever the control call says. `sent` and `received` are the
  same UTC instant. The webhook is called with a timeout of `webhook_timeout_ms`; a delivery
  that times out is missed with status 0. No webhook configured → missed with status 0.
- `missed_feeds` with a foreign `app_id` → 403; `limit` ≤ 50, default 10; each message carries
  `request{url, headers}` and `response{req_time, http_code}`.
- Topics being switched off after repeated failures (R§4.2) is not modelled.
- Ids: items `MLA` + 10 digits, user products `MLAU` + 9 digits, orders and packs 16 digits,
  shipments 11 digits. All numbers are ints, prices and amounts floats.
- Dates of resources have the Argentina offset with milliseconds
  (`2026-11-23T10:00:00.000-03:00`); `orders/search` compares instants, so any ISO 8601 offset
  works in the filters.
