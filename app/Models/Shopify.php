<?php

return [
    
    /*
    |--------------------------------------------------------------------------
    | Shopify Store Configuration
    |--------------------------------------------------------------------------
    */

    'store_domain' => env('SHOPIFY_STORE_DOMAIN'),
    
    // Simple API key for verifying requests from your Shopify frontend
    'api_key' => env('SHOPIFY_API_KEY'),
    
    // Webhook secret for verifying webhooks from Shopify
    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),
    
    // Access token for making API calls to Shopify (if needed)
    'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
    
    // API version
    'api_version' => '2024-01',
    
];