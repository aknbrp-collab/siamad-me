<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\IssueApiToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\V1\DeviceResource;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Signing in and out of the mobile app, and the devices signed in (v6 D37).
 *
 * @tags Auth
 */
class AuthController extends Controller
{
    /**
     * Log in.
     *
     * With the ID number (NIM, NIDM, guardian code or staff login) and password. Accounts with two-factor
     * authentication first get `422` with `code: "two_factor_required"`; send the login again with `code`
     * (from the authenticator app) or a `recovery_code`. Send the returned token as `Authorization: Bearer …`.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request, IssueApiToken $issue): JsonResponse
    {
        $token = $issue->handle($request->all());

        /** @var User $user */
        $user = $token->accessToken->tokenable;

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => (new UserResource($user))->resolve($request),
        ], 201);
    }

    /**
     * Log out this device.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Devices signed in to the app.
     */
    public function devices(Request $request): AnonymousResourceCollection
    {
        return DeviceResource::collection($request->user()->tokens()->latest('last_used_at')->latest('id')->get());
    }

    /**
     * Sign out a device.
     */
    public function destroyDevice(Request $request, int $device): Response
    {
        $request->user()->tokens()->whereKey($device)->firstOrFail()->delete();

        return response()->noContent();
    }

    /**
     * Set this device's push token.
     *
     * The Firebase Cloud Messaging token of the app on this device; `null` turns push off. Sending through FCM
     * starts once the server has its Firebase keys (v6 D39).
     */
    public function pushToken(Request $request): Response
    {
        $data = $request->validate(['fcm_token' => ['present', 'nullable', 'string', 'max:512']]);
        $request->user()->currentAccessToken()->forceFill(['fcm_token' => $data['fcm_token']])->save();

        return response()->noContent();
    }
}
