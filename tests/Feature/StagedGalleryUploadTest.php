<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FootballMatch;
use App\Models\Media;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use App\Support\ImagePipeline;
use App\Support\PostMediaManager;
use App\Support\StagedGallery;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StagedGalleryUploadTest extends TestCase
{
    public function test_match_form_uploads_photos_before_the_match_is_saved(): void
    {
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)
            ->get(route('admin.matches.create'))
            ->assertOk()
            ->assertSee('data-gallery-upload', false)
            ->assertSee('Fotografije se učitavaju odmah', false)
            ->assertSee(route('admin.gallery-uploads.store'), false);

        $first = $this->stagedPhoto($admin, StagedGallery::MATCH, 'matches/galleries/first.webp');
        $second = $this->stagedPhoto($admin, StagedGallery::MATCH, 'matches/galleries/second.webp');

        $response = $this->actingAs($admin)->post(route('admin.matches.store'), $this->matchPayload($admin, [
            'title' => 'FK Posavina - Modrica',
            'slug' => 'fk-posavina-modrica',
            'away_team' => 'Modrica',
            'staged_gallery' => [$first->id, $second->id],
            'gallery_order' => [$second->id, $first->id],
            'gallery_captions' => [$second->id => 'Gol'],
        ]));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $match = FootballMatch::query()->first();
        $this->assertNotNull($match);
        $this->assertSame(
            [$second->id, $first->id],
            $match->media()->pluck('media.id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertSame('Gol', $match->media()->where('media.id', $second->id)->first()->pivot->caption);
        $this->assertSame('match_gallery', $first->fresh()->media_type);
    }

    public function test_foreign_staged_photo_does_not_create_the_match(): void
    {
        $admin = $this->userWithRole('super_admin');
        $other = $this->userWithRole('author');
        $photo = $this->stagedPhoto($other, StagedGallery::MATCH, 'matches/galleries/other.webp');

        $response = $this->actingAs($admin)
            ->from(route('admin.matches.create'))
            ->post(route('admin.matches.store'), $this->matchPayload($admin, [
                'title' => 'FK Posavina - Odjak',
                'slug' => 'fk-posavina-odjak',
                'away_team' => 'Odjak',
                'staged_gallery' => [$photo->id],
            ]));

        $response->assertRedirect(route('admin.matches.create'));
        $response->assertSessionHasErrors('staged_gallery');
        $this->assertSame(0, FootballMatch::query()->count());
        $this->assertSame(StagedGallery::MATCH, $photo->fresh()->media_type);
    }

    public function test_article_gallery_attaches_staged_photos_in_requested_order(): void
    {
        $author = $this->userWithRole('author');
        $category = Category::query()->create([
            'name' => 'Sport',
            'slug' => 'sport',
        ]);
        $post = Post::query()->create([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'title' => 'Derbi',
            'slug' => 'derbi',
            'content' => 'Izvjestaj sa utakmice.',
            'status' => 'draft',
        ]);
        $first = $this->stagedPhoto($author, StagedGallery::POST, 'posts/galleries/first.webp');
        $second = $this->stagedPhoto($author, StagedGallery::POST, 'posts/galleries/second.webp');

        $request = Request::create('/author/posts', 'POST', [
            'staged_gallery' => [$first->id, $second->id],
            'gallery_order' => [$second->id, $first->id],
            'gallery_captions' => [$second->id => 'Centar'],
        ]);
        $request->setUserResolver(fn () => $author);

        app(PostMediaManager::class)->syncGallery($request, $post);

        $this->assertSame(
            [$second->id, $first->id],
            $post->galleryMedia()->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertSame('Centar', $post->galleryMedia()->find($second->id)->caption);
        $this->assertSame($post->id, $first->fresh()->post_id);
    }

    public function test_single_photo_upload_is_staged_for_the_current_user(): void
    {
        $admin = $this->userWithRole('super_admin');
        $created = null;

        $this->mock(ImagePipeline::class, function ($mock) use ($admin, &$created) {
            $mock->shouldReceive('process')->once()->andReturnUsing(function () use ($admin, &$created) {
                $created = Media::query()->create([
                    'user_id' => $admin->id,
                    'disk' => 'public',
                    'path' => 'matches/galleries/uploaded.webp',
                    'filename' => 'uploaded.webp',
                    'mime_type' => 'image/webp',
                    'size' => 120,
                    'alt_text' => 'stadion',
                    'media_type' => 'image',
                ]);

                return [
                    'path' => $created->path,
                    'responsive' => ['original' => $created->path, 'variants' => []],
                ];
            });
        });

        $response = $this->actingAs($admin)->postJson(route('admin.gallery-uploads.store'), [
            'context' => 'match',
            'image' => UploadedFile::fake()->image('stadion.jpg', 40, 40),
        ]);

        $response->assertOk();
        $response->assertJsonPath('id', $created->id);
        $this->assertSame(StagedGallery::MATCH, $created->fresh()->media_type);
    }

    public function test_user_can_discard_only_own_staged_photo(): void
    {
        Storage::fake('public');
        $admin = $this->userWithRole('super_admin');
        $other = $this->userWithRole('author');
        $photo = $this->stagedPhoto($admin, StagedGallery::MATCH, 'matches/galleries/drop.webp');
        $foreign = $this->stagedPhoto($other, StagedGallery::MATCH, 'matches/galleries/keep.webp');

        $this->actingAs($admin)
            ->deleteJson(route('admin.gallery-uploads.destroy', $photo))
            ->assertOk();

        $this->assertNull($photo->fresh());

        $this->actingAs($admin)
            ->deleteJson(route('admin.gallery-uploads.destroy', $foreign))
            ->assertNotFound();

        $this->assertNotNull($foreign->fresh());
    }

    public function test_abandoned_staged_photos_are_pruned(): void
    {
        Storage::fake('public');
        $admin = $this->userWithRole('super_admin');
        $old = $this->stagedPhoto($admin, StagedGallery::MATCH, 'matches/galleries/old.webp');
        $fresh = $this->stagedPhoto($admin, StagedGallery::POST, 'posts/galleries/fresh.webp');
        $old->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('gallery:prune-staged')->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($fresh->fresh());
    }

    private function matchPayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Utakmica',
            'slug' => 'utakmica',
            'home_team' => 'FK Posavina',
            'away_team' => 'Gost',
            'home_score' => '',
            'away_score' => '',
            'played_at' => '',
            'venue' => '',
            'excerpt' => '',
            'content' => '',
            'author_id' => $user->id,
            'status' => 'draft',
            'published_at' => '',
            'scheduled_at' => '',
            'meta_title' => '',
            'meta_description' => '',
        ], $overrides);
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::query()->firstOrCreate(
            ['name' => $role],
            ['label' => $role]
        );

        return User::factory()->create(['role_id' => $roleModel->id]);
    }

    private function stagedPhoto(User $user, string $type, string $path): Media
    {
        return Media::query()->create([
            'user_id' => $user->id,
            'disk' => 'public',
            'path' => $path,
            'filename' => basename($path),
            'mime_type' => 'image/webp',
            'media_type' => $type,
            'size' => 10,
            'alt_text' => pathinfo($path, PATHINFO_FILENAME),
            'responsive_paths' => ['original' => $path, 'variants' => []],
        ]);
    }
}
