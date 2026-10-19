<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Support\ImagePipeline;
use App\Support\StagedGallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GalleryUploadController extends Controller
{
    public function store(Request $request)
    {
        set_time_limit(120);

        $context = $request->validate([
            'context' => ['required', Rule::in(['match', 'post'])],
        ])['context'];

        $maxKb = $context === 'match' ? 8192 : 12288;

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
        ], [
            'image.max' => 'Fotografija je prevelika. Najveća dozvoljena veličina je '.($maxKb === 8192 ? 8 : 12).' MB.',
            'image.image' => 'Dozvoljene su samo JPG, PNG i WebP fotografije.',
        ]);

        $processed = app(ImagePipeline::class)->process(
            $request->file('image'),
            $request->user()->id,
            null,
            $context === 'match' ? 'matches/galleries' : 'posts/galleries',
            $context === 'match',
        );

        $media = Media::query()
            ->where('path', $processed['path'])
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first();

        if (! $media) {
            abort(500, 'Fotografija nije sačuvana.');
        }

        $media->update([
            'media_type' => $context === 'match' ? StagedGallery::MATCH : StagedGallery::POST,
        ]);

        return response()->json([
            'id' => $media->id,
            'url' => asset('storage/'.$media->path),
            'name' => $media->alt_text,
        ]);
    }

    public function destroy(Request $request, Media $media)
    {
        $ownsStaged = (int) $media->user_id === (int) $request->user()->id
            && in_array($media->media_type, [StagedGallery::MATCH, StagedGallery::POST], true)
            && $media->post_id === null
            && ! DB::table('match_media')->where('media_id', $media->id)->exists();

        abort_unless($ownsStaged, 404);

        app(StagedGallery::class)->deleteFiles($media);

        return response()->json(['ok' => true]);
    }
}
