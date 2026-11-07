<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Actions\Lms\ManageQuizzes;
use App\Http\Controllers\Api\V1\Student\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\QuizAttemptResource;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

/**
 * Taking a quiz: start (or continue) an attempt, save answers one by one,
 * submit. Time-outs submit automatically, as on the web.
 */
#[Group('Mahasantri', weight: 9)]
class QuizController extends Controller
{
    use ResolvesStudent;

    /**
     * Start a quiz.
     *
     * Starts an attempt, or returns the one already running.
     */
    public function start(Request $request, Quiz $quiz, ManageQuizzes $quizzes): QuizAttemptResource
    {
        abort_if($quiz->published_at === null, 404);
        $attempt = $quizzes->start($quiz, $this->enrollment($request, $quiz->courseOffering));

        return $this->present($attempt);
    }

    /**
     * An attempt.
     *
     * Questions in my order, the answers saved so far, the deadline; after submitting, the score.
     */
    public function show(Request $request, QuizAttempt $attempt, ManageQuizzes $quizzes): QuizAttemptResource
    {
        $this->authorizeAttempt($request, $attempt);
        $this->expireIfDue($attempt, $quizzes);

        return $this->present($attempt);
    }

    /**
     * Save an answer.
     *
     * `answer`: an option id (single choice, true/false), a list of option ids (multiple choice) or text
     * (essay). Saved at once; send it again to change it.
     */
    public function answer(Request $request, QuizAttempt $attempt, QuizQuestion $question, ManageQuizzes $quizzes): QuizAttemptResource
    {
        $request->validate(['answer' => ['present']]);
        $this->authorizeAttempt($request, $attempt);
        $this->expireIfDue($attempt, $quizzes);

        $quizzes->answer($attempt, $question, $request->input('answer'));

        return $this->present($attempt->refresh());
    }

    /**
     * Submit the quiz.
     *
     * Choice questions are scored at once; essays wait for the muhadhir.
     */
    public function submit(Request $request, QuizAttempt $attempt, ManageQuizzes $quizzes): QuizAttemptResource
    {
        $this->authorizeAttempt($request, $attempt);
        $quizzes->submit($attempt);

        return $this->present($attempt->refresh());
    }

    private function authorizeAttempt(Request $request, QuizAttempt $attempt): void
    {
        $attempt->loadMissing('courseEnrollment.student');
        abort_unless($attempt->courseEnrollment->student->user_id === $request->user()->id, 403);
    }

    private function expireIfDue(QuizAttempt $attempt, ManageQuizzes $quizzes): void
    {
        if ($attempt->submitted_at === null && now()->greaterThanOrEqualTo($attempt->deadline_at)) {
            $quizzes->submit($attempt);
            $attempt->refresh();
        }
    }

    private function present(QuizAttempt $attempt): QuizAttemptResource
    {
        $attempt->load(['quiz', 'answers']);
        $questions = QuizQuestion::query()->with('options')->whereIn('id', $attempt->question_order)->get()->keyBy('id');

        return new QuizAttemptResource($attempt, collect($attempt->question_order)->map(fn (int $id) => $questions->get($id))->filter()->values());
    }
}
