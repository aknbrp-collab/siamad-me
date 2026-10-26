<?php

namespace Database\Seeders;

use App\Actions\Academic\SaveAcademicYear;
use App\Actions\Academic\SemesterLifecycle;
use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\ManageScholarshipAwards;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Actions\Lms\ManageAssignments;
use App\Actions\Lms\ManageForum;
use App\Actions\Lms\ManageQuizzes;
use App\Actions\Lms\RecordActivity;
use App\Actions\People\ManageUserAccounts;
use App\Actions\Supervision\ManageSupervision;
use App\Actions\Teaching\ManageGrades;
use App\Actions\Teaching\ManageMeetings;
use App\Enums\DocumentType;
use App\Enums\FeeFrequency;
use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\ScholarshipType;
use App\Enums\SemesterTerm;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Scholarship;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\Subject;
use App\Models\User;
use App\Support\Forum\SupervisionForum;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Demo accounts for local development. Every password is "password".
     * In production create the first account with `php artisan siakad:superadmin`.
     *
     * @var list<array{username: string, name: string, roles: list<Role>}>
     */
    private const STAFF = [
        ['username' => 'superadmin', 'name' => 'Superadmin', 'roles' => [Role::Superadmin]],
        ['username' => 'admin', 'name' => 'Admin Akademik', 'roles' => [Role::Admin]],
        ['username' => 'keuangan', 'name' => 'Admin Keuangan', 'roles' => [Role::AdminPayment]],
        ['username' => 'monitor', 'name' => 'Pemantau Akademik', 'roles' => [Role::AcademicMonitor]],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Demo data is not seeded in production. Run `php artisan siakad:superadmin`.');

            return;
        }

        $staff = [];
        foreach (self::STAFF as $account) {
            $staff[$account['username']] = $this->user($account['username'], $account['name'], ...$account['roles']);
        }

        $program = StudyProgram::create(['code' => 'MA', 'name' => 'Takhassus Fiqh wa Ushuluhu']);

        $year = app(SaveAcademicYear::class)->handle(null, ['name' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $semester = $year->semesters()->where('term', SemesterTerm::Odd)->sole();
        app(SemesterLifecycle::class)->activate($semester);

        // Classes per semester of study, Putra and Putri. Semester II classes are the promotion targets.
        $classOne = ClassGroup::create(['study_program_id' => $program->id, 'name' => 'Semester I Putra', 'level' => 1, 'gender' => Gender::Male]);
        $classOnePutri = ClassGroup::create(['study_program_id' => $program->id, 'name' => 'Semester I Putri', 'level' => 1, 'gender' => Gender::Female]);
        ClassGroup::create(['study_program_id' => $program->id, 'name' => 'Semester II Putra', 'level' => 2, 'gender' => Gender::Male]);
        ClassGroup::create(['study_program_id' => $program->id, 'name' => 'Semester II Putri', 'level' => 2, 'gender' => Gender::Female]);

        $fiqh = Subject::create(['study_program_id' => $program->id, 'code' => 'FQH101', 'name' => 'Fiqh Ibadah', 'kitab' => 'Fathul Qarib', 'credits' => 3]);
        $nahwu = Subject::create(['study_program_id' => $program->id, 'code' => 'NHW101', 'name' => 'Nahwu', 'kitab' => 'Alfiyah Ibnu Malik', 'credits' => 2]);
        Subject::create(['study_program_id' => $program->id, 'code' => 'TFS101', 'name' => 'Tafsir Ahkam', 'kitab' => 'Tafsir Jalalain', 'credits' => 2]);

        $ahmad = $this->user('2026010001', 'Ahmad Zaini', Role::Teacher)
            ->teacher()->create(['nidm' => '2026010001', 'title' => 'Lc., M.A.', 'gender' => Gender::Male, 'expertise' => 'Fiqh']);
        $hasan = $this->user('2026010002', 'Hasan Basri', Role::Teacher, Role::AcademicMonitor)
            ->teacher()->create(['nidm' => '2026010002', 'title' => 'Lc.', 'gender' => Gender::Male, 'expertise' => 'Nahwu & Sharaf']);

        $students = collect([
            ['2026110001', 'Muhammad Faqih', Gender::Male],
            ['2026110002', 'Ahmad Rifqi', Gender::Male],
            ['2026110003', 'Fatimah Azzahra', Gender::Female],
            ['2026110004', 'Umar Faruq', Gender::Male],
        ])->map(fn (array $data) => $this->user($data[0], $data[1], Role::Student)->student()->create([
            'nim' => $data[0],
            'study_program_id' => $program->id,
            'entry_year' => 2026,
            'gender' => $data[2],
            'father_name' => 'Abdullah',
            'father_is_alive' => true,
        ]));

        foreach ([$ahmad, $hasan, ...$students] as $person) {
            foreach (DocumentType::cases() as $type) {
                $person->documents()->create(['type' => $type]);
            }
        }

        $guardian = $this->user('WS-DEMO0001', 'Bapak Abdullah', Role::Guardian)
            ->guardian()->create(['code' => 'WS-DEMO0001', 'relationship' => 'Ayah']);
        $guardian->students()->attach($students->first());

        [$putra, $putri] = $students->partition(fn (Student $student) => $student->gender === Gender::Male);
        app(ManageClassEnrollments::class)->enroll($semester, $classOne, array_values($putra->map(fn (Student $student) => $student->id)->all()));
        app(ManageClassEnrollments::class)->enroll($semester, $classOnePutri, array_values($putri->map(fn (Student $student) => $student->id)->all()));

        $offerings = app(ManageCourseOfferings::class);
        $fiqhClass = $offerings->save($semester, null, ['subject_id' => $fiqh->id, 'class_group_id' => $classOne->id, 'teacher_id' => $ahmad->id, 'day_of_week' => 6, 'starts_at' => '07:30', 'ends_at' => '09:00', 'room' => 'R.101']);
        $nahwuClass = $offerings->save($semester, null, ['subject_id' => $nahwu->id, 'class_group_id' => $classOne->id, 'teacher_id' => $hasan->id, 'day_of_week' => 7, 'starts_at' => '07:30', 'ends_at' => '09:00', 'room' => 'R.101']);

        $this->seedFinance($year->id, $semester, array_values($students->take(3)->all()), $staff['keuangan'], $staff['admin']);

        $this->seedTeaching($fiqhClass, $nahwuClass, $ahmad->user);
        $this->seedClassroom($fiqhClass, $ahmad->user, $staff['monitor']);

        // Pembimbing akademik: Ahmad supervises the putra, Hasan the putri.
        $supervision = app(ManageSupervision::class);
        $supervision->assign($ahmad, array_values($putra->map(fn (Student $student) => $student->id)->all()), $staff['admin']);
        $supervision->assign($hasan, array_values($putri->map(fn (Student $student) => $student->id)->all()), $staff['admin']);
        app(ManageForum::class)->startThread(new SupervisionForum($ahmad), $ahmad->user, 'Rencana studi semester ini', 'Silakan tulis target hafalan dan kitab yang ingin diselesaikan semester ini.');

        // Fatimah collects and records the payments of the putri (v2 Petugas Pembayaran).
        foreach ($putri as $student) {
            app(ManageUserAccounts::class)->setCollector($student->user, Gender::Female);
        }
    }

    /**
     * Fiqh: four meetings with attendance and finalised grades (Ahmad Rifqi
     * below the attendance minimum). Nahwu: components set, scores partly entered.
     */
    private function seedTeaching(CourseOffering $fiqh, CourseOffering $nahwu, User $fiqhTeacher): void
    {
        $meetings = app(ManageMeetings::class);
        $grades = app(ManageGrades::class);

        // Faqih, Rifqi, Umar (by NIM): the Semester I Putra class.
        $ids = $fiqh->enrollments()->join('students', 'students.id', '=', 'course_enrollments.student_id')
            ->orderBy('students.nim')->pluck('course_enrollments.id')->all();

        $sessions = [
            ['held_on' => '2026-08-01', 'topic' => 'Thaharah', 'statuses' => ['hadir', 'hadir', 'hadir']],
            ['held_on' => '2026-08-08', 'topic' => 'Najis dan cara mensucikannya', 'statuses' => ['hadir', 'alpa', 'hadir']],
            ['held_on' => '2026-08-15', 'topic' => 'Wudhu', 'statuses' => ['hadir', 'sakit', 'izin']],
        ];

        foreach ($sessions as $data) {
            $session = $meetings->save($fiqh, null, ['held_on' => $data['held_on'], 'topic' => $data['topic'], 'mode' => 'offline']);
            $meetings->recordAttendance($session, array_combine($ids, $data['statuses']));
        }

        $online = $meetings->save($fiqh, null, [
            'held_on' => '2026-08-22', 'topic' => 'Tayammum', 'mode' => 'online',
            'meeting_provider' => 'google_meet', 'meeting_url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $meetings->recordAttendance($online, array_combine($ids, ['hadir', 'alpa', 'hadir']));

        $grades->saveComponents($fiqh, [['name' => 'Tugas', 'weight' => 20], ['name' => 'UTS', 'weight' => 30], ['name' => 'UAS', 'weight' => 50]]);
        $components = $fiqh->components()->pluck('id')->all();
        $scores = [[92, 88, 90], [70, 65, 58], [85, 80, 83]];
        $grades->saveScores($fiqh, array_combine($ids, array_map(fn (array $row) => array_combine($components, $row), $scores)));
        $grades->finalize($fiqh, $fiqhTeacher);

        $grades->saveComponents($nahwu, [['name' => 'Hafalan', 'weight' => 40], ['name' => 'UAS', 'weight' => 60]]);
        $hafalan = $nahwu->components()->where('name', 'Hafalan')->value('id');
        $grades->saveScores($nahwu, $nahwu->enrollments()->pluck('id')->mapWithKeys(fn (int $id) => [$id => [$hafalan => 80]])->all());
    }

    /**
     * Fees, a scholarship, generated bills, one verified transfer and one
     * cash payment still waiting to be handed over.
     *
     * @param  list<Student>  $students
     */
    private function seedFinance(int $academicYearId, Semester $semester, array $students, User $cashier, User $admin): void
    {
        // Shown to mahasantri and wali who confirm a transfer (sample account).
        Setting::set('payment.bank_accounts', "BSI 7123456789 a.n. Yayasan Ma'had Aly (contoh)");

        $spp = FeeType::create(['name' => 'SPP', 'frequency' => FeeFrequency::PerSemester]);
        $dorm = FeeType::create(['name' => 'Asrama', 'frequency' => FeeFrequency::Monthly]);
        $registration = FeeType::create(['name' => 'Daftar Ulang', 'frequency' => FeeFrequency::Once]);

        FeeRate::create(['fee_type_id' => $spp->id, 'academic_year_id' => $academicYearId, 'amount' => 1_500_000]);
        FeeRate::create(['fee_type_id' => $dorm->id, 'academic_year_id' => $academicYearId, 'amount' => 250_000]);
        FeeRate::create(['fee_type_id' => $registration->id, 'academic_year_id' => $academicYearId, 'entry_year' => 2026, 'amount' => 500_000]);

        [$faqih, $rifqi, $fatimah] = $students;

        $orphans = Scholarship::create([
            'academic_year_id' => $academicYearId, 'name' => 'Beasiswa Yatim', 'funding_source' => 'Yayasan',
            'type' => ScholarshipType::Percent, 'value' => 50, 'quota' => 10,
        ]);
        app(ManageScholarshipAwards::class)->award($orphans, $fatimah, $semester, $cashier, 'SK Yayasan 12/2026');

        app(GenerateBills::class)->handle($semester, '2026-08-31');

        $bills = Bill::query()->where('semester_id', $semester->id)->get()->keyBy('student_id');
        $record = app(RecordPayment::class);

        $transfer = $record->handle($bills[$faqih->id], $cashier, ['amount' => 2_000_000, 'method' => 'transfer', 'paid_on' => '2026-08-03', 'reference' => 'BSI 0803-7781']);
        app(ReviewPayments::class)->verifyTransfer($transfer, $admin);

        $record->handle($bills[$rifqi->id], $cashier, ['amount' => 1_000_000, 'method' => 'cash', 'paid_on' => '2026-08-05']);
    }

    /**
     * Fiqh classroom: a material, a graded past assignment and an open one, a
     * published quiz, a forum topic; plus pesantren activities for the monitor.
     */
    private function seedClassroom(CourseOffering $fiqh, User $teacher, User $monitor): void
    {
        $fiqh->materials()->create([
            'title' => 'Ringkasan Bab Thaharah',
            'body' => 'Pokok bahasan: macam-macam air, najis, wudhu dan tayammum.
Baca Fathul Qarib hlm. 3–12 sebelum pertemuan.',
            'url' => 'https://id.wikipedia.org/wiki/Thaharah',
        ]);

        $assignments = app(ManageAssignments::class);
        $enrollments = $fiqh->enrollments()->join('students', 'students.id', '=', 'course_enrollments.student_id')
            ->orderBy('students.nim')->select('course_enrollments.*')->with('student.user')->get();

        // Handed in on time last week: created with a future deadline, then moved into the past.
        $past = $assignments->save($fiqh, null, ['title' => 'Ringkasan macam-macam air', 'instructions' => 'Tulis ringkasan 1 halaman.', 'due_at' => now()->addHour(), 'max_score' => 100]);
        $submission = $assignments->submit($past, $enrollments[0], ['text' => 'Air terbagi empat: air mutlak, air musta’mal, air mutanajjis, dan air musyammas.']);
        $assignments->grade($submission, 88, 'Ringkas dan tepat.', $teacher);
        $assignments->submit($past, $enrollments[2], ['text' => 'Air mutlak adalah air yang suci dan mensucikan.']);
        $past->update(['due_at' => now()->subDays(3)]);
        $past->submissions()->update(['submitted_at' => now()->subDays(4)]);

        $assignments->save($fiqh, null, ['title' => 'Makalah tata cara tayammum', 'instructions' => 'Sertakan dalil dan sumber kitab.', 'due_at' => now()->addWeek(), 'max_score' => 100, 'allow_late' => true]);

        $quizzes = app(ManageQuizzes::class);
        $quiz = $quizzes->save($fiqh, null, [
            'title' => 'Kuis Thaharah', 'description' => 'Kerjakan sendiri. 15 menit.',
            'opens_at' => now()->subHour(), 'closes_at' => now()->addWeek(), 'duration_minutes' => 15, 'max_attempts' => 2,
        ]);
        $quizzes->saveQuestion($quiz, null, ['type' => 'single_choice', 'body' => 'Air yang suci dan mensucikan disebut…', 'points' => 2, 'options' => [
            ['body' => 'Air mutlak', 'is_correct' => true], ['body' => 'Air musta’mal', 'is_correct' => false], ['body' => 'Air mutanajjis', 'is_correct' => false],
        ]]);
        $quizzes->saveQuestion($quiz, null, ['type' => 'multiple_choice', 'body' => 'Manakah yang termasuk rukun wudhu?', 'points' => 3, 'options' => [
            ['body' => 'Niat', 'is_correct' => true], ['body' => 'Membasuh muka', 'is_correct' => true], ['body' => 'Berkumur', 'is_correct' => false],
        ]]);
        $quizzes->saveQuestion($quiz, null, ['type' => 'essay', 'body' => 'Jelaskan kapan tayammum diperbolehkan.', 'points' => 5]);
        $quizzes->publish($quiz);

        $forum = app(ManageForum::class);
        $thread = $forum->startThread($fiqh, $enrollments[1]->student->user, 'Najis mughallazhah', 'Bagaimana cara mensucikan najis anjing?');
        $forum->reply($thread, $teacher, 'Dibasuh tujuh kali, salah satunya dengan debu yang suci.');

        $record = app(RecordActivity::class);
        foreach ($enrollments as $index => $enrollment) {
            $record->handle($enrollment->student, $monitor, ['category' => 'tahfidz', 'happened_on' => now()->subDays(2)->toDateString(), 'title' => 'Setoran Juz 30: An-Naba 1–'.(20 + $index * 10)]);
        }
        $record->handle($enrollments[1]->student, $monitor, ['category' => 'kedisiplinan', 'happened_on' => now()->subDay()->toDateString(), 'title' => 'Terlambat halaqah subuh', 'notes' => 'Sudah dinasihati.']);
    }

    private function user(string $username, string $name, Role ...$roles): User
    {
        $user = User::factory()->create(['username' => $username, 'name' => $name, 'email' => null]);

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }
}
