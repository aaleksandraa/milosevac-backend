<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->string('import_type');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('records_read')->default(0);
            $table->unsignedInteger('records_inserted')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->text('errors')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['data_source_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
    }
};
