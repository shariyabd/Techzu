<?php

declare(strict_types=1);

namespace App\Enums;

enum SavedReplyPlaceholder: string
{
    case CustomerName = 'customer_name';
    case CustomerFirstName = 'customer_first_name';
    case TicketNumber = 'ticket_number';
    case TicketSubject = 'ticket_subject';
    case AgentName = 'agent_name';
    case AgentFirstName = 'agent_first_name';

    private const string PATTERN = '/\{([A-Za-z0-9_]+)\}/';

    public function token(): string
    {
        return '{'.$this->value.'}';
    }

    /** @return list<string> */
    public static function unknownIn(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        $unknown = array_filter(
            array_unique($matches[1]),
            static fn (string $name): bool => self::tryFrom($name) === null,
        );

        return array_values(array_map(static fn (string $name): string => '{'.$name.'}', $unknown));
    }
}
