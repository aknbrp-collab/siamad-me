<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Articles\ManageArticles;
use App\Actions\TanyaHukum\ManageLegalQuestions;
use App\Http\Controllers\Controller;
use App\Jobs\Cms\SendLegalAnswer;
use App\Models\LegalAnswer;
use App\Models\LegalAnswerApproval;
use App\Models\LegalQuestion;
use App\Models\User;
use App\Support\Cms\CmsClient;
use App\Support\DiscussionAccess;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Tanya Hukum (v5 D30): questions from the website, discussed in their LPPPK
 * room (`discussion_id`, see Discussions). Any muhadhir drafts the answer and
 * asks for approval; two other muhadhir approve and it goes back to the
 * website. A correction after sending is a new version.
 */
#[Group('Tanya Hukum', weight: 14)]
class TanyaHukumController extends Controller
{
    /**
     * Questions.
     *
     * `show`: open (no answer sent yet, oldest first), approval (an answer waits for approval), answered,
     * or all.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['show' => ['nullable', Rule::in(['open', 'approval', 'answered', 'all'])]]);
        abort_unless(DiscussionAccess::canUseLpppk($request->user()), 403);
        $show = (string) $request->input('show', 'open');

        $questions = LegalQuestion::query()
            ->with(['answers' => fn ($query) => $query->orderByDesc('version')])
            ->when($show === 'open', fn (Builder $query) => $query->whereDoesntHave('answers', fn ($query) => $query->where('status', 'sent')))
            ->when($show === 'approval', fn (Builder $query) => $query->whereHas('answers', fn ($query) => $query->where('status', 'awaiting')))
            ->when($show === 'answered', fn (Builder $query) => $query->whereHas('answers', fn ($query) => $query->where('status', 'sent')))
            ->orderBy($show === 'answered' ? 'updated_at' : 'created_at', $show === 'answered' ? 'desc' : 'asc')
            ->paginate(20);

        return response()->json([
            'data' => collect($questions->items())->map(fn (LegalQuestion $question) => [
                ...$this->question($question),
                'latest_answer_status' => $question->answers->first()?->status,
                'latest_answer_status_label' => $question->answers->first()?->statusLabel(),
            ])->values()->all(),
            'meta' => ['current_page' => $questions->currentPage(), 'last_page' => $questions->lastPage(), 'total' => $questions->total()],
        ]);
    }

    /**
     * A question.
     *
     * The question, the version being prepared (draft, awaiting approval or approved) with its approvals,
     * the versions sent, and what I can do now.
     */
    public function show(Request $request, LegalQuestion $question, ManageLegalQuestions $manage): JsonResponse
    {
        abort_unless(DiscussionAccess::canUseLpppk($request->user()), 403);

        return response()->json(['data' => $this->detail($request->user(), $question, $manage)]);
    }

    /**
     * Save the draft (muhadhir).
     *
     * The answer in Markdown and optional references. Whoever saves last is the drafter and cannot approve it.
     */
    public function draft(Request $request, LegalQuestion $question, ManageLegalQuestions $manage): JsonResponse
    {
        $request->validate(['answer' => ['required', 'string'], 'references' => ['nullable', 'string', 'max:5000']]);
        $this->authorizeLpppk($request);

        $manage->saveDraft($question, $request->user(), (string) $request->input('answer'), $request->input('references'));

        return response()->json(['data' => $this->detail($request->user(), $question, $manage)]);
    }

    /**
     * Ask for approval (muhadhir).
     *
     * The other muhadhir are notified.
     */
    public function submit(Request $request, LegalQuestion $question, ManageLegalQuestions $manage): JsonResponse
    {
        $this->authorizeLpppk($request);
        $manage->submit($this->open($question, $manage), $request->user());

        return response()->json(['data' => $this->detail($request->user(), $question, $manage)]);
    }

    /**
     * Approve (another muhadhir).
     *
     * The second approval sends the answer to the website.
     */
    public function approve(Request $request, LegalQuestion $question, ManageLegalQuestions $manage): JsonResponse
    {
        $this->authorizeLpppk($request);
        $manage->approve($this->open($question, $manage), $request->user());

        return response()->json(['data' => $this->detail($request->user(), $question, $manage)]);
    }

    /**
     * Back to draft (muhadhir).
     *
     * Drops the approvals given so far.
     */
    public function reopen(Request $request, LegalQuestion $question, ManageLegalQuestions $manage): JsonResponse
    {
        $this->authorizeLpppk($request);
        $manage->reopen($this->open($question, $manage), $request->user());

        return response()->json(['data' => $this->detail($request->user(), $question, $manage)]);
    }

    /**
     * Correct the sent answer (muhadhir).
     *
     * Starts a new version from the sent one; it needs two new approvals.
     */
    public function correction(Request $request, LegalQuestion $question, ManageLegalQuestions $manage): JsonResponse
    {
        $this->authorizeLpppk($request);
        $manage->startCorrection($question, $request->user());

        return response()->json(['data' => $this->detail($request->user(), $question, $manage)]);
    }

    /**
     * Send again (muhadhir).
     *
     * For an approved answer the website did not accept yet.
     */
    public function resend(Request $request, LegalQuestion $question, ManageLegalQuestions $manage): Response
    {
        $this->authorizeLpppk($request);
        $open = $manage->open($question);
        abort_unless(ManageArticles::isMuhadhir($request->user()) && $open?->status === 'approved' && CmsClient::enabled(), 403);

        $open->update(['error' => null]);
        SendLegalAnswer::dispatch($open->id);

        return response()->noContent();
    }

    private function authorizeLpppk(Request $request): void
    {
        abort_unless(DiscussionAccess::canUseLpppk($request->user()), 403);
    }

    private function open(LegalQuestion $question, ManageLegalQuestions $manage): LegalAnswer
    {
        $open = $manage->open($question);
        abort_if($open === null, 422, __('There is no answer being prepared.'));

        return $open;
    }

    /**
     * @return array<string, mixed>
     */
    private function question(LegalQuestion $question): array
    {
        return [
            'id' => $question->id,
            'code' => $question->tracking_code ?? $question->publicId(),
            'category' => $question->category,
            'category_label' => $question->categoryLabel(),
            'question' => $question->question,
            'asker' => $question->askerLabel(),
            'asker_city' => $question->asker_city,
            'moderator_note' => $question->moderator_note,
            /** Its LPPPK room. */
            'discussion_id' => $question->discussion_id,
            'created_at' => $question->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(User $user, LegalQuestion $question, ManageLegalQuestions $manage): array
    {
        $open = $manage->open($question)?->load(['drafter', 'approvals.approver']);
        $sent = $question->answers()->with('drafter')->whereIn('status', ['sent', 'superseded'])->orderByDesc('version')->get();
        $muhadhir = ManageArticles::isMuhadhir($user);
        $approved = $open !== null && $open->approvals->contains('approver_id', $user->id);

        return [
            ...$this->question($question),
            'open_answer' => $open === null ? null : [
                ...$this->answer($open),
                'approvals' => $open->approvals->map(fn (LegalAnswerApproval $approval) => [
                    'approver' => $approval->approver->name,
                    'created_at' => $approval->created_at->toIso8601String(),
                ])->values()->all(),
                'approvals_needed' => ManageLegalQuestions::APPROVALS_NEEDED,
            ],
            'sent_answers' => $sent->map(fn (LegalAnswer $answer) => $this->answer($answer))->values()->all(),
            'can' => [
                'draft' => $muhadhir && ($open === null ? $sent->isEmpty() : $open->status === 'draft'),
                'submit' => $muhadhir && $open?->status === 'draft',
                'approve' => $muhadhir && $open?->status === 'awaiting' && $open->drafted_by !== $user->id && ! $approved,
                'reopen' => $muhadhir && $open?->status === 'awaiting',
                'correct' => $muhadhir && $open === null && $sent->isNotEmpty(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(LegalAnswer $answer): array
    {
        return [
            'id' => $answer->id,
            'version' => $answer->version,
            /** draft | awaiting | approved | sent | superseded */
            'status' => $answer->status,
            'status_label' => $answer->statusLabel(),
            'answer' => $answer->answer_md,
            'answer_html' => $answer->html(),
            'references' => $answer->references,
            'drafter' => $answer->drafter->name,
            'submitted_at' => $answer->submitted_at?->toIso8601String(),
            'sent_at' => $answer->sent_at?->toIso8601String(),
            'website_status' => $answer->cms_status,
            'correction_pending' => $answer->correction_pending,
            'error' => $answer->error,
        ];
    }
}
