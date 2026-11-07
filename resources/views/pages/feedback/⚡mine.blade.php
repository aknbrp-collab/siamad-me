<?php

use App\Actions\Feedback\ManageFeedback;
use App\Enums\FeedbackCategory;
use App\Models\Feedback;
use App\Support\ActiveRole;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Reviews & suggestions')] class extends Component {
    use WithPagination;

    public string $category = '';

    public string $subject = '';

    public string $message = '';

    public bool $anonymous = false;

    public function send(ManageFeedback $feedback): void
    {
        $feedback->send(auth()->user(), ActiveRole::get(), [
            'category' => $this->category,
            'subject' => $this->subject,
            'message' => $this->message,
            'anonymous' => $this->anonymous,
        ]);

        $this->reset('category', 'subject', 'message', 'anonymous');
        $this->resetPage();
        Flux::toast(variant: 'success', text: __('Thank you. Your message was sent.'));
    }

    /**
     * @return LengthAwarePaginator<int, Feedback>
     */
    #[Computed]
    public function sent(): LengthAwarePaginator
    {
        return Feedback::query()->where('user_id', auth()->id())->latest('id')->paginate(10);
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('Reviews & suggestions')" :subtitle="__('Send a review or suggestion about the services of the Ma\'had Aly or this app. The Admin reads every message and replies here.')" />

    <form wire:submit="send" class="mb-8 space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="feedback-form">
        <div class="grid gap-4 sm:grid-cols-[14rem_1fr]">
            <flux:select wire:model="category" :label="__('Category')" :placeholder="__('Choose…')">
                @foreach (FeedbackCategory::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="subject" :label="__('Topic')" maxlength="150" />
        </div>
        <flux:textarea wire:model="message" :label="__('Message')" rows="5" maxlength="5000" />
        <flux:checkbox wire:model="anonymous" :label="__('Send anonymously')"
            :description="__('The Admin will not see your name. It is still recorded and visible to the Superadmin, and you still receive the reply here.')" />
        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send') }}</flux:button>
        </div>
    </form>

    <flux:heading size="lg" class="mb-3">{{ __('My messages') }}</flux:heading>
    <div class="space-y-3">
        @forelse ($this->sent as $item)
            <div wire:key="feedback-{{ $item->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="my-feedback-{{ $item->id }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <flux:heading>{{ $item->subject }}</flux:heading>
                        <flux:text size="sm">
                            {{ $item->category->label() }} · {{ $item->created_at->translatedFormat('j M Y H:i') }}
                            @if ($item->anonymous) · {{ __('anonymous') }} @endif
                        </flux:text>
                    </div>
                    <flux:badge size="sm" :color="$item->status->color()">{{ $item->status->label() }}</flux:badge>
                </div>
                <p class="mt-2 whitespace-pre-line text-sm">{{ $item->message }}</p>
                @if ($item->reply)
                    <div class="mt-3 rounded-md border-s-4 border-[var(--color-accent)] bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                        <div class="mb-1 text-xs text-zinc-500">{{ __('Response') }} · {{ $item->replied_at?->translatedFormat('j M Y H:i') }}</div>
                        <p class="whitespace-pre-line">{{ $item->reply }}</p>
                    </div>
                @endif
            </div>
        @empty
            <flux:text>{{ __('You have not sent any feedback yet.') }}</flux:text>
        @endforelse
    </div>
    <div class="mt-4">{{ $this->sent->links() }}</div>
</section>
