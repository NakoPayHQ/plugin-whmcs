# NakoPay for WHMCS

Accept Bitcoin and other crypto on your WHMCS install through [NakoPay](https://nakopay.com).

- Stripe-style API: invoices created server-side, polled and webhook-notified.
- Signed webhooks (HMAC-SHA256, 5-minute replay window).
- No bundled Angular, no per-coin sub-domains. Plain PHP + ~30 KB of vendor JS.

## Requirements

- WHMCS 8.0+
- PHP 8.0+
- SFTP / SSH access to your WHMCS install (the WHMCS admin uploader does **not** support payment gateway modules)
- A NakoPay account (free) - <https://nakopay.com/dashboard/api-keys>

## Download

| # | Source | When to use |
|---|--------|-------------|
| 1 | **WHMCS Marketplace** - <https://marketplace.whmcs.com/product/nakopay> | *Listing pending review - use option 2 in the meantime.* |
| 2 | **GitHub Releases zip** - <https://github.com/NakoPayHQ/plugin-whmcs/releases/latest/download/nakopay-whmcs.zip> | Available today. Download `nakopay-whmcs.zip`. |
| 3 | **Build from source** | See bottom of this file. |

## Install

WHMCS payment gateways must live in a specific folder layout, so you must use SFTP or the file manager built into your hosting panel. There is no admin-UI uploader for gateway modules.

1. Download `nakopay-whmcs.zip` and unzip it on your computer. You'll get a folder structure like:

   ```
   modules/gateways/nakopay.php
   modules/gateways/nakopay/
   modules/gateways/callback/nakopay.php
   ```

2. Upload these files into your WHMCS install so the final paths are:

   ```
   <whmcs-root>/modules/gateways/nakopay.php
   <whmcs-root>/modules/gateways/nakopay/...
   <whmcs-root>/modules/gateways/callback/nakopay.php
   ```

   Use **FileZilla / Cyberduck / WinSCP** (SFTP), or your hosting panel's File Manager (cPanel, Plesk, DirectAdmin all work). Make sure files keep `0644` permissions and folders `0755`.

3. In WHMCS admin go to **Setup -> Payments -> Payment Gateways -> All Payment Gateways**, find **NakoPay (Bitcoin)**, click **Activate**.

4. Paste:
   - **API Key** - `sk_live_...` (or `sk_test_...` for testing). Get one at <https://nakopay.com/dashboard/api-keys>.
   - **Webhook Signing Secret** - shown once when you create a webhook endpoint in your NakoPay dashboard.

5. In your NakoPay dashboard, **Settings -> Webhooks -> Add endpoint**, paste the **Your Webhook URL** value the gateway shows you (typically `https://your-whmcs.example/modules/gateways/callback/nakopay.php`). Subscribe to `invoice.paid`, `invoice.completed`, `invoice.expired`, `invoice.cancelled`. Save - copy the signing secret it shows you back into step 4.

6. Browse to `https://your-whmcs.example/modules/gateways/nakopay/upgrade.php` once (admin login required) to create the `mod_nakopay_orders` table. You should see `{"ok": true}`.

## Verify

- Create a test invoice in WHMCS for any client.
- Click **Pay Now** on the client side - you should see a QR + Bitcoin address + amount.
- Pay with a `sk_test_*` key from a Bitcoin testnet faucet. Within ~10s the page redirects back to `viewinvoice.php` and the invoice is marked **Paid**.

If verification fails, run the **Test Setup** button on the gateway settings page in WHMCS admin - it pings NakoPay with your key.

## How it works

- A WHMCS invoice's "Pay Now" button posts to `modules/gateways/nakopay/payment.php`, which calls `POST /v1/invoices` and shows the customer an address + QR + amount.
- The checkout page polls `?poll=in_xxx` every 5s. When the invoice flips to `paid` or `completed`, the customer is redirected back to `viewinvoice.php`.
- The webhook receiver verifies the signature, looks up the WHMCS invoice id from `metadata.whmcs_invoice_id` (or the local orders table), and calls `addInvoicePayment()`. Duplicate transaction ids are ignored, so retries are safe.

## Test mode

Use a `sk_test_...` key. Test invoices accept BTC testnet sends - grab funds from any testnet faucet.

## Uninstall

1. WHMCS admin -> **Setup -> Payments -> Payment Gateways -> NakoPay (Bitcoin)** -> **Deactivate**.
2. Via SFTP, delete:
   - `modules/gateways/nakopay.php`
   - `modules/gateways/nakopay/` (whole folder)
   - `modules/gateways/callback/nakopay.php`
3. (Optional) Drop the `mod_nakopay_orders` table from your WHMCS database.

## Files

| Path | Purpose |
|------|---------|
| `nakopay.php` | Gateway entry: settings + Pay Now button. |
| `nakopay/nakopay.php` | `NakoPay\Client` - API + DB + signature verify. |
| `nakopay/payment.php` | Customer checkout controller + JSON poll endpoint. |
| `nakopay/testsetup.php` | Admin-only API key smoke test. |
| `nakopay/upgrade.php` | Creates the orders table. |
| `nakopay/assets/templates/checkout.tpl` | Smarty checkout view. |
| `nakopay/assets/js/checkout.js` | QR render + polling. |
| `nakopay/assets/js/vendors/qrious.min.js` | QR generator (MIT). |
| `nakopay/assets/css/order.css` | Checkout styling. |
| `nakopay/lang/english.php` | UI strings. |
| `modules/gateways/callback/nakopay.php` | Webhook receiver. |

## Build from source

```bash
git clone https://github.com/NakoPayHQ/plugin-whmcs.git
cd plugin-whmcs
zip -r nakopay-whmcs.zip modules/ -x "*.git*" "tests/*" "*.DS_Store"
```

## Support

- Issues: <https://github.com/NakoPayHQ/plugin-whmcs/issues>
- Email: support@nakopay.com

## License

MIT.
