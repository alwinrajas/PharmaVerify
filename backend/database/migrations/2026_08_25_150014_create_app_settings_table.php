<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group_name', 50)->default('general');
            $table->string('key_name', 100)->unique();
            $table->string('value', 1000)->nullable();
            $table->string('value_type', 20)->default('string');
            $table->string('label', 200)->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('is_editable')->default(true);
            $table->timestamps();

            $table->index('group_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
