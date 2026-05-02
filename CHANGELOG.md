# Changelog

## 0.2.0 - 2026-04-28

- First working release with the full payment flow.
- Full payment flow: Pay Now → NakoPay invoice → QR + address checkout → 5 s status polling → automatic redirect on paid.
- Signed webhook receiver (`X-NakoPay-Signature`, HMAC-SHA256, 5 min replay window).
- Admin "Test API key" smoke endpoint.
- Local `mod_nakopay_orders` table for idempotency and reuse of in-flight orders.

## 0.1.0

- Initial skeleton (`nakopay_MetaData`, `nakopay_config` only).
