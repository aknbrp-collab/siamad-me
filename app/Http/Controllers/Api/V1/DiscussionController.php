<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Discussions\ManageDiscussions;
use App\Enums\DiscussionKind;
use App\Http\Controllers\Controller;
use App\Models\Discussion;
use App\Models\DiscussionFile;
use App\Models\DiscussionMember;
use App\Models\DiscussionMessage;
use App\Models\DiscussionNote;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use App\Support\DiscussionAccess;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Discussion rooms (v5 D26): bimbingan groups, LPPPK channels and the
 * discussion of each Tanya Hukum question. Chat with files and replies,
 * shared notes, and moderation by the group's muhadhir and the staff who
 * oversee. The app polls `GET /discussions/{id}/messages?after_id=` every few
 * seconds while a room is open (D27).
 */
#[Group('Discussions', weight: 12)]
class DiscussionController extends Controller
{
    /**
     * Rooms.
     *
     * `kind=bimbingan`: my bimbingan groups (all of them for the staff who oversee); `kind=lpppk`: the LPPPK
     * channels. Each with its unread count and latest message.
     */
    public function index(Request $request, ManageDiscussions $manage): JsonResponse
    {
        $request->validate(['kind' => ['required', Rule::in(['bimbingan', 'lpppk'])]]);
        $user = $request->user();
        $kind = DiscussionKind::from((string) $request->input('kind'));

        if ($kind === DiscussionKind::Lpppk) {
            abort_unless(DiscussionAccess::canUseLpppk($user), 403);
        }

        $rooms = Discussion::query()->with('leadTeacher.user')
            ->where('kind', $kind)
            ->when($kind === DiscussionKind::Bimbingan && ! DiscussionAccess::oversees($user), fn ($query) => $query->whereHas('members', fn ($query) => $query->where('user_id', $user->id)))
            ->orderByRaw('case when closed_at is null then 0 else 1 end')->orderBy('name')
            ->get();
        $unread = $kind === DiscussionKind::Lpppk ? $manage->unreadLpppk($user) : $manage->unread($user, $kind);

        $latestIds = DiscussionMessage::query()->whereIn('discussion_id', $rooms->modelKeys())->whereNull('hidden_at')
            ->groupBy('discussion_id')->selectRaw('max(id) as id')->pluck('id');
        $latest = DiscussionMessage::query()->with('user')->whereKey($latestIds)->get()->keyBy('discussion_id');

        return response()->json(['data' => $rooms->map(fn (Discussion $room) => [
            ...$this->room($room),
            'unread' => $unread[$room->id] ?? 0,
            'latest_message' => ($message = $latest->get($room->id)) === null ? null : [
                'author' => $message->user->name,
                'body' => $message->body,
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ])->values()->all()]);
    }

    /**
     * A room.
     *
     * Its details, whether I moderate, why I cannot post (null when I can) and the members.
     */
    public function show(Request $request, Discussion $discussion, ManageDiscussions $manage): JsonResponse
    {
        $user = $request->user();
        $this->authorizeRoom($request, $discussion);
        $manage->memberFor($discussion, $user);

        return response()->json(['data' => [
            ...$this->room($discussion->load('leadTeacher.user')),
            'moderator' => DiscussionAccess::canModerate($user, $discussion),
            'cannot_post' => DiscussionAccess::whyNotPost($user, $discussion),
            'members' => $discussion->members()->with('user')->get()
                ->sortBy(fn (DiscussionMember $member) => ($member->role === 'lead' ? '0' : '1').$member->user->name)
                ->map(fn (DiscussionMember $member) => [
                    'id' => $member->id,
                    'name' => $member->user->name,
                    'role' => $member->role,
                    'muted' => $member->muted_at !== null && ($member->muted_until === null || $member->muted_until->isFuture()),
                    'muted_until' => $member->muted_until?->toIso8601String(),
                ])->values()->all(),
        ]]);
    }

    /**
     * Messages.
     *
     * Oldest first. Without parameters: the latest `limit` (default 50). `after_id`: only newer ones (poll
     * every few seconds while the room is open). `before_id`: older ones (scrolling up). Removed messages
     * stay as `hidden: true` without text or files. Reading marks the room read up to the newest message.
     */
    public function messages(Request $request, Discussion $discussion, ManageDiscussions $manage): JsonResponse
    {
        $request->validate([
            'after_id' => ['nullable', 'integer'],
            'before_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorizeRoom($request, $discussion);
        $user = $request->user();

        $messages = $discussion->messages()->with(['user', 'files', 'replyTo.user'])
            ->when($request->filled('after_id'), fn ($query) => $query->where('id', '>', $request->integer('after_id'))->orderBy('id'))
            ->when(! $request->filled('after_id'), fn ($query) => $query
                ->when($request->filled('before_id'), fn ($query) => $query->where('id', '<', $request->integer('before_id')))
                ->orderByDesc('id'))
            ->limit($request->integer('limit', 50))
            ->get()
            ->sortBy('id')->values();

        $newest = (int) $messages->max('id');

        if ($newest > 0) {
            $manage->markRead($discussion, $user, $newest);
        }

        return response()->json(['data' => $messages->map(fn (DiscussionMessage $message) => $this->message($request, $message))->all()]);
    }

    /**
     * Send a message.
     *
     * Multipart: `body` and/or up to five `files[]`, optionally `reply_to_id`. Refused with the reason when the
     * room is locked or closed, or I am muted.
     */
    public function send(Request $request, Discussion $discussion, ManageDiscussions $manage): JsonResponse
    {
        $request->validate(['body' => ['nullable', 'string', 'max:5000'], 'files' => ['nullable', 'array'], 'files.*' => ['file'], 'reply_to_id' => ['nullable', 'integer']]);
        $this->authorizeRoom($request, $discussion);

        $message = $manage->post($discussion, $request->user(), $request->input('body'), array_values($request->file('files', [])), $request->integer('reply_to_id') ?: null);

        return response()->json(['data' => $this->message($request, $message->load(['user', 'files', 'replyTo.user']))], 201);
    }

    /**
     * Remove a message.
     *
     * Its author, or a moderator.
     */
    public function hide(Request $request, Discussion $discussion, DiscussionMessage $message, ManageDiscussions $manage): Response
    {
        $this->authorizeRoom($request, $discussion);
        abort_unless($message->discussion_id === $discussion->id, 404);
        $manage->hide($message, $request->user());

        return response()->noContent();
    }

    /**
     * Files shared in the room.
     */
    public function files(Request $request, Discussion $discussion): JsonResponse
    {
        $this->authorizeRoom($request, $discussion);

        return response()->json(['data' => $discussion->files()->with('user')
            ->whereHas('message', fn ($query) => $query->whereNull('hidden_at'))
            ->latest('id')->get()
            ->map(fn (DiscussionFile $file) => $this->file($request, $file) + ['author' => $file->user->name, 'created_at' => $file->created_at->toIso8601String()])
            ->values()->all()]);
    }

    /**
     * Notes.
     *
     * Shared notes of the room (e.g. a reading list), latest changed first.
     */
    public function notes(Request $request, Discussion $discussion): JsonResponse
    {
        $this->authorizeRoom($request, $discussion);

        return response()->json(['data' => $discussion->notes()->with(['user', 'editor'])->whereNull('hidden_at')->latest('updated_at')->get()
            ->map(fn (DiscussionNote $note) => $this->note($note))->values()->all()]);
    }

    /**
     * Add a note.
     */
    public function storeNote(Request $request, Discussion $discussion, ManageDiscussions $manage): JsonResponse
    {
        $request->validate(['title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string']]);
        $this->authorizeRoom($request, $discussion);

        $note = $this->withNoteKeys(fn () => $manage->saveNote($discussion, $request->user(), null, (string) $request->input('title'), (string) $request->input('body')));

        return response()->json(['data' => $this->note($note->load(['user', 'editor']))], 201);
    }

    /**
     * Change a note.
     */
    public function updateNote(Request $request, Discussion $discussion, DiscussionNote $note, ManageDiscussions $manage): JsonResponse
    {
        $request->validate(['title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string']]);
        $this->authorizeRoom($request, $discussion);
        abort_unless($note->discussion_id === $discussion->id, 404);

        $note = $this->withNoteKeys(fn () => $manage->saveNote($discussion, $request->user(), $note, (string) $request->input('title'), (string) $request->input('body')));

        return response()->json(['data' => $this->note($note->load(['user', 'editor']))]);
    }

    /**
     * Remove a note.
     */
    public function hideNote(Request $request, Discussion $discussion, DiscussionNote $note, ManageDiscussions $manage): Response
    {
        $this->authorizeRoom($request, $discussion);
        abort_unless($note->discussion_id === $discussion->id, 404);
        $this->withNoteKeys(fn () => $manage->hideNote($note, $request->user()));

        return response()->noContent();
    }

    /**
     * Mute a member (moderator).
     *
     * `until`: a date and time, or null to mute until lifted.
     */
    public function mute(Request $request, Discussion $discussion, DiscussionMember $member, ManageDiscussions $manage): Response
    {
        $request->validate(['until' => ['nullable', 'date', 'after:now']]);
        $this->authorizeMember($request, $discussion, $member);

        $manage->mute($member, $request->filled('until') ? Carbon::parse((string) $request->input('until')) : null, $request->user());

        return response()->noContent();
    }

    /**
     * Lift a mute (moderator).
     */
    public function unmute(Request $request, Discussion $discussion, DiscussionMember $member, ManageDiscussions $manage): Response
    {
        $this->authorizeMember($request, $discussion, $member);
        $manage->unmute($member, $request->user());

        return response()->noContent();
    }

    /**
     * Lock or unlock (moderator).
     *
     * A locked room can be read but not written in.
     */
    public function lock(Request $request, Discussion $discussion, ManageDiscussions $manage): Response
    {
        $request->validate(['locked' => ['required', 'boolean']]);
        $this->authorizeRoom($request, $discussion);
        $manage->setLocked($discussion, $request->boolean('locked'), $request->user());

        return response()->noContent();
    }

    /**
     * Report the action's note errors (named after the web form) under the API's field names.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withNoteKeys(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key) => [$key === 'noteTitle' ? 'title' : 'body' => $messages])->all());
        }
    }

    private function authorizeRoom(Request $request, Discussion $discussion): void
    {
        abort_unless(DiscussionAccess::canView($request->user(), $discussion), 403);
    }

    private function authorizeMember(Request $request, Discussion $discussion, DiscussionMember $member): void
    {
        $this->authorizeRoom($request, $discussion);
        abort_unless($member->discussion_id === $discussion->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function room(Discussion $room): array
    {
        return [
            'id' => $room->id,
            'kind' => $room->kind->value,
            'name' => $room->name,
            'description' => $room->description,
            'lead' => $room->relationLoaded('leadTeacher') ? $room->leadTeacher?->user->name : null,
            'locked' => $room->locked_at !== null,
            'closed' => $room->closed_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function message(Request $request, DiscussionMessage $message): array
    {
        $hidden = $message->hidden_at !== null;

        return [
            'id' => $message->id,
            'author' => $message->user->name,
            'mine' => $message->user_id === $request->user()->id,
            'hidden' => $hidden,
            'body' => $hidden ? null : $message->body,
            'reply_to' => $message->replyTo === null ? null : [
                'id' => $message->replyTo->id,
                'author' => $message->replyTo->user->name,
                'body' => $message->replyTo->hidden_at === null ? $message->replyTo->body : null,
            ],
            'files' => $hidden ? [] : $message->files->map(fn (DiscussionFile $file) => $this->file($request, $file))->values()->all(),
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function file(Request $request, DiscussionFile $file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'size' => $file->size,
            'url' => ApiFiles::url($request->user(), ActiveRole::get(), 'discussion-file', $file->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function note(DiscussionNote $note): array
    {
        return [
            'id' => $note->id,
            'title' => $note->title,
            'body' => $note->body,
            'author' => $note->user->name,
            'edited_by' => $note->editor?->name,
            'updated_at' => $note->updated_at->toIso8601String(),
        ];
    }
}
