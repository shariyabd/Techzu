<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\IssueApiToken;
use App\Actions\Auth\RevokeCurrentApiToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginTokenRequest;
use App\Http\Resources\V1\ApiTokenResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AuthTokenController extends Controller
{
    public function store(LoginTokenRequest $request, IssueApiToken $issueApiToken): JsonResponse
    {
        $token = $issueApiToken->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->string('device_name')->toString(),
        );

        return (new ApiTokenResource($token))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, RevokeCurrentApiToken $revokeCurrentApiToken): Response
    {
        /** @var User $user */
        $user = $request->user();

        $revokeCurrentApiToken->handle($user);

        return response()->noContent();
    }
}
