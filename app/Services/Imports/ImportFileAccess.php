<?php

declare(strict_types=1);

namespace App\Services\Imports;

use Closure;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

final class ImportFileAccess
{
    public function __construct(private readonly FilesystemFactory $filesystems) {}

    /**
     * @template TResult
     *
     * @param  Closure(string): TResult  $read
     * @return TResult
     */
    public function withLocalPath(string $disk, string $path, Closure $read): mixed
    {
        $storage = $this->filesystems->disk($disk);

        if ($storage instanceof FilesystemAdapter && $storage->getAdapter() instanceof LocalFilesystemAdapter) {
            return $read($storage->path($path));
        }

        $copy = $this->copyToTemporaryFile($storage->readStream($path));

        try {
            return $read($copy);
        } finally {
            if (is_file($copy)) {
                unlink($copy);
            }
        }
    }

    /** @param resource|null $source */
    private function copyToTemporaryFile($source): string
    {
        $copy = tempnam(sys_get_temp_dir(), 'ticket-import-');
        $target = $copy === false ? false : fopen($copy, 'wb');

        if ($source === null || $copy === false || $target === false) {
            throw new RuntimeException('The import file could not be copied for reading.');
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($target);
            fclose($source);
        }

        return $copy;
    }
}
