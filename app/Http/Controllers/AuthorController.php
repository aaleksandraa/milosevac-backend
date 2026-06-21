<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\FootballMatch;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Support\HtmlSanitizer;
use App\Support\ImagePipeline;
use App\Support\DateFormat;
use App\Support\PostMediaManager;
use App\Support\PostTaxonomyManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthorController extends Controller
{
    public function dashboard(Request $request)
    {
        return view('author.dashboard', [
            'posts' => $request->user()->posts()->with('category')->latest()->paginate(15),
            'matches' => $request->user()->footballMatches()->with('media')->latest('played_at')->take(10)->get(),
        ]);
    }

    public function create()
    {
        $status = auth()->user()?->canPublishDirectly() ? 'published' : 'pending_review';

        return view('author.form', $this->formData(new Post(['status' => $status])));
    }

    public function store(Request $request)
    {
        $post = new Post(['author_id' => $request->user()->id]);
        $this->save($request, $post);

        return redirect()->route('author.posts.edit', $post)->with('status', 'Draft je sačuvan.');
    }

    public function edit(Request $request, Post $post)
    {
        abort_unless($post->author_id === $request->user()->id, 403);

        return view('author.form', $this->formData($post->load('galleryMedia')));
    }

    public function update(Request $request, Post $post)
    {
        abort_unless($post->author_id === $request->user()->id, 403);
        $this->save($request, $post);

        return back()->with('status', 'Članak je ažuriran.');
    }

    public function createMatch()
    {
        return view('author.matches.form', $this->matchFormData(new FootballMatch([
            'status' => 'draft',
            'home_team' => 'FK Posavina',
        ])));
    }

    public function storeMatch(Request $request)
    {
        $match = new FootballMatch(['author_id' => $request->user()->id]);
        $this->saveMatch($request, $match);

        return redirect()->route('author.matches.edit', $match)->with('status', 'Utakmica je sačuvana.');
    }

    public function editMatch(Request $request, FootballMatch $match)
    {
        abort_unless($match->author_id === $request->user()->id, 403);

        return view('author.matches.form', $this->matchFormData($match->load('media')));
    }

    public function updateMatch(Request $request, FootballMatch $match)
    {
        abort_unless($match->author_id === $request->user()->id, 403);
        $this->saveMatch($request, $match);

        return back()->with('status', 'Utakmica je ažurirana.');
    }

    private function formData(Post $post): array
    {
        return [
            'post' => $post,
            'categories' => Category::where('is_active', true)->orderBy('name')->get(),
            'tags' => Tag::orderBy('name')->get(),
        ];
    }

    private function matchFormData(FootballMatch $match): array
    {
        return ['match' => $match];
    }

    private function save(Request $request, Post $post): void
    {
        DateFormat::normalizeRequest($request, ['published_at', 'scheduled_at', 'notice_starts_at', 'notice_ends_at']);

        $allowedStatus = $request->user()->canPublishDirectly()
            ? ['draft', 'pending_review', 'published', 'scheduled']
            : ['draft', 'pending_review'];

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('posts', 'slug')->ignore($post)],
            'excerpt' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['integer', 'exists:categories,id'],
            'status' => ['required', Rule::in($allowedStatus)],
            'published_at' => ['nullable', 'date'],
            'scheduled_at' => ['nullable', 'date'],
            'label' => ['nullable', Rule::in(['hitno', 'obavijest', 'info', 'najava'])],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'featured_image_alt' => ['nullable', 'string', 'max:255'],
            'featured_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'content_images' => ['array'],
            'content_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
            'gallery_order' => ['array'],
            'gallery_order.*' => ['integer', 'exists:media,id'],
            'gallery_captions' => ['array'],
            'gallery_captions.*' => ['nullable', 'string', 'max:255'],
            'delete_gallery' => ['array'],
            'delete_gallery.*' => ['integer', 'exists:media,id'],
            'gallery_images' => ['array'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
            'tags_text' => ['nullable', 'string'],
        ]);

        $data['content'] = app(HtmlSanitizer::class)->clean($data['content']);
        $categoryIds = $data['categories'];
        $tagsText = $data['tags_text'] ?? '';
        $data['category_id'] = (int) $categoryIds[0];
        unset($data['categories'], $data['tags_text'], $data['content_images'], $data['gallery_order'], $data['gallery_captions'], $data['delete_gallery'], $data['gallery_images']);

        $processedImagePaths = [];
        if ($request->hasFile('featured_image')) {
            $imagePipeline = app(ImagePipeline::class);
            $processed = $imagePipeline->process($request->file('featured_image'), $request->user()->id, $post->exists ? $post->id : null);
            $data['featured_image'] = $processed['path'];
            $data['featured_image_responsive'] = $processed['responsive'];
            $data['og_image'] = $imagePipeline->socialImage($processed['path'], true);
            $processedImagePaths[] = $processed['path'];
        }

        $data['slug'] = $data['slug'] ?: Str::slug($data['title']);
        $data['service_type'] = null;
        $data['notice_starts_at'] = null;
        $data['notice_ends_at'] = null;
        $data['notice_schedule'] = null;
        $data['author_id'] = $post->author_id ?: $request->user()->id;
        $data['published_at'] = $data['status'] === 'published' ? ($data['published_at'] ?: now()) : ($data['published_at'] ?? $post->published_at);
        $post->fill($data)->save();
        Media::whereIn('path', $processedImagePaths)->update(['post_id' => $post->id]);
        app(PostMediaManager::class)->appendContentImages($request, $post);
        app(PostMediaManager::class)->syncGallery($request, $post);
        app(PostTaxonomyManager::class)->syncCategories($post, $categoryIds);
        app(PostTaxonomyManager::class)->syncTagsFromText($post, $tagsText);
    }

    private function saveMatch(Request $request, FootballMatch $match): void
    {
        DateFormat::normalizeRequest($request, ['played_at']);

        $allowedStatus = $request->user()->canPublishDirectly()
            ? ['draft', 'pending_review', 'published', 'scheduled']
            : ['draft', 'pending_review'];

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
            'status' => ['required', Rule::in($allowedStatus)],
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
        $data['author_id'] = $match->author_id ?: $request->user()->id;
        $data['published_at'] = $data['status'] === 'published' ? now() : $match->published_at;
        unset($data['gallery_order'], $data['gallery_captions'], $data['delete_gallery'], $data['gallery_images']);

        if ($request->hasFile('cover_image')) {
            $imagePipeline = app(ImagePipeline::class);
            $processed = $imagePipeline->process($request->file('cover_image'), $request->user()->id, null, 'matches', true);
            $imagePipeline->socialImage($processed['path'], true);
            $data['cover_image'] = $processed['path'];
            $data['cover_image_responsive'] = $processed['responsive'];
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
            $processed = app(ImagePipeline::class)->process($image, $request->user()->id, null, 'matches/galleries', true);
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
