<?php

use App\Actions\Articles\ManageArticles;
use App\Actions\TanyaHukum\ManageLegalQuestions;
use App\Jobs\Cms\SendLegalAnswer;
use App\Models\Article;
use App\Models\LegalAnswer;
use App\Models\LegalQuestion;
use App\Support\Cms\CmsClient;
use App\Support\DiscussionAccess;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tanya Hukum')] class extends Component {
    public LegalQuestion $question;

    public string $answer = '';
    public string $references = '';
    /** write | preview */
    public string $mode = 'write';

    public function mount(LegalQuestion $question, ManageLegalQuestions $manage): void
    {
        abort_unless(DiscussionAccess::canUseLpppk(auth()->user()), 403);
        $this->question = $question;
        $this->fillFromOpen($manage);
    }

    #[Computed]
    public function muhadhir(): bool
    {
        return ManageArticles::isMuhadhir(auth()->user());
    }

    #[Computed]
    public function open(): ?LegalAnswer
    {
        return app(ManageLegalQuestions::class)->open($this->question)?->load(['drafter', 'approvals.approver']);
    }

    /**
     * Versions already sent (or replaced), newest first.
     *
     * @return Collection<int, LegalAnswer>
     */
    #[Computed]
    public function sent(): Collection
    {
        return $this->question->answers()->with('drafter')->whereIn('status', ['sent', 'superseded'])->orderByDesc('version')->get();
    }

    #[Computed]
    public function preview(): string
    {
        return Article::render($this->answer);
    }

    public function saveDraft(ManageLegalQuestions $manage): void
    {
        $this->resetErrorBag();
        $manage->saveDraft($this->question, auth()->user(), $this->answer, $this->references);
        unset($this->open);
        Flux::toast(variant: 'success', text: __('Draft saved.'));
    }

    public function submit(ManageLegalQuestions $manage): void
    {
        $this->saveDraft($manage);
        $manage->submit($this->open, auth()->user());
        unset($this->open);
        Flux::toast(variant: 'success', text: __('Sent for approval: two other muhadhir approve it before it goes to the website.'));
    }

    public function approve(ManageLegalQuestions $manage): void
    {
        $manage->approve($this->open, auth()->user());
        unset($this->open, $this->sent);
        Flux::toast(variant: 'success', text: __('Approved.'));
    }

    public function reopen(ManageLegalQuestions $manage): void
    {
        $manage->reopen($this->open, auth()->user());
        unset($this->open);
        $this->fillFromOpen($manage);
    }

    public function startCorrection(ManageLegalQuestions $manage): void
    {
        $manage->startCorrection($this->question, auth()->user());
        unset($this->open);
        $this->fillFromOpen($manage);
    }

    public function resend(): void
    {
        abort_unless($this->muhadhir && $this->open?->status === 'approved' && CmsClient::enabled(), 403);
        $this->open->update(['error' => null]);
        SendLegalAnswer::dispatch($this->open->id);
        unset($this->open);
        Flux::toast(variant: 'success', text: __('Queued. The website status updates in a minute or two.'));
    }

    private function fillFromOpen(ManageLegalQuestions $manage): void
    {
        $open = $manage->open($this->question);
        $this->answer = $open->answer_md ?? '';
        $this->references = (string) ($open?->references ?? '');
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('Tanya Hukum :code', ['code' => $question->tracking_code ?? $question->publicId()])"
        :subtitle="$question->categoryLabel().' · '.$question->askerLabel().' · '.($question->submitted_at ?? $question->created_at)->translatedFormat('j F Y H:i')">
        <x-slot:actions>
            <flux:button icon="arrow-left" :href="route('tanya-hukum')" wire:navigate>{{ __('Tanya Hukum') }}</flux:button>
            @if ($question->discussion)
                <flux:button icon="chat-bubble-left-right" :href="route('discussions.show', $question->discussion)" wire:navigate data-test="discuss">{{ __('Discussion') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <flux:card class="mb-6 space-y-3">
        <div class="whitespace-pre-line">{{ $question->question }}</div>
        @if ($question->moderator_note)
            <flux:callout icon="information-circle" :heading="__('Note from the website moderator')" :text="$question->moderator_note" />
        @endif
    </flux:card>

    @error('answer')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :text="$message" />
    @enderror

    @php($open = $this->open)
    @if ($open && $open->status !== 'draft')
        <flux:card class="mb-6 space-y-3" data-test="open-answer">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading class="me-auto">{{ __('Answer, version :version', ['version' => $open->version]) }}</flux:heading>
                <flux:badge size="sm" color="blue">{{ $open->statusLabel() }}</flux:badge>
            </div>
            <div class="article-body">{!! $open->html() !!}</div>
            @if ($open->references)
                <flux:text class="text-sm whitespace-pre-line"><strong>{{ __('References') }}:</strong> {{ $open->references }}</flux:text>
            @endif
            <flux:text class="text-xs">
                {{ __('Written by :name', ['name' => $open->drafter->name]) }} ·
                {{ __('Approved by: :names', ['names' => $open->approvals->map(fn ($approval) => $approval->approver->name)->join(', ') ?: '—']) }}
                ({{ $open->approvals->count() }}/{{ ManageLegalQuestions::APPROVALS_NEEDED }})
            </flux:text>
            @if ($open->error)
                <flux:callout variant="danger" icon="exclamation-triangle" :text="$open->error" />
            @endif
            @if ($this->muhadhir)
                <div class="flex flex-wrap gap-2">
                    @if ($open->status === 'awaiting' && $open->drafted_by !== auth()->id() && ! $open->approvals->contains('approver_id', auth()->id()))
                        <flux:button variant="primary" icon="check" wire:click="approve" data-test="approve-answer">{{ __('Approve') }}</flux:button>
                    @endif
                    @if ($open->status === 'awaiting')
                        <flux:button icon="pencil-square" wire:click="reopen" wire:confirm="{{ __('Back to draft? The approvals given so far are dropped.') }}">{{ __('Back to draft') }}</flux:button>
                    @endif
                    @if ($open->status === 'approved' && $open->error && CmsClient::enabled())
                        <flux:button icon="paper-airplane" wire:click="resend">{{ __('Send again') }}</flux:button>
                    @endif
                </div>
            @endif
        </flux:card>
    @elseif ($this->muhadhir && ($open || $this->sent->isEmpty()))
        <flux:card class="mb-6 space-y-4" data-test="answer-form">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading class="me-auto">{{ $open ? __('Draft answer, version :version', ['version' => $open->version]) : __('Write the answer') }}</flux:heading>
                <flux:radio.group wire:model.live="mode" variant="segmented" size="sm">
                    <flux:radio value="write" :label="__('Write')" />
                    <flux:radio value="preview" :label="__('Preview')" />
                </flux:radio.group>
            </div>
            @if ($open)
                <flux:text class="text-xs">{{ __('Last saved by :name :time. Whoever saves it last cannot approve it.', ['name' => $open->drafter->name, 'time' => $open->updated_at->diffForHumans()]) }}</flux:text>
            @endif
            @if ($mode === 'preview')
                <div class="article-body rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">{!! $this->preview !!}</div>
            @else
                <flux:textarea wire:model="answer" rows="12" :placeholder="__('The answer, in Markdown…')" />
            @endif
            <flux:error name="answer" />
            <flux:textarea wire:model="references" :label="__('Kitab references (optional)')" rows="2" />
            <div class="flex flex-wrap justify-end gap-2">
                <flux:button wire:click="saveDraft" data-test="save-draft">{{ __('Save draft') }}</flux:button>
                <flux:button variant="primary" icon="paper-airplane" wire:click="submit" data-test="submit-answer">{{ __('Ask for approval') }}</flux:button>
            </div>
        </flux:card>
    @elseif ($open && $open->status === 'draft')
        <flux:callout icon="pencil-square" class="mb-6" :text="__('A muhadhir is writing the answer.')" />
    @endif

    @foreach ($this->sent as $version)
        <flux:card wire:key="sent-{{ $version->id }}" class="mb-4 space-y-2 {{ $version->status === 'superseded' ? 'opacity-60' : '' }}">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading class="me-auto">{{ __('Answer, version :version', ['version' => $version->version]) }}</flux:heading>
                <flux:badge size="sm" :color="$version->status === 'sent' ? 'green' : 'zinc'">{{ $version->statusLabel() }}</flux:badge>
            </div>
            <div class="article-body">{!! $version->html() !!}</div>
            @if ($version->references)
                <flux:text class="text-sm whitespace-pre-line"><strong>{{ __('References') }}:</strong> {{ $version->references }}</flux:text>
            @endif
            <flux:text class="text-xs">{{ __('Written by :name', ['name' => $version->drafter->name]) }} · {{ __('sent :time', ['time' => $version->sent_at?->diffForHumans()]) }}</flux:text>
        </flux:card>
    @endforeach

    @if ($this->muhadhir && ! $open && $this->sent->isNotEmpty())
        <flux:button icon="pencil-square" wire:click="startCorrection" data-test="start-correction">{{ __('Correct the answer') }}</flux:button>
        <flux:text class="mt-1 text-xs">{{ __('A correction needs two new approvals. After publication the website moderator decides whether to apply it.') }}</flux:text>
    @endif
</section>
