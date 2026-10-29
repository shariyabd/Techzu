<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

final class ApiTokenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var NewAccessToken $token */
        $token = $this->resource;

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $token->accessToken->abilities,
        ];
    }
}
