<?php

namespace App\Actions\Discussions;

use App\Enums\DiscussionKind;
use App\Models\Discussion;
use App\Models\DiscussionFile;
use App\Models\DiscussionMember;
use App\Models\DiscussionMessage;
use App\Models\DiscussionNote;
use App\Models\Teacher;
use App\Models\User;
use App\Support\DiscussionAccess;
use App\Support\Notify;
use App\Support\Upload;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Discussion rooms (v5 D26): chat messages with replies and files, pinned
 * notes, read markers and moderation (hide, mute, lock, close). Nothing is
 * deleted; hidden messages and notes keep their text for the audit.
 */
class ManageDiscussions
{
    public const MAX_FILES = 5;

    public function create(DiscussionKind $kind, string $name, ?string $description, ?Teacher $lead, User $by): Discussion
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 150) {
            $this->fail('name', __('Give the discussion a name (max 150 characters).'));
        }

        return DB::transaction(function () use ($kind, $name, $description, $lead, $by) {
            $discussion = Discussion::create([
                'kind' => $kind,
                'name' => $name,
                'description' => filled($description) ? trim((string) $description) : null,
                'lead_teacher_id' => $lead?->id,
                'created_by' => $by->id,
            ]);

            if ($lead !== null) {
                DiscussionMember::create(['discussion_id' => $discussion->id, 'user_id' => $lead->user_id, 'role' => 'lead']);
            }

            return $discussion;
        });
    }

    /**
     * Add people (user ids); people already in stay as they are.
     *
     * @param  list<int>  $userIds
     */
    public function addMembers(Discussion $discussion, array $userIds): int
    {
        $added = 0;

        foreach (array_unique($userIds) as $userId) {
            $member = DiscussionMember::query()->firstOrCreate(['discussion_id' => $discussion->id, 'user_id' => $userId]);
            $added += $member->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }

    public function removeMember(DiscussionMember $member): void
    {
        if ($member->role === 'lead') {
            $this->fail('member', __('The lead muhadhir cannot be removed; choose another lead first.'));
        }

        $member->delete();
    }

    /**
     * The member row of $user, made on their first visit to an LPPPK channel.
     */
    public function memberFor(Discussion $discussion, User $user): ?DiscussionMember
    {
        $member = DiscussionAccess::member($user, $discussion);

        if ($member === null && $discussion->kind === DiscussionKind::Lpppk && DiscussionAccess::canView($user, $discussion)) {
            $member = DiscussionMember::query()->firstOrCreate(['discussion_id' => $discussion->id, 'user_id' => $user->id]);
        }

        return $member;
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function post(Discussion $discussion, User $user, ?string $body, array $files = [], ?int $replyToId = null): DiscussionMessage
    {
        if ($reason = DiscussionAccess::whyNotPost($user, $discussion)) {
            $this->fail('body', $reason);
        }

        $body = trim((string) $body);

        Validator::make(['body' => $body, 'files' => $files], [
            'body' => ['nullable', 'string', 'max:5000'],
            'files' => ['array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,zip,txt', Upload::max()],
        ], [], ['body' => __('message'), 'files' => __('files'), 'files.*' => __('file')])->validate();

        if ($body === '' && $files === []) {
            $this->fail('body', __('Write a message or attach a file.'));
        }

        $replyTo = $replyToId === null ? null
            : DiscussionMessage::query()->with('user')->where('discussion_id', $discussion->id)->whereKey($replyToId)->first();

        $message = DB::transaction(function () use ($discussion, $user, $body, $files, $replyTo) {
            $message = DiscussionMessage::create([
                'discussion_id' => $discussion->id,
                'user_id' => $user->id,
                'reply_to_id' => $replyTo?->id,
                'body' => $body !== '' ? $body : null,
            ]);

            foreach ($files as $file) {
                $path = $file->store("discussions/{$discussion->id}", 'local');

                if ($path === false) {
                    throw new RuntimeException('The file could not be stored.');
                }

                DiscussionFile::create([
                    'discussion_id' => $discussion->id,
                    'discussion_message_id' => $message->id,
                    'user_id' => $user->id,
                    'file_path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'size' => (int) $file->getSize(),
                ]);
            }

            return $message;
        });

        $this->memberFor($discussion, $user)?->update(['last_read_message_id' => $message->id]);
        $this->notify($discussion, $message, $user, $replyTo);

        return $message;
    }

    /**
     * Hide a message: its author, or a moderator.
     */
    public function hide(DiscussionMessage $message, User $by): void
    {
        $discussion = $message->discussion;

        if ($message->user_id !== $by->id && ! DiscussionAccess::canModerate($by, $discussion)) {
            $this->fail('message', __('Only the author or a moderator can remove a message.'));
        }

        $message->update(['hidden_at' => now(), 'hidden_by' => $by->id]);
    }

    /**
     * Mute a member until $until, or until lifted when null.
     */
    public function mute(DiscussionMember $member, ?CarbonInterface $until, User $by): void
    {
        $this->ensureModerator($member->discussion, $by);

        if ($member->role === 'lead' || $member->user_id === $by->id) {
            $this->fail('member', __('This person cannot be muted here.'));
        }

        if ($until !== null && $until->isPast()) {
            $this->fail('mutedUntil', __('Choose a time in the future, or no end.'));
        }

        $member->update(['muted_at' => now(), 'muted_until' => $until]);
    }

    public function unmute(DiscussionMember $member, User $by): void
    {
        $this->ensureModerator($member->discussion, $by);
        $member->update(['muted_at' => null, 'muted_until' => null]);
    }

    public function setLocked(Discussion $discussion, bool $locked, User $by): void
    {
        $this->ensureModerator($discussion, $by);
        $discussion->update(['locked_at' => $locked ? now() : null]);
    }

    public function setClosed(Discussion $discussion, bool $closed, User $by): void
    {
        $this->ensureModerator($discussion, $by);
        $discussion->update(['closed_at' => $closed ? now() : null]);
    }

    /**
     * Add a note, or change one (its author or a moderator).
     */
    public function saveNote(Discussion $discussion, User $user, ?DiscussionNote $note, string $title, string $body): DiscussionNote
    {
        if ($reason = DiscussionAccess::whyNotPost($user, $discussion)) {
            $this->fail('noteBody', $reason);
        }

        if ($note !== null && $note->user_id !== $user->id && ! DiscussionAccess::canModerate($user, $discussion)) {
            $this->fail('noteBody', __('Only its author or a moderator can change this note.'));
        }

        $data = Validator::make(['noteTitle' => trim($title), 'noteBody' => trim($body)], [
            'noteTitle' => ['required', 'string', 'max:150'],
            'noteBody' => ['required', 'string', 'max:10000'],
        ], [], ['noteTitle' => __('title'), 'noteBody' => __('note')])->validate();

        if ($note === null) {
            return DiscussionNote::create(['discussion_id' => $discussion->id, 'user_id' => $user->id, 'title' => $data['noteTitle'], 'body' => $data['noteBody']]);
        }

        $note->update(['title' => $data['noteTitle'], 'body' => $data['noteBody'], 'updated_by' => $user->id]);

        return $note;
    }

    public function hideNote(DiscussionNote $note, User $by): void
    {
        $discussion = Discussion::query()->findOrFail($note->discussion_id);

        if ($note->user_id !== $by->id && ! DiscussionAccess::canModerate($by, $discussion)) {
            $this->fail('noteBody', __('Only its author or a moderator can remove this note.'));
        }

        $note->update(['hidden_at' => now(), 'updated_by' => $by->id]);
    }

    public function markRead(Discussion $discussion, User $user, int $lastMessageId): void
    {
        $member = $this->memberFor($discussion, $user);

        if ($member !== null && $lastMessageId > $member->last_read_message_id) {
            $member->update(['last_read_message_id' => $lastMessageId]);
        }
    }

    /**
     * Unread messages per discussion of $user (their own and hidden ones not counted).
     *
     * @return array<int, int> discussion id => count
     */
    public function unread(User $user, ?DiscussionKind $kind = null): array
    {
        return DiscussionMember::query()
            ->join('discussions', 'discussions.id', '=', 'discussion_members.discussion_id')
            ->join('discussion_messages', function ($join) {
                $join->on('discussion_messages.discussion_id', '=', 'discussion_members.discussion_id')
                    ->whereColumn('discussion_messages.id', '>', 'discussion_members.last_read_message_id');
            })
            ->where('discussion_members.user_id', $user->id)
            ->where('discussion_messages.user_id', '!=', $user->id)
            ->whereNull('discussion_messages.hidden_at')
            ->when($kind, fn ($query) => $query->where('discussions.kind', $kind->value))
            ->groupBy('discussion_members.discussion_id')
            ->selectRaw('discussion_members.discussion_id as id, count(*) as aggregate')
            ->pluck('aggregate', 'id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Bimbingan: everyone else in the group, one grouped notification per room
     * while unread (D27). LPPPK channels are large: only the author of the
     * message replied to is told.
     */
    private function notify(Discussion $discussion, DiscussionMessage $message, User $by, ?DiscussionMessage $replyTo): void
    {
        $recipients = $discussion->kind === DiscussionKind::Bimbingan
            ? User::query()->whereIn('id', $discussion->members()->where('user_id', '!=', $by->id)->pluck('user_id'))->get()
            : ($replyTo !== null && $replyTo->user_id !== $by->id ? [$replyTo->user] : []);

        $snippet = $message->body !== null ? Str::limit($message->body, 120) : __('shared a file');

        Notify::send(
            $recipients,
            'discussion',
            $discussion->name,
            $by->name.': '.$snippet,
            route('discussions.show', $discussion),
            'chat-bubble-left-right',
            $by,
            "discussion:{$discussion->id}",
            __(':count new messages', ['count' => ':count']),
        );
    }

    private function ensureModerator(Discussion $discussion, User $by): void
    {
        if (! DiscussionAccess::canModerate($by, $discussion)) {
            $this->fail('member', __('Only a moderator can do this.'));
        }
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
