<?php

return [
    'errors' => [
        'invalid_callback' => 'An invalid response was received from SnappPay.',
        'transaction_not_found' => 'The SnappPay transaction was not found.',
        'amount_mismatch' => 'The transaction amount does not match the order amount.',
        'gateway_failed' => 'The SnappPay payment failed.',
        'payment_failed' => 'Payment failed. The order was not completed.',
        'payment_processing' => 'The transaction is still being processed. Please do not retry simultaneously.',
        'settle_failed' => 'The SnappPay transaction could not be finalized.',
        'try_again' => 'Please return to the cart and try the payment again.',
        'return_to_cart' => 'Return to cart',
    ],
];
