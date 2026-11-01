<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use App\Jobs\PrepareTicketImport;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class StartTicketImport
{
    public const string DIRECTORY = 'imports';

    public function handle(User $admin, UploadedFile $file, bool $autoAssign = false): TicketImport
    {
        $disk = (string) config('imports.disk');
        $path = $file->store(self::DIRECTORY, $disk);

        if ($path === false) {
            throw new RuntimeException('The import file could not be stored.');
        }

        $import = new TicketImport(['original_name' => Str::limit($file->getClientOriginalName(), 250, ''), 'path' => $path]);
        $import->user_id = $admin->id;
        $import->auto_assign = $autoAssign;

        try {
            $import->save();
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        PrepareTicketImport::dispatch($import->id);

        return $import->refresh();
    }
}
