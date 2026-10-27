<?php

use App\Actions\Exams\ExamEligibility;
use App\Models\ExamPeriod;
use App\Models\Semester;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Exam card')] class extends Component {
    /**
     * Published exam periods of the active semester.
     *
     * @return Collection<int, ExamPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return ExamPeriod::query()
            ->where('semester_id', Semester::active()?->id)
            ->whereNotNull('published_at')
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function statuses(): array
    {
        $student = auth()->user()->student;
        $eligibility = app(ExamEligibility::class);

        return $student === null ? [] : $this->periods
            ->mapWithKeys(fn (ExamPeriod $period) => [$period->id => $eligibility->check($period, $student)])
            ->all();
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('Exam card')" :subtitle="__('Your exam schedule, seat numbers and exam card. The card can be printed once the semester bill is paid in full; subjects below the attendance minimum are not on the card.')" />

    @forelse ($this->periods as $period)
        @php($status = $this->statuses[$period->id])
        <flux:card wire:key="period-{{ $period->id }}" class="mb-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">{{ $period->type->label() }}</flux:heading>
                @if ($status['eligible'])
                    <flux:button variant="primary" icon="printer" :href="route('exams.card', [$period, auth()->user()->student])" target="_blank" data-test="print-card">{{ __('Print exam card') }}</flux:button>
                @endif
            </div>

            @if ($status['dispensation'])
                <flux:callout icon="document-check" color="blue" :text="__('Dispensation: letter :number.', ['number' => $status['dispensation']->letter_number])" />
            @elseif (! $status['payment_ok'])
                <flux:callout icon="banknotes" color="amber" data-test="payment-missing"
                    :text="__('The semester bill is not paid in full yet (still to pay :amount). The exam card can be printed once it is paid and verified.', ['amount' => Money::format($status['outstanding'])])">
                    <x-slot name="actions"><flux:button size="sm" :href="route('mahasantri.bills')" wire:navigate>{{ __('My bills') }}</flux:button></x-slot>
                </flux:callout>
            @endif

            @if ($status['seat'])
                <div class="flex flex-wrap items-center gap-3 rounded-lg border border-brand-tint bg-brand-soft px-4 py-3" data-test="period-seat">
                    <flux:icon.map-pin class="size-5 text-accent-content" />
                    <span class="text-sm">{{ __('Your seat for every exam of this period:') }}</span>
                    <span class="font-semibold">{{ __('Room :room · seat :seat', ['room' => $status['seat']->room->name, 'seat' => $status['seat']->seat_number]) }}</span>
                </div>
            @endif

            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($status['subjects'] as $subject)
                    <div wire:key="subject-{{ $subject['session']->id }}" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <span>
                            <span class="font-medium">{{ $subject['session']->courseOffering->subject_name }}</span>
                            <span class="block text-xs text-zinc-500">{{ $subject['session']->timeLabel() }}</span>
                        </span>
                        @if ($subject['ok'])
                            <flux:badge size="sm" color="green">{{ __('On the card') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="red">{{ __('Attendance :percent% (minimum :min%): not on the card', ['percent' => $subject['attendance'], 'min' => Setting::get('attendance.min_percent', 75)]) }}</flux:badge>
                        @endif
                    </div>
                @endforeach
            </div>
        </flux:card>
    @empty
        <flux:text>{{ __('No exam schedule has been published yet.') }}</flux:text>
    @endforelse
</section>
