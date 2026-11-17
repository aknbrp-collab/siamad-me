<?php

namespace App\Livewire;

use App\Livewire\Concerns\PicksStudents;
use Livewire\Component;

/**
 * A page with a mahasantri picker (v7 D43): bimbingan groups, semester placements, scholarships, activities
 * and LPPPK moderation. Pages built on another base class use PicksStudents directly.
 */
abstract class PageWithStudentPicker extends Component
{
    use PicksStudents;
}
