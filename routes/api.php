<?php

use App\Http\Controllers\Api\LegalQuestionController;
use App\Http\Middleware\VerifyCmsRequest;
use Illuminate\Support\Facades\Route;

/*
| Calls from the website CMS (v5, docs/req/siakad-api-spec.md §4). The CMS sets
| SIAKAD_BASE_URL to {APP_URL}/api/cms. Bearer API key + signature, no session.
*/
Route::prefix('cms')->middleware(['throttle:60,1', VerifyCmsRequest::class])->group(function () {
    Route::post('legal-questions', [LegalQuestionController::class, 'store'])->name('api.cms.legal-questions');
});
