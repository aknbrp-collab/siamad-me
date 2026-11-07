<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Evaluations\ManageEvaluations;
use App\Enums\Role;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\EvaluationQuestion;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->semester = Semester::factory()->active()->create();
    $class = ClassGroup::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->otherTeacher = Teacher::factory()->create();

    $offer = fn (string $name, Teacher $teacher) => app(ManageCourseOfferings::class)->save($this->semester, null, [
        'subject_id' => Subject::factory()->create(['name' => $name])->id, 'class_group_id' => $class->id,
        'teacher_id' => $teacher->id, 'day_of_week' => 6,
    ]);
    $this->fiqh = $offer('Fiqh Ibadah', $this->teacher);
    $this->nahwu = $offer('Nahwu', $this->otherTeacher);

    $this->students = Student::factory()->count(4)->create();
    app(ManageClassEnrollments::class)->enroll($this->semester, $class, $this->students->modelKeys());

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->evaluations = app(ManageEvaluations::class);
    $this->evaluations->prepareQuestions($this->semester);
    $this->evaluations->savePeriod($this->semester, [
        'opens_on' => today()->subDay()->toDateString(), 'closes_on' => today()->addWeek()->toDateString(),
        'comment_prompt' => 'Saran untuk muhadhir', 'required_for_khs' => false,
    ]);
});

/**
 * @return array<int, int> question id => score
 */
function evaluationScores(Semester $semester, int $score): array
{
    return EvaluationQuestion::query()->where('semester_id', $semester->id)->where('is_active', true)->pluck('id')
        ->mapWithKeys(fn (int $id) => [$id => $score])->all();
}

test('an empty semester gets the default statements, a later one copies the previous semester', function () {
    expect(EvaluationQuestion::query()->where('semester_id', $this->semester->id)->count())->toBe(count(ManageEvaluations::DEFAULT_QUESTIONS));

    $first = EvaluationQuestion::query()->where('semester_id', $this->semester->id)->orderBy('position')->first();
    $this->evaluations->setActive($first, false);
    $this->evaluations->addQuestion($this->semester, 'Akhlak', 'Menjadi teladan dalam adab dan akhlak.');

    $next = Semester::factory()->even()->create(['academic_year_id' => $this->semester->academic_year_id]);
    $this->evaluations->prepareQuestions($next);

    $copied = EvaluationQuestion::query()->where('semester_id', $next->id)->orderBy('position')->pluck('question');
    expect($copied)->toHaveCount(count(ManageEvaluations::DEFAULT_QUESTIONS))
        ->and($copied)->not->toContain($first->question)
        ->and($copied->last())->toBe('Menjadi teladan dalam adab dan akhlak.');

    expect(fn () => $this->evaluations->prepareQuestions($next))->toThrow(ValidationException::class);
});

test('a mahasantri evaluates each class once; only counts are kept, without the mahasantri', function () {
    $student = $this->students[0];
    $this->actingAs($student->user)->withSession(['active_role' => Role::Student->value]);

    $this->get(route('mahasantri.dashboard'))->assertOk()->assertSeeHtml('data-test="evaluation-reminder"');
    $this->get(route('mahasantri.evaluations'))->assertOk()->assertSee('Fiqh Ibadah');

    $component = Livewire::test('pages::mahasantri.evaluations')
        ->call('choose', $this->fiqh->id)
        ->assertSeeHtml('data-test="evaluation-form"')
        ->assertSee('Saran untuk muhadhir')
        ->call('submit')
        ->assertHasErrors('scores');

    foreach (evaluationScores($this->semester, 4) as $id => $score) {
        $component->set("scores.$id", (string) $score);
    }
    $component->set('comment', 'Penjelasannya jelas, mohon lebih banyak contoh.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="class-'.$this->fiqh->id.'"');

    expect($this->evaluations->pendingCount($student))->toBe(1)
        ->and(DB::table('evaluation_scores')->where('course_offering_id', $this->fiqh->id)->sum('count'))->toBe(count(ManageEvaluations::DEFAULT_QUESTIONS))
        ->and(DB::table('evaluation_comments')->count())->toBe(1)
        // No answer row names the mahasantri; nor is any of it in the audit log.
        ->and(Schema::getColumnListing('evaluation_scores'))->not->toContain('student_id')
        ->and(Schema::getColumnListing('evaluation_comments'))->not->toContain('student_id')
        ->and(DB::table('audit_logs')->where('auditable_type', 'like', '%Evaluation%')->where('user_id', $student->user_id)->exists())->toBeFalse();

    expect(fn () => $this->evaluations->submit($student, $this->fiqh, evaluationScores($this->semester, 5), null))
        ->toThrow(ValidationException::class);
});

test('results stay hidden below three answers, then show averages, distribution and comments', function () {
    $this->evaluations->submit($this->students[0], $this->fiqh, evaluationScores($this->semester, 5), 'Sangat baik');
    $this->evaluations->submit($this->students[1], $this->fiqh, evaluationScores($this->semester, 3), null);

    $result = $this->evaluations->results($this->semester)->firstWhere('offering.id', $this->fiqh->id);
    expect($result['responses'])->toBe(2)->and($result['enrolled'])->toBe(4)
        ->and($result['shown'])->toBeFalse()->and($result['average'])->toBeNull()->and($result['comments'])->toBe([]);

    $this->evaluations->submit($this->students[2], $this->fiqh, evaluationScores($this->semester, 4), 'Mohon tepat waktu');

    $result = $this->evaluations->results($this->semester)->firstWhere('offering.id', $this->fiqh->id);
    expect($result['shown'])->toBeTrue()
        ->and($result['average'])->toBe(4.0)
        ->and($result['questions'][0]['distribution'])->toBe([1 => 0, 2 => 0, 3 => 1, 4 => 1, 5 => 1])
        ->and($result['comments'])->toHaveCount(2);

    // Admin and the academic monitor see every class; Excel export.
    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value]);
    $this->get(route('evaluations'))->assertOk()->assertSee('Fiqh Ibadah')->assertSee('Nahwu');
    Livewire::test('pages::evaluations.index')
        ->set('open', $this->fiqh->id)
        ->assertSee('Mohon tepat waktu')
        ->call('export')
        ->assertFileDownloaded('evaluasi-muhadhir-'.$this->semester->id.'.xlsx');

    $monitor = User::factory()->withRole(Role::AcademicMonitor)->create();
    $this->actingAs($monitor)->withSession(['active_role' => Role::AcademicMonitor->value]);
    Livewire::test('pages::evaluations.index')
        ->assertSet('tab', 'results')
        ->assertDontSee(__('Questions & period'))
        ->call('savePeriod')
        ->assertForbidden();
});

test('a muhadhir sees only their own results, and only after the period closes', function () {
    foreach ($this->students->take(3) as $student) {
        $this->evaluations->submit($student, $this->fiqh, evaluationScores($this->semester, 4), null);
    }
    $this->actingAs($this->teacher->user)->withSession(['active_role' => Role::Teacher->value]);

    Livewire::test('pages::ustadz.evaluations')->assertSee(__('No evaluation has closed yet.'))->assertDontSee('Fiqh Ibadah');

    $this->travel(8)->days();
    Livewire::test('pages::ustadz.evaluations')
        ->assertSee('Fiqh Ibadah')
        ->assertSeeHtml('data-test="evaluation-result-'.$this->fiqh->id.'"')
        ->assertDontSee('Nahwu');

    // Closed: no more answers.
    expect(fn () => $this->evaluations->submit($this->students[3], $this->fiqh, evaluationScores($this->semester, 4), null))
        ->toThrow(ValidationException::class);
});

test('when required, the KHS of the semester waits until every class is evaluated', function () {
    $student = $this->students[0];
    $this->evaluations->savePeriod($this->semester, [
        'opens_on' => today()->toDateString(), 'closes_on' => today()->addWeek()->toDateString(),
        'comment_prompt' => 'Saran', 'required_for_khs' => true,
    ]);
    $this->actingAs($student->user)->withSession(['active_role' => Role::Student->value]);

    Livewire::test('pages::mahasantri.khs')->assertSeeHtml('data-test="khs-waits-for-evaluation"');
    $this->get(route('records.khs', [$student, $this->semester]))->assertForbidden();

    $this->evaluations->submit($student, $this->fiqh, evaluationScores($this->semester, 4), null);
    $this->evaluations->submit($student, $this->nahwu, evaluationScores($this->semester, 4), null);

    Livewire::test('pages::mahasantri.khs')->assertDontSeeHtml('data-test="khs-waits-for-evaluation"');
    $this->get(route('records.khs', [$student, $this->semester]))->assertOk();
});

test('the Admin sets up the period and statements; answered statements cannot be reworded', function () {
    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value]);
    $question = EvaluationQuestion::query()->where('semester_id', $this->semester->id)->orderBy('position')->first();

    Livewire::test('pages::evaluations.index')
        ->set('tab', 'setup')
        ->assertSeeHtml('data-test="period-form"')
        ->set('closesOn', today()->subMonth()->toDateString())
        ->call('savePeriod')
        ->assertHasErrors('closes_on')
        ->set('closesOn', today()->addMonth()->toDateString())
        ->set('requiredForKhs', true)
        ->call('savePeriod')
        ->assertHasNoErrors()
        ->call('editQuestion', $question->id)
        ->set('question', 'Menguasai materi dan kitab rujukannya.')
        ->call('saveQuestion')
        ->assertHasNoErrors()
        ->call('move', $question->id, 1);

    expect($question->fresh()->question)->toBe('Menguasai materi dan kitab rujukannya.')
        ->and($question->fresh()->position)->toBe(2);

    $this->evaluations->submit($this->students[0], $this->fiqh, evaluationScores($this->semester, 4), null);
    expect(fn () => $this->evaluations->updateQuestion($question->fresh(), 'Penguasaan materi', 'Lain'))->toThrow(ValidationException::class);
});

test('a class kuliah the mahasantri does not follow cannot be evaluated', function () {
    $outsider = Student::factory()->create();

    expect(fn () => $this->evaluations->submit($outsider, $this->fiqh, evaluationScores($this->semester, 4), null))
        ->toThrow(ValidationException::class)
        ->and(CourseOffering::query()->count())->toBe(2);
});
