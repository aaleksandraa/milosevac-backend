<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\FootballMatch;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FrontendRedirectController extends Controller
{
    public function home(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/', Seo::page(
            'Najnovije vijesti',
            'Lokalne vijesti, servisne informacije i priče iz Miloševca.'
        ));
    }

    public function about(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/omilosevcu', Seo::page(
            'O Miloševcu',
            'Istorija, život, ljudi, fotografije i lokalne priče iz Miloševca.',
            route('about-milosevac')
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
            route('search')
        )));
    }

    public function fkPosavina(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/fk-posavina', Seo::page(
            'FK Posavina',
            'Rezultati, raspored utakmica, vijesti i galerije FK Posavina Miloševac.',
            route('fk-posavina')
        ));
    }

    public function match(Request $request, string $match): RedirectResponse|Response
    {
        if (! $this->wantsSocialPreview($request)) {
            return $this->to('/fk-posavina/utakmica/'.$match);
        }

        $footballMatch = FootballMatch::published()->where('slug', $match)->firstOrFail();

        return $this->preview(Seo::page(
            $footballMatch->meta_title ?: $footballMatch->title,
            $footballMatch->meta_description ?: $footballMatch->excerpt,
            route('matches.show', $footballMatch->slug),
            Seo::storageImage($footballMatch->cover_image)
        ));
    }

    public function weather(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/vrijeme', Seo::page(
            'Vrijeme u Miloševcu',
            'Trenutno vrijeme i vremenska prognoza za Miloševac.',
            route('weather.show')
        ));
    }

    public function contact(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/kontakt', Seo::page(
            'Kontakt',
            'Pošaljite vijest, fotografiju, obavještenje ili prijedlog redakciji portala Miloševac.',
            route('contact')
        ));
    }

    public function privacy(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/politika-privatnosti', Seo::page(
            'Politika privatnosti',
            'Kako portal Miloševac prikuplja, koristi i štiti podatke posjetilaca.',
            route('privacy')
        ));
    }

    public function cookies(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/politika-kolacica', Seo::page(
            'Politika kolačića',
            'Informacije o kolačićima i postavkama privatnosti na portalu Miloševac.',
            route('cookies')
        ));
    }

    public function terms(Request $request): RedirectResponse|Response
    {
        return $this->respond($request, '/uslovi-koristenja', Seo::page(
            'Uslovi korištenja',
            'Pravila korištenja sadržaja i usluga portala Miloševac.',
            route('terms')
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
        return $request->header('X-Social-Preview') === '1';
    }

    private function to(string $path, ?Request $request = null, array $query = []): RedirectResponse
    {
        $url = rtrim(config('services.frontend.url'), '/').'/'.ltrim($path, '/');
        $query = array_filter(array_merge($request?->query() ?? [], $query), fn ($value) => $value !== null && $value !== '');

        return redirect()->away($query ? $url.'?'.http_build_query($query) : $url);
    }
}
