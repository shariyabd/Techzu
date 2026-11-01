<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\Role;

final class DemoPeople
{
    public const string PHOTO_URL = 'https://images.unsplash.com/photo-%s?w=256&h=256&fit=crop&crop=faces&q=80';

    public const array CATEGORIES = [
        'billing' => ['Billing', true],
        'technical-support' => ['Technical Support', true],
        'general-inquiry' => ['General Inquiry', true],
        'account-access' => ['Account & Access', true],
        'feature-requests' => ['Feature Requests', true],
        'legacy-desktop-app' => ['Legacy Desktop App', false],
    ];

    /** @return array<string, array{name: string, email: string, role: Role, categories: list<string>, available: bool, photo: string|null, last_seen_minutes: int|null, company: string|null}> */
    public static function all(): array
    {
        return [...self::staff(), ...self::customers()];
    }

    /** @return array<string, array{name: string, email: string, role: Role, categories: list<string>, available: bool, photo: string|null, last_seen_minutes: int|null, company: string|null}> */
    public static function staff(): array
    {
        return [
            'admin' => self::person('Ada Mensah', 'admin@example.com', Role::Admin, [], true, '1573496359142-b8d87734a5a2', 0),
            'a1' => self::person('Liam Carter', 'agent1@example.com', Role::Agent, ['billing', 'technical-support'], true, '1507003211169-0a1dd7228f2d', 1),
            'a2' => self::person('Priya Sharma', 'agent2@example.com', Role::Agent, ['technical-support'], true, '1494790108377-be9c29b29330', 12),
            'a3' => self::person('Marcus Chen', 'agent3@example.com', Role::Agent, ['general-inquiry'], true, '1500648767791-00dcc994a43e', 180),
            'a4' => self::person('Sofia Rossi', 'agent4@example.com', Role::Agent, ['billing', 'general-inquiry'], false, '1438761681033-6461ffad8d80', 8640),
            'a5' => self::person('Daniel Okafor', 'agent5@example.com', Role::Agent, ['account-access', 'technical-support'], true, '1472099645785-5658abf4ff4e', 35),
            'a6' => self::person('Hannah Weber', 'agent6@example.com', Role::Agent, ['feature-requests', 'general-inquiry', 'billing'], true, '1544005313-94ddf0286df2', 2),
        ];
    }

    /** @return array<string, array{name: string, email: string, role: Role, categories: list<string>, available: bool, photo: string|null, last_seen_minutes: int|null, company: string|null}> */
    public static function customers(): array
    {
        $customers = [
            ['Emma Johnson', 'Harbor & Pine Coffee', '1534528741775-53994a69daeb', 3],
            ['Noah Williams', 'Kestrel Analytics', '1506794778202-cad84cf45f1d', 50],
            ['Olivia Brown', 'Lumen Dental Group', null, 1440],
            ['James Garcia', 'Orchard Lane Books', '1539571696357-5a69c17a67c6', 300],
            ['Ava Martinez', 'Brightpath Tutors', null, null],
            ['Lucas Silva', 'Copperleaf Interiors', null, 4320],
            ['Mia Anderson', 'Fieldstone Realty', '1517841905240-472988babdf9', 1],
            ['Ethan Patel', 'Nimbus Travel', '1519085360753-af0119f7cbe7', 2880],
            ['Chloe Dubois', 'Quarry Hill Fitness', null, 12960],
            ['Benjamin Lee', 'Saltmarsh Media', '1560250097-0b93528c311a', 20],
            ['Zara Ahmed', 'Tidewater Clinics', '1531123897727-8f129e1688ce', null],
            ['Ryan O\'Connor', 'Verdant Grocers', null, 240],
        ];

        $people = [];

        foreach ($customers as $index => [$name, $company, $photo, $lastSeen]) {
            $n = $index + 1;
            $people["c{$n}"] = self::person($name, "customer{$n}@example.com", Role::Customer, [], true, $photo, $lastSeen, $company);
        }

        return $people;
    }

    /**
     * @param  list<string>  $categories
     * @return array{name: string, email: string, role: Role, categories: list<string>, available: bool, photo: string|null, last_seen_minutes: int|null, company: string|null}
     */
    private static function person(string $name, string $email, Role $role, array $categories, bool $available, ?string $photo, ?int $lastSeenMinutes, ?string $company = null): array
    {
        return [
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'categories' => $categories,
            'available' => $available,
            'photo' => $photo,
            'last_seen_minutes' => $lastSeenMinutes,
            'company' => $company,
        ];
    }
}
