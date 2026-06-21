<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (! Schema::hasColumn('media', 'media_type')) {
                $table->string('media_type', 40)->default('image')->after('mime_type');
            }

            if (! Schema::hasColumn('media', 'caption')) {
                $table->string('caption')->nullable()->after('alt_text');
            }

            if (! Schema::hasColumn('media', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('caption');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            foreach (['sort_order', 'caption', 'media_type'] as $column) {
                if (Schema::hasColumn('media', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
