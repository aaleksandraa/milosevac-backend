<?php

namespace Tests\Feature;

use Database\Seeders\GeoAtlasSeeder;
use Tests\TestCase;

class GeoAtlasSeoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
        config(['services.frontend.url' => 'https://milosevac.com']);
    }

    public function test_atlas_routes_redirect_browsers_to_frontend(): void
    {
        $this->get('/mjesta')->assertRedirect('https://milosevac.com/mjesta');
        $this->get('/mjesta/modrica')->assertRedirect('https://milosevac.com/mjesta/modrica');
        $this->get('/mjesta/modrica/milosevac')->assertRedirect('https://milosevac.com/mjesta/modrica/milosevac');
    }

    public function test_crawler_receives_atlas_index_metadata(): void
    {
        $this->withHeader('X-Social-Preview', '1')
            ->get('/mjesta')
            ->assertOk()
            ->assertSee('Atlas naseljenih mjesta | Miloševac', false)
            ->assertSee('Modriča i Šamac', false)
            ->assertSee('<link rel="canonical" href="https://milosevac.com/mjesta">', false)
            ->assertSee('"@type":"WebPage"', false);
    }

    public function test_crawler_receives_municipality_and_settlement_metadata(): void
    {
        $this->withHeader('X-Social-Preview', '1')
            ->get('/mjesta/modrica')
            ->assertOk()
            ->assertSee('Naselja opštine Modriča', false)
            ->assertSee('25.720 stanovnika prema Popisu 2013.', false)
            ->assertSee('"@type":"AdministrativeArea"', false)
            ->assertDontSee('GeoCoordinates', false);

        $this->withHeader('X-Social-Preview', '1')
            ->get('/mjesta/modrica/milosevac')
            ->assertOk()
            ->assertSee('Miloševac – stanovništvo i podaci', false)
            ->assertSee('1.366 stanovnika prema Popisu 2013.', false)
            ->assertSee('"@type":"Place"', false)
            ->assertDontSee('GeoCoordinates', false);
    }

    public function test_crawler_keeps_njegosevo_zero_population(): void
    {
        $this->withHeader('X-Social-Preview', '1')
            ->get('/mjesta/samac/njegosevo')
            ->assertOk()
            ->assertSee('0 stanovnika prema Popisu 2013.', false);
    }

    public function test_crawler_404_for_cross_resolved_settlement(): void
    {
        $this->withHeader('X-Social-Preview', '1')
            ->get('/mjesta/samac/milosevac')
            ->assertNotFound();
    }

    public function test_settlements_sitemap_includes_pilot_pages_only(): void
    {
        $this->get('/sitemap-settlements.xml')
            ->assertOk()
            ->assertSee('<loc>https://milosevac.com/mjesta</loc>', false)
            ->assertSee('<loc>https://milosevac.com/mjesta/modrica</loc>', false)
            ->assertSee('<loc>https://milosevac.com/mjesta/samac</loc>', false)
            ->assertSee('<loc>https://milosevac.com/mjesta/modrica/milosevac</loc>', false)
            ->assertSee('<loc>https://milosevac.com/mjesta/samac/njegosevo</loc>', false)
            ->assertDontSee('/mjesta/brcko', false);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('sitemap-settlements.xml', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /sitemap-settlements.xml', false);
    }
}
