<?php

use App\Actions\Articles\ManageArticles;
use App\Enums\ArticleStatus;
use App\Enums\Role;
use App\Jobs\Cms\PushArticle;
use App\Models\Article;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ArticleImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * v7 D48: an article may have one picture; it is kept with each published version and reaches the CMS as
 * image_url and on top of body_html.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->articles = app(ManageArticles::class);
    $this->ahmad = Teacher::factory()->create();
    $this->student = Student::factory()->create();
});

function pictureArticleInput(array $overrides = []): array
{
    return [
        'title' => 'Hikmah Zakat Fitrah',
        'summary' => 'Mengapa zakat fitrah dibayar sebelum shalat Id.',
        'body' => "## Pendahuluan\n\nZakat fitrah adalah kewajiban setiap muslim yang menemui akhir Ramadhan. **Hikmahnya** menyucikan orang yang berpuasa.",
        'keywords' => 'zakat',
        ...$overrides,
    ];
}

test('a picture is shrunk to 1600 px, kept with the published version and shown to readers', function () {
    $article = $this->articles->save(null, $this->ahmad->user, pictureArticleInput([
        'image' => UploadedFile::fake()->image('masjid.png', 2400, 1200),
        'image_caption' => 'Masjid pesantren',
    ]));

    expect($article->image_path)->toStartWith(ArticleImages::DIRECTORY.'/')->toEndWith('.jpg');
    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($article->image_path));
    expect([$width, $height])->toBe([1600, 800]);

    // A draft picture: the author and muhadhir, not other mahasantri.
    $this->actingAs($this->ahmad->user)->get(route('articles.image', $article))->assertOk()->assertHeader('content-type', 'image/jpeg');
    $this->actingAs($this->student->user)->get(route('articles.image', $article))->assertForbidden();

    $version = $this->articles->publish($article, $this->ahmad->user);
    expect($version->image_path)->toBe($article->image_path)->and($version->image_caption)->toBe('Masjid pesantren');

    $this->actingAs($this->student->user)->get(route('articles.image', $article))->assertOk();
    Livewire::test('pages::articles.show', ['article' => $article->fresh()])
        ->assertSeeHtml('data-test="article-image"')
        ->assertSee('Masjid pesantren');
});

test('the CMS gets a public link to the published picture, also on top of the text', function () {
    $article = $this->articles->save(null, $this->ahmad->user, pictureArticleInput(['image' => UploadedFile::fake()->image('a.jpg', 800, 600), 'image_caption' => 'Santri & kitab']));
    $this->articles->publish($article, $this->ahmad->user);
    $article->refresh();

    $payload = PushArticle::payload($article);
    expect($payload['image_url'])->toBe(ArticleImages::publicUrl($article))
        ->and($payload['image_caption'])->toBe('Santri & kitab')
        ->and($payload['body_html'])->toStartWith('<figure><img src="'.e($payload['image_url']).'"')
        ->toContain('<figcaption>Santri &amp; kitab</figcaption>');

    // Public: no login needed.
    app('auth')->forgetGuards();
    $this->get($payload['image_url'])->assertOk()->assertHeader('content-type', 'image/jpeg');
    $this->get(route('articles.public-image', ['code' => $article->code, 'hash' => str_repeat('a', 24)]))->assertNotFound();

    // Withdrawn: the link stops working.
    $this->articles->withdraw($article, $this->ahmad->user);
    $this->get($payload['image_url'])->assertNotFound();
});

test('an article without a picture sends none', function () {
    $article = $this->articles->save(null, $this->ahmad->user, pictureArticleInput());
    $this->articles->publish($article, $this->ahmad->user);

    $payload = PushArticle::payload($article->refresh());
    expect($payload)->not->toHaveKey('image_url')
        ->and($payload['body_html'])->not->toContain('<figure>');
});

test('a new picture on a published article waits for the next publication; files no version uses are deleted', function () {
    $article = $this->articles->save(null, $this->ahmad->user, pictureArticleInput(['image' => UploadedFile::fake()->image('a.jpg', 800, 600)]));
    $this->articles->publish($article, $this->ahmad->user);
    $first = $article->refresh()->image_path;
    $firstLink = ArticleImages::publicUrl($article);

    $this->articles->save($article, $this->ahmad->user, pictureArticleInput(['image' => UploadedFile::fake()->image('b.jpg', 800, 600)]));
    $second = $article->refresh()->image_path;

    expect($article->status)->toBe(ArticleStatus::Draft)
        ->and(ArticleImages::publicUrl($article))->toBe($firstLink)
        ->and(Storage::disk('local')->exists($first))->toBeTrue();

    // Replaced again before publishing: the unpublished second picture goes.
    $this->articles->save($article, $this->ahmad->user, pictureArticleInput(['image' => UploadedFile::fake()->image('c.jpg', 800, 600)]));
    expect(Storage::disk('local')->exists($second))->toBeFalse()
        ->and(Storage::disk('local')->exists($first))->toBeTrue();

    $this->articles->publish($article->refresh(), $this->ahmad->user);
    expect(ArticleImages::publicUrl($article->refresh()))->not->toBe($firstLink);
    app('auth')->forgetGuards();
    $this->get($firstLink)->assertNotFound();

    // Removing it from a never-published draft deletes the file.
    $draft = $this->articles->save(null, $this->ahmad->user, pictureArticleInput(['image' => UploadedFile::fake()->image('d.jpg', 400, 300)]));
    $path = $draft->image_path;
    $this->articles->save($draft, $this->ahmad->user, pictureArticleInput(['remove_image' => true, 'image_caption' => 'ignored']));
    expect($draft->refresh()->image_path)->toBeNull()->and($draft->image_caption)->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

test('the edit page uploads, captions and removes the picture', function () {
    $this->actingAs($this->student->user);

    Livewire::test('pages::articles.edit')
        ->set('title', 'Adab Menuntut Ilmu')
        ->set('body', str_repeat('Menuntut ilmu dengan adab adalah jalan para ulama. ', 3))
        ->set('image', UploadedFile::fake()->image('kitab.jpg', 1000, 700))
        ->assertSeeHtml('data-test="image-preview"')
        ->set('imageCaption', 'Kitab kuning')
        ->call('save')
        ->assertHasNoErrors();

    $article = Article::query()->sole();
    expect($article->image_path)->not->toBeNull()->and($article->image_caption)->toBe('Kitab kuning');

    Livewire::test('pages::articles.edit', ['article' => $article])
        ->assertSeeHtml('data-test="image-current"')
        ->set('removeImage', true)
        ->call('save')
        ->assertHasNoErrors();
    expect($article->refresh()->image_path)->toBeNull();

    Livewire::test('pages::articles.edit', ['article' => $article])
        ->set('image', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
        ->call('save')
        ->assertHasErrors('image');
});

test('the app sets and removes the picture and opens it through a signed link', function () {
    $token = fn (User $user, Role $role) => $this->withToken($user->createToken('Test')->plainTextToken)->withHeader('X-Role', $role->value);
    $article = $this->articles->save(null, $this->student->user, pictureArticleInput());

    app('auth')->forgetGuards();
    $set = $token($this->student->user, Role::Student)
        ->post("/api/v1/articles/{$article->id}/image", ['image' => UploadedFile::fake()->image('a.jpg', 600, 400), 'image_caption' => 'Halaqah'], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.image_caption', 'Halaqah');

    app('auth')->forgetGuards();
    $this->get($set->json('data.image_url'))->assertOk();

    // Changing the text keeps the picture and its caption.
    app('auth')->forgetGuards();
    $token($this->student->user, Role::Student)->putJson("/api/v1/articles/{$article->id}", pictureArticleInput(['title' => 'Judul baru']))
        ->assertOk()->assertJsonPath('data.image_caption', 'Halaqah');
    expect($article->refresh()->image_path)->not->toBeNull();

    app('auth')->forgetGuards();
    $token($this->student->user, Role::Student)->deleteJson("/api/v1/articles/{$article->id}/image")
        ->assertOk()->assertJsonPath('data.image_url', null);
});
