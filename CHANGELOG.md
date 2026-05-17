# Changelog

## 0.3.0

- Default API base URL is now `https://api.nakopay.com/v1/` (branded host).
  The Supabase functions URL stays declared as `Client::BASE_FALLBACK` and is
  still selectable via the new "API Base URL override (advanced)" gateway
  setting or the `NAKOPAY_API_BASE` PHP constant - existing installs that
  pinned a custom base keep working with zero changes.
- New `Client::getBaseUrl()` helper resolves: gateway config override ->
  `NAKOPAY_API_BASE` constant -> `BASE_PRIMARY`. The legacy `Client::BASE_URL`
  constant is retained as an alias for backward compatibility.
- README: fixed the "How it works" path - the plugin calls `invoices-create`,
  not `/v1/invoices` (the kebab-case path is what's sent on the wire).

## 0.2.0 - 2026-04-28

- First working release with the full payment flow.
- Full payment flow: Pay Now → NakoPay invoice → QR + address checkout → 5 s status polling → automatic redirect on paid.
- Signed webhook receiver (`X-NakoPay-Signature`, HMAC-SHA256, 5 min replay window).
- Admin "Test API key" smoke endpoint.
- Local `mod_nakopay_orders` table for idempotency and reuse of in-flight orders.

## 0.1.0

- Initial skeleton (`nakopay_MetaData`, `nakopay_config` only).
