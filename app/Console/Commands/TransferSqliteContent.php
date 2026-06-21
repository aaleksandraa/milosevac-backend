<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class TransferSqliteContent extends Command
{
    protected $signature = 'content:transfer-sqlite
        {source : Absolute path, or Laravel-root-relative path, to the source SQLite database}
        {--target= : Destination Laravel database connection; defaults to DB_CONNECTION}
        {--truncate : Empty destination content tables before transfer}
        {--dry-run : Compare source and destination without writing}
        {--force : Confirm that destination data may be written}';

    protected $description = 'Transfer Miloševac CMS content from SQLite to the configured production database.';

    /** @var array<string, string> */
    private const TABLES = [
        'roles' => 'id',
        'permissions' => 'id',
        'users' => 'id',
        'permission_role' => 'role_id',
        'categories' => 'id',
        'tags' => 'id',
        'posts' => 'id',
        'category_post' => 'post_id',
        'post_tag' => 'post_id',
        'media' => 'id',
        'post_views' => 'id',
        'settings' => 'id',
        'seo_metadata' => 'id',
        'comments' => 'id',
        'matches' => 'id',
        'match_media' => 'id',
    ];

    public function handle(): int
    {
        [$sourcePath, $checkedPaths] = $this->resolveSourcePath((string) $this->argument('source'));
        if (! $sourcePath) {
            $this->error('Source SQLite database does not exist.');
            $this->line('Current working directory: '.getcwd());
            $this->line('Laravel base path: '.base_path());
            $this->line('Checked paths:');
            foreach ($checkedPaths as $path) {
                $this->line(' - '.$path);
            }

            return self::FAILURE;
        }

        $targetName = (string) ($this->option('target') ?: config('database.default'));
        if (! config("database.connections.{$targetName}")) {
            $this->error("Unknown target database connection: {$targetName}");

            return self::FAILURE;
        }

        config(['database.connections.content_transfer_source' => [
            'driver' => 'sqlite',
            'database' => $sourcePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('content_transfer_source');

        if ($this->sameSqliteDatabase($sourcePath, $targetName)) {
            $this->error('Source and destination point to the same SQLite database.');

            return self::FAILURE;
        }

        $source = DB::connection('content_transfer_source');
        $target = DB::connection($targetName);
        $tables = $this->transferableTables($targetName);
        if ($tables === []) {
            $this->error('No transferable Miloševac content tables were found.');

            return self::FAILURE;
        }

        $this->table(
            ['Table', 'Source', 'Destination'],
            collect($tables)->map(fn ($orderBy, $table) => [
                $table,
                $source->table($table)->count(),
                $target->table($table)->count(),
            ])->values()->all()
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run completed; destination was not changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->error('Refusing to write without --force. Run with --dry-run first.');

            return self::FAILURE;
        }

        $occupied = collect(array_keys($tables))
            ->filter(fn ($table) => $target->table($table)->exists())
            ->values();
        if ($occupied->isNotEmpty() && ! $this->option('truncate')) {
            $this->error('Destination contains data in: '.$occupied->implode(', '));
            $this->line('Use a fresh migrated database, or explicitly pass --truncate --force.');

            return self::FAILURE;
        }

        try {
            Schema::connection($targetName)->disableForeignKeyConstraints();
            $target->transaction(function () use ($source, $target, $tables): void {
                if ($this->option('truncate')) {
                    if ($target->getSchemaBuilder()->hasTable('category_post')) {
                        $target->table('category_post')->delete();
                    }

                    foreach (array_reverse(array_keys($tables)) as $table) {
                        $target->table($table)->delete();
                    }
                }

                foreach ($tables as $table => $orderBy) {
                    $this->transferTable($source, $target, $table, $orderBy);
                }

                if (! array_key_exists('category_post', $tables) && $target->getSchemaBuilder()->hasTable('category_post')) {
                    $this->backfillCategoryPost($target);
                }
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            Schema::connection($targetName)->enableForeignKeyConstraints();
        }

        foreach (array_keys($tables) as $table) {
            $sourceCount = $source->table($table)->count();
            $targetCount = $target->table($table)->count();
            if ($sourceCount !== $targetCount) {
                throw new RuntimeException("Count mismatch for {$table}: source={$sourceCount}, destination={$targetCount}");
            }
        }

        Cache::increment('api.content.version');

        $this->info('SQLite content transfer completed and row counts match.');

        return self::SUCCESS;
    }

    /** @return array{0: string|null, 1: array<int, string>} */
    private function resolveSourcePath(string $source): array
    {
        $source = trim($source);
        $candidates = [$source];

        if (! $this->isAbsolutePath($source)) {
            $candidates[] = base_path($source);
            $candidates[] = storage_path($source);
            $candidates[] = database_path($source);
        }

        $checked = [];
        foreach (array_unique($candidates) as $candidate) {
            $realPath = realpath($candidate);
            $checked[] = $candidate.($realPath ? " ({$realPath})" : '');

            if ($realPath && is_file($realPath) && is_readable($realPath)) {
                return [$realPath, $checked];
            }
        }

        return [null, $checked];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('/^[A-Za-z]:[\/\\\\]/', $path);
    }

    /** @return array<string, string> */
    private function transferableTables(string $targetName): array
    {
        return collect(self::TABLES)
            ->filter(fn ($orderBy, $table) => Schema::connection('content_transfer_source')->hasTable($table)
                && Schema::connection($targetName)->hasTable($table))
            ->all();
    }

    private function transferTable(
        ConnectionInterface $source,
        ConnectionInterface $target,
        string $table,
        string $orderBy
    ): void {
        $source->table($table)
            ->orderBy($orderBy)
            ->chunk(100, function ($rows) use ($target, $table): void {
                $payload = $rows->map(fn ($row) => (array) $row)->all();
                if ($payload !== []) {
                    $target->table($table)->insert($payload);
                }
            });

        $this->line("Transferred {$table}: ".$source->table($table)->count());
    }

    private function backfillCategoryPost(ConnectionInterface $target): void
    {
        $target->table('posts')
            ->whereNotNull('category_id')
            ->orderBy('id')
            ->select(['id', 'category_id'])
            ->chunk(100, function ($posts) use ($target): void {
                $payload = $posts->map(fn ($post) => [
                    'post_id' => $post->id,
                    'category_id' => $post->category_id,
                ])->all();

                if ($payload !== []) {
                    $target->table('category_post')->insertOrIgnore($payload);
                }
            });
    }

    private function sameSqliteDatabase(string $sourcePath, string $targetName): bool
    {
        $target = config("database.connections.{$targetName}");
        if (($target['driver'] ?? null) !== 'sqlite') {
            return false;
        }

        $targetPath = realpath((string) ($target['database'] ?? ''));

        return $targetPath !== false && $targetPath === $sourcePath;
    }
}
