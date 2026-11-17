<?php

namespace App\Http\Controllers;

use App\Actions\Articles\ManageArticles;
use App\Models\Article;
use App\Support\ArticleImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The picture of an article (v7 D48): in SIAKAD for whoever may read the article (the working copy, as the
 * article page shows), and publicly for the CMS while the article is published (the published version's
 * picture, behind a link that changes with the picture).
 */
class ArticleImageController extends Controller
{
    public function show(Request $request, Article $article): StreamedResponse
    {
        abort_unless(ManageArticles::canView($request->user(), $article), 403);
        abort_if($article->image_path === null, 404);

        return $this->serve($article->image_path, 'private, max-age=300');
    }

    public function published(string $code, string $hash): StreamedResponse
    {
        $article = Article::query()->where('code', $code)->firstOrFail();
        $version = ArticleImages::published($article);

        abort_if($version === null || ! hash_equals(ArticleImages::hash((string) $version->image_path), $hash), 404);

        // The link changes with the picture, so it may be kept for long.
        return $this->serve((string) $version->image_path, 'public, max-age=31536000, immutable');
    }

    private function serve(string $path, string $cache): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => $cache]);
    }
}
