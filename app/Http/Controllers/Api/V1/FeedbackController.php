<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Feedback\ManageFeedback;
use App\Enums\FeedbackCategory;
use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Support\ActiveRole;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kritik & saran (v6 D34) from any role. The Admin's inbox comes in Step 9.
 */
#[Group('Feedback', weight: 5)]
class FeedbackController extends Controller
{
    /**
     * My feedback.
     *
     * Messages I sent, newest first, with their status and the reply.
     */
    public function index(Request $request): JsonResponse
    {
        $items = Feedback::query()->where('user_id', $request->user()->id)->latest('id')->paginate(20);

        return response()->json([
            'data' => collect($items->items())->map(fn (Feedback $item) => $this->present($item))->values()->all(),
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
            'categories' => array_map(fn (FeedbackCategory $category) => ['value' => $category->value, 'label' => $category->label()], FeedbackCategory::cases()),
        ]);
    }

    /**
     * Send feedback.
     *
     * Anonymous messages hide the name from the Admin (Superadmin can see it); the reply still comes here.
     */
    public function store(Request $request, ManageFeedback $feedback): JsonResponse
    {
        $request->validate([
            'category' => ['required', Rule::enum(FeedbackCategory::class)],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
            'anonymous' => ['nullable', 'boolean'],
        ]);
        $role = ActiveRole::get();
        abort_if($role === null, 403, __('Choose a role first (X-Role).'));

        $item = $feedback->send($request->user(), $role, $request->only(['category', 'subject', 'message', 'anonymous']));

        return response()->json(['data' => $this->present($item)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Feedback $item): array
    {
        return [
            'id' => $item->id,
            'category' => $item->category->value,
            'category_label' => $item->category->label(),
            'subject' => $item->subject,
            'message' => $item->message,
            'anonymous' => $item->anonymous,
            'status' => $item->status->value,
            'status_label' => $item->status->label(),
            'reply' => $item->reply,
            'replied_at' => $item->replied_at?->toIso8601String(),
            'created_at' => $item->created_at->toIso8601String(),
        ];
    }
}
