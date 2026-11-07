<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Discussions\ManageDiscussions;
use App\Actions\Discussions\ModerateLpppk;
use App\Actions\Evaluations\ManageEvaluations;
use App\Actions\Feedback\ManageFeedback;
use App\Enums\DiscussionKind;
use App\Enums\FeedbackStatus;
use App\Enums\Role;
use App\Enums\TeacherStatus;
use App\Http\Controllers\Controller;
use App\Models\Discussion;
use App\Models\DiscussionMember;
use App\Models\EvaluationPeriod;
use App\Models\EvaluationQuestion;
use App\Models\Feedback;
use App\Models\LpppkSanction;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ActiveRole;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Bimbingan groups and LPPPK (Admin, Superadmin, academic monitor), the
 * Kritik & saran inbox and the muhadhir evaluation (Admin, Superadmin; the
 * monitor reads evaluation results).
 */
#[Group('Admin akademik', weight: 15)]
class CommunityController extends Controller
{
    /**
     * Bimbingan groups.
     *
     * `show`: open (default), closed or all; with the leading muhadhir and how many mahasantri. `?group=` also
     * lists that group's mahasantri. The rooms themselves: `/discussions/{id}`.
     */
    public function groups(Request $request): JsonResponse
    {
        $request->validate(['show' => ['nullable', Rule::in(['open', 'closed', 'all'])], 'group' => ['nullable', 'integer']]);
        $show = (string) $request->input('show', 'open');

        $groups = Discussion::query()->with('leadTeacher.user')
            ->withCount(['members as students_count' => fn ($query) => $query->where('role', 'member')])
            ->where('kind', DiscussionKind::Bimbingan)
            ->when($show === 'open', fn (Builder $query) => $query->whereNull('closed_at'))
            ->when($show === 'closed', fn (Builder $query) => $query->whereNotNull('closed_at'))
            ->orderBy('name')->get();

        $members = ! $request->filled('group') ? null : Discussion::query()->where('kind', DiscussionKind::Bimbingan)->findOrFail($request->integer('group'))
            ->members()->with('user.student')->where('role', 'member')->get()->sortBy('user.name')->values()
            ->map(fn (DiscussionMember $member) => ['member_id' => $member->id, 'nim' => $member->user->student?->nim, 'name' => $member->user->name])->all();

        return response()->json(['data' => [
            'groups' => $groups->map(fn (Discussion $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'lead_teacher_id' => $group->lead_teacher_id,
                'lead' => $group->leadTeacher?->user->name,
                'students_count' => (int) $group->getAttribute('students_count'),
                'closed' => $group->closed_at !== null,
            ])->values()->all(),
            'members' => $members,
        ]]);
    }

    /**
     * Add or change a bimbingan group.
     *
     * `name`, `description`, `lead_teacher_id` (an active muhadhir). Without `id`: a new group.
     */
    public function saveGroup(Request $request, ManageDiscussions $manage): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string'], 'description' => ['nullable', 'string'], 'lead_teacher_id' => ['required', 'integer']]);
        $lead = Teacher::query()->where('status', TeacherStatus::Active)->findOrFail($request->integer('lead_teacher_id'));

        if ($request->filled('id')) {
            $group = Discussion::query()->where('kind', DiscussionKind::Bimbingan)->findOrFail($request->integer('id'));
            $manage->rename($group, (string) $request->input('name'), $request->input('description'));
            $manage->setLead($group, $lead);
        } else {
            $group = $manage->create(DiscussionKind::Bimbingan, (string) $request->input('name'), $request->input('description'), $lead, $request->user());
        }

        return response()->json(['data' => ['id' => $group->id]], $request->filled('id') ? 200 : 201);
    }

    /**
     * Add mahasantri to a group.
     *
     * `student_ids`; they are told.
     */
    public function addMembers(Request $request, Discussion $group, ManageDiscussions $manage): JsonResponse
    {
        abort_unless($group->kind === DiscussionKind::Bimbingan, 404);
        $request->validate(['student_ids' => ['required', 'array'], 'student_ids.*' => ['integer']]);
        $userIds = Student::query()->whereKey(array_map('intval', (array) $request->input('student_ids')))->pluck('user_id')->all();

        return response()->json(['data' => ['added' => $manage->addMembers($group, array_values(array_map('intval', $userIds)))]]);
    }

    /**
     * Remove a mahasantri from a group.
     */
    public function removeMember(Discussion $group, DiscussionMember $member, ManageDiscussions $manage): Response
    {
        abort_unless($group->kind === DiscussionKind::Bimbingan && $member->discussion_id === $group->id, 404);
        $manage->removeMember($member);

        return response()->noContent();
    }

    /**
     * Close or reopen a group or LPPPK channel.
     *
     * A closed room can be read, not written in.
     */
    public function setClosed(Request $request, Discussion $discussion, ManageDiscussions $manage): Response
    {
        abort_unless(in_array($discussion->kind, [DiscussionKind::Bimbingan, DiscussionKind::Lpppk], true), 404);
        $request->validate(['closed' => ['required', 'boolean']]);
        $manage->setClosed($discussion, $request->boolean('closed'), $request->user());

        return response()->noContent();
    }

    /**
     * Add or rename an LPPPK channel.
     */
    public function saveChannel(Request $request, ManageDiscussions $manage): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string'], 'description' => ['nullable', 'string']]);

        if ($request->filled('id')) {
            $channel = Discussion::query()->where('kind', DiscussionKind::Lpppk)->findOrFail($request->integer('id'));
            $manage->rename($channel, (string) $request->input('name'), $request->input('description'));
        } else {
            $channel = $manage->create(DiscussionKind::Lpppk, (string) $request->input('name'), $request->input('description'), null, $request->user());
        }

        return response()->json(['data' => ['id' => $channel->id]], $request->filled('id') ? 200 : 201);
    }

    /**
     * LPPPK sanctions in force.
     *
     * People muted (until a time or until lifted) or shut out of LPPPK, with the reason.
     */
    public function sanctions(): JsonResponse
    {
        $sanctions = LpppkSanction::query()->with(['user', 'decider'])
            ->where(fn (Builder $query) => $query->whereNotNull('restricted_at')
                ->orWhere(fn (Builder $query) => $query->whereNotNull('muted_at')->where(fn (Builder $query) => $query->whereNull('muted_until')->orWhere('muted_until', '>', now()))))
            ->latest('updated_at')->get();

        return response()->json(['data' => $sanctions->map(fn (LpppkSanction $sanction) => [
            'user_id' => $sanction->user_id,
            'name' => $sanction->user->name,
            'restricted' => $sanction->restricted_at !== null,
            'muted_until' => $sanction->muted_until?->toIso8601String(),
            'reason' => $sanction->reason,
            'decided_by' => $sanction->decider->name,
        ])->values()->all()]);
    }

    /**
     * Mute or shut out of LPPPK.
     *
     * `action`: mute (with `until`, or until lifted) or restrict (no access to LPPPK at all); a `reason` the
     * person is told.
     */
    public function sanction(Request $request, User $person, ModerateLpppk $moderate): Response
    {
        $request->validate(['action' => ['required', Rule::in(['mute', 'restrict'])], 'until' => ['nullable', 'date', 'after:now'], 'reason' => ['nullable', 'string', 'max:500']]);

        $request->input('action') === 'restrict'
            ? $moderate->restrict($person, $request->input('reason'), $request->user())
            : $moderate->mute($person, $request->filled('until') ? Carbon::parse((string) $request->input('until')) : null, $request->input('reason'), $request->user());

        return response()->noContent();
    }

    /**
     * Lift an LPPPK sanction.
     */
    public function lift(Request $request, User $person, ModerateLpppk $moderate): Response
    {
        $moderate->lift($person, $request->user());

        return response()->noContent();
    }

    /**
     * Kritik & saran inbox (Admin, Superadmin).
     *
     * `status` new (default), in_progress, done or all; `category`; `role` of the sender; `search` on topic and
     * message. Anonymous senders show as "Anonim" (Superadmin sees the name).
     */
    public function feedback(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $request->validate(['status' => ['nullable', 'string'], 'category' => ['nullable', 'string'], 'role' => ['nullable', 'string'], 'search' => ['nullable', 'string']]);
        $status = (string) $request->input('status', 'new');
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';
        $reader = $request->user();

        $items = Feedback::query()->with(['user', 'replier'])
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->input('category')))
            ->when($request->filled('role'), fn (Builder $query) => $query->where('role', $request->input('role')))
            ->when($request->filled('search'), fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereRaw('lower(subject) like ?', [$term])->orWhereRaw('lower(message) like ?', [$term])))
            ->latest('id')->paginate(20);

        return response()->json([
            'data' => collect($items->items())->map(fn (Feedback $item) => [
                'id' => $item->id,
                'from' => $item->senderLabel($reader),
                'role' => $item->role->value,
                'category' => $item->category->value,
                'category_label' => $item->category->label(),
                'subject' => $item->subject,
                'message' => $item->message,
                'status' => $item->status->value,
                'reply' => $item->reply,
                'replied_by' => $item->replier?->name,
                'created_at' => $item->created_at->toIso8601String(),
            ])->values()->all(),
            'counts' => Feedback::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total)->all(),
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
        ]);
    }

    /**
     * Answer feedback (Admin, Superadmin).
     *
     * `reply` (optional) and `status`; the sender is notified.
     */
    public function answerFeedback(Request $request, Feedback $feedback, ManageFeedback $manage): Response
    {
        $this->ensureAdmin();
        $request->validate(['reply' => ['nullable', 'string', 'max:5000'], 'status' => ['required', Rule::enum(FeedbackStatus::class)]]);
        $status = FeedbackStatus::from((string) $request->input('status'));

        $request->filled('reply')
            ? $manage->reply($feedback, $request->user(), (string) $request->input('reply'), $status === FeedbackStatus::New ? FeedbackStatus::Done : $status)
            : $manage->setStatus($feedback, $request->user(), $status);

        return response()->noContent();
    }

    /**
     * Evaluation results.
     *
     * Per class kuliah of a semester (`?semester=`, default the active one): answers against enrolled, average,
     * and per statement the average and spread, with comments; nothing shows below 3 answers.
     */
    public function evaluationResults(Request $request, ManageEvaluations $evaluations): JsonResponse
    {
        $request->validate(['semester' => ['nullable', 'integer']]);
        $semester = $request->filled('semester') ? Semester::query()->findOrFail($request->integer('semester')) : Semester::active();
        abort_if($semester === null, 404);

        return response()->json(['data' => $evaluations->results($semester)->map(fn (array $result) => [
            'class_id' => $result['offering']->id,
            'subject_name' => $result['offering']->subject_name,
            'class_name' => $result['offering']->class_name,
            'teacher' => $result['offering']->teacher->user->name,
            'responses' => $result['responses'],
            'enrolled' => $result['enrolled'],
            'shown' => $result['shown'],
            'average' => $result['average'],
            'statements' => array_map(fn (array $row) => [
                'group' => $row['question']->group,
                'statement' => $row['question']->question,
                'average' => $row['average'],
                'distribution' => $row['distribution'],
            ], $result['questions']),
            'comments' => $result['comments'],
        ])->values()->all()]);
    }

    /**
     * Evaluation setup of a semester (Admin, Superadmin).
     *
     * The period (dates, closing question, required for the KHS) and the statements.
     */
    public function evaluationSetup(Semester $semester): JsonResponse
    {
        $this->ensureAdmin();
        $period = EvaluationPeriod::query()->where('semester_id', $semester->id)->first();

        return response()->json(['data' => [
            'period' => $period === null ? null : [
                'opens_on' => $period->opens_on->toDateString(),
                'closes_on' => $period->closes_on->toDateString(),
                'comment_prompt' => $period->comment_prompt,
                'required_for_khs' => $period->required_for_khs,
                'open' => $period->isOpen(),
            ],
            'statements' => EvaluationQuestion::query()->withExists('scores')->where('semester_id', $semester->id)->orderBy('position')->orderBy('id')->get()
                ->map(fn (EvaluationQuestion $question) => [
                    ...$question->only(['id', 'group', 'question', 'position', 'is_active']),
                    /** A statement with answers cannot be reworded. */
                    'answered' => (bool) $question->getAttribute('scores_exists'),
                ])->values()->all(),
        ]]);
    }

    /**
     * Save the evaluation period (Admin, Superadmin).
     */
    public function saveEvaluationPeriod(Request $request, Semester $semester, ManageEvaluations $evaluations): Response
    {
        $this->ensureAdmin();
        $request->validate(['opens_on' => ['required', 'date'], 'closes_on' => ['required', 'date'], 'comment_prompt' => ['required', 'string'], 'required_for_khs' => ['nullable', 'boolean']]);
        $evaluations->savePeriod($semester, [...$request->only(['opens_on', 'closes_on', 'comment_prompt']), 'required_for_khs' => $request->boolean('required_for_khs')]);

        return response()->noContent();
    }

    /**
     * Prepare the statements (Admin, Superadmin).
     *
     * Copies the previous semester's active statements, or the defaults.
     */
    public function prepareStatements(Semester $semester, ManageEvaluations $evaluations): JsonResponse
    {
        $this->ensureAdmin();

        return response()->json(['data' => ['added' => $evaluations->prepareQuestions($semester)]]);
    }

    /**
     * Add, change, turn on/off or move a statement (Admin, Superadmin).
     *
     * Without `id`: a new statement (`group`, `question`). With `id`: change the wording (only while
     * unanswered), set `is_active`, or `move` -1 / 1.
     */
    public function saveStatement(Request $request, Semester $semester, ManageEvaluations $evaluations): Response
    {
        $this->ensureAdmin();
        $request->validate(['id' => ['nullable', 'integer'], 'group' => ['nullable', 'string'], 'question' => ['nullable', 'string'], 'is_active' => ['nullable', 'boolean'], 'move' => ['nullable', Rule::in([-1, 1])]]);

        if (! $request->filled('id')) {
            $evaluations->addQuestion($semester, (string) $request->input('group'), (string) $request->input('question'));

            return response()->noContent();
        }

        $question = EvaluationQuestion::query()->where('semester_id', $semester->id)->findOrFail($request->integer('id'));

        match (true) {
            $request->filled('move') => $evaluations->move($question, $request->integer('move')),
            $request->has('is_active') => $evaluations->setActive($question, $request->boolean('is_active')),
            default => $evaluations->updateQuestion($question, (string) $request->input('group'), (string) $request->input('question')),
        };

        return response()->noContent();
    }

    private function ensureAdmin(): void
    {
        abort_unless(in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin], true), 403);
    }
}
