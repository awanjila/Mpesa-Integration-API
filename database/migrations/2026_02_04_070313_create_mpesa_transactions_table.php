<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mpesa_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->index();
            $table->string('phone');
            $table->decimal('amount', 10, 2);
            $table->string('checkout_request_id')->nullable()->index();
            $table->string('merchant_request_id')->nullable();
            $table->string('mpesa_receipt_number')->nullable(); // Renamed from mpesa_receipt for clarity
            $table->string('status')->default('PENDING')->index();
            $table->text('result_desc')->nullable(); // Added for storing M-Pesa response messages
            $table->json('raw_callback')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_transactions');
    }
};