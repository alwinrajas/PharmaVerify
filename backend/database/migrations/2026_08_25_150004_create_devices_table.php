<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('device_code', 50);
            $table->string('description', 200)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_submission_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'device_code'], 'device_shop_code_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
