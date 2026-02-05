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
     * 
     * This is called by Shopify webhook automatically
     */
    public function stkPush(Request $request)
    {
        // 1. Validate request
        $validated = $request->validate([
            'order_id' => 'required|string|max:255',
            'amount'   => 'required|numeric|min:1|max:150000',
            'phone'    => 'required|string|regex:/^(254|0)[17]\d{8}$/',
            'email'    => 'nullable|email'
        ]);

        $phone = $this->formatPhone($validated['phone']);
        $amount = (int) $validated['amount'];
        $orderId = $validated['order_id'];
        $email = $validated['email'] ?? null;

        // Check for duplicate payment
        $existingPayment = MpesaTransaction::where('order_id', $orderId)
            ->whereIn('status', ['PENDING', 'COMPLETED'])
            ->first();

        if ($existingPayment) {
            Log::info('Duplicate payment request detected', [
                'order_id' => $orderId,
                'existing_status' => $existingPayment->status
            ]);

            return response()->json([
                'success' => true,
                'status' => strtolower($existingPayment->status),
                'message' => 'Payment already initiated',
                'payment_id' => $existingPayment->id,
                'checkout_request_id' => $existingPayment->checkout_request_id
            ], 200);
        }

        // 2. Get access token
        $accessToken = $this->getAccessToken();
        
        if (!$accessToken) {
            Log::error('M-Pesa: Failed to get access token', ['order_id' => $orderId]);
            
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to authenticate with M-Pesa service'
            ], 500);
        }

        // 3. Prepare STK push
        $timestamp = Carbon::now()->format('YmdHis');
        $password = base64_encode(
            config('services.mpesa.shortcode') .
            config('services.mpesa.passkey') .
            $timestamp
        );

        $stkPayload = [
            'BusinessShortCode' => config('services.mpesa.shortcode'),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => config('services.mpesa.shortcode'),
            'PhoneNumber' => $phone,
            'CallBackURL' => config('services.mpesa.callback_url'),
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
                    'success' => false,
                    'status' => 'error',
                    'message' => $responseData['errorMessage'] ?? 'STK Push failed'
                ], 400);
            }

            // 5. Save transaction
            $transaction = MpesaTransaction::create([
                'order_id' => $orderId,
                'phone' => $phone,
                'amount' => $amount,
                'checkout_request_id' => $responseData['CheckoutRequestID'] ?? null,
                'merchant_request_id' => $responseData['MerchantRequestID'] ?? null,
                'status' => 'PENDING'
            ]);

            Log::info('M-Pesa STK Push Initiated', [
                'order_id' => $orderId,
                'payment_id' => $transaction->id,
                'checkout_request_id' => $responseData['CheckoutRequestID'] ?? null,
                'phone' => $phone
            ]);

            // 6. Success response to Shopify
            return response()->json([
                'success' => true,
                'status' => 'pending',
                'message' => 'Payment initiated successfully. Customer will receive M-Pesa prompt.',
                'payment_id' => $transaction->id,
                'checkout_request_id' => $responseData['CheckoutRequestID'] ?? null,
                'merchant_request_id' => $responseData['MerchantRequestID'] ?? null
            ], 200);

        } catch (\Exception $e) {
            Log::error('M-Pesa STK Push Exception', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Payment request failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Check payment status by order_id
     * 
     * Called by Shopify to poll payment status
     */
    public function checkStatus($orderId)
    {
        $transaction = MpesaTransaction::where('order_id', $orderId)
            ->orWhere('checkout_request_id', $orderId)
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'status' => 'not_found',
                'message' => 'Payment not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'status' => strtolower($transaction->status),
            'payment_id' => $transaction->id,
            'order_id' => $transaction->order_id,
            'amount' => $transaction->amount,
            'phone' => $transaction->phone,
            'mpesa_receipt_number' => $transaction->mpesa_receipt_number,
            'result_desc' => $transaction->result_desc,
            'created_at' => $transaction->created_at->toIso8601String(),
            'updated_at' => $transaction->updated_at->toIso8601String(),
            'message' => $this->getStatusMessage($transaction->status)
        ], 200);
    }

    /**
     * Test STK Push with hardcoded values
     */
    public function stkPushTest()
    {
        // Only allow in non-production
        if (config('services.mpesa.env') === 'production') {
            abort(403, 'Test endpoint not available in production');
        }

        $orderId = 'TEST-' . time();
        $amount = 1; // Minimum amount for testing
        $phone = '254708374149'; // Safaricom sandbox test number

        $phone = $this->formatPhone($phone);

        // Get access token
        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to get access token. Check your credentials.'
            ], 500);
        }

        // Prepare STK payload
        $timestamp = Carbon::now()->format('YmdHis');
        $password = base64_encode(
            config('services.mpesa.shortcode') .
            config('services.mpesa.passkey') .
            $timestamp
        );

        $stkPayload = [
            'BusinessShortCode' => config('services.mpesa.shortcode'),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => config('services.mpesa.shortcode'),
            'PhoneNumber' => $phone,
            'CallBackURL' => config('services.mpesa.callback_url'),
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
                    'success' => false,
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
                'success' => true,
                'status' => 'pending',
                'message' => 'Test STK Push sent to ' . $phone,
                'data' => $responseData
            ]);

        } catch (\Exception $e) {
            Log::error('M-Pesa Test Error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
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

        try {
            $resultCode = $data['Body']['stkCallback']['ResultCode'] ?? null;
            $checkoutRequestId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? null;
            $resultDesc = $data['Body']['stkCallback']['ResultDesc'] ?? 'Unknown result';

            if (!$checkoutRequestId) {
                Log::error('M-Pesa Callback: Missing CheckoutRequestID');
                return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback'], 400);
            }

            // Find transaction
            $transaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)->first();

            if (!$transaction) {
                Log::warning('M-Pesa: Transaction not found', ['checkout_request_id' => $checkoutRequestId]);
                return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Transaction not found'], 404);
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
                    'result_desc' => $resultDesc
                ]);

                Log::info('M-Pesa: Payment completed', [
                    'order_id' => $transaction->order_id,
                    'payment_id' => $transaction->id,
                    'receipt' => $mpesaReceiptNumber,
                    'amount' => $transaction->amount
                ]);

            } else {
                // Failed
                $transaction->update([
                    'status' => 'FAILED',
                    'result_desc' => $resultDesc
                ]);

                Log::info('M-Pesa: Payment failed', [
                    'order_id' => $transaction->order_id,
                    'payment_id' => $transaction->id,
                    'result_code' => $resultCode,
                    'reason' => $resultDesc
                ]);
            }

            // Acknowledge callback to M-Pesa
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Success']);

        } catch (\Exception $e) {
            Log::error('M-Pesa Callback Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Internal error'], 500);
        }
    }

    /**
     * Get M-Pesa access token
     */
    private function getAccessToken()
    {
        try {
            $response = Http::withBasicAuth(
                config('services.mpesa.consumer_key'),
                config('services.mpesa.consumer_secret')
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
        return config('services.mpesa.env') === 'production'
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

    /**
     * Get user-friendly status message
     */
    private function getStatusMessage($status)
    {
        return match(strtoupper($status)) {
            'PENDING' => 'Payment is being processed. Customer should check their phone.',
            'COMPLETED' => 'Payment completed successfully.',
            'FAILED' => 'Payment failed or was cancelled.',
            default => 'Unknown status'
        };
    }
}