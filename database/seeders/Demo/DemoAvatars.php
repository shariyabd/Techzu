<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Profile\UpdateAvatar;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Throwable;

final class DemoAvatars
{
    private const int TIMEOUT_SECONDS = 8;

    private const int CONNECT_TIMEOUT_SECONDS = 4;

    private bool $offline = false;

    public function __construct(private readonly UpdateAvatar $updateAvatar) {}

    public function attach(User $user, string $photoId): bool
    {
        if ($this->offline) {
            return false;
        }

        $contents = $this->download(sprintf(DemoPeople::PHOTO_URL, $photoId));

        if ($contents === null) {
            return false;
        }

        try {
            $this->updateAvatar->handle($user, UploadedFile::fake()->createWithContent('avatar.jpg', $contents));
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function download(string $url): ?string
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->accept('image/*')
                ->get($url);
        } catch (Throwable) {
            $this->offline = true;

            return null;
        }

        $type = (string) $response->header('Content-Type');

        if (! $response->successful() || ! str_starts_with($type, 'image/') || $response->body() === '') {
            return null;
        }

        return $response->body();
    }
}
