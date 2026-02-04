<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use App\Models\MpesaTransaction;

class MpesaController extends Controller
{
    /**
     * Initiate STK Push for Shopify orders
     */
    public function stkPush(Request $request)
    {
        // 1. Validate request
        $validated = $request->validate([
            'order_id' => 'required|string|max:255',
            'amount'   => 'required|numeric|min:1|max:150000',
            'phone'    => 'required|string|regex:/^(254|0)[17]\d{8}$/'
        ]);

        $phone = $this->formatPhone($validated['phone']);
        $amount = (int) $validated['amount'];
        $orderId = $validated['order_id'];

        // 2. Get access token
        $accessToken = $this->getAccessToken();
        
        if (!$accessToken) {
            Log::error('M-Pesa: Failed to get access token');
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to authenticate with M-Pesa'
            ], 500);
        }

        // 3. Prepare STK push
        $timestamp = Carbon::now()->format('YmdHis');
        $password = base64_encode(
            config('mpesa.shortcode') .
            config('mpesa.passkey') .
            $timestamp
        );

        $stkPayload = [
            'BusinessShortCode' => config('mpesa.shortcode'),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => config('mpesa.shortcode'),
            'PhoneNumber' => $phone,
            'CallBackURL' => config('mpesa.callback_url'),
            'AccountReference' => $orderId,
            'TransactionDesc' => 'Order Payment'
        ];

        // 4. Send STK push
        try {
            $stkResponse = Http::withToken($accessToken)
                ->timeout(30)
                ->post($this->mpesaBaseUrl() . '/mpesa/stkpush/v1/processrequest', $stkPayload);

            $responseData = $stkResponse->json();

            // Check for errors in response
            if (isset($responseData['errorCode'])) {
                Log::error('M-Pesa STK Push Error', [
                    'error_code' => $responseData['errorCode'],
                    'error_message' => $responseData['errorMessage'],
                    'order_id' => $orderId
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => $responseData['errorMessage'] ?? 'STK Push failed'
                ], 400);
            }

            // 5. Save transaction
            MpesaTransaction::create([
                'order_id' => $orderId,
                'phone' => $phone,
                'amount' => $amount,
                'checkout_request_id' => $responseData['CheckoutRequestID'] ?? null,
                'merchant_request_id' => $responseData['MerchantRequestID'] ?? null,
                'status' => 'PENDING'
            ]);

            Log::info('M-Pesa STK Push Initiated', [
                'order_id' => $orderId,
                'checkout_request_id' => $responseData['CheckoutRequestID'] ?? null
            ]);

            // 6. Success response
            return response()->json([
                'status' => 'pending',
                'message' => 'Payment request sent. Please check your phone.',
                'checkout_request_id' => $responseData['CheckoutRequestID'] ?? null
            ]);

        } catch (\Exception $e) {
            Log::error('M-Pesa STK Push Exception', [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Payment request failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Test STK Push with hardcoded values
     */
    public function stkPushTest()
    {
        // Only allow in non-production
        if (config('mpesa.env') === 'production') {
            abort(403, 'Test endpoint not available in production');
        }

        $orderId = 'TEST-' . time();
        $amount = 1; // Minimum amount for testing
        $phone = '254710909198'; // Safaricom test number

        $phone = $this->formatPhone($phone);

        // Get access token
        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get access token. Check your credentials.'
            ], 500);
        }

        // Prepare STK payload
        $timestamp = Carbon::now()->format('YmdHis');
        $password = base64_encode(
            config('mpesa.shortcode') .
            config('mpesa.passkey') .
            $timestamp
        );

        $stkPayload = [
            'BusinessShortCode' => config('mpesa.shortcode'),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => config('mpesa.shortcode'),
            'PhoneNumber' => $phone,
            'CallBackURL' => config('mpesa.callback_url'),
            'AccountReference' => $orderId,
            'TransactionDesc' => 'Test Payment'
        ];

        try {
            $stkResponse = Http::withToken($accessToken)
                ->timeout(30)
                ->post($this->mpesaBaseUrl() . '/mpesa/stkpush/v1/processrequest', $stkPayload);

            $responseData = $stkResponse->json();

            // Log the response for debugging
            Log::info('M-Pesa Test STK Response', $responseData);

            if (isset($responseData['errorCode'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $responseData['errorMessage'] ?? 'STK Push failed',
                    'data' => $responseData
                ], 400);
            }

            // Save transaction
            MpesaTransaction::create([
                'order_id' => $orderId,
                'phone' => $phone,
                'amount' => $amount,
                'checkout_request_id' => $responseData['CheckoutRequestID'] ?? null,
                'merchant_request_id' => $responseData['MerchantRequestID'] ?? null,
                'status' => 'PENDING'
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Test STK Push sent to ' . $phone,
                'data' => $responseData
            ]);

        } catch (\Exception $e) {
            Log::error('M-Pesa Test Error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * M-Pesa callback handler
     */
    public function callback(Request $request)
    {
        $data = $request->all();
        
        Log::info('M-Pesa Callback Received', $data);

        $resultCode = $data['Body']['stkCallback']['ResultCode'] ?? null;
        $checkoutRequestId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? null;

        if (!$checkoutRequestId) {
            return response()->json(['status' => 'error'], 400);
        }

        // Find transaction
        $transaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)->first();

        if (!$transaction) {
            Log::warning('M-Pesa: Transaction not found', ['checkout_request_id' => $checkoutRequestId]);
            return response()->json(['status' => 'not_found'], 404);
        }

        // Update transaction based on result
        if ($resultCode == 0) {
            // Success
            $callbackMetadata = $data['Body']['stkCallback']['CallbackMetadata']['Item'] ?? [];
            $mpesaReceiptNumber = null;

            foreach ($callbackMetadata as $item) {
                if ($item['Name'] === 'MpesaReceiptNumber') {
                    $mpesaReceiptNumber = $item['Value'];
                    break;
                }
            }

            $transaction->update([
                'status' => 'COMPLETED',
                'mpesa_receipt_number' => $mpesaReceiptNumber,
                'result_desc' => 'Payment successful'
            ]);

            Log::info('M-Pesa: Payment completed', [
                'order_id' => $transaction->order_id,
                'receipt' => $mpesaReceiptNumber
            ]);

        } else {
            // Failed
            $resultDesc = $data['Body']['stkCallback']['ResultDesc'] ?? 'Payment failed';
            
            $transaction->update([
                'status' => 'FAILED',
                'result_desc' => $resultDesc
            ]);

            Log::info('M-Pesa: Payment failed', [
                'order_id' => $transaction->order_id,
                'reason' => $resultDesc
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Get M-Pesa access token
     */
    private function getAccessToken()
    {
        try {
            $response = Http::withBasicAuth(
                config('mpesa.consumer_key'),
                config('mpesa.consumer_secret')
            )
            ->timeout(30)
            ->get($this->mpesaBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials');

            if ($response->successful() && isset($response['access_token'])) {
                return $response['access_token'];
            }

            // Log the error response
            Log::error('M-Pesa Auth Error', $response->json());
            
            return null;

        } catch (\Exception $e) {
            Log::error('M-Pesa Auth Exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get M-Pesa base URL based on environment
     */
    private function mpesaBaseUrl()
    {
        return config('mpesa.env') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Format phone number to 254XXXXXXXXX
     */
    private function formatPhone($phone)
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/\D/', '', $phone);

        // Convert 0710... to 254710...
        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        // Convert 710... to 254710...
        if (str_starts_with($phone, '7')) {
            return '254' . $phone;
        }

        // Already in correct format
        return $phone;
    }
}