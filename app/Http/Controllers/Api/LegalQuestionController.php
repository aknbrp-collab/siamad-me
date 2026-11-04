<?php

namespace App\Http\Controllers\Api;

use App\Actions\TanyaHukum\ManageLegalQuestions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/cms/legal-questions (spec §4.1): a question forwarded by the CMS
 * moderator. The key and signature are checked by VerifyCmsRequest. A repeat
 * (same Idempotency-Key / cms_question_id) returns the existing id with 200.
 */
class LegalQuestionController extends Controller
{
    public function store(Request $request, ManageLegalQuestions $questions): JsonResponse
    {
        $input = $request->json()->all();
        $input['cms_question_id'] ??= $request->header('Idempotency-Key');

        [$question, $new] = $questions->receive($input);

        return response()->json(['id' => $question->publicId()], $new ? 201 : 200);
    }
}
