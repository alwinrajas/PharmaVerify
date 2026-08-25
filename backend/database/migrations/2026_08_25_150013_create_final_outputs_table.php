<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('audit_id')->constrained('audits')->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('file_path', 500)->nullable();
            $table->unsignedInteger('record_count')->default(0);
            $table->string('verification_status', 25)->default('pending');
            $table->string('adjustment_status', 25)->default('pending');
            $table->string('onedrive_status', 25)->default('not_uploaded');
            $table->string('onedrive_item_id', 200)->nullable();
            $table->string('onedrive_url', 1000)->nullable();
            $table->unsignedInteger('upload_attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index('audit_id');
            $table->index('onedrive_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_outputs');
    }
};
