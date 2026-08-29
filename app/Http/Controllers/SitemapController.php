<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\FootballMatch;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;

/**
 * Machine-readable discovery endpoints: XML sitemaps, the RSS feed and robots.txt.
 */
class SitemapController extends Controller
{
    public function index()
    {
        return $this->xml('seo.sitemap-index');
    }

    public function pages()
    {
        $lastmod = collect([
            Post::published()->max('updated_at'),
            FootballMatch::published()->max('updated_at'),
        ])->filter()->max() ?: now();

        return $this->xml('seo.sitemap-pages', compact('lastmod'));
    }

    public function posts()
    {
        $posts = Post::published()->with(['category', 'author', 'tags'])->latest('updated_at')->get();

        return $this->xml('seo.sitemap-posts', compact('posts'));
    }

    public function matches()
    {
        $matches = FootballMatch::published()->with('media')->latest('updated_at')->get();

        return $this->xml('seo.sitemap-matches', compact('matches'));
    }

    public function news()
    {
        $posts = Post::published()
            ->with(['category', 'tags'])
            ->where('published_at', '>=', now()->subDays(2))
            ->latest('published_at')
            ->limit(1000)
            ->get();

        return $this->xml('seo.sitemap-news', compact('posts'));
    }

    public function taxonomies()
    {
        $categories = Category::where('is_active', true)->get();
        $tags = Tag::all();
        $authors = User::whereHas('posts', fn ($query) => $query->published())->get();

        return $this->xml('seo.sitemap-taxonomies', compact('categories', 'tags', 'authors'));
    }

    public function feed()
    {
        $posts = Post::published()->with(['category', 'author'])->latest('published_at')->take(30)->get();

        return response()->view('seo.feed', compact('posts'))->header('Content-Type', 'application/rss+xml');
    }

    public function robots()
    {
        $frontend = rtrim(config('services.frontend.url'), '/');
        $robots = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Allow: /sitemap.xml',
            'Allow: /sitemap-pages.xml',
            'Allow: /sitemap-posts.xml',
            'Allow: /sitemap-news.xml',
            'Allow: /sitemap-matches.xml',
            'Allow: /sitemap-taxonomies.xml',
            'Disallow: /api/',
            'Disallow: /admin',
            'Disallow: /author',
            'Disallow: /login',
            'Disallow: /pretraga',
            '',
            'Sitemap: '.$frontend.'/sitemap.xml',
            '',
        ]);

        return response($robots, 200)->header('Content-Type', 'text/plain');
    }

    private function xml(string $view, array $data = [])
    {
        return response()->view($view, $data)->header('Content-Type', 'application/xml');
    }
}
