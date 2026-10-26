<?php

use App\Actions\Teaching\AcademicRecord;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Transcript')] class extends Component {
    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function transcript(): ?array
    {
        $student = auth()->user()->student;

        return $student ? app(AcademicRecord::class)->transcript($student) : null;
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('Transcript')" :subtitle="__('All finalised grades across semesters.')">
        <x-slot:actions>
            @if (auth()->user()->student)
                <flux:button icon="arrow-down-tray" :href="route('records.transcript', auth()->user()->student)" target="_blank">{{ __('PDF') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($this->transcript)
        <x-transcript-table :transcript="$this->transcript" />
    @endif
</section>
