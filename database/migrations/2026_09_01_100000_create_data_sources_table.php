<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider')->nullable();
            $table->string('source_class', 8);
            $table->unsignedSmallInteger('authority_rank');
            $table->string('source_type')->nullable();
            $table->string('url')->nullable();
            $table->string('license')->nullable();
            $table->text('attribution')->nullable();
            $table->string('dataset_version')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_class', 'authority_rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
