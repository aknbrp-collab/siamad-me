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

    <flux:navbar scrollable class="-mb-px border-b border-zinc-200 dark:border-zinc-700">
        <flux:navbar.item icon="calendar-days" :href="route('ustadz.offerings.meetings', $offering)" :current="$current === 'meetings'" wire:navigate>{{ __('Meetings') }}</flux:navbar.item>
        <flux:navbar.item icon="document-text" :href="route('ustadz.offerings.materials', $offering)" :current="$current === 'materials'" wire:navigate>{{ __('Materials') }}</flux:navbar.item>
        <flux:navbar.item icon="clipboard-document-list" :href="route('ustadz.offerings.assignments', $offering)" :current="$current === 'assignments'" wire:navigate>{{ __('Assignments') }}</flux:navbar.item>
        <flux:navbar.item icon="question-mark-circle" :href="route('ustadz.offerings.quizzes', $offering)" :current="$current === 'quizzes'" wire:navigate>{{ __('Quizzes') }}</flux:navbar.item>
        <flux:navbar.item icon="chat-bubble-left-right" :href="route('ustadz.offerings.forum', $offering)" :current="$current === 'forum'" wire:navigate>{{ __('Forum') }}</flux:navbar.item>
        <flux:navbar.item icon="calculator" :href="route('ustadz.offerings.grades', $offering)" :current="$current === 'grades'" wire:navigate>{{ __('Grades') }}</flux:navbar.item>
    </flux:navbar>
</div>
