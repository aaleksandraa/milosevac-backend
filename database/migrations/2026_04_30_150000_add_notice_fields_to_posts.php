<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('label', 40)->nullable()->after('is_breaking')->index();
            $table->string('service_type', 60)->nullable()->after('label')->index();
            $table->timestamp('notice_starts_at')->nullable()->after('service_type');
            $table->timestamp('notice_ends_at')->nullable()->after('notice_starts_at')->index();
            $table->text('notice_schedule')->nullable()->after('notice_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn([
                'label',
                'service_type',
                'notice_starts_at',
                'notice_ends_at',
                'notice_schedule',
            ]);
        });
    }
};
