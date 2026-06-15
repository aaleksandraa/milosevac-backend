<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class TransferSqliteContentTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourcePath = tempnam(sys_get_temp_dir(), 'milosevac-transfer-');
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key VARCHAR(255) NOT NULL UNIQUE,
            value TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
        $statement = $pdo->prepare('INSERT INTO settings (id, key, value, created_at, updated_at) VALUES (1, ?, ?, ?, ?)');
        $statement->execute(['site', '{"name":"Miloševac"}', '2026-06-14 12:00:00', '2026-06-14 12:00:00']);
    }

    protected function tearDown(): void
    {
        @unlink($this->sourcePath);

        parent::tearDown();
    }

    public function test_it_transfers_content_from_sqlite_into_an_empty_destination(): void
    {
        $this->artisan('content:transfer-sqlite', [
            'source' => $this->sourcePath,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('Miloševac', Setting::where('key', 'site')->firstOrFail()->value['name']);
    }

    public function test_it_refuses_to_write_into_a_non_empty_destination_without_truncate(): void
    {
        Setting::create(['key' => 'existing', 'value' => ['enabled' => true]]);

        $this->artisan('content:transfer-sqlite', [
            'source' => $this->sourcePath,
            '--force' => true,
        ])->assertFailed();

        $this->assertDatabaseHas('settings', ['key' => 'existing']);
        $this->assertDatabaseMissing('settings', ['key' => 'site']);
    }
}
