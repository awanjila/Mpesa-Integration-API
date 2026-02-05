<?php
//app/Http/Middleware/VerifyShopifyWebhook
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhook
{
    /**
     * Verify that the request is coming from Shopify
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the HMAC from Shopify header
        $hmacHeader = $request->header('X-Shopify-Hmac-SHA256');
        
        if (!$hmacHeader) {
            Log::warning('Shopify webhook rejected: Missing HMAC header', [
                'ip' => $request->ip(),
                'url' => $request->fullUrl()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Missing signature'
            ], 401);
        }

        // Get the raw request body
        $data = $request->getContent();
        
        // Calculate expected HMAC
        $calculatedHmac = base64_encode(
            hash_hmac('sha256', $data, config('services.shopify.webhook_secret'), true)
        );

        // Compare HMACs (timing-safe comparison)
        if (!hash_equals($calculatedHmac, $hmacHeader)) {
            Log::warning('Shopify webhook rejected: Invalid HMAC signature', [
                'ip' => $request->ip(),
                'expected' => substr($calculatedHmac, 0, 10) . '...',
                'received' => substr($hmacHeader, 0, 10) . '...'
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Invalid signature'
            ], 401);
        }

        Log::info('Shopify webhook verified successfully', [
            'shop_domain' => $request->header('X-Shopify-Shop-Domain'),
            'topic' => $request->header('X-Shopify-Topic')
        ]);

        return $next($request);
    }
}