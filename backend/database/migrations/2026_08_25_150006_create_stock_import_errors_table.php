<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_import_id')->constrained('stock_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('column_name', 100)->nullable();
            $table->string('column_value', 300)->nullable();
            $table->string('error_message', 500);
            $table->timestamps();

            $table->index('stock_import_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_import_errors');
    }
};
