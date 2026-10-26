<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Teaching\AcademicRecord;
use App\Actions\Teaching\ManageGrades;
use App\Actions\Teaching\ManageMeetings;
use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\GradeScale;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->semester = Semester::factory()->active()->create();
    $this->classGroup = ClassGroup::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $subject = Subject::factory()->create(['code' => 'FQH1', 'name' => 'Fiqh', 'credits' => 3]);
    $this->offering = app(ManageCourseOfferings::class)->save($this->semester, null, [
        'subject_id' => $subject->id, 'class_group_id' => $this->classGroup->id, 'teacher_id' => $this->teacher->id,
    ]);
    [$this->a, $this->b] = Student::factory()->count(2)->create()->all();
    app(ManageClassEnrollments::class)->enroll($this->semester, $this->classGroup, [$this->a->id, $this->b->id]);
    $this->grades = app(ManageGrades::class);
});

function enrollmentOf(Student $student, CourseOffering $offering): CourseEnrollment
{
    return CourseEnrollment::query()->where('student_id', $student->id)->where('course_offering_id', $offering->id)->sole();
}

test('the grade scale maps scores to letters and points', function (float $score, string $letter, string $point) {
    expect(GradeScale::resolve($score))->toBe(['letter' => $letter, 'point' => $point]);
})->with([
    [100, 'A', '4.00'], [85, 'A', '4.00'], [84.99, 'B+', '3.50'], [70, 'B', '3.00'], [54.5, 'D', '1.00'], [12, 'E', '0.00'],
]);

test('component weights must add up to 100', function () {
    expect(fn () => $this->grades->saveComponents($this->offering, [['name' => 'UTS', 'weight' => 40], ['name' => 'UAS', 'weight' => 50]]))
        ->toThrow(ValidationException::class);

    $this->grades->saveComponents($this->offering, [['name' => 'Tugas', 'weight' => 30], ['name' => 'UTS', 'weight' => 30], ['name' => 'UAS', 'weight' => 40]]);

    expect($this->offering->components()->pluck('weight', 'name')->all())->toBe(['Tugas' => 30, 'UTS' => 30, 'UAS' => 40]);
});

test('finalising computes weighted scores, letters and IP, then locks', function () {
    $this->grades->saveComponents($this->offering, [['name' => 'UTS', 'weight' => 40], ['name' => 'UAS', 'weight' => 60]]);
    [$uts, $uas] = $this->offering->components()->get()->all();
    $ea = enrollmentOf($this->a, $this->offering);
    $eb = enrollmentOf($this->b, $this->offering);

    $this->grades->saveScores($this->offering, [$ea->id => [$uts->id => '90', $uas->id => '80'], $eb->id => [$uts->id => '70,5']]);

    // B has no UAS score yet.
    expect(fn () => $this->grades->finalize($this->offering, $this->teacher->user))->toThrow(ValidationException::class);

    $this->grades->saveScores($this->offering, [$eb->id => [$uas->id => 50]]);
    $this->grades->finalize($this->offering, $this->teacher->user);

    expect($ea->fresh())->final_score->toBe('84.00')->final_letter->toBe('B+')->final_point->toBe('3.50')
        ->and($eb->fresh())->final_score->toBe('58.20')->final_letter->toBe('C')
        ->and($this->offering->fresh()->isFinalized())->toBeTrue();

    expect(fn () => $this->grades->saveScores($this->offering, [$ea->id => [$uts->id => 100]]))->toThrow(ValidationException::class);

    $khs = app(AcademicRecord::class)->khs($this->a, $this->semester);
    expect($khs['credits'])->toBe(3)->and($khs['ip'])->toBe(3.5);
});

test('scores outside 0 to 100 are refused', function () {
    $this->grades->saveComponents($this->offering, [['name' => 'UAS', 'weight' => 100]]);
    $component = $this->offering->components()->sole();

    expect(fn () => $this->grades->saveScores($this->offering, [enrollmentOf($this->a, $this->offering)->id => [$component->id => '101']]))
        ->toThrow(ValidationException::class);
});

test('changing the grade scale later never rewrites finalised grades', function () {
    $this->grades->saveComponents($this->offering, [['name' => 'UAS', 'weight' => 100]]);
    $component = $this->offering->components()->sole();
    $this->grades->saveScores($this->offering, [
        enrollmentOf($this->a, $this->offering)->id => [$component->id => 82],
        enrollmentOf($this->b, $this->offering)->id => [$component->id => 82],
    ]);
    $this->grades->finalize($this->offering, $this->teacher->user);

    GradeScale::query()->where('letter', 'B+')->update(['point' => 3.25]);

    expect(enrollmentOf($this->a, $this->offering)->final_point)->toBe('3.50');
});

test('the admin can unlock finalised grades with a reason while the semester is open', function () {
    $this->grades->saveComponents($this->offering, [['name' => 'UAS', 'weight' => 100]]);
    $component = $this->offering->components()->sole();
    foreach ([$this->a, $this->b] as $student) {
        $this->grades->saveScores($this->offering, [enrollmentOf($student, $this->offering)->id => [$component->id => 75]]);
    }
    $this->grades->finalize($this->offering, $this->teacher->user);

    expect(fn () => $this->grades->unlock($this->offering, ''))->toThrow(ValidationException::class);

    $this->grades->unlock($this->offering, 'Salah input nilai UAS');

    expect($this->offering->fresh())->isFinalized()->toBeFalse()->grades_note->toBe('Salah input nilai UAS')
        ->and(enrollmentOf($this->a, $this->offering)->final_letter)->toBeNull();
});

test('attendance is recorded for everyone and summarised', function () {
    $meetings = app(ManageMeetings::class);
    $first = $meetings->save($this->offering, null, ['held_on' => '2026-08-01', 'mode' => 'offline', 'topic' => 'Thaharah']);
    $second = $meetings->save($this->offering, null, ['held_on' => '2026-08-08', 'mode' => 'online', 'meeting_provider' => 'google_meet', 'meeting_url' => 'https://meet.google.com/abc-defg-hij']);

    expect($second->meeting_no)->toBe(2);

    $ea = enrollmentOf($this->a, $this->offering);
    $eb = enrollmentOf($this->b, $this->offering);

    expect(fn () => $meetings->recordAttendance($first, [$ea->id => 'hadir']))->toThrow(ValidationException::class);

    $meetings->recordAttendance($first, [$ea->id => 'hadir', $eb->id => 'sakit']);
    $meetings->recordAttendance($second, [$ea->id => 'hadir', $eb->id => 'alpa']);

    $summary = app(AcademicRecord::class)->attendance([$ea->id, $eb->id]);

    expect($summary[$ea->id])->toMatchArray(['hadir' => 2, 'total' => 2, 'percent' => 100])
        ->and($summary[$eb->id])->toMatchArray(['sakit' => 1, 'alpa' => 1, 'percent' => 0]);

    // A meeting with attendance cannot be deleted.
    expect(fn () => $meetings->delete($first))->toThrow(ValidationException::class);
});

test('online meetings need a link', function () {
    expect(fn () => app(ManageMeetings::class)->save($this->offering, null, ['held_on' => '2026-08-01', 'mode' => 'online']))
        ->toThrow(ValidationException::class);
});

test('students with scores or attendance cannot be moved out of the class', function () {
    $session = app(ManageMeetings::class)->save($this->offering, null, ['held_on' => '2026-08-01', 'mode' => 'offline']);
    app(ManageMeetings::class)->recordAttendance($session, [
        enrollmentOf($this->a, $this->offering)->id => 'hadir',
        enrollmentOf($this->b, $this->offering)->id => 'hadir',
    ]);

    $other = ClassGroup::factory()->create();

    expect(fn () => app(ManageClassEnrollments::class)->enroll($this->semester, $other, [$this->a->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ManageCourseOfferings::class)->delete($this->offering))
        ->toThrow(ValidationException::class);
});

test('nothing changes once the semester is closed', function () {
    $this->grades->saveComponents($this->offering, [['name' => 'UAS', 'weight' => 100]]);
    $this->semester->update(['status' => 'closed']);

    expect(fn () => app(ManageMeetings::class)->save($this->offering->fresh(), null, ['held_on' => '2026-08-01', 'mode' => 'offline']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->grades->saveComponents($this->offering->fresh(), [['name' => 'UAS', 'weight' => 100]]))
        ->toThrow(ValidationException::class);
});

test('the transcript lists finalised grades by semester with IPK', function () {
    $this->grades->saveComponents($this->offering, [['name' => 'UAS', 'weight' => 100]]);
    $component = $this->offering->components()->sole();
    foreach ([[$this->a, 90], [$this->b, 60]] as [$student, $score]) {
        $this->grades->saveScores($this->offering, [enrollmentOf($student, $this->offering)->id => [$component->id => $score]]);
    }
    $this->grades->finalize($this->offering, User::factory()->create());

    $transcript = app(AcademicRecord::class)->transcript($this->a);

    expect($transcript['credits'])->toBe(3)
        ->and($transcript['ipk'])->toBe(4.0)
        ->and($transcript['semesters'])->toHaveCount(1)
        ->and($transcript['semesters'][0]['rows'][0]['letter'])->toBe('A');
});

/**
 * Finalise $offering with one UAS component and the given score per student.
 *
 * @param  array<int, int>  $scores  student id => score
 */
function finaliseWith(CourseOffering $offering, array $scores): void
{
    $grades = app(ManageGrades::class);
    $grades->saveComponents($offering, [['name' => 'UAS', 'weight' => 100]]);
    $component = $offering->components()->sole();

    foreach ($scores as $studentId => $score) {
        $enrollment = CourseEnrollment::query()->where('student_id', $studentId)->where('course_offering_id', $offering->id)->sole();
        $grades->saveScores($offering, [$enrollment->id => [$component->id => $score]]);
    }

    $grades->finalize($offering, User::factory()->create());
}

/**
 * The same subject again in the even semester of the same academic year.
 */
function retakeOffering(Semester $first, ClassGroup $classGroup, Teacher $teacher, CourseOffering $original, array $studentIds): CourseOffering
{
    $second = Semester::factory()->even()->create(['academic_year_id' => $first->academic_year_id]);
    app(ManageClassEnrollments::class)->enroll($second, $classGroup, $studentIds);

    return app(ManageCourseOfferings::class)->save($second, null, [
        'subject_id' => $original->subject_id, 'class_group_id' => $classGroup->id, 'teacher_id' => $teacher->id,
    ]);
}

test('a retaken subject counts once on the transcript, with its best grade', function () {
    finaliseWith($this->offering, [$this->a->id => 50, $this->b->id => 50]);
    $retake = retakeOffering($this->semester, $this->classGroup, $this->teacher, $this->offering, [$this->a->id, $this->b->id]);
    finaliseWith($retake, [$this->a->id => 90, $this->b->id => 50]);

    $transcript = app(AcademicRecord::class)->transcript($this->a);
    $rows = $transcript['semesters']->flatMap(fn (array $entry) => $entry['rows']);

    // Only the better retake is listed; the first semester has nothing left to show.
    expect($rows)->toHaveCount(1)
        ->and($rows->first()['enrollment_id'])->toBe(enrollmentOf($this->a, $retake)->id)
        ->and($rows->first()['letter'])->toBe('A')
        ->and($transcript['semesters'])->toHaveCount(1)
        ->and($transcript['credits'])->toBe(3)
        ->and($transcript['ipk'])->toBe(4.0);

    // The KHS keeps each semester's own attempt.
    $firstKhs = app(AcademicRecord::class)->khs($this->a, $this->semester);
    expect($firstKhs['rows']->first()['score'])->toEqual(50)
        ->and($firstKhs['credits'])->toBe(3);
});

test('a worse retake does not replace the earlier grade', function () {
    finaliseWith($this->offering, [$this->a->id => 90, $this->b->id => 90]);
    $retake = retakeOffering($this->semester, $this->classGroup, $this->teacher, $this->offering, [$this->a->id, $this->b->id]);
    finaliseWith($retake, [$this->a->id => 40, $this->b->id => 40]);

    $transcript = app(AcademicRecord::class)->transcript($this->a);

    expect($transcript['semesters'])->toHaveCount(1)
        ->and($transcript['semesters'][0]['semester']->is($this->semester))->toBeTrue()
        ->and($transcript['ipk'])->toBe(4.0)
        ->and($transcript['credits'])->toBe(3);
});

test('equal retakes keep the latest attempt', function () {
    finaliseWith($this->offering, [$this->a->id => 90, $this->b->id => 90]);
    $retake = retakeOffering($this->semester, $this->classGroup, $this->teacher, $this->offering, [$this->a->id, $this->b->id]);
    finaliseWith($retake, [$this->a->id => 90, $this->b->id => 90]);

    $rows = app(AcademicRecord::class)->transcript($this->a)['semesters']->flatMap(fn (array $entry) => $entry['rows']);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['enrollment_id'])->toBe(enrollmentOf($this->a, $retake)->id);
});

test('a retake of a renamed subject is still the same subject', function () {
    finaliseWith($this->offering, [$this->a->id => 50, $this->b->id => 50]);
    $this->offering->subject->update(['code' => 'FQH1-NEW', 'name' => 'Fiqh Ibadah']);
    $retake = retakeOffering($this->semester, $this->classGroup, $this->teacher, $this->offering, [$this->a->id, $this->b->id]);
    finaliseWith($retake, [$this->a->id => 90, $this->b->id => 90]);

    $rows = app(AcademicRecord::class)->transcript($this->a)['semesters']->flatMap(fn (array $entry) => $entry['rows']);

    expect($rows)->toHaveCount(1)->and($rows->first()['code'])->toBe('FQH1-NEW');
});
