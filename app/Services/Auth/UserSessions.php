<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Session\SessionManager;

final class UserSessions
{
    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $database,
        private readonly SessionManager $sessions,
    ) {}

    public function endFor(User $user, bool $keepCurrent): void
    {
        if ($this->config->get('session.driver') !== 'database') {
            return;
        }

        $connection = $this->config->get('session.connection');

        $this->database->connection(is_string($connection) ? $connection : null)
            ->table((string) $this->config->get('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($keepCurrent, fn ($query) => $query->where('id', '!=', $this->sessions->driver()->getId()))
            ->delete();
    }
}
