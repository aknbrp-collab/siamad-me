<?php

use App\Actions\Letters\ManageLetters;
use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Student;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My letters')] class extends Component {
    public ?int $requestTypeId = null;
    public string $purpose = '';

    #[Computed]
    public function student(): Student
    {
        $student = auth()->user()->student;
        abort_if($student === null, 403);

        return $student;
    }

    /**
     * @return Collection<int, LetterType>
     */
    #[Computed]
    public function requestable(): Collection
    {
        return LetterType::query()->where('is_active', true)->where('requestable', true)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, LetterRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        return LetterRequest::query()->with(['type', 'letter'])->where('student_id', $this->student->id)->latest('id')->get();
    }

    /**
     * Letters about this mahasantri that are not cancelled.
     *
     * @return Collection<int, Letter>
     */
    #[Computed]
    public function letters(): Collection
    {
        return Letter::query()->with('type')->where('student_id', $this->student->id)->whereNull('cancelled_at')->orderByDesc('issued_on')->orderByDesc('id')->get();
    }

    public function submit(ManageLetters $letters): void
    {
        $this->resetErrorBag();
        $letters->request($this->student, (int) $this->requestTypeId, $this->purpose);
        $this->reset('requestTypeId', 'purpose');
        unset($this->requests);
        Flux::toast(variant: 'success', text: __('Request sent. You will get a notification when the letter is ready.'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('My letters')" :subtitle="__('Request a letter from the academic office (e.g. a statement that you are an active student) and download the letters issued for you.')" />

    @if ($this->requestable->isNotEmpty())
        <flux:card class="mb-8">
            <form wire:submit="submit" class="space-y-4">
                <flux:heading>{{ __('Request a letter') }}</flux:heading>
                <flux:select wire:model="requestTypeId" :label="__('Letter type')" :placeholder="__('Choose…')" required>
                    @foreach ($this->requestable as $type)
                        <flux:select.option :value="$type->id">{{ $type->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="requestTypeId" />
                <flux:input wire:model="purpose" :label="__('What is it for?')" :placeholder="__('e.g. applying for the Baznas scholarship')" required />
                <flux:error name="purpose" />
                <flux:button type="submit" variant="primary" icon="paper-airplane" data-test="request-letter">{{ __('Send request') }}</flux:button>
            </form>
        </flux:card>
    @endif

    <flux:heading size="lg" class="mb-3">{{ __('Letters for you') }}</flux:heading>
    <div class="mb-8 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->letters as $letter)
            <div wire:key="letter-{{ $letter->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3">
                <div class="min-w-0 flex-1 text-sm">
                    <div class="font-medium">{{ $letter->subject_line }}</div>
                    <div class="font-mono text-xs text-zinc-500">{{ $letter->number }} · {{ $letter->issued_on->translatedFormat('j M Y') }}</div>
                </div>
                <flux:button size="sm" icon="arrow-down-tray" :href="route('letters.pdf', $letter)" target="_blank" data-test="download-{{ $letter->id }}">{{ __('PDF') }}</flux:button>
            </div>
        @empty
            <flux:text class="p-4 text-sm">{{ __('No letters yet.') }}</flux:text>
        @endforelse
    </div>

    @if ($this->requests->isNotEmpty())
        <flux:heading size="lg" class="mb-3">{{ __('My requests') }}</flux:heading>
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->requests as $request)
                <div wire:key="request-{{ $request->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ $request->type->name }}</div>
                        <div class="text-zinc-600 dark:text-zinc-400">{{ $request->purpose }}</div>
                        @if ($request->reject_reason)
                            <div class="text-red-600 dark:text-red-400">{{ $request->reject_reason }}</div>
                        @endif
                    </div>
                    <flux:badge size="sm" :color="$request->status->color()">{{ $request->status->label() }}</flux:badge>
                </div>
            @endforeach
        </div>
    @endif
</section>
