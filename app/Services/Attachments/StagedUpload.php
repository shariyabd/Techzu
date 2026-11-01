<?php

declare(strict_types=1);

namespace App\Services\Attachments;

final readonly class StagedUpload
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes,
    ) {}
}
