<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;

final class TicketNumberGenerator
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Config $config,
    ) {}

    public function next(CarbonImmutable $at): string
    {
        $year = $at->setTimezone($this->config->string('business_hours.timezone'))->year;
        $prefix = $this->config->string('tickets.number_prefix');

        $number = $this->connection->transaction(function () use ($year): int {
            $now = CarbonImmutable::now();

            $this->connection->affectingStatement(
                'insert into `ticket_sequences` (`year`, `last_number`, `created_at`, `updated_at`) '
                .'values (?, 1, ?, ?) '
                .'on duplicate key update `last_number` = `last_number` + 1, `updated_at` = ?',
                [$year, $now, $now, $now],
            );

            return (int) $this->connection->table('ticket_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->value('last_number');
        });

        return sprintf('%s-%d-%06d', $prefix, $year, $number);
    }
}
