@props(['offering', 'current'])

<div class="mb-6 space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $offering->subject_name }}</flux:heading>
            <flux:subheading>
                {{ $offering->subject_code }} · {{ $offering->credits }} {{ __('SKS') }} · {{ $offering->classGroup->name }} ·
                {{ $offering->semester->label() }}
            </flux:subheading>
        </div>
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('ustadz.dashboard', ['semester' => $offering->semester_id])" wire:navigate>{{ __('My classes') }}</flux:button>
    </div>

    @if ($offering->semester->isLocked())
        <flux:callout icon="lock-closed" color="amber" :heading="__('Archive — read only')" :text="__('This semester is closed.')" />
    @endif

    @php($tabs = [
        'meetings' => ['calendar-days', __('Meetings')],
        'materials' => ['document-text', __('Materials')],
        'assignments' => ['clipboard-document-list', __('Assignments')],
        'quizzes' => ['question-mark-circle', __('Quizzes')],
        'forum' => ['chat-bubble-left-right', __('Forum')],
        'grades' => ['calculator', __('Grades')],
        'documents' => ['document-check', __('Documents')],
    ])

    {{-- Phones: every section visible as a grid instead of a tab bar that scrolls out of sight. --}}
    <div class="grid grid-cols-3 gap-2 sm:hidden">
        @foreach ($tabs as $tab => [$icon, $label])
            <flux:button size="sm" :icon="$icon" :variant="$current === $tab ? 'primary' : 'outline'"
                :href="route('ustadz.offerings.'.$tab, $offering)" wire:navigate>{{ $label }}</flux:button>
        @endforeach
    </div>

    <flux:navbar scrollable class="-mb-px border-b border-zinc-200 max-sm:hidden dark:border-zinc-700">
        @foreach ($tabs as $tab => [$icon, $label])
            <flux:navbar.item :icon="$icon" :href="route('ustadz.offerings.'.$tab, $offering)" :current="$current === $tab" wire:navigate>{{ $label }}</flux:navbar.item>
        @endforeach
    </flux:navbar>
</div>
