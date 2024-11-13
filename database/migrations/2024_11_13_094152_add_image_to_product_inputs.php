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
        Schema::table('product_inputs', function (Blueprint $table) {
            $table->string('image')->nullable();
        });
        Schema::table('filter_product_inputs', function (Blueprint $table) {
            $table->string('image')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_inputs', function (Blueprint $table) {
            $table->dropColumn('image');
        });
        Schema::table('filter_product_inputs', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
