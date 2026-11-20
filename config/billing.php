<?php

return [

    // Users pay Pixelforge's cost plus this markup.
    'markup_percent' => (int) env('BILLING_MARKUP_PERCENT', 25),

    // Credit granted to every new account (USD, exact decimal string).
    'signup_credit' => env('BILLING_SIGNUP_CREDIT', '1.00'),

    // Fake credit packs offered by the "Buy credits" page (USD, exact decimal strings).
    'credit_packs' => ['1.00', '5.00', '20.00'],

    // How long a quote is cached for the pricing table (seconds): fresh, then served stale while refreshing.
    'pricing_cache' => [
        'fresh' => 300,
        'stale' => 3600,
    ],

];
