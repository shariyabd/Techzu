<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\TicketImport;
use App\Models\TicketImportError;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

final class TicketImportQuery
{
    /** @return LengthAwarePaginator<int, TicketImport> */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return TicketImport::query()->with('user:id,name')->latest('id')->paginate($perPage);
    }

    /** @return LengthAwarePaginator<int, TicketImportError> */
    public function errors(TicketImport $import, int $perPage): LengthAwarePaginator
    {
        return $import->errors()->orderBy('row_number')->orderBy('id')->paginate($perPage)->withQueryString();
    }

    /** @return LazyCollection<int, TicketImportError> */
    public function allErrors(TicketImport $import): LazyCollection
    {
        return $import->errors()->orderBy('row_number')->orderBy('id')->lazy(1000);
    }
}
