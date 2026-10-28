<?php

namespace App\Enums;

enum Role: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case AdminPayment = 'admin_payment';
    case AcademicMonitor = 'academic_monitor';
    case Teacher = 'teacher';
    case Student = 'student';
    case Guardian = 'guardian';
    /** Records payments for the bills of one gender only (v2); may be held by a mahasantri. */
    case PaymentCollector = 'payment_collector';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => __('Superadmin'),
            self::Admin => __('Admin'),
            self::AdminPayment => __('Admin Payment'),
            self::AcademicMonitor => __('Academic Monitor'),
            self::Teacher => __('Ustadz'),
            self::Student => __('Mahasantri'),
            self::Guardian => __('Wali Santri'),
            self::PaymentCollector => __('Payment Collector'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Superadmin => __('System configuration, roles and audit log.'),
            self::Admin => __('Master data, users, classes and payment verification.'),
            self::AdminPayment => __('Bills, payments, scholarships and receipts.'),
            self::AcademicMonitor => __('Attendance and academic progress monitoring.'),
            self::Teacher => __('Your course offerings, attendance and grading.'),
            self::Student => __('Schedule, classes, KHS and bills.'),
            self::Guardian => __("Your children's progress, attendance and payments."),
            self::PaymentCollector => __('Record payments for the bills of putra or putri mahasantri.'),
        };
    }

    /**
     * Roles granted on the user & role management screen. Ustadz, mahasantri
     * and wali santri come with a profile and are managed on their own pages.
     *
     * @return list<self>
     */
    public static function staff(): array
    {
        return [self::Superadmin, self::Admin, self::AdminPayment, self::AcademicMonitor];
    }

    public function isStaff(): bool
    {
        return in_array($this, self::staff(), true);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Superadmin => 'shield-check',
            self::Admin => 'building-library',
            self::AdminPayment => 'banknotes',
            self::AcademicMonitor => 'chart-bar',
            self::Teacher => 'academic-cap',
            self::Student => 'book-open',
            self::Guardian => 'users',
            self::PaymentCollector => 'wallet',
        };
    }

    /**
     * The URL prefix of this role's area, also used as its route name prefix.
     */
    public function area(): string
    {
        return match ($this) {
            self::Superadmin => 'superadmin',
            self::Admin => 'admin',
            self::AdminPayment => 'finance',
            self::AcademicMonitor => 'monitor',
            self::Teacher => 'ustadz',
            self::Student => 'mahasantri',
            self::Guardian => 'wali',
            self::PaymentCollector => 'petugas',
        };
    }

    public function homeRoute(): string
    {
        return $this->area().'.dashboard';
    }

    /**
     * Sidebar navigation for this role, as headed groups. `match` is the route
     * name pattern that marks an item as current.
     *
     * @return list<array{heading: string, items: list<array{label: string, icon: string, route: string, match: string}>}>
     */
    public function navigation(): array
    {
        $item = fn (string $label, string $icon, string $route, ?string $match = null) => [
            'label' => $label, 'icon' => $icon, 'route' => $route, 'match' => $match ?? $route,
        ];

        $groups = [
            ['heading' => $this->label(), 'items' => [$item(__('Dashboard'), 'home', $this->homeRoute(), $this === self::Teacher ? 'ustadz.dashboard|ustadz.offerings.*' : null)]],
        ];

        if ($this === self::Teacher) {
            $groups[0]['items'][] = $item(__('My timetable'), 'calendar', 'ustadz.timetable');
            $groups[0]['items'][] = $item(__('My supervisees'), 'user-group', 'ustadz.supervision', 'ustadz.supervision*');
        }

        if ($this === self::AcademicMonitor) {
            $groups[0]['items'][] = $item(__('Activity log'), 'clipboard-document-list', 'monitor.activities');
            $groups[0]['items'][] = $item(__('Teaching documents'), 'document-check', 'teaching-documents');
        }

        if ($this === self::Student) {
            $groups[0]['items'][] = $item(__('My classes'), 'book-open', 'mahasantri.classes', 'mahasantri.class*|mahasantri.quiz-attempt');
            $groups[0]['items'][] = $item(__('KHS'), 'document-chart-bar', 'mahasantri.khs');
            $groups[0]['items'][] = $item(__('Transcript'), 'academic-cap', 'mahasantri.transcript');
            $groups[0]['items'][] = $item(__('My bills'), 'banknotes', 'mahasantri.bills');
            $groups[0]['items'][] = $item(__('Exam card'), 'ticket', 'mahasantri.exams');
            $groups[0]['items'][] = $item(__('My letters'), 'document-text', 'mahasantri.letters');
            $groups[0]['items'][] = $item(__('Academic supervisor'), 'user', 'mahasantri.supervision');
        }

        if ($this === self::Guardian) {
            $groups[0]['items'][] = $item(__('Academic progress'), 'chart-bar', 'wali.academic');
            $groups[0]['items'][] = $item(__('Bills'), 'banknotes', 'wali.bills');
        }

        if (in_array($this, [self::Teacher, self::Student, self::Guardian], true)) {
            $groups[0]['items'][] = $item(__('My profile'), 'identification', 'profile.mine');
        }

        if ($this === self::PaymentCollector) {
            $groups[0]['items'][] = $item(__('Bills'), 'document-currency-dollar', 'finance.bills.index', 'finance.bills.*');
            $groups[0]['items'][] = $item(__('Cash handovers'), 'inbox-arrow-down', 'finance.handovers');
        }

        if (in_array($this, [self::Teacher, self::Student], true)) {
            $groups[0]['items'][] = $item(__('My documents'), 'document-text', 'documents.mine');
        }

        if (in_array($this, [self::Admin, self::Superadmin], true)) {
            $groups[] = ['heading' => __('Academic'), 'items' => [
                $item(__('Academic years'), 'calendar-days', 'admin.academic-years', 'admin.academic-years|admin.semesters.*'),
                $item(__('Study programs'), 'building-library', 'admin.study-programs'),
                $item(__('Classes'), 'user-group', 'admin.class-groups'),
                $item(__('Subjects'), 'book-open', 'admin.subjects'),
                $item(__('Timetable'), 'calendar', 'admin.timetable'),
                $item(__('Exams'), 'clipboard-document-check', 'admin.exams.index', 'admin.exams.*|admin.rooms'),
                $item(__('Grade scale'), 'scale', 'admin.grade-scale'),
                $item(__('Teaching documents'), 'document-check', 'teaching-documents'),
                $item(__('Academic monitor'), 'chart-bar', 'monitor.dashboard'),
                $item(__('Activity log'), 'clipboard-document-list', 'monitor.activities'),
            ]];
            $groups[] = ['heading' => __('People'), 'items' => [
                $item(__('Mahasantri'), 'academic-cap', 'admin.students.index', 'admin.students.*'),
                $item(__('Ustadz'), 'user', 'admin.teachers.index', 'admin.teachers.*'),
                $item(__('Wali santri'), 'users', 'admin.guardians.index', 'admin.guardians.*'),
                $item(__('Academic supervisors'), 'user-group', 'admin.supervision'),
                $item(__('Documents'), 'document-check', 'admin.documents'),
                $item(__('Import'), 'arrow-up-tray', 'admin.imports'),
                $item(__('Previous-year data'), 'archive-box', 'admin.history'),
            ]];
            $groups[] = ['heading' => __('Letters'), 'items' => [
                $item(__('Outgoing letters'), 'paper-airplane', 'admin.letters'),
                $item(__('Incoming letters'), 'inbox-arrow-down', 'admin.incoming-letters'),
            ]];
        }

        if (in_array($this, [self::AdminPayment, self::Superadmin], true)) {
            $finance = [
                $item(__('Bills'), 'document-currency-dollar', 'finance.bills.index', 'finance.bills.*'),
                $item(__('Cash handovers'), 'inbox-arrow-down', 'finance.handovers'),
                $item(__('Transfer confirmations'), 'building-library', 'finance.transfers'),
                $item(__('Unverified payments'), 'clock', 'finance.pending'),
                $item(__('Fees'), 'tag', 'finance.fees'),
                $item(__('Scholarships'), 'gift', 'finance.scholarships'),
                $item(__('Cash book'), 'book-open', 'finance.cashbook', 'finance.cashbook|finance.cash-accounts'),
            ];

            // Superadmin reaches the same page from the Academic menu.
            if ($this === self::AdminPayment) {
                $finance[] = $item(__('Previous-year data'), 'archive-box', 'finance.history');
            }

            if ($this === self::Superadmin) {
                array_unshift($finance, $item(__('Overview'), 'chart-pie', 'finance.dashboard'));
            }

            $groups[] = ['heading' => __('Finance'), 'items' => $finance];
        }

        if (in_array($this, [self::Admin, self::Superadmin], true)) {
            $groups[] = ['heading' => __('Verification'), 'items' => [
                $item(__('Payment verification'), 'check-badge', 'admin.payments'),
                $item(__('Unverified payments'), 'clock', 'finance.pending'),
            ]];
        }

        if ($this === self::Superadmin) {
            $groups[] = ['heading' => __('System'), 'items' => [
                $item(__('Users & roles'), 'user-plus', 'superadmin.users.index', 'superadmin.users.*'),
                $item(__('Identity'), 'building-library', 'superadmin.identity'),
                $item(__('Signatures & stamps'), 'pencil', 'superadmin.signatures'),
                $item(__('Letter types'), 'document-duplicate', 'superadmin.letter-types'),
                $item(__('Settings'), 'adjustments-horizontal', 'superadmin.settings'),
                $item(__('Maintenance mode'), 'wrench-screwdriver', 'superadmin.maintenance'),
                $item(__('Audit log'), 'clipboard-document-list', 'superadmin.audit-logs'),
            ]];
        }

        return $groups;
    }
}
