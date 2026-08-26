<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices');
            $table->unsignedInteger('audit_number');
            $table->date('audit_date');
            $table->string('hht_user', 150)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('variance_count')->default(0);
            $table->string('status', 25)->default('submitted');
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'device_id', 'audit_number'], 'audit_identity_unique');
            $table->index('status');
            $table->index('audit_number');
            $table->index('audit_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
