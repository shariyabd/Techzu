<?php

declare(strict_types=1);

use App\Services\Imports\ImportFileAccess;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;

it('reads an import from a disk without local paths through a temporary copy that is removed afterwards', function (): void {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, "subject,description\nPrinter,On fire\n");
    rewind($stream);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('readStream')->once()->with('imports/tickets.csv')->andReturn($stream);
    $filesystems = Mockery::mock(FilesystemFactory::class);
    $filesystems->shouldReceive('disk')->with('remote')->andReturn($disk);
    $readFrom = null;

    $contents = (new ImportFileAccess($filesystems))->withLocalPath('remote', 'imports/tickets.csv', function (string $path) use (&$readFrom): string {
        $readFrom = $path;

        return (string) file_get_contents($path);
    });

    expect($contents)->toBe("subject,description\nPrinter,On fire\n")
        ->and(is_file((string) $readFrom))->toBeFalse();
});
