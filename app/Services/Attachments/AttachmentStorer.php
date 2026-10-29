<?php

declare(strict_types=1);

namespace App\Services\Attachments;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
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
        /** @var Collection<int, Attachment> $attachments */
        $attachments = new Collection;

        if ($files === []) {
            return $attachments;
        }

        $disk = $this->disk();
        $directory = $this->config->get('attachments.directory', 'attachments').'/'.$ticket->id;
        $storedPaths = [];

        try {
            $pending = [];
            foreach ($files as $file) {
                $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'bin');
                $path = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, ['disk' => $disk]);

                if ($path === false) {
                    throw new RuntimeException('Could not store attachment '.$file->getClientOriginalName());
                }

                $storedPaths[] = $path;
                $pending[] = [$file, $path];
            }

            foreach ($pending as [$file, $path]) {
                $attachment = new Attachment;
                $attachment->forceFill([
                    'ticket_id' => $ticket->id,
                    'reply_id' => $reply?->id,
                    'uploaded_by' => $uploader->id,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size_bytes' => (int) $file->getSize(),
                    'is_internal' => $isInternal,
                ])->save();

                $attachments->push($attachment);
            }
        } catch (Throwable $e) {
            $this->storage->disk($disk)->delete($storedPaths);

            throw $e;
        }

        return $attachments;
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

    private function disk(): string
    {
        $disk = $this->config->get('attachments.disk', 'local');

        return is_string($disk) ? $disk : 'local';
    }
}
