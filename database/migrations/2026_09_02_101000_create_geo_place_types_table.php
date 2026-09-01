<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_place_types', function (Blueprint $table) {
            $table->id();
            $table->string('family', 32);
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('singular_label')->nullable();
            $table->string('plural_label')->nullable();
            $table->string('icon_key', 64)->nullable();
            $table->boolean('default_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('family');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_place_types');
    }
};
