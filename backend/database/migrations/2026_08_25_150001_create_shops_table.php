<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('shop_code', 50)->unique();
            $table->string('shop_name', 200);
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('shop_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
