<?php

use App\Actions\Articles\ManageArticles;
use App\Enums\ArticleStatus;
use App\Enums\Role;
use App\Events\ArticlePublished;
use App\Events\ArticleWithdrawn;
use App\Models\Article;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->articles = app(ManageArticles::class);
    $this->student = Student::factory()->create();
    $this->ahmad = Teacher::factory()->create();
    $this->hasan = Teacher::factory()->create();
    $this->umar = Teacher::factory()->create();
});

function articleInput(array $overrides = []): array
{
    return [
        'title' => 'Hikmah Zakat Fitrah',
        'summary' => 'Mengapa zakat fitrah dibayar sebelum shalat Id.',
        'body' => "## Pendahuluan\n\nZakat fitrah adalah kewajiban setiap muslim yang menemui akhir Ramadhan. **Hikmahnya** menyucikan orang yang berpuasa.",
        'keywords' => 'zakat, ramadhan, zakat',
        ...$overrides,
    ];
}

test('a mahasantri article is published after two different muhadhir approve it', function () {
    Event::fake([ArticlePublished::class]);
    $article = $this->articles->save(null, $this->student->user, articleInput());

    expect($article->status)->toBe(ArticleStatus::Draft)
        ->and($article->keywords)->toBe(['zakat', 'ramadhan'])
        ->and(fn () => $this->articles->publish($article, $this->student->user))->toThrow(ValidationException::class);

    $this->articles->submit($article, $this->student->user);
    expect($article->fresh())->status->toBe(ArticleStatus::InReview)->review_round->toBe(1)
        ->and($this->ahmad->user->notifications()->count())->toBe(1);

    // Not the author, not a mahasantri, and not twice in a round.
    expect(fn () => $this->articles->review($article, Student::factory()->create()->user, true, null))->toThrow(ValidationException::class);
    $this->articles->review($article->fresh(), $this->ahmad->user, true, null);
    expect(fn () => $this->articles->review($article->fresh(), $this->ahmad->user, true, null))->toThrow(ValidationException::class, 'already');
    expect($article->fresh()->status)->toBe(ArticleStatus::InReview);

    $this->articles->review($article->fresh(), $this->hasan->user, true, 'Bagus.');

    $article->refresh();
    expect($article->status)->toBe(ArticleStatus::Published)
        ->and($article->code)->toBe(sprintf('ART-%d-%04d', now()->year, $article->id))
        ->and($article->versions()->sole()->version)->toBe(1)
        ->and($this->student->user->notifications()->get()->pluck('data.title')->contains(fn ($title) => str_contains($title, 'Published')))->toBeTrue();
    Event::assertDispatched(ArticlePublished::class, fn ($event) => $event->article->is($article));
});

test('asking for changes needs a note, sends it back, and the next round starts over', function () {
    $article = $this->articles->save(null, $this->student->user, articleInput());
    $this->articles->submit($article, $this->student->user);
    $this->articles->review($article->fresh(), $this->ahmad->user, true, null);

    expect(fn () => $this->articles->review($article->fresh(), $this->hasan->user, false, 'no'))->toThrow(ValidationException::class);
    $this->articles->review($article->fresh(), $this->hasan->user, false, 'Tambahkan dalil hadisnya.');
    expect($article->fresh()->status)->toBe(ArticleStatus::ChangesRequested);

    // Locked while in review; editable after the changes request.
    $this->articles->save($article->fresh(), $this->student->user, articleInput(['body' => articleInput()['body']."\n\nDalil: hadis Ibnu Abbas."]));
    $this->articles->submit($article->fresh(), $this->student->user);
    expect(fn () => $this->articles->save($article->fresh(), $this->student->user, articleInput()))->toThrow(ValidationException::class, 'being reviewed');

    // Round 2: Ahmad's approval from round 1 does not count.
    $this->articles->review($article->fresh(), $this->umar->user, true, null);
    expect($article->fresh()->status)->toBe(ArticleStatus::InReview);
    $this->articles->review($article->fresh(), $this->ahmad->user, true, null);
    expect($article->fresh())->status->toBe(ArticleStatus::Published)->review_round->toBe(2);
});

test('a muhadhir publishes directly; editing makes a new version; withdrawing takes it down', function () {
    Event::fake([ArticlePublished::class, ArticleWithdrawn::class]);
    $article = $this->articles->save(null, $this->ahmad->user, articleInput());

    expect(fn () => $this->articles->submit($article, $this->ahmad->user))->toThrow(ValidationException::class);
    $this->articles->publish($article, $this->ahmad->user);
    expect($article->fresh()->status)->toBe(ArticleStatus::Published);

    $this->articles->save($article->fresh(), $this->ahmad->user, articleInput(['title' => 'Hikmah Zakat Fitrah (revisi)']));
    expect($article->fresh()->status)->toBe(ArticleStatus::Draft)
        ->and($article->versions()->sole()->title)->toBe('Hikmah Zakat Fitrah');

    $this->articles->publish($article->fresh(), $this->ahmad->user);
    expect($article->versions()->count())->toBe(2);

    expect(fn () => $this->articles->withdraw($article->fresh(), $this->hasan->user))->toThrow(ValidationException::class);
    $this->articles->withdraw($article->fresh(), $this->ahmad->user);
    expect($article->fresh()->status)->toBe(ArticleStatus::Withdrawn);
    Event::assertDispatchedTimes(ArticlePublished::class, 2);
    Event::assertDispatched(ArticleWithdrawn::class);
});

test('only drafts never sent can be deleted; only people allowed can see an article', function () {
    $draft = $this->articles->save(null, $this->student->user, articleInput());
    $this->articles->discard($draft, $this->student->user);
    expect(Article::query()->count())->toBe(0);

    $article = $this->articles->save(null, $this->student->user, articleInput());
    $other = Student::factory()->create();

    $this->actingAs($other->user)->get(route('articles.show', $article))->assertForbidden();
    $this->actingAs($this->ahmad->user)->get(route('articles.show', $article))->assertOk();
    $this->actingAs(User::factory()->withRole(Role::Guardian)->create())->get(route('articles.mine'))->assertForbidden();
});

test('the pages write, send, review and publish', function () {
    $this->actingAs($this->student->user)->withSession(['active_role' => Role::Student->value]);
    $this->get(route('articles.mine'))->assertOk();

    Livewire::test('pages::articles.edit')
        ->set('title', 'Adab Menuntut Ilmu')
        ->set('body', 'short')
        ->call('save')
        ->assertHasErrors('body')
        ->set('body', "Menuntut ilmu dimulai dengan niat yang ikhlas.\n\n- Ikhlas\n- Sabar\n- Menghormati guru")
        ->set('mode', 'preview')
        ->assertSeeHtml('<li>Sabar</li>')
        ->call('save')
        ->assertHasNoErrors();

    $article = Article::query()->sole();
    Livewire::test('pages::articles.show', ['article' => $article])->call('submit')->assertHasNoErrors();

    $this->actingAs($this->ahmad->user)->withSession(['active_role' => Role::Teacher->value]);
    $this->get(route('articles.review'))->assertOk()->assertSee('Adab Menuntut Ilmu');
    Livewire::test('pages::articles.show', ['article' => $article->fresh()])
        ->assertSeeHtml('data-test="review-form"')
        ->call('review', true)
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="review-form"');

    $this->actingAs($this->hasan->user);
    Livewire::test('pages::articles.show', ['article' => $article->fresh()])->set('reviewNote', 'Sudah baik.')->call('review', true);

    expect($article->fresh()->status)->toBe(ArticleStatus::Published);

    // Raw HTML in the Markdown never reaches the page.
    expect(Article::render('<script>alert(1)</script> **tebal** [x](javascript:alert(1))'))->not->toContain('<script>')->not->toContain('javascript:');
});
