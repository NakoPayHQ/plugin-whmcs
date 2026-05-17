<link rel="stylesheet" type="text/css" href="{$asset_base}/css/order.css">

<div id="nakopay_checkout" class="nakopay-wrap">
    <div class="nakopay-card">
        <div class="nakopay-header">
            <span class="nakopay-order-id">Invoice #{$order.whmcs_invoice_id}</span>
            <span class="nakopay-amount-fiat">{$order.amount_fiat} {$order.currency}</span>
        </div>

        <div class="nakopay-status" id="nakopay-status" data-status="{$order.status}">
            Waiting for payment…
        </div>

        <div class="nakopay-row">
            <label>Send exactly</label>
            <div class="nakopay-copy">
                <input type="text" id="nakopay-amount" value="{$order.amount_crypto}" readonly />
                <button type="button" class="nakopay-copy-btn" data-target="nakopay-amount">Copy</button>
                <span class="nakopay-coin">{$order.coin}</span>
            </div>
        </div>

        <div class="nakopay-row">
            <label>To this address</label>
            <div class="nakopay-copy">
                <input type="text" id="nakopay-address" value="{$order.address}" readonly />
                <button type="button" class="nakopay-copy-btn" data-target="nakopay-address">Copy</button>
            </div>
        </div>

        <div class="nakopay-qr">
            <a id="nakopay-wallet-link" href="#" target="_blank" rel="noopener">
                <canvas id="nakopay-qr-canvas"></canvas>
            </a>
            <small><a id="nakopay-wallet-link-text" href="#" target="_blank" rel="noopener">Open in wallet</a></small>
        </div>

        <div class="nakopay-footer">
            <a href="{$return_url}">Back to invoice</a>
            <span>Powered by <a href="https://nakopay.com" target="_blank" rel="noopener">NakoPay</a></span>
        </div>
    </div>
</div>

<script>
window.NAKOPAY = {
    pollUrl:  "{$poll_url}",
    address:  "{$order.address}",
    amount:   "{$order.amount_crypto}",
    coin:     "{$order.coin}",
    bip21:    "{$order.bip21}"
};
</script>
<script type="text/javascript" src="{$asset_base}/js/vendors/qrious.min.js"></script>
<script type="text/javascript" src="{$asset_base}/js/checkout.js"></script>
