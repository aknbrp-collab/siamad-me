<?php

namespace App\Console\Commands;

use App\Enums\BillStatus;
use App\Models\Assignment;
use App\Models\Bill;
use App\Models\CourseEnrollment;
use App\Models\Quiz;
use App\Support\Money;
use App\Support\Notify;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('siakad:send-reminders')]
#[Description('Remind mahasantri of work due within 24 hours, and mahasantri and wali of bills due in 7 days or today (each reminder once)')]
class SendReminders extends Command
{
    public function handle(): int
    {
        $sent = $this->assignments() + $this->quizzes() + $this->bills();

        $this->components->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }

    /**
     * Assignments due within 24 hours, to enrolled mahasantri who have not handed in.
     */
    private function assignments(): int
    {
        $sent = 0;

        $assignments = Assignment::query()
            ->with(['courseOffering.semester', 'submissions'])
            ->whereBetween('due_at', [now(), now()->addDay()])
            ->get()
            ->reject(fn (Assignment $assignment) => $assignment->courseOffering->semester->isLocked());

        foreach ($assignments as $assignment) {
            $offering = $assignment->courseOffering;
            $handedIn = $assignment->submissions->pluck('course_enrollment_id');

            foreach ($offering->enrollments()->with('student.user')->whereNotIn('id', $handedIn)->get() as $enrollment) {
                $sent += (int) Notify::remindOnce(
                    $enrollment->student->user,
                    'assignment-due:'.$assignment->id,
                    'deadline',
                    __('Due soon: :title', ['title' => $assignment->title]),
                    __(':subject · due :date', ['subject' => $offering->subject_name, 'date' => $assignment->due_at->translatedFormat('j M Y H:i')]),
                    route('mahasantri.class', [$offering, 'tab' => 'assignments']),
                    'clock',
                );
            }
        }

        return $sent;
    }

    /**
     * Published quizzes closing within 24 hours, to mahasantri who have not started them.
     */
    private function quizzes(): int
    {
        $sent = 0;

        $quizzes = Quiz::query()
            ->with(['courseOffering.semester', 'attempts'])
            ->whereNotNull('published_at')
            ->whereBetween('closes_at', [now(), now()->addDay()])
            ->get()
            ->reject(fn (Quiz $quiz) => $quiz->courseOffering->semester->isLocked());

        foreach ($quizzes as $quiz) {
            $offering = $quiz->courseOffering;
            $started = $quiz->attempts->pluck('course_enrollment_id');

            foreach ($offering->enrollments()->with('student.user')->whereNotIn('id', $started)->get() as $enrollment) {
                /** @var CourseEnrollment $enrollment */
                $sent += (int) Notify::remindOnce(
                    $enrollment->student->user,
                    'quiz-due:'.$quiz->id,
                    'deadline',
                    __('Quiz closes soon: :title', ['title' => $quiz->title]),
                    __(':subject · open until :date', ['subject' => $offering->subject_name, 'date' => $quiz->closes_at->translatedFormat('j M Y H:i')]),
                    route('mahasantri.class', [$offering, 'tab' => 'quizzes']),
                    'clock',
                );
            }
        }

        return $sent;
    }

    /**
     * Unpaid or partly paid bills due in 7 days or today, to the mahasantri and their wali.
     */
    private function bills(): int
    {
        $sent = 0;

        foreach ([7, 0] as $days) {
            $bills = Bill::query()
                ->with(['student.user', 'student.guardians.user', 'semester.academicYear'])
                ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
                ->whereDate('due_on', today()->addDays($days))
                ->get();

            foreach ($bills as $bill) {
                $title = $days === 0 ? __('Bill due today') : __('Bill due in :days days', ['days' => $days]);
                $body = __(':semester · still to pay :amount', [
                    'semester' => $bill->semester->label(),
                    'amount' => Money::format($bill->outstanding()),
                ]);

                $sent += (int) Notify::remindOnce($bill->student->user, "bill-due:{$bill->id}:{$days}", 'bill', $title, $body, route('mahasantri.bills'), 'banknotes');

                foreach ($bill->student->guardians as $guardian) {
                    $sent += (int) Notify::remindOnce($guardian->user, "bill-due:{$bill->id}:{$days}", 'bill', $title, $bill->student->user->name.' · '.$body, route('wali.bills'), 'banknotes');
                }
            }
        }

        return $sent;
    }
}
