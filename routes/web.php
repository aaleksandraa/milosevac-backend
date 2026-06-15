<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuthorController;
use App\Http\Controllers\FrontendRedirectController;
use App\Http\Controllers\PublicPortalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FrontendRedirectController::class, 'home'])->name('home');
Route::get('/omilosevcu', [FrontendRedirectController::class, 'about'])->name('about-milosevac');
Route::get('/clanak/{slug}', [FrontendRedirectController::class, 'post'])->name('posts.show');
Route::get('/kategorija/{category}', [FrontendRedirectController::class, 'category'])->name('categories.show');
Route::get('/tag/{tag}', [FrontendRedirectController::class, 'tag'])->name('tags.show');
Route::get('/autor/{author}', [FrontendRedirectController::class, 'author'])->name('authors.show');
Route::get('/pretraga', [FrontendRedirectController::class, 'search'])->name('search');
Route::get('/fk-posavina', [FrontendRedirectController::class, 'fkPosavina'])->name('fk-posavina');
Route::get('/fk-posavina/utakmica/{match}', [FrontendRedirectController::class, 'match'])->name('matches.show');
Route::get('/vrijeme', [FrontendRedirectController::class, 'weather'])->name('weather.show');
Route::get('/kontakt', [FrontendRedirectController::class, 'contact'])->name('contact');
Route::get('/politika-privatnosti', [FrontendRedirectController::class, 'privacy'])->name('privacy');
Route::get('/politika-kolacica', [FrontendRedirectController::class, 'cookies'])->name('cookies');
Route::get('/uslovi-koristenja', [FrontendRedirectController::class, 'terms'])->name('terms');
Route::get('/sitemap.xml', [PublicPortalController::class, 'sitemap'])->name('sitemap');
Route::get('/sitemap-pages.xml', [PublicPortalController::class, 'sitemapPages'])->name('sitemap.pages');
Route::get('/sitemap-posts.xml', [PublicPortalController::class, 'sitemapPosts'])->name('sitemap.posts');
Route::get('/sitemap-matches.xml', [PublicPortalController::class, 'sitemapMatches'])->name('sitemap.matches');
Route::get('/sitemap-news.xml', [PublicPortalController::class, 'sitemapNews'])->name('sitemap.news');
Route::get('/sitemap-taxonomies.xml', [PublicPortalController::class, 'sitemapTaxonomies'])->name('sitemap.taxonomies');
Route::get('/robots.txt', [PublicPortalController::class, 'robots'])->name('robots');
Route::get('/feed.xml', [PublicPortalController::class, 'feed'])->name('feed');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'role:super_admin,admin,editor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/posts', [AdminController::class, 'posts'])->name('posts.index');
    Route::get('/posts/create', [AdminController::class, 'createPost'])->name('posts.create');
    Route::post('/posts', [AdminController::class, 'storePost'])->name('posts.store');
    Route::get('/posts/{post}/edit', [AdminController::class, 'editPost'])->name('posts.edit');
    Route::put('/posts/{post}', [AdminController::class, 'updatePost'])->name('posts.update');
    Route::get('/matches', [AdminController::class, 'matches'])->name('matches.index');
    Route::get('/matches/create', [AdminController::class, 'createMatch'])->name('matches.create');
    Route::post('/matches', [AdminController::class, 'storeMatch'])->name('matches.store');
    Route::get('/matches/{match}/edit', [AdminController::class, 'editMatch'])->name('matches.edit');
    Route::put('/matches/{match}', [AdminController::class, 'updateMatch'])->name('matches.update');
    Route::post('/watermark', [AdminController::class, 'updateWatermark'])->name('watermark.update');
    Route::get('/ads', [AdminController::class, 'ads'])->name('ads.index');
    Route::post('/ads', [AdminController::class, 'updateAds'])->name('ads.update');
    Route::get('/categories', [AdminController::class, 'categories'])->name('categories.index');
    Route::post('/categories', [AdminController::class, 'storeCategory'])->name('categories.store');
    Route::get('/tags', [AdminController::class, 'tags'])->name('tags.index');
    Route::post('/tags', [AdminController::class, 'storeTag'])->name('tags.store');
    Route::get('/users', [AdminController::class, 'users'])->name('users.index');
    Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
});

Route::middleware(['auth', 'role:super_admin,admin,editor,author,contributor'])->prefix('author')->name('author.')->group(function () {
    Route::get('/', [AuthorController::class, 'dashboard'])->name('dashboard');
    Route::get('/posts/create', [AuthorController::class, 'create'])->name('posts.create');
    Route::post('/posts', [AuthorController::class, 'store'])->name('posts.store');
    Route::get('/posts/{post}/edit', [AuthorController::class, 'edit'])->name('posts.edit');
    Route::put('/posts/{post}', [AuthorController::class, 'update'])->name('posts.update');
    Route::get('/matches/create', [AuthorController::class, 'createMatch'])->name('matches.create');
    Route::post('/matches', [AuthorController::class, 'storeMatch'])->name('matches.store');
    Route::get('/matches/{match}/edit', [AuthorController::class, 'editMatch'])->name('matches.edit');
    Route::put('/matches/{match}', [AuthorController::class, 'updateMatch'])->name('matches.update');
});
