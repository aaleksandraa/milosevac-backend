<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementClimateProfile;
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
            ->assertDontSee('GeoCoordinates', false)
            ->assertDontSee('ClimateForecast', false);
    }

    public function test_crawler_adds_climate_sentence_without_inventing_schema(): void
    {
        $settlement = GeoSettlement::query()
            ->where('slug', 'milosevac')
            ->whereHas('municipality', fn ($query) => $query->where('slug', 'modrica'))
            ->firstOrFail();

        SettlementClimateProfile::factory()->create([
            'settlement_id' => $settlement->id,
            'source_id' => DataSource::query()->where('source_type', 'copernicus_era5_land')->value('id'),
            'boundary_id' => null,
            'temperature_mean_annual_c' => 11.6,
            'precipitation_annual_mm' => 780,
        ]);

        $this->withHeader('X-Social-Preview', '1')
            ->get('/mjesta/modrica/milosevac')
            ->assertOk()
            ->assertSee('Klima prema ERA5-Land reanalizi 1991–2020', false)
            ->assertSee('11,6 °C', false)
            ->assertSee('"@type":"Place"', false)
            ->assertDontSee('ClimateForecast', false)
            ->assertDontSee('izmjerena', false);
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

        $this->get('/sitemap-pages.xml')
            ->assertOk()
            ->assertSee('<loc>https://milosevac.com/rang-liste</loc>', false)
            ->assertDontSee('/uporedi/', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /sitemap-settlements.xml', false);
    }

    public function test_rankings_page_is_indexable_with_real_rows(): void
    {
        $this->withHeader('X-Social-Preview', '1')
            ->get('/rang-liste')
            ->assertOk()
            ->assertSee('Rang-liste', false)
            ->assertSee('trenutno obrađen', false)
            ->assertSee('Miloševac', false)
            ->assertDontSee('najniže naselje Bosne', false)
            ->assertSee('<table>', false);
    }

    public function test_compare_pages_are_noindex_and_not_cross_resolved(): void
    {
        $this->withHeader('X-Social-Preview', '1')
            ->get('/uporedi/modrica/milosevac/samac/samac')
            ->assertOk()
            ->assertSee('noindex, follow', false)
            ->assertSee('Miloševac', false)
            ->assertSee('Šamac', false)
            ->assertDontSee('/uporedi/milosevac/samac', false);

        $this->withHeader('X-Social-Preview', '1')
            ->get('/uporedi/samac/milosevac/modrica/modrica')
            ->assertNotFound();
    }
}
