<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\LookupRequest;
use App\Http\Resources\V1\CustomerLookupResource;
use App\Services\Users\UserDirectory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CustomerLookupController extends Controller
{
    public function __invoke(LookupRequest $request, UserDirectory $directory): AnonymousResourceCollection
    {
        return CustomerLookupResource::collection($directory->lookupCustomers($request->term(), $request->customerId()));
    }
}
