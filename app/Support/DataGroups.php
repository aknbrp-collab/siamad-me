<?php

namespace App\Support;

use App\Enums\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The groups of data the Superadmin may delete (data-manage D52). Each group names the tables it empties;
 * "orang" and "staf" also delete user accounts. A group needs the groups whose tables point at its rows
 * (derived from the foreign keys, checked by a test against the schema). Tables in KEPT are never touched.
 */
final class DataGroups
{
    public const PEOPLE = 'orang';

    public const STAFF = 'staf';

    /**
     * group => tables it empties.
     *
     * @var array<string, list<string>>
     */
    public const TABLES = [
        'akademik' => [
            'class_enrollments', 'course_offerings', 'class_sessions', 'attendances', 'course_enrollments',
            'grade_components', 'grade_scores', 'materials', 'assignments', 'submissions',
            'quizzes', 'quiz_questions', 'quiz_options', 'quiz_attempts', 'quiz_answers',
            'exam_periods', 'exam_period_room', 'exam_sessions', 'exam_seats', 'exam_dispensations',
            'evaluation_periods', 'evaluation_questions', 'evaluation_scores', 'evaluation_submissions', 'evaluation_comments',
            'teaching_documents', 'supervisions', 'activity_logs', 'forum_threads', 'forum_posts',
        ],
        'keuangan' => ['bills', 'bill_lines', 'payments', 'payment_allocations', 'payment_handovers', 'scholarship_awards', 'cash_entries'],
        'surat' => ['letters', 'letter_requests', 'incoming_letters', 'dispensation_requests'],
        'artikel' => [
            'articles', 'article_reviews', 'article_versions',
            'discussions', 'discussion_members', 'discussion_messages', 'discussion_files', 'discussion_notes',
            'lpppk_sanctions', 'legal_questions', 'legal_answers', 'legal_answer_approvals', 'feedback',
        ],
        self::PEOPLE => ['students', 'teachers', 'guardians', 'guardian_student', 'student_documents', 'teacher_documents'],
        'dasar' => [
            'academic_years', 'semesters', 'study_programs', 'subjects', 'class_groups', 'rooms',
            'fee_types', 'fee_rates', 'scholarships', 'cash_accounts', 'cash_categories',
            'letter_types', 'signatories', 'stamps', 'document_templates', 'grade_scales',
        ],
        self::STAFF => [],
        'log' => ['notifications', 'audit_logs', 'push_subscriptions', 'cms_requests', 'sent_reminders', 'maintenance_sessions', 'failed_jobs'],
    ];

    /**
     * group => groups it needs (whose rows point at its rows).
     *
     * @var array<string, list<string>>
     */
    public const NEEDS = [
        'akademik' => [],
        'keuangan' => [],
        'surat' => [],
        'artikel' => [],
        self::PEOPLE => ['akademik', 'keuangan', 'surat', 'artikel'],
        'dasar' => [self::PEOPLE, 'akademik', 'keuangan', 'surat', 'artikel'],
        self::STAFF => [],
        'log' => [],
    ];

    /**
     * Never deleted: the Superadmin accounts and settings, numbering (so letter and receipt numbers never
     * repeat), and what the framework needs to run.
     *
     * @var list<string>
     */
    public const KEPT = [
        'users', 'user_roles', 'settings', 'number_sequences', 'migrations',
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'password_reset_tokens', 'personal_access_tokens', 'passkeys',
    ];

    /**
     * References to deleted accounts in rows that stay: those rows go with the account (a membership,
     * a sanction) instead of being given to the Superadmin who deletes.
     *
     * @var list<string>
     */
    public const BELONGS_TO_ACCOUNT = ['discussion_members.user_id', 'lpppk_sanctions.user_id'];

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'akademik' => __('Academic'),
            'keuangan' => __('Finance'),
            'surat' => __('Letters'),
            'artikel' => __('Articles & discussions'),
            self::PEOPLE => __('People'),
            'dasar' => __('Basic data'),
            self::STAFF => __('Staff accounts'),
            'log' => __('Notifications & logs'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return [
            'akademik' => __('Class placements, classes kuliah, meetings, attendance, materials, assignments, quizzes, grades, exams, evaluations, teaching documents, supervision, activity log, forums.'),
            'keuangan' => __('Bills, payments, handovers, scholarship awards, cash book entries and their proofs.'),
            'surat' => __('Letters issued, letter requests, incoming letters, dispensation requests.'),
            'artikel' => __('Articles, bimbingan / LPPPK discussions, Tanya Hukum, kritik & saran.'),
            self::PEOPLE => __('Mahasantri, muhadhir and wali santri with their accounts, documents and photos.'),
            'dasar' => __('Academic years and semesters, study programs, subjects, classes, rooms, fees and rates, scholarships, cash accounts, letter types, signatures and stamps, uploaded templates, grade scale.'),
            self::STAFF => __('Admin, Admin Keuangan and Pemantau Akademik accounts (not Superadmin). What they recorded stays, under your name.'),
            'log' => __('Notifications, audit log, app devices, CMS log, maintenance sessions.'),
        ];
    }

    /**
     * The chosen groups with everything they need.
     *
     * @param  list<string>  $groups
     * @return list<string>
     */
    public static function withNeeds(array $groups): array
    {
        $all = collect($groups)->filter(fn (string $group) => array_key_exists($group, self::TABLES));

        foreach ($all->all() as $group) {
            $all = $all->merge(self::NEEDS[$group]);
        }

        // Kept in the order of TABLES, for a steady display.
        return array_values(array_intersect(array_keys(self::TABLES), $all->unique()->all()));
    }

    /**
     * The accounts the groups delete: with a mahasantri, muhadhir or wali profile ("orang"), or with staff
     * roles only ("staf"). Superadmin accounts are never deleted.
     *
     * @param  list<string>  $groups
     * @return Collection<int, int>
     */
    public static function users(array $groups): Collection
    {
        $superadmins = DB::table('user_roles')->where('role', Role::Superadmin->value)->pluck('user_id');
        $withProfile = collect(['students', 'teachers', 'guardians'])->flatMap(fn (string $table) => DB::table($table)->pluck('user_id'))->unique();
        $ids = collect();

        if (in_array(self::PEOPLE, $groups, true)) {
            $ids = $ids->merge($withProfile);
        }

        if (in_array(self::STAFF, $groups, true)) {
            $staffRoles = [Role::Admin->value, Role::AdminPayment->value, Role::AcademicMonitor->value];
            $ids = $ids->merge(DB::table('user_roles')->whereIn('role', $staffRoles)->whereNotIn('user_id', $withProfile->all())->pluck('user_id'));
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->diff($superadmins->map(fn ($id) => (int) $id))->values();
    }

    /**
     * Rows each group would delete now (accounts counted with their group).
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = [];

        foreach (self::TABLES as $group => $tables) {
            $counts[$group] = (int) collect($tables)->sum(fn (string $table) => DB::table($table)->count());
        }

        $counts[self::PEOPLE] += self::users([self::PEOPLE])->count();
        $counts[self::STAFF] += self::users([self::STAFF])->count();

        return $counts;
    }
}
