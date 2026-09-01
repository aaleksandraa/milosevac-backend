<?php

namespace App\Console\Commands;

use App\Support\Atlas\Poi\OverpassPoiFetcher;
use Illuminate\Console\Command;
use Throwable;

class FetchAtlasOsmPois extends Command
{
    protected $signature = 'atlas:fetch-osm-pois
        {--pilot : Fetch Modriča and Šamac Overpass POIs sequentially}
        {--municipality= : Municipality slug (modrica, samac)}';

    protected $description = 'Download OSM POIs via Overpass into the private atlas cache (operator/import only)';

    public function handle(OverpassPoiFetcher $fetcher): int
    {
        $slugs = $this->municipalitySlugs();
        if ($slugs === []) {
            $this->error('Navedite --pilot ili --municipality=modrica|samac.');

            return self::FAILURE;
        }

        try {
            $results = $fetcher->fetch($slugs);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $result) {
            $meta = $result['meta'] ?? [];
            $this->line('Municipality: '.($meta['municipality'] ?? '?'));
            $this->line('Endpoint: '.($meta['endpoint'] ?? '?'));
            $this->line('Retrieved: '.($meta['retrieved_at'] ?? '?'));
            $this->line('OSM timestamp: '.($meta['overpass_timestamp'] ?? 'n/a'));
            $this->line('Elements: '.($meta['element_count'] ?? 0));
            $this->line('Hash: '.($meta['response_hash'] ?? '?'));
            $this->line('File: '.($result['path'] ?? '?'));
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function municipalitySlugs(): array
    {
        if ($this->option('pilot')) {
            return ['modrica', 'samac'];
        }
        $municipality = trim((string) $this->option('municipality'));

        return $municipality === '' ? [] : [$municipality];
    }
}
