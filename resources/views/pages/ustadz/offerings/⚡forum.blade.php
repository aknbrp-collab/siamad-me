<?php

use App\Models\CourseOffering;
use App\Support\OfferingAccess;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Forum')] class extends Component {
    public CourseOffering $offering;

    public function mount(CourseOffering $offering): void
    {
        abort_unless(OfferingAccess::teaches(auth()->user(), $offering), 403);

        $this->offering = $offering->load(['classGroup', 'semester.academicYear']);
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-offering-header :offering="$offering" current="forum" />

    <livewire:pages::shared.forum :offering="$offering" />
</section>
