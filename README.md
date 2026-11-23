# Meli — Mercado Libre for Dolibarr

Links the listings of a Mercado Libre seller account to Dolibarr products:
Dolibarr's stock is offered on Mercado Libre, sales paid there become
Dolibarr orders (invoiced and paid by default), and shipments and labels
are followed from Dolibarr.

Status: version 0.1.0, in development. **It has only been run against a
test double of Mercado Libre's API, never against Mercado Libre itself.**
Do not connect it to a real account yet.

## Requirements

- Dolibarr 22 to 24, PHP 8.1 or later with the `curl` extension.
- Modules Products, Orders and Third parties; Stock, Invoices and Banks for
  stock and invoicing.
- A Mercado Libre account in Argentina (site MLA) and an application created
  for it at developers.mercadolibre.com.ar.
- Optional: `arca`, which then asks ARCA for the CAE of the invoices of
  Mercado Libre sales like any other; `l10nar`, which receives the buyer's
  "condición frente al IVA".

## How it works

1. **Links.** Each listing that sells one physical product — an item, or a
   variation of an older item — is linked to the product whose ref is its
   SKU (`SELLER_SKU`). A listing without a SKU, or whose SKU matches no
   product, several products or a product with variants, stays unlinked and
   is shown with the reason. A link can be set by hand.
2. **Read only until switched on.** Until "Enviar el stock a Mercado Libre"
   is set, the module changes nothing on Mercado Libre.
3. **Stock.** Dolibarr holds the only real stock. The quantity offered is the
   stock of the chosen warehouses minus what validated orders still hold
   (the same rule as the `tienda` module's online shop), minus a buffer, up
   to a cap. Stock movements and order changes put the product in an outbox;
   a job sends it within a minute, never inside the transaction of the sale
   that caused it. Quantities of items in Mercado Libre's "User Products"
   model are written with its version header, so a value computed before a
   sale there cannot overwrite it. Every night, everything is sent again.
4. **Sales.** Notifications are stored as received and answered at once; a
   job reads the sale again from Mercado Libre with the account's token
   (the body is never trusted), and an hourly job catches up what was not
   notified. Only fully paid sales are imported: one Dolibarr order per pack,
   validated at import, in a transaction that locks the product rows first,
   like the shop's checkout. When another channel took the last unit first,
   the sale is still imported — the buyer has paid — and flagged "vendida sin
   stock". When Dolibarr refuses orders without stock
   (`STOCK_MUST_BE_ENOUGH_FOR_ORDER`), such a sale stays in draft for a
   person.
5. **Invoices.** By default (`MELI_INVOICE_ORDERS`), the order is invoiced,
   the invoice validated and paid with the amount the buyer paid, shipping
   included, with the payment mode "Mercado Libre" on the chosen bank
   account. Nothing is sent to Mercado Libre or ARCA in that transaction.
6. **Cancellations.** A sale cancelled on Mercado Libre before it is
   invoiced cancels its order, which returns the stock. After invoicing, it
   is flagged for a person: a credit note is a person's decision, and an
   invoice is never deleted nor set back to draft.
7. **Shipments.** Mercado Libre manages them; the sale shows the shipment
   status, and the label is downloaded as Mercado Libre gives it into the
   order's documents.

## Install

1. Copy or symlink this directory into your Dolibarr tree as
   `htdocs/custom/meli` and enable the module. It creates the payment mode
   "Mercado Libre" (customers only), a third-party field for the buyer's
   Mercado Libre id and three scheduled jobs. Disabling the module keeps
   them.
2. Follow the "Primeros pasos" tab of the setup: in Spanish, step by step,
   with the state of each step.

## Decisions to make per product

- **Buffer.** With a buffer of 0, the last unit of a piece is offered on every
  channel at once; if it sells twice in the same minute, one sale is
  oversold and must be cancelled by hand (Mercado Libre counts seller
  cancellations against the reputation). A buffer of 1 keeps the last unit
  off Mercado Libre. For unique pieces sold in several places, choose per
  product; the default is 0.
- **Invoicing.** Turn `MELI_INVOICE_ORDERS` off only if invoices are issued
  with Mercado Libre's own Facturador. The `arca` module then stops sending
  the invoices of these orders by itself (it reads this setting), so that no
  sale is reported twice at ARCA.

## Personal data

The module stores what it needs to invoice and ship:

| Where | What | How long |
|---|---|---|
| Third parties (`llx_societe`) | Buyer's name, document number, fiscal condition and billing address, from Mercado Libre's billing information; the buyer's Mercado Libre id | Like any customer of Dolibarr |
| Contacts (`llx_socpeople`) | Name and delivery address of the shipment | Like any contact |
| `llx_meli_order` | Mercado Libre ids of the sale, the buyer and the shipment; amounts | Like the order |
| `llx_meli_inbox` | Raw notification bodies (ids of resources and users) | Bodies erased after 30 days, rows after 90 |
| `llx_meli_call` | Method, path without query string, status and duration of each API call: no personal data, no token | 30 days |
| Order documents | Shipping labels (name and address of the buyer) | Like the order's documents |

Access tokens, refresh tokens and the application's secret are stored
encrypted (Dolibarr's `dolEncrypt`) and never logged.

## Development

`test/fake/` is a test double of Mercado Libre's API and OAuth server
(`php -S`), with failure injection; its own README lists what it assumes.
`test/phpunit/` runs the module against it.

## Licence

GPL-3.0-or-later. See [COPYING](COPYING).
