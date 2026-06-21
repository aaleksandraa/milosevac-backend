<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('category_post')) {
            Schema::create('category_post', function (Blueprint $table) {
                $table->foreignId('post_id')->constrained()->cascadeOnDelete();
                $table->foreignId('category_id')->constrained()->cascadeOnDelete();
                $table->primary(['post_id', 'category_id']);
            });
        }

        DB::table('posts')
            ->whereNotNull('category_id')
            ->orderBy('id')
            ->select(['id', 'category_id'])
            ->chunk(500, function ($posts): void {
                $rows = $posts->map(fn ($post) => [
                    'post_id' => $post->id,
                    'category_id' => $post->category_id,
                ])->all();

                if ($rows !== []) {
                    DB::table('category_post')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_post');
    }
};
