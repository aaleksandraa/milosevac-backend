<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\FootballMatch;
use App\Models\Media;
use App\Models\Post;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Support\HtmlSanitizer;
use App\Support\ImagePipeline;
use App\Support\DateFormat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function dashboard()
    {
        return view('admin.dashboard', [
            'postsCount' => Post::count(),
            'publishedCount' => Post::where('status', 'published')->count(),
            'pendingCount' => Post::where('status', 'pending_review')->count(),
            'viewsCount' => Post::sum('views_count'),
            'popular' => Post::with(['author', 'category'])->orderByDesc('views_count')->take(8)->get(),
            'latest' => Post::with(['author', 'category'])->latest()->take(8)->get(),
        ]);
    }

    public function posts()
    {
        return view('admin.posts.index', ['posts' => Post::with(['author', 'category'])->latest()->paginate(20)]);
    }

    public function createPost()
    {
        return view('admin.posts.form', $this->postFormData(new Post(['status' => 'draft'])));
    }

    public function storePost(Request $request)
    {
        $post = new Post();
        $this->savePost($request, $post);

        return redirect()->route('admin.posts.edit', $post)->with('status', 'Članak je sačuvan.');
    }

    public function editPost(Post $post)
    {
        return view('admin.posts.form', $this->postFormData($post));
    }

    public function updatePost(Request $request, Post $post)
    {
        $this->savePost($request, $post);

        return back()->with('status', 'Članak je ažuriran.');
    }

    public function matches()
    {
        return view('admin.matches.index', [
            'matches' => FootballMatch::with(['author', 'media'])->latest('played_at')->paginate(20),
            'watermark' => Setting::where('key', 'gallery_watermark')->first()?->value ?? [],
        ]);
    }

    public function createMatch()
    {
        return view('admin.matches.form', $this->matchFormData(new FootballMatch([
            'status' => 'draft',
            'home_team' => 'FK Posavina',
        ])));
    }

    public function storeMatch(Request $request)
    {
        $match = new FootballMatch();
        $this->saveMatch($request, $match);

        return redirect()->route('admin.matches.edit', $match)->with('status', 'Utakmica je sačuvana.');
    }

    public function editMatch(FootballMatch $match)
    {
        return view('admin.matches.form', $this->matchFormData($match->load('media')));
    }

    public function updateMatch(Request $request, FootballMatch $match)
    {
        $this->saveMatch($request, $match);

        return back()->with('status', 'Utakmica je ažurirana.');
    }

    public function updateWatermark(Request $request)
    {
        $data = $request->validate([
            'watermark_logo' => ['nullable', 'image', 'mimes:png', 'max:4096'],
            'opacity' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $settings = Setting::where('key', 'gallery_watermark')->first()?->value ?? [];
        if ($request->hasFile('watermark_logo')) {
            $settings['path'] = $request->file('watermark_logo')->store('settings/watermarks', 'public');
        }
        $settings['opacity'] = (int) $data['opacity'];

        Setting::updateOrCreate(['key' => 'gallery_watermark'], ['value' => $settings]);

        return back()->with('status', 'Watermark postavke su sačuvane.');
    }

    public function ads()
    {
        return view('admin.ads', [
            'settings' => Setting::where('key', 'ad_settings')->first()?->value ?? $this->defaultAdSettings(),
            'positions' => $this->adPositions(),
        ]);
    }

    public function updateAds(Request $request)
    {
        $request->validate([
            'google.enabled' => ['nullable', 'boolean'],
            'google.client_id' => ['nullable', 'string', 'max:120', 'regex:/^ca-pub-\d{12,24}$/'],
            'slots' => ['array'],
            'slots.*.enabled' => ['nullable', 'boolean'],
        ]);

        $settings = [
            'google' => [
                'enabled' => $request->boolean('google.enabled'),
                'client_id' => trim((string) $request->input('google.client_id', 'ca-pub-1407310093643341')),
            ],
            'slots' => [],
        ];

        foreach ($this->adPositions() as $key => $label) {
            $slot = $request->input("slots.{$key}", []);
            $settings['slots'][$key] = [
                'label' => $label,
                'enabled' => (bool) ($slot['enabled'] ?? false),
            ];
        }

        Setting::updateOrCreate(['key' => 'ad_settings'], ['value' => $settings]);
        cache()->forget('settings.ads');
        cache()->forget('settings.ads.public');
        cache()->forget('settings.ads.bootstrap');

        return back()->with('status', 'Oglasne postavke su sačuvane.');
    }

    public function categories()
    {
        return view('admin.taxonomy.categories', [
            'categories' => Category::with('parent')->orderBy('sort_order')->get(),
            'parents' => Category::orderBy('name')->get(),
        ]);
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:categories,slug'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
        ]);
        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
        Category::create($data);

        return back()->with('status', 'Kategorija je dodana.');
    }

    public function tags()
    {
        return view('admin.taxonomy.tags', ['tags' => Tag::orderBy('name')->get()]);
    }

    public function storeTag(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:tags,slug'],
            'description' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
        ]);
        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
        Tag::create($data);

        return back()->with('status', 'Tag je dodan.');
    }

    public function users()
    {
        return view('admin.users', [
            'users' => User::with('role')->latest()->get(),
            'roles' => Role::orderBy('label')->get(),
        ]);
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role_id' => ['required', 'exists:roles,id'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        $data['slug'] = Str::slug($data['name']);
        $data['password'] = Hash::make($data['password']);
        User::create($data);

        return back()->with('status', 'Korisnik je kreiran.');
    }

    private function postFormData(Post $post): array
    {
        return [
            'post' => $post,
            'categories' => Category::orderBy('name')->get(),
            'tags' => Tag::orderBy('name')->get(),
            'authors' => User::with('role')->orderBy('name')->get(),
        ];
    }

    private function matchFormData(FootballMatch $match): array
    {
        return [
            'match' => $match,
            'authors' => User::with('role')->orderBy('name')->get(),
            'watermark' => Setting::where('key', 'gallery_watermark')->first()?->value ?? [],
        ];
    }

    private function adPositions(): array
    {
        return [
            'top_banner' => 'Top banner ispod navigacije',
            'home_after_featured' => 'Naslovna poslije izdvojenih vijesti',
            'home_mid_feed' => 'Naslovna između liste vijesti',
            'archive_top' => 'Arhive/kategorije ispod zaglavlja',
            'archive_mid_feed' => 'Arhive/kategorije između vijesti',
            'sidebar_primary' => 'Sidebar 300x250',
            'sidebar_secondary' => 'Sidebar manji/native oglas',
            'article_inline' => 'Unutar članka / utakmice',
            'article_top' => 'Članak ispod uvoda',
            'article_mid' => 'Sredina članka',
            'club_after_results' => 'FK Posavina poslije rezultata',
            'match_gallery_top' => 'Utakmica iznad galerije',
            'match_mid' => 'Sredina izvještaja utakmice',
            'footer_banner' => 'Banner iznad footera',
        ];
    }

    private function defaultAdSettings(): array
    {
        return [
            'google' => ['enabled' => false, 'client_id' => 'ca-pub-1407310093643341'],
            'slots' => collect($this->adPositions())->mapWithKeys(fn ($label, $key) => [$key => [
                'label' => $label,
                'enabled' => false,
            ]])->all(),
        ];
    }

    private function savePost(Request $request, Post $post): void
    {
        DateFormat::normalizeRequest($request, ['published_at', 'scheduled_at', 'notice_starts_at', 'notice_ends_at']);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('posts', 'slug')->ignore($post)],
            'excerpt' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'category_id' => ['required', 'exists:categories,id'],
            'author_id' => ['required', 'exists:users,id'],
            'status' => ['required', Rule::in(['draft', 'pending_review', 'published', 'scheduled', 'archived'])],
            'published_at' => ['nullable', 'date'],
            'scheduled_at' => ['nullable', 'date'],
            'label' => ['nullable', Rule::in(['hitno', 'obavijest', 'info', 'najava'])],
            'service_type' => ['nullable', Rule::in(['power_outage'])],
            'notice_starts_at' => ['nullable', 'date'],
            'notice_ends_at' => [Rule::requiredIf($request->input('service_type') === 'power_outage'), 'nullable', 'date', 'after_or_equal:notice_starts_at'],
            'notice_schedule' => [Rule::requiredIf($request->input('service_type') === 'power_outage'), 'nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'canonical_url' => ['nullable', 'url'],
            'featured_image_alt' => ['nullable', 'string', 'max:255'],
            'featured_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'og_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'tags' => ['array'],
            'tags.*' => ['exists:tags,id'],
        ]);

        $data['content'] = app(HtmlSanitizer::class)->clean($data['content']);

        $processedImagePaths = [];
        $imagePipeline = app(ImagePipeline::class);
        foreach (['featured_image', 'og_image'] as $field) {
            if ($request->hasFile($field)) {
                $processed = $imagePipeline->process($request->file($field), $request->user()?->id, $post->exists ? $post->id : null);
                $data[$field] = $field === 'og_image'
                    ? ($imagePipeline->socialImage($processed['path'], true) ?: $processed['path'])
                    : $processed['path'];
                $data[$field.'_responsive'] = $processed['responsive'];
                $processedImagePaths[] = $processed['path'];
            } else {
                unset($data[$field]);
                unset($data[$field.'_responsive']);
            }
        }
        if ($request->hasFile('featured_image')
            && ! $request->hasFile('og_image')
            && (! $post->og_image || Str::endsWith($post->og_image, '-social.jpg'))) {
            $data['og_image'] = $imagePipeline->socialImage($data['featured_image'], true);
        }

        $data['slug'] = $data['slug'] ?: Str::slug($data['title']);
        $data['service_type'] = $data['service_type'] ?: null;
        if ($data['service_type'] === 'power_outage') {
            $data['label'] = 'obavijest';
            $data['featured_image_alt'] = $data['featured_image_alt'] ?: 'Planirani prekid isporuke električne energije';
        } else {
            $data['notice_starts_at'] = null;
            $data['notice_ends_at'] = null;
            $data['notice_schedule'] = null;
        }
        $data['is_featured'] = $request->boolean('is_featured');
        $data['is_breaking'] = $request->boolean('is_breaking');
        $data['published_at'] = $data['status'] === 'published' ? ($data['published_at'] ?: now()) : $data['published_at'];

        $post->fill($data)->save();
        Media::whereIn('path', $processedImagePaths)->update(['post_id' => $post->id]);
        $post->tags()->sync($data['tags'] ?? []);
        cache()->forget('posts.popular');
    }

    private function saveMatch(Request $request, FootballMatch $match): void
    {
        DateFormat::normalizeRequest($request, ['played_at', 'published_at', 'scheduled_at']);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('matches', 'slug')->ignore($match)],
            'home_team' => ['required', 'string', 'max:255'],
            'away_team' => ['required', 'string', 'max:255'],
            'home_score' => ['nullable', 'integer', 'min:0', 'max:99'],
            'away_score' => ['nullable', 'integer', 'min:0', 'max:99'],
            'played_at' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'author_id' => ['required', 'exists:users,id'],
            'status' => ['required', Rule::in(['draft', 'pending_review', 'published', 'scheduled', 'archived'])],
            'published_at' => ['nullable', 'date'],
            'scheduled_at' => ['nullable', 'date'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'gallery_order' => ['array'],
            'gallery_order.*' => ['integer', 'exists:media,id'],
            'gallery_captions' => ['array'],
            'gallery_captions.*' => ['nullable', 'string', 'max:255'],
            'delete_gallery' => ['array'],
            'delete_gallery.*' => ['integer', 'exists:media,id'],
            'gallery_images' => ['array'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $data['content'] = app(HtmlSanitizer::class)->clean((string) ($data['content'] ?? ''));
        $data['slug'] = $data['slug'] ?: Str::slug($data['title']);
        $data['published_at'] = $data['status'] === 'published' ? ($data['published_at'] ?: now()) : $data['published_at'];
        unset($data['gallery_order'], $data['gallery_captions'], $data['delete_gallery'], $data['gallery_images']);

        if ($request->hasFile('cover_image')) {
            $imagePipeline = app(ImagePipeline::class);
            $processed = $imagePipeline->process($request->file('cover_image'), $request->user()?->id, null, 'matches', true);
            $imagePipeline->socialImage($processed['path'], true);
            $data['cover_image'] = $processed['path'];
            $data['cover_image_responsive'] = $processed['responsive'];
        } else {
            unset($data['cover_image'], $data['cover_image_responsive']);
        }

        $match->fill($data)->save();
        $this->syncMatchGallery($request, $match);
    }

    private function syncMatchGallery(Request $request, FootballMatch $match): void
    {
        $deleteIds = collect($request->input('delete_gallery', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($deleteIds->isNotEmpty()) {
            $mediaToDelete = $match->media()
                ->whereIn('media.id', $deleteIds)
                ->get();

            $match->media()->detach($mediaToDelete->pluck('id'));
            $mediaToDelete->each(fn (Media $media) => $this->deleteMediaIfUnused($media));
        }

        $attachedIds = $match->media()
            ->pluck('media.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $orderedIds = collect($request->input('gallery_order', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => in_array($id, $attachedIds, true))
            ->unique()
            ->values();

        $sort = 0;
        foreach ($orderedIds as $mediaId) {
            $match->media()->updateExistingPivot($mediaId, ['sort_order' => ++$sort]);
        }

        foreach (collect($attachedIds)->diff($orderedIds) as $mediaId) {
            $match->media()->updateExistingPivot($mediaId, ['sort_order' => ++$sort]);
        }

        foreach ($request->input('gallery_captions', []) as $mediaId => $caption) {
            $mediaId = (int) $mediaId;
            if (in_array($mediaId, $attachedIds, true)) {
                $match->media()->updateExistingPivot($mediaId, [
                    'caption' => trim((string) $caption) ?: null,
                ]);
            }
        }

        foreach ($request->file('gallery_images', []) as $image) {
            $processed = app(ImagePipeline::class)->process($image, $request->user()?->id, null, 'matches/galleries', true);
            $media = Media::where('path', $processed['path'])->latest('id')->first();
            if ($media) {
                $match->media()->attach($media->id, ['sort_order' => ++$sort]);
            }
        }
    }

    private function deleteMediaIfUnused(Media $media): void
    {
        $isUsedByMatch = DB::table('match_media')->where('media_id', $media->id)->exists();
        if ($isUsedByMatch || $media->post_id) {
            return;
        }

        $paths = collect($media->responsive_paths['variants'] ?? [])
            ->pluck('path')
            ->push($media->responsive_paths['original'] ?? null)
            ->push($media->path)
            ->filter()
            ->unique()
            ->all();

        Storage::disk($media->disk ?: 'public')->delete($paths);
        $media->delete();
    }
}
