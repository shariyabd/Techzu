<?php

declare(strict_types=1);

namespace App\Services\Users;

use Illuminate\Contracts\Config\Repository as Config;

final class AvatarRules
{
    public function __construct(private readonly Config $config) {}

    /** @return list<string> */
    public function file(): array
    {
        $min = (int) $this->config->get('avatars.min_dimension', 64);
        $max = (int) $this->config->get('avatars.max_dimension', 4096);

        return [
            'file',
            'image',
            'mimes:'.implode(',', $this->strings('avatars.extensions')),
            'mimetypes:'.implode(',', $this->strings('avatars.mime_types')),
            'max:'.(int) $this->config->get('avatars.max_kb', 2048),
            "dimensions:min_width={$min},min_height={$min},max_width={$max},max_height={$max}",
        ];
    }

    /** @return array{max_kb: int, extensions: list<string>, min_dimension: int, max_dimension: int} */
    public function hints(): array
    {
        return [
            'max_kb' => (int) $this->config->get('avatars.max_kb', 2048),
            'extensions' => $this->strings('avatars.extensions'),
            'min_dimension' => (int) $this->config->get('avatars.min_dimension', 64),
            'max_dimension' => (int) $this->config->get('avatars.max_dimension', 4096),
        ];
    }

    /** @return list<string> */
    private function strings(string $key): array
    {
        $values = $this->config->get($key, []);

        return array_values(array_filter(is_array($values) ? $values : [], is_string(...)));
    }
}
