<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('stored_path', 500)->nullable();
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('success_records')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->unsignedInteger('replaced_records')->default(0);
            // Long enough for 'completed_with_errors' with room to spare.
            $table->string('status', 40)->default('pending');
            $table->string('failure_reason', 500)->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('shop_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_imports');
    }
};
