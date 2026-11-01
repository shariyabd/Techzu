<?php

declare(strict_types=1);

namespace App\Services\Attachments;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AttachmentStorer
{
    public function __construct(
        private readonly FilesystemManager $storage,
        private readonly Config $config,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return Collection<int, Attachment>
     */
    public function storeMany(Ticket $ticket, ?TicketReply $reply, User $uploader, array $files, bool $isInternal): Collection
    {
        $staged = $this->stage($files);

        try {
            return $this->persist($ticket, $reply, $uploader, $staged, $isInternal);
        } catch (Throwable $e) {
            $this->discard($staged);

            throw $e;
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<StagedUpload>
     */
    public function stage(array $files): array
    {
        $disk = $this->disk();
        $directory = $this->directory();
        $staged = [];

        try {
            foreach ($files as $file) {
                $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'bin');
                $path = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, ['disk' => $disk]);

                if ($path === false) {
                    throw new RuntimeException('Could not store attachment '.$file->getClientOriginalName());
                }

                $staged[] = new StagedUpload(
                    disk: $disk,
                    path: $path,
                    originalName: mb_substr($file->getClientOriginalName(), 0, 255),
                    mimeType: $file->getMimeType() ?? 'application/octet-stream',
                    sizeBytes: (int) $file->getSize(),
                );
            }
        } catch (Throwable $e) {
            $this->discard($staged);

            throw $e;
        }

        return $staged;
    }

    /**
     * @param  list<StagedUpload>  $staged
     * @return Collection<int, Attachment>
     */
    public function persist(Ticket $ticket, ?TicketReply $reply, User $uploader, array $staged, bool $isInternal): Collection
    {
        /** @var Collection<int, Attachment> $attachments */
        $attachments = new Collection;

        foreach ($staged as $upload) {
            $attachment = new Attachment;
            $attachment->forceFill([
                'ticket_id' => $ticket->id,
                'reply_id' => $reply?->id,
                'uploaded_by' => $uploader->id,
                'disk' => $upload->disk,
                'path' => $upload->path,
                'original_name' => $upload->originalName,
                'mime_type' => $upload->mimeType,
                'size_bytes' => $upload->sizeBytes,
                'is_internal' => $isInternal,
            ])->save();

            $attachments->push($attachment);
        }

        return $attachments;
    }

    /** @param  list<StagedUpload>  $staged */
    public function discard(array $staged): void
    {
        foreach ($staged as $upload) {
            $this->storage->disk($upload->disk)->delete($upload->path);
        }
    }

    /** @param  Collection<int, Attachment>  $attachments */
    public function deleteFiles(Collection $attachments): void
    {
        $attachments
            ->groupBy('disk')
            ->each(function (Collection $group, string $disk): void {
                $this->storage->disk($disk)->delete($group->pluck('path')->all());
            });
    }

    private function directory(): string
    {
        $base = $this->config->get('attachments.directory', 'attachments');

        return (is_string($base) ? $base : 'attachments').'/'.CarbonImmutable::now('UTC')->format('Y/m');
    }

    private function disk(): string
    {
        $disk = $this->config->get('attachments.disk', 'local');

        return is_string($disk) ? $disk : 'local';
    }
}
