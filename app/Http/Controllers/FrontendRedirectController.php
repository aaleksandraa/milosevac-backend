<?php

namespace App\Http\Controllers;

use App\Enums\RankingScope;
use App\Models\Category;
use App\Models\FootballMatch;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\Atlas\AtlasQueryService;
use App\Support\Atlas\Metrics\RankingDatasetVersion;
use App\Support\Atlas\Metrics\SettlementComparisonService;
use App\Support\Atlas\Metrics\SettlementMetricRegistry;
use App\Support\Atlas\Metrics\SettlementRankingService;
use App\Support\Atlas\PopulationSelector;
use App\Support\Seo;
use App\Support\SocialPreviewDetector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FrontendRedirectController extends Controller
{
    public function home(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/', Seo::page(
            'Najnovije vijesti',
            'Lokalne vijesti, servisne informacije i priče iz Miloševca.',
            Seo::frontendUrl('/'),
        ));
    }

    public function about(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/omilosevcu', Seo::page(
            'O Miloševcu',
            'Istorija, život, ljudi, fotografije i lokalne priče iz Miloševca.',
            Seo::frontendUrl('omilosevcu'),
        ));
    }

    public function post(Request $request, string $slug): RedirectResponse|Response
    {
        if (! $this->wantsSocialPreview($request)) {
            return $this->to('/clanak/'.$slug);
        }

        $post = Post::published()->with(['category', 'author', 'tags'])->where('slug', $slug)->firstOrFail();

        return $this->preview(Seo::post($post));
    }

    public function category(Request $request, string $category): RedirectResponse|Response
    {
        if (! $this->wantsSocialPreview($request)) {
            return $this->to('/kategorija/'.$category, $request);
        }

        return $this->preview(Seo::category(Category::where('slug', $category)->firstOrFail()));
    }

    public function tag(Request $request, string $tag): RedirectResponse|Response
    {
        if (! $this->wantsSocialPreview($request)) {
            return $this->to('/kategorija/vijesti', $request, ['tag' => $tag]);
        }

        return $this->preview(Seo::tag(Tag::where('slug', $tag)->firstOrFail()));
    }

    public function author(Request $request, string $author): RedirectResponse|Response
    {
        if (! $this->wantsSocialPreview($request)) {
            return $this->to('/');
        }

        return $this->preview(Seo::author(User::where('slug', $author)->firstOrFail()));
    }

    public function search(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/', Seo::noindex(Seo::page(
            'Pretraga',
            'Pretražite vijesti i članke portala Miloševac.',
            Seo::frontendUrl('/'),
        )));
    }

    public function fkPosavina(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/fk-posavina', Seo::page(
            'FK Posavina',
            'Rezultati, raspored utakmica, vijesti i galerije FK Posavina Miloševac.',
            Seo::frontendUrl('fk-posavina'),
        ));
    }

    public function match(Request $request, string $match): RedirectResponse|Response
    {
        if (! $this->wantsSocialPreview($request)) {
            return $this->to('/fk-posavina/utakmica/'.$match);
        }

        $footballMatch = FootballMatch::published()->where('slug', $match)->firstOrFail();

        return $this->preview(Seo::footballMatch($footballMatch));
    }

    public function weather(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/vrijeme', Seo::page(
            'Vrijeme u Miloševcu',
            'Trenutno vrijeme i vremenska prognoza za Miloševac.',
            Seo::frontendUrl('vrijeme'),
        ));
    }

    public function powerOutages(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/prekidi-struje', Seo::page(
            'Prekidi struje',
            'Planirani prekidi električne energije u Miloševcu i okolini.',
            Seo::frontendUrl('prekidi-struje'),
        ));
    }

    public function contact(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/kontakt', Seo::page(
            'Kontakt',
            'Pošaljite vijest, fotografiju, obavještenje ili prijedlog redakciji portala Miloševac.',
            Seo::frontendUrl('kontakt'),
        ));
    }

    public function privacy(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/politika-privatnosti', Seo::page(
            'Politika privatnosti',
            'Kako portal Miloševac prikuplja, koristi i štiti podatke posjetilaca.',
            Seo::frontendUrl('politika-privatnosti'),
        ));
    }

    public function cookies(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/politika-kolacica', Seo::page(
            'Politika kolačića',
            'Informacije o kolačićima i postavkama privatnosti na portalu Miloševac.',
            Seo::frontendUrl('politika-kolacica'),
        ));
    }

    public function terms(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/uslovi-koristenja', Seo::page(
            'Uslovi korištenja',
            'Pravila korištenja sadržaja i usluga portala Miloševac.',
            Seo::frontendUrl('uslovi-koristenja'),
        ));
    }

    public function atlasIndex(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/mjesta', Seo::atlasIndex());
    }

    public function atlasMunicipality(Request $request, string $municipality): RedirectResponse|Response
    {
        $path = '/mjesta/'.$municipality;
        if (! $this->wantsSocialPreview($request)) {
            return $this->to($path);
        }

        $row = app(AtlasQueryService::class)->municipalityBySlug($municipality);
        abort_if($row === null, 404);

        $population = null;
        if ($row->relationLoaded('settlements')) {
            $selector = new PopulationSelector;
            $rows = $row->settlements
                ->map(fn ($settlement) => $selector->preferredForYear($settlement->populationRecords, 2013))
                ->filter();
            if ($rows->isNotEmpty()) {
                $population = (int) $rows->sum('population');
            }
        }

        return $this->preview(Seo::atlasMunicipality(
            $row,
            (int) ($row->settlements_count ?? $row->settlements->count()),
            $population,
        ));
    }

    public function atlasSettlement(Request $request, string $municipality, string $settlement): RedirectResponse|Response
    {
        $path = '/mjesta/'.$municipality.'/'.$settlement;
        if (! $this->wantsSocialPreview($request)) {
            return $this->to($path);
        }

        $row = app(AtlasQueryService::class)->settlementInMunicipality($municipality, $settlement);
        abort_if($row === null, 404);

        return $this->preview(Seo::atlasSettlement($row));
    }

    public function atlasRankings(Request $request): RedirectResponse|Response
    {
        $path = '/rang-liste';
        if (! $this->wantsSocialPreview($request)) {
            return $this->to($path, $request);
        }

        $metricCode = is_string($request->query('metric')) ? $request->query('metric') : 'population_2013';
        $rankings = app(SettlementRankingService::class);
        $registry = app(SettlementMetricRegistry::class);
        $metric = $registry->find($metricCode);
        $label = $metric?->highRankingLabel ?? 'Rang-liste naselja';
        $rows = [];
        $scopeNote = null;
        if ($metric?->rankable) {
            $table = $rankings->table(
                $metric->code,
                RankingScope::Pilot,
                $metric->defaultDirection,
            );
            $scopeNote = app(RankingDatasetVersion::class)->universeNote(
                $table->scope,
                $table->municipality,
                $table->universe->map(fn ($settlement) => $settlement->municipality)->filter()->unique('id')->values(),
            );
            $rows = $table->entries->take(max($table->eligible, 0))->map(fn ($entry) => [
                'rank' => $entry->rank,
                'name' => $entry->settlement->name,
                'municipality' => $entry->settlement->municipality?->name,
                'value' => $entry->value,
                'unit' => $metric->unit,
            ])->all();
        }

        return $this->preview(Seo::atlasRankings($label, $rows, $scopeNote));
    }

    public function atlasCompare(
        Request $request,
        string $municipalityA,
        string $settlementA,
        string $municipalityB,
        string $settlementB,
    ): RedirectResponse|Response {
        $path = '/uporedi/'.$municipalityA.'/'.$settlementA.'/'.$municipalityB.'/'.$settlementB;
        $atlas = app(AtlasQueryService::class);
        $left = $atlas->settlementInMunicipality($municipalityA, $settlementA);
        $right = $atlas->settlementInMunicipality($municipalityB, $settlementB);
        abort_if($left === null || $right === null, 404);

        if (! $this->wantsSocialPreview($request)) {
            return $this->to($path);
        }

        $compared = app(SettlementComparisonService::class)->compare($left, $right);
        $rows = [];
        foreach ($compared['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $rows[] = [
                    'label' => $row['label'],
                    'a' => $row['a']['value'],
                    'b' => $row['b']['value'],
                    'unit' => $row['unit'],
                ];
            }
        }

        return $this->preview(Seo::atlasCompare(
            $path,
            $left->name,
            $left->municipality?->name ?? $municipalityA,
            $right->name,
            $right->municipality?->name ?? $municipalityB,
            $rows,
        ));
    }

    private function respond(Request $request, string $path, array $seo): RedirectResponse|Response
    {
        return $this->wantsSocialPreview($request) ? $this->preview($seo) : $this->to($path, $request);
    }

    private function preview(array $seo): Response
    {
        return response()
            ->view('frontend.social-preview', compact('seo'))
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=3600');
    }

    private function wantsSocialPreview(Request $request): bool
    {
        return SocialPreviewDetector::isSocialCrawler($request);
    }

    private function to(string $path, ?Request $request = null, array $query = []): RedirectResponse
    {
        $url = rtrim(config('services.frontend.url'), '/').'/'.ltrim($path, '/');
        $query = array_filter(array_merge($request?->query() ?? [], $query), fn ($value) => $value !== null && $value !== '');

        return redirect()->away($query ? $url.'?'.http_build_query($query) : $url);
    }
}
