<?php

namespace App\Http\Controllers;

use App\Support\Restores;
use Illuminate\Http\JsonResponse;

/**
 * How a restore is going (data-manage D51), for the Data page while SIAKAD is down: status, step and
 * message. Open without login, during maintenance; the 40-character token of that restore is the key.
 */
class RestoreStatusController extends Controller
{
    public function __invoke(string $token): JsonResponse
    {
        $state = Restores::forToken($token);
        abort_if($state === null, 404);

        return response()->json([
            'status' => $state['status'] ?? null,
            'step' => $state['step'] ?? null,
            'message' => $state['message'] ?? null,
            'safety_backup' => $state['safety_backup'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
        ])->header('Cache-Control', 'no-store');
    }
}
