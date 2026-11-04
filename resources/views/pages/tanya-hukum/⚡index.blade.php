<?php

use App\Models\LegalQuestion;
use App\Support\DiscussionAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tanya Hukum')] class extends Component {
    use WithPagination;

    /** open (no answer sent yet) | approval (waiting for approval) | answered | all */
    #[Url]
    public string $show = 'open';

    public function mount(): void
    {
        abort_unless(DiscussionAccess::canUseLpppk(auth()->user()), 403);
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, LegalQuestion>
     */
    #[Computed]
    public function questions(): LengthAwarePaginator
    {
        return LegalQuestion::query()
            ->with(['answers' => fn ($query) => $query->orderByDesc('version')])
            ->when($this->show === 'open', fn (Builder $query) => $query->whereDoesntHave('answers', fn ($query) => $query->where('status', 'sent')))
            ->when($this->show === 'approval', fn (Builder $query) => $query->whereHas('answers', fn ($query) => $query->where('status', 'awaiting')))
            ->when($this->show === 'answered', fn (Builder $query) => $query->whereHas('answers', fn ($query) => $query->where('status', 'sent')))
            ->orderBy($this->show === 'answered' ? 'updated_at' : 'created_at', $this->show === 'answered' ? 'desc' : 'asc')
            ->paginate(20);
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('Tanya Hukum')" :subtitle="__('Questions from the website, forwarded by its moderators. Discuss each one; a muhadhir writes the answer, and it goes back to the website once two other muhadhir approve it.')">
        <x-slot:actions>
            <flux:button icon="arrow-left" :href="route('lpppk')" wire:navigate>{{ __('LPPPK') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <flux:radio.group wire:model.live="show" variant="segmented" class="mb-4 w-full sm:w-auto">
        <flux:radio value="open" :label="__('Not answered')" />
        <flux:radio value="approval" :label="__('Waiting for approval')" />
        <flux:radio value="answered" :label="__('Answered')" />
        <flux:radio value="all" :label="__('All')" />
    </flux:radio.group>

    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->questions as $question)
            @php($latest = $question->answers->first())
            <a wire:key="question-{{ $question->id }}" href="{{ route('tanya-hukum.show', $question) }}" wire:navigate class="flex items-start gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800" data-test="question-{{ $question->id }}">
                <flux:icon.scale class="mt-0.5 size-5 shrink-0 text-zinc-400" />
                <span class="min-w-0 flex-1">
                    <span class="block text-sm">{{ Str::limit($question->question, 160) }}</span>
                    <span class="mt-0.5 block text-xs text-zinc-500">
                        <span class="font-mono">{{ $question->tracking_code ?? $question->publicId() }}</span> · {{ $question->categoryLabel() }} · {{ $question->askerLabel() }} · {{ $question->created_at->diffForHumans() }}
                    </span>
                </span>
                <flux:badge size="sm" :color="match ($latest?->status) { 'sent' => 'green', 'awaiting' => 'blue', 'approved' => 'blue', 'draft' => 'amber', default => 'zinc' }">
                    {{ $latest?->statusLabel() ?? __('No answer yet') }}
                </flux:badge>
            </a>
        @empty
            <flux:text class="p-4">{{ __('No questions here.') }}</flux:text>
        @endforelse
    </div>
    <div class="mt-4">{{ $this->questions->links() }}</div>
</section>
