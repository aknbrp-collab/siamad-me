<?php

use App\Actions\Feedback\ManageFeedback;
use App\Enums\FeedbackCategory;
use App\Enums\FeedbackStatus;
use App\Enums\Role;
use App\Models\Feedback;
use App\Support\SpreadsheetDownload;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Reviews & suggestions')] class extends Component {
    use WithPagination;

    /** new | in_progress | done | all */
    #[Url(except: 'new')]
    public string $status = 'new';

    #[Url(except: '')]
    public string $category = '';

    /** The sender's role (mahasantri, wali, muhadhir, staff roles). */
    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $search = '';

    /** The message being read and answered. */
    #[Url]
    public ?int $open = null;

    public string $reply = '';

    public string $newStatus = '';

    public function mount(): void
    {
        abort_unless(ManageFeedback::handles(auth()->user()), 403);
        $this->fillForm();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'category', 'role', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function show(?int $id): void
    {
        $this->open = $id;
        unset($this->selected);
        $this->resetErrorBag();
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $this->reply = (string) $this->selected?->reply;
        $this->newStatus = $this->selected?->status->value ?? '';
    }

    /**
     * @return Builder<Feedback>
     */
    private function query(): Builder
    {
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return Feedback::query()
            ->with('user')
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->category !== '', fn (Builder $query) => $query->where('category', $this->category))
            ->when($this->role !== '', fn (Builder $query) => $query->where('role', $this->role))
            // Subject and message only: searching names would reveal anonymous senders.
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw('lower(subject) like ?', [$term])
                ->orWhereRaw('lower(message) like ?', [$term])))
            ->latest('id');
    }

    /**
     * @return LengthAwarePaginator<int, Feedback>
     */
    #[Computed]
    public function messages(): LengthAwarePaginator
    {
        return $this->query()->paginate(20);
    }

    #[Computed]
    public function selected(): ?Feedback
    {
        return $this->open === null ? null : Feedback::query()->with(['user', 'replier'])->find($this->open);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return Feedback::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total)->all();
    }

    public function sendReply(ManageFeedback $feedback): void
    {
        $item = $this->selected;
        abort_if($item === null, 404);

        $status = FeedbackStatus::tryFrom($this->newStatus);
        $feedback->reply($item, auth()->user(), $this->reply, $status === null || $status === FeedbackStatus::New ? FeedbackStatus::Done : $status);

        $this->show($item->id);
        unset($this->messages, $this->counts);
        Flux::toast(variant: 'success', text: __('Reply sent.'));
    }

    public function saveStatus(ManageFeedback $feedback): void
    {
        $item = $this->selected;
        abort_if($item === null, 404);

        $feedback->setStatus($item, auth()->user(), FeedbackStatus::from($this->newStatus));

        $this->show($item->id);
        unset($this->messages, $this->counts);
        Flux::toast(variant: 'success', text: __('Status saved.'));
    }

    public function export(): BinaryFileResponse
    {
        $reader = auth()->user();

        $rows = $this->query()->with('replier')->lazy()->map(fn (Feedback $item) => [
            __('Date') => $item->created_at->format('Y-m-d H:i'),
            __('From') => $item->senderLabel($reader),
            __('Role') => $item->role->label(),
            __('Category') => $item->category->label(),
            __('Topic') => $item->subject,
            __('Message') => $item->message,
            __('Status') => $item->status->label(),
            __('Response') => (string) $item->reply,
            __('Replied by') => (string) $item->replier?->name,
        ]);

        return SpreadsheetDownload::make('kritik-saran.xlsx', $rows);
    }
}; ?>

<section class="w-full max-w-5xl">
    <x-page-header :title="__('Reviews & suggestions')" :subtitle="__('Reviews and suggestions from mahasantri, wali, muhadhir and staff. Anonymous senders are hidden from the Admin; only the Superadmin can see who they are.')">
        <x-slot:actions>
            <flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($item = $this->selected)
        <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="feedback-detail">
            <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <flux:heading size="lg">{{ $item->subject }}</flux:heading>
                    <flux:text size="sm">
                        {{ $item->senderLabel(auth()->user()) }} · {{ $item->role->label() }} · {{ $item->category->label() }} · {{ $item->created_at->translatedFormat('j M Y H:i') }}
                    </flux:text>
                </div>
                <div class="flex items-center gap-2">
                    <flux:badge size="sm" :color="$item->status->color()">{{ $item->status->label() }}</flux:badge>
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="show(null)" :aria-label="__('Close')" />
                </div>
            </div>
            <p class="mb-4 whitespace-pre-line text-sm">{{ $item->message }}</p>

            @if ($item->reply)
                <flux:text size="sm" class="mb-1">{{ __('Replied by :name, :when', ['name' => $item->replier?->name ?? '—', 'when' => $item->replied_at?->translatedFormat('j M Y H:i')]) }}</flux:text>
            @endif
            <form wire:submit="sendReply" class="space-y-3">
                <flux:textarea wire:model="reply" :label="$item->reply ? __('Response (change)') : __('Response')" rows="4" maxlength="5000" />
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <flux:select wire:model="newStatus" :label="__('Status')" class="sm:max-w-52">
                        @foreach (FeedbackStatus::cases() as $case)
                            <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="flex gap-2">
                        <flux:button type="button" wire:click="saveStatus">{{ __('Save status') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send reply') }}</flux:button>
                    </div>
                </div>
            </form>
        </div>
    @endif

    <flux:radio.group wire:model.live="status" variant="segmented" class="mb-4 w-full sm:w-auto">
        @foreach (FeedbackStatus::cases() as $case)
            <flux:radio :value="$case->value" :label="$case->label().' ('.($this->counts[$case->value] ?? 0).')'" />
        @endforeach
        <flux:radio value="all" :label="__('All')" />
    </flux:radio.group>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="category" class="sm:max-w-52">
            <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
            @foreach (FeedbackCategory::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="role" class="sm:max-w-52">
            <flux:select.option value="">{{ __('All senders') }}</flux:select.option>
            @foreach (Role::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search subject or message')" />
    </div>

    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->messages as $row)
            <button type="button" wire:key="feedback-{{ $row->id }}" wire:click="show({{ $row->id }})" data-test="feedback-{{ $row->id }}"
                @class(['flex w-full items-start gap-3 px-4 py-3 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800', 'bg-zinc-50 dark:bg-zinc-800' => $open === $row->id])>
                <flux:icon.chat-bubble-bottom-center-text class="mt-0.5 size-5 shrink-0 text-zinc-400" />
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium">{{ $row->subject }}</span>
                    <span class="block truncate text-sm text-zinc-600 dark:text-zinc-300">{{ $row->message }}</span>
                    <span class="mt-0.5 block text-xs text-zinc-500">
                        {{ $row->senderLabel(auth()->user()) }} · {{ $row->role->label() }} · {{ $row->category->label() }} · {{ $row->created_at->diffForHumans() }}
                    </span>
                </span>
                <flux:badge size="sm" :color="$row->status->color()">{{ $row->status->label() }}</flux:badge>
            </button>
        @empty
            <flux:text class="p-4">{{ __('No messages here.') }}</flux:text>
        @endforelse
    </div>
    <div class="mt-4">{{ $this->messages->links() }}</div>
</section>
