<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('power_outage_ingestions', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique();
            $table->unsignedBigInteger('elektrodoboj_id')->nullable()->unique();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 40)->default('pending')->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('raw_subject')->nullable();
            $table->json('parsed')->nullable();
            $table->string('body_hash', 64)->nullable();
            $table->text('body_excerpt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('power_outage_ingestions');
    }
};
