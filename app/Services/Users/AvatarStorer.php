<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use GdImage;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AvatarStorer
{
    public function __construct(
        private readonly FilesystemManager $storage,
        private readonly Config $config,
    ) {}

    public function store(UploadedFile $file): string
    {
        $name = $this->directory().'/'.Str::random(40);
        $encoded = $this->encode($file);

        if ($encoded !== null) {
            $path = $name.'.'.$encoded['extension'];

            if (! $this->disk()->put($path, $encoded['contents'])) {
                throw new RuntimeException('Could not store the profile image.');
            }

            return $path;
        }

        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $path = $file->storeAs($this->directory(), basename($name).'.'.$extension, ['disk' => $this->diskName()]);

        if ($path === false) {
            throw new RuntimeException('Could not store the profile image.');
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            $this->disk()->delete($path);
        }
    }

    public function response(User $user, Request $request): ?Response
    {
        $path = $user->avatar_path;
        $disk = $this->disk();

        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        $mime = $disk->mimeType($path);

        if (! is_string($mime) || ! in_array($mime, $this->allowedMimeTypes(), true)) {
            return null;
        }

        $maxAge = (int) $this->config->get('avatars.cache_seconds', 604800);

        $response = $disk->response($path, basename($path), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => "private, max-age={$maxAge}",
        ], 'inline');

        $response->setEtag(sha1($path));
        $response->isNotModified($request);

        return $response;
    }

    /** @return array{contents: string, extension: string}|null */
    private function encode(UploadedFile $file): ?array
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagecopyresampled')) {
            return null;
        }

        try {
            $contents = (string) file_get_contents((string) $file->getRealPath());
            $source = imagecreatefromstring($contents);
        } catch (Throwable) {
            return null;
        }

        if (! $source instanceof GdImage) {
            return null;
        }

        $canvas = $this->cropToSquare($source);

        ob_start();
        $written = function_exists('imagewebp') ? imagewebp($canvas, null, 85) : imagepng($canvas);
        $output = (string) ob_get_clean();

        if (! $written || $output === '') {
            return null;
        }

        return ['contents' => $output, 'extension' => function_exists('imagewebp') ? 'webp' : 'png'];
    }

    private function cropToSquare(GdImage $source): GdImage
    {
        $size = max(1, (int) $this->config->get('avatars.size', 256));
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $canvas = imagecreatetruecolor($size, $size);

        if (! $canvas instanceof GdImage) {
            throw new RuntimeException('Could not resize the profile image.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $size, $size, $side, $side);

        return $canvas;
    }

    /** @return list<string> */
    private function allowedMimeTypes(): array
    {
        $types = $this->config->get('avatars.mime_types', []);

        return array_values(array_filter(is_array($types) ? $types : [], is_string(...)));
    }

    private function disk(): FilesystemAdapter
    {
        $disk = $this->storage->disk($this->diskName());

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('The avatar disk must be a filesystem adapter.');
        }

        return $disk;
    }

    private function diskName(): string
    {
        $disk = $this->config->get('avatars.disk', 'local');

        return is_string($disk) ? $disk : 'local';
    }

    private function directory(): string
    {
        $directory = $this->config->get('avatars.directory', 'avatars');

        return is_string($directory) ? $directory : 'avatars';
    }
}
