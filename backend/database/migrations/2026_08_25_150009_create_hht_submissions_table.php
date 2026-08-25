<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hht_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('submission_uid', 80);
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->unsignedInteger('audit_number');
            $table->date('audit_date');
            $table->string('hht_user', 150)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->string('payload_hash', 64);
            $table->string('status', 25)->default('accepted');
            $table->string('message', 500)->nullable();
            $table->foreignId('audit_id')->nullable()->constrained('audits')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['shop_id', 'device_id', 'audit_number', 'submission_uid'],
                'hht_submission_identity_unique'
            );
            $table->index('status');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hht_submissions');
    }
};
