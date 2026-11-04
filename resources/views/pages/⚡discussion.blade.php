<?php

use App\Actions\Discussions\ManageDiscussions;
use App\Enums\DiscussionKind;
use App\Models\Discussion;
use App\Models\DiscussionFile;
use App\Models\DiscussionMember;
use App\Models\DiscussionMessage;
use App\Models\DiscussionNote;
use App\Support\DiscussionAccess;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Discussion')] class extends Component {
    use WithFileUploads;

    public Discussion $discussion;

    #[Url]
    public string $tab = 'chat';

    /** How many of the latest messages are shown. */
    public int $limit = 50;

    public string $body = '';

    /** @var array<int, mixed> */
    public array $uploads = [];

    public ?int $replyToId = null;

    public ?int $noteId = null;
    public string $noteTitle = '';
    public string $noteBody = '';

    public ?int $muteMemberId = null;
    public ?string $mutedUntil = null;

    public function mount(Discussion $discussion, ManageDiscussions $manage): void
    {
        abort_unless(DiscussionAccess::canView(auth()->user(), $discussion), 403);

        $this->discussion = $discussion;
        $manage->memberFor($discussion, auth()->user());
        $this->markRead($manage);
    }

    #[Computed]
    public function moderator(): bool
    {
        return DiscussionAccess::canModerate(auth()->user(), $this->discussion);
    }

    #[Computed]
    public function cannotPost(): ?string
    {
        return DiscussionAccess::whyNotPost(auth()->user(), $this->discussion);
    }

    /**
     * The latest messages, oldest first.
     *
     * @return Collection<int, DiscussionMessage>
     */
    #[Computed]
    public function messages(): Collection
    {
        return $this->discussion->messages()
            ->with(['user', 'files', 'replyTo.user'])
            ->orderByDesc('id')
            ->limit($this->limit)
            ->get()
            ->reverse()
            ->values();
    }

    #[Computed]
    public function hasEarlier(): bool
    {
        $first = $this->messages->first();

        return $first !== null && $this->discussion->messages()->where('id', '<', $first->id)->exists();
    }

    #[Computed]
    public function replyTo(): ?DiscussionMessage
    {
        return $this->replyToId ? $this->discussion->messages()->with('user')->find($this->replyToId) : null;
    }

    /**
     * @return Collection<int, DiscussionFile>
     */
    #[Computed]
    public function files(): Collection
    {
        return $this->discussion->files()->with('user')
            ->whereHas('message', fn ($query) => $query->whereNull('hidden_at'))
            ->latest('id')->get();
    }

    /**
     * @return Collection<int, DiscussionNote>
     */
    #[Computed]
    public function notes(): Collection
    {
        return $this->discussion->notes()->with(['user', 'editor'])->whereNull('hidden_at')->latest('updated_at')->get();
    }

    /**
     * @return Collection<int, DiscussionMember>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->discussion->members()->with('user')->get()
            ->sortBy(fn (DiscussionMember $member) => ($member->role === 'lead' ? '0' : '1').$member->user->name)
            ->values();
    }

    /**
     * Called every few seconds while the chat is open (D27).
     */
    public function refresh(ManageDiscussions $manage): void
    {
        unset($this->messages, $this->hasEarlier, $cannotPost);
        $this->markRead($manage);
    }

    public function loadEarlier(): void
    {
        $this->limit += 50;
        unset($this->messages, $this->hasEarlier);
    }

    public function send(ManageDiscussions $manage): void
    {
        $this->resetErrorBag();

        try {
            $manage->post($this->discussion, auth()->user(), $this->body, array_values($this->uploads), $this->replyToId);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => [str_starts_with($key, 'files') ? 'uploads' : 'body' => $messages])->all());
        }

        $this->reset('body', 'uploads', 'replyToId');
        unset($this->messages, $this->hasEarlier, $this->files);
        $this->dispatch('discussion-sent');
    }

    public function reply(int $messageId): void
    {
        $this->replyToId = $messageId;
        unset($this->replyTo);
    }

    public function cancelReply(): void
    {
        $this->replyToId = null;
    }

    public function hide(int $messageId, ManageDiscussions $manage): void
    {
        $manage->hide($this->discussion->messages()->findOrFail($messageId), auth()->user());
        unset($this->messages, $this->files);
    }

    public function editNote(?int $noteId = null): void
    {
        $note = $noteId ? $this->discussion->notes()->findOrFail($noteId) : null;
        $this->noteId = $note?->id;
        $this->noteTitle = $note?->title ?? '';
        $this->noteBody = $note?->body ?? '';
        $this->resetErrorBag();
        Flux::modal('discussion-note')->show();
    }

    public function saveNote(ManageDiscussions $manage): void
    {
        $this->resetErrorBag();
        $note = $this->noteId ? $this->discussion->notes()->findOrFail($this->noteId) : null;
        $manage->saveNote($this->discussion, auth()->user(), $note, $this->noteTitle, $this->noteBody);

        Flux::modal('discussion-note')->close();
        $this->reset('noteId', 'noteTitle', 'noteBody');
        unset($this->notes);
    }

    public function hideNote(int $noteId, ManageDiscussions $manage): void
    {
        $manage->hideNote($this->discussion->notes()->findOrFail($noteId), auth()->user());
        unset($this->notes);
    }

    public function startMute(int $memberId): void
    {
        $this->muteMemberId = $memberId;
        $this->mutedUntil = null;
        $this->resetErrorBag();
        Flux::modal('discussion-mute')->show();
    }

    public function mute(ManageDiscussions $manage): void
    {
        $this->resetErrorBag();
        $until = filled($this->mutedUntil) ? Carbon::parse((string) $this->mutedUntil) : null;
        $manage->mute($this->discussion->members()->findOrFail($this->muteMemberId), $until, auth()->user());

        Flux::modal('discussion-mute')->close();
        unset($this->members);
        Flux::toast(variant: 'success', text: __('Muted.'));
    }

    public function unmute(int $memberId, ManageDiscussions $manage): void
    {
        $manage->unmute($this->discussion->members()->findOrFail($memberId), auth()->user());
        unset($this->members);
        Flux::toast(variant: 'success', text: __('They can post again.'));
    }

    public function toggleLock(ManageDiscussions $manage): void
    {
        $manage->setLocked($this->discussion, $this->discussion->locked_at === null, auth()->user());
        $this->discussion->refresh();
        unset($cannotPost);
    }

    private function markRead(ManageDiscussions $manage): void
    {
        $latest = (int) $this->discussion->messages()->max('id');

        if ($latest > 0) {
            $manage->markRead($this->discussion, auth()->user(), $latest);
        }
    }
}; ?>

{{-- Computed once per render: Livewire does not keep a computed null, and this is used per message. --}}
@php($cannotPost = $this->cannotPost)
<section class="flex w-full max-w-4xl flex-col">
    <x-page-header :title="$discussion->name" :subtitle="collect([
        $discussion->kind->label(),
        $discussion->leadTeacher ? __('Led by :name', ['name' => $discussion->leadTeacher->displayName()]) : null,
        $discussion->description,
    ])->filter()->join(' · ')">
        <x-slot:actions>
            @if ($discussion->closed_at)
                <flux:badge color="zinc" icon="archive-box">{{ __('Closed') }}</flux:badge>
            @elseif ($discussion->locked_at)
                <flux:badge color="amber" icon="lock-closed">{{ __('Locked') }}</flux:badge>
            @endif
            @if ($discussion->kind === DiscussionKind::TanyaHukum && ($question = \App\Models\LegalQuestion::query()->where('discussion_id', $discussion->id)->first()))
                <flux:button size="sm" icon="scale" :href="route('tanya-hukum.show', $question)" wire:navigate>{{ __('Question and answer') }}</flux:button>
            @endif
            @if ($this->moderator && ! $discussion->closed_at)
                <flux:button size="sm" :icon="$discussion->locked_at ? 'lock-open' : 'lock-closed'" wire:click="toggleLock" data-test="toggle-lock">
                    {{ $discussion->locked_at ? __('Unlock') : __('Lock') }}
                </flux:button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <flux:radio.group wire:model.live="tab" variant="segmented" class="mb-4 w-full sm:w-auto">
        <flux:radio value="chat" icon="chat-bubble-left-right" :label="__('Chat')" />
        <flux:radio value="files" icon="paper-clip" :label="__('Files').' ('.$this->files->count().')'" />
        <flux:radio value="notes" icon="bookmark" :label="__('Notes').' ('.$this->notes->count().')'" />
        @if ($discussion->kind === DiscussionKind::Bimbingan || $this->moderator)
            <flux:radio value="members" icon="users" :label="__('Members')" />
        @endif
    </flux:radio.group>

    @if ($tab === 'chat')
        <div wire:poll.5s.visible="refresh"
            x-data="{ atBottom: true }"
            x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => { if (atBottom) $el.scrollTop = $el.scrollHeight }).observe($el, { childList: true, subtree: true })"
            x-on:scroll="atBottom = $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 80"
            x-on:discussion-sent.window="atBottom = true; $nextTick(() => $el.scrollTop = $el.scrollHeight)"
            class="h-[60vh] min-h-80 space-y-3 overflow-y-auto rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900"
            data-test="discussion-messages">
            @if ($this->hasEarlier)
                <div class="text-center">
                    <flux:button size="xs" variant="ghost" wire:click="loadEarlier">{{ __('Show earlier messages') }}</flux:button>
                </div>
            @endif

            @forelse ($this->messages as $message)
                @php($mine = $message->user_id === auth()->id())
                <div wire:key="message-{{ $message->id }}" @class(['flex', 'justify-end' => $mine])>
                    <div @class([
                        'group max-w-[85%] rounded-lg px-3 py-2 text-sm shadow-xs',
                        'bg-white dark:bg-zinc-800' => ! $mine,
                        'bg-[var(--color-accent)]/10 dark:bg-[var(--color-accent)]/20' => $mine,
                    ])>
                        <div class="mb-0.5 flex items-center gap-2 text-xs text-zinc-500">
                            <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $message->user->name }}</span>
                            <span title="{{ $message->created_at->translatedFormat('j M Y H:i') }}">{{ $message->created_at->translatedFormat($message->created_at->isToday() ? 'H:i' : 'j M H:i') }}</span>
                        </div>

                        @if ($message->isHidden())
                            <div class="italic text-zinc-500">{{ __('This message was removed.') }}</div>
                            @if ($this->moderator)
                                <div class="mt-1 text-xs text-zinc-400 line-through">{{ Str::limit((string) $message->body, 200) }}</div>
                            @endif
                        @else
                            @if ($message->replyTo)
                                <div class="mb-1 border-s-2 border-zinc-300 ps-2 text-xs text-zinc-500 dark:border-zinc-600">
                                    <span class="font-medium">{{ $message->replyTo->user->name }}:</span>
                                    {{ $message->replyTo->isHidden() ? __('This message was removed.') : Str::limit((string) ($message->replyTo->body ?? __('a file')), 120) }}
                                </div>
                            @endif
                            @if ($message->body)
                                <div class="break-words whitespace-pre-line">{{ $message->body }}</div>
                            @endif
                            @foreach ($message->files as $file)
                                <a href="{{ route('files.show', ['discussion-file', $file->id]) }}" target="_blank" class="mt-1 flex items-center gap-1 text-[var(--color-accent-content)] hover:underline">
                                    <flux:icon.paper-clip variant="micro" /> {{ $file->original_name }}
                                    <span class="text-xs text-zinc-500">({{ Number::fileSize($file->size) }})</span>
                                </a>
                            @endforeach
                            <div class="mt-1 hidden gap-3 text-xs group-hover:flex">
                                @unless ($cannotPost)
                                    <button type="button" class="text-zinc-500 hover:underline" wire:click="reply({{ $message->id }})">{{ __('Reply') }}</button>
                                @endunless
                                @if ($mine || $this->moderator)
                                    <button type="button" class="text-red-600 hover:underline" wire:click="hide({{ $message->id }})" wire:confirm="{{ __('Remove this message?') }}" data-test="hide-{{ $message->id }}">{{ __('Remove') }}</button>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <flux:text class="py-8 text-center">{{ __('No messages yet. Start the discussion.') }}</flux:text>
            @endforelse
        </div>

        @if ($cannotPost)
            <flux:callout class="mt-3" icon="information-circle" :text="$cannotPost" data-test="cannot-post" />
        @else
            <form wire:submit="send" class="mt-3 space-y-2">
                @if ($this->replyTo)
                    <div class="flex items-center justify-between rounded-md bg-zinc-100 px-3 py-1.5 text-xs dark:bg-zinc-800">
                        <span>{{ __('Replying to :name', ['name' => $this->replyTo->user->name]) }}: {{ Str::limit((string) ($this->replyTo->body ?? __('a file')), 80) }}</span>
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="cancelReply" :aria-label="__('Cancel')" />
                    </div>
                @endif
                <flux:textarea wire:model="body" rows="2" :placeholder="__('Write a message…')"
                    x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }" />
                <div class="flex flex-wrap items-center gap-2">
                    <input type="file" multiple wire:model="uploads" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp,.zip,.txt"
                        class="min-w-0 flex-1 text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                    <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="send,uploads" data-test="send">{{ __('Send') }}</flux:button>
                </div>
                <flux:text class="text-xs">{{ __('Enter sends, Shift+Enter for a new line. Up to :count files, :size MB each.', ['count' => ManageDiscussions::MAX_FILES, 'size' => \App\Support\Upload::maxMb()]) }}</flux:text>
                <flux:error name="body" />
                <flux:error name="uploads" />
            </form>
        @endif
    @endif

    @if ($tab === 'files')
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->files as $file)
                <a wire:key="file-{{ $file->id }}" href="{{ route('files.show', ['discussion-file', $file->id]) }}" target="_blank" class="flex items-center gap-3 px-4 py-3 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                    <flux:icon.document class="size-5 text-zinc-400" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ $file->original_name }}</span>
                        <span class="text-xs text-zinc-500">{{ $file->user->name }} · {{ $file->created_at->translatedFormat('j M Y H:i') }} · {{ Number::fileSize($file->size) }}</span>
                    </span>
                    <flux:icon.arrow-down-tray class="size-4 text-zinc-400" />
                </a>
            @empty
                <flux:text class="p-4 text-sm">{{ __('No files shared yet. Attach them to a chat message.') }}</flux:text>
            @endforelse
        </div>
    @endif

    @if ($tab === 'notes')
        @unless ($cannotPost)
            <div class="mb-3">
                <flux:button size="sm" icon="plus" wire:click="editNote" data-test="add-note">{{ __('Add note') }}</flux:button>
            </div>
        @endunless
        <div class="grid gap-3 sm:grid-cols-2">
            @forelse ($this->notes as $note)
                <flux:card wire:key="note-{{ $note->id }}" class="space-y-2">
                    <div class="flex items-start justify-between gap-2">
                        <flux:heading>{{ $note->title }}</flux:heading>
                        @if (! $cannotPost && ($note->user_id === auth()->id() || $this->moderator))
                            <div class="flex">
                                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editNote({{ $note->id }})" :aria-label="__('Edit')" />
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="hideNote({{ $note->id }})" wire:confirm="{{ __('Remove this note?') }}" :aria-label="__('Remove')" />
                            </div>
                        @endif
                    </div>
                    <div class="whitespace-pre-line text-sm">{{ $note->body }}</div>
                    <flux:text class="text-xs">
                        {{ $note->user->name }} · {{ $note->updated_at->translatedFormat('j M Y H:i') }}
                        @if ($note->editor && $note->editor->isNot($note->user))
                            · {{ __('edited by :name', ['name' => $note->editor->name]) }}
                        @endif
                    </flux:text>
                </flux:card>
            @empty
                <flux:text class="text-sm">{{ __('No notes yet. Notes keep what the group agreed on: the outline, deadlines, references.') }}</flux:text>
            @endforelse
        </div>
    @endif

    @if ($tab === 'members')
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->members as $member)
                <div wire:key="member-{{ $member->id }}" class="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm">
                    <span class="me-auto">
                        <span class="font-medium">{{ $member->user->name }}</span>
                        @if ($member->role === 'lead')
                            <flux:badge size="sm" color="green" class="ms-1">{{ __('Lead') }}</flux:badge>
                        @endif
                        @if ($member->isMuted())
                            <flux:badge size="sm" color="amber" class="ms-1">{{ $member->muted_until ? __('Muted until :date', ['date' => $member->muted_until->translatedFormat('j M H:i')]) : __('Muted') }}</flux:badge>
                        @endif
                    </span>
                    @if ($this->moderator && $member->role !== 'lead' && $member->user_id !== auth()->id())
                        @if ($member->isMuted())
                            <flux:button size="xs" wire:click="unmute({{ $member->id }})">{{ __('Unmute') }}</flux:button>
                        @else
                            <flux:button size="xs" variant="ghost" icon="speaker-x-mark" wire:click="startMute({{ $member->id }})" data-test="mute-{{ $member->id }}">{{ __('Mute') }}</flux:button>
                        @endif
                    @endif
                </div>
            @empty
                <flux:text class="p-4 text-sm">{{ __('Nobody has joined yet.') }}</flux:text>
            @endforelse
        </div>
    @endif

    <flux:modal name="discussion-note" class="w-full max-w-lg">
        <form wire:submit="saveNote" class="space-y-4">
            <flux:heading size="lg">{{ $noteId ? __('Edit note') : __('Add note') }}</flux:heading>
            <flux:input wire:model="noteTitle" :label="__('Title')" required />
            <flux:textarea wire:model="noteBody" :label="__('Note')" rows="8" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="discussion-mute" class="w-full max-w-md">
        <form wire:submit="mute" class="space-y-4">
            <flux:heading size="lg">{{ __('Mute') }}</flux:heading>
            <flux:text>{{ __('They can still read the discussion but cannot post.') }}</flux:text>
            <flux:input wire:model="mutedUntil" type="datetime-local" :label="__('Until')" :description:trailing="__('Leave empty to mute until you lift it.')" />
            <flux:error name="mutedUntil" />
            <flux:error name="member" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Mute') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
