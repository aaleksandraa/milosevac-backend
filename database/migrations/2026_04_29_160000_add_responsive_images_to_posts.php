<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->json('featured_image_responsive')->nullable()->after('featured_image');
            $table->json('og_image_responsive')->nullable()->after('og_image');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['featured_image_responsive', 'og_image_responsive']);
        });
    }
};
