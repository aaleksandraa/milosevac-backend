<?php

namespace Tests\Unit;

use App\Support\PowerOutage\ArticleWriter;
use App\Support\PowerOutage\CyrillicLatin;
use App\Support\PowerOutage\NoticeParser;
use App\Support\PowerOutage\PlaceMatcher;
use App\Support\PowerOutage\PlaceNormalizer;
use Database\Seeders\PlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PowerOutageParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlaceSeeder::class);
    }

    public function test_parses_dobrinja_fixture(): void
    {
        $body = file_get_contents(base_path('tests/fixtures/power-outage-dobrinja.txt'));
        $notice = (new NoticeParser())->parse($body);

        $this->assertSame('15175', $notice->externalId);
        $this->assertSame('2026-08-31', $notice->date->format('Y-m-d'));
        $this->assertSame('09:00', $notice->startsAt);
        $this->assertSame('11:00', $notice->endsAt);
        $this->assertSame('Modriča', $notice->fieldUnit);
        $this->assertStringContainsString('Dobrinja', $notice->locationsRaw);
        $this->assertSame('DOBRINjA 1', $notice->transformerArea);
        $this->assertSame(
            'http://elektrodoboj.net/UsluzniCentar/PrekidNapajanja?id=15175',
            $notice->sourceUrl,
        );

        $places = (new PlaceMatcher())->match($notice);
        $this->assertCount(2, $places);
        $this->assertSame('dobrinja', $places[0]->slug);

        $title = (new ArticleWriter())->title($notice, $places);
        $this->assertSame('Planirani prekid struje u Dobrinji 31. avgusta', $title);
    }

    public function test_parses_modrica_streets_fixture(): void
    {
        $body = file_get_contents(base_path('tests/fixtures/power-outage-modrica-streets.txt'));
        $notice = (new NoticeParser())->parse($body);

        $this->assertSame('15143', $notice->externalId);
        $this->assertSame('2026-08-25', $notice->date->format('Y-m-d'));
        $this->assertStringContainsString('Vasilija Ostroškog', $notice->locationsRaw);

        $places = (new PlaceMatcher())->match($notice);
        $this->assertCount(1, $places);
        $this->assertSame('modrica', $places[0]->slug);
    }

    public function test_parses_vranjak_fixture_and_prefers_settlement_in_title(): void
    {
        $body = file_get_contents(base_path('tests/fixtures/power-outage-vranjak.txt'));
        $notice = (new NoticeParser())->parse($body);

        $this->assertSame('15169', $notice->externalId);
        $this->assertStringContainsString('Vranjak', $notice->locationsRaw);
        $this->assertSame('Modriča', $notice->fieldUnit);

        $places = (new PlaceMatcher())->match($notice);
        $slugs = collect($places)->pluck('slug')->all();
        $this->assertContains('vranjak', $slugs);
        $this->assertContains('modrica', $slugs);

        $title = (new ArticleWriter())->title($notice, $places);
        $this->assertSame('Planirani prekid struje u Vranjku 28. avgusta', $title);
    }

    public function test_place_normalizer_matches_mixed_scripts(): void
    {
        $normalizer = new PlaceNormalizer(new CyrillicLatin());

        $this->assertSame('dobrinja', $normalizer->matchKey('DOBRINjA 1'));
        $this->assertSame('dobrinja', $normalizer->matchKey('Dobrinja'));
        $this->assertSame('dobrinja', $normalizer->matchKey('ДОБРИЊА'));
    }

    public function test_cyrillic_latin_preserves_existing_latin(): void
    {
        $latin = (new CyrillicLatin())->transliterate('DOBRINjA 1 Modriča');

        $this->assertSame('DOBRINjA 1 Modriča', $latin);
    }
}
