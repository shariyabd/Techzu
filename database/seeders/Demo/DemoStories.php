<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;

final class DemoStories
{
    private const int HOUR = 60;

    private const int DAY = 1440;

    /** @return list<DemoStory> */
    public static function all(): array
    {
        return [
            ...self::untouched(),
            ...self::assignedWithoutReply(),
            ...self::inProgress(),
            ...self::waitingForCustomer(),
            ...self::resolved(),
            ...self::closed(),
            ...self::reopened(),
            ...self::handoffs(),
            ...self::categoryMoves(),
            ...self::priorityChanges(),
            ...self::internalOnly(),
            ...self::onBehalf(),
            ...self::longConversations(),
        ];
    }

    /** @return list<DemoStory> */
    private static function untouched(): array
    {
        return [
            DemoStory::of('dashboard-blank', 'c7')->elapsed(0.4)->file(DemoFileType::Screenshot),
            DemoStory::of('slow-search', 'c4')->elapsed(0.2),
            DemoStory::of('nonprofit-discount', 'c9')->elapsed(0.1),
            DemoStory::of('dark-mode', 'c11')->daysAgo(0.3),
            DemoStory::of('webhook-401', 'c10')->elapsed(1.6)->file(DemoFileType::Log),
            DemoStory::of('account-deletion', 'c5')->elapsed(0.8),
            DemoStory::of('onboarding-call', 'c3')->onBehalfBy('a3')->elapsed(0.3),
            DemoStory::of('receipt-email', 'c12')->daysAgo(0.05),
        ];
    }

    /** @return list<DemoStory> */
    private static function assignedWithoutReply(): array
    {
        return [
            DemoStory::of('export-timeout', 'c2')->elapsed(0.3)->file(DemoFileType::Spreadsheet)->auto(),
            DemoStory::of('sso-setup', 'c6')->elapsed(0.5)->file(DemoFileType::Screenshot)->claim('a5', 25),
            DemoStory::of('seat-proration', 'c8')->priority(TicketPriority::Low)->elapsed(0.78)->assign('a6', 40),
            DemoStory::of('calendar-sync', 'c1')->elapsed(1.4)->auto(1),
            DemoStory::of('custom-fields-export', 'c4')->elapsed(0.25)->file(DemoFileType::Spreadsheet)->assign('a2', 30, outsideCategory: true),
        ];
    }

    /** @return list<DemoStory> */
    private static function inProgress(): array
    {
        return [
            DemoStory::of('mobile-crash', 'c3')->daysAgo(2.2)->auto()->agent(50)->customer(90, DemoFileType::Log)->agent(120)->note(30),
            DemoStory::of('api-rate-limit', 'c10')->elapsed(0.6)->auto()->talk(3, 25),
            DemoStory::of('double-charge', 'c5')->priority(TicketPriority::Medium)->elapsed(0.77)->auto()->agent(20)->customer(40, DemoFileType::Document)->note(15),
            DemoStory::of('dashboard-blank', 'c12')->daysAgo(1.1)->auto()->agent(15)->customer(20, DemoFileType::Screenshot)->note(10)->agent(40)->note(5),
            DemoStory::of('failed-payment', 'c11')->elapsed(0.5)->auto()->agent(10)->customer(15)->agent(12),
            DemoStory::of('lost-2fa', 'c2')->daysAgo(1.8)->assign('a5', 10)->agent(20)->note(15)->note(30, DemoFileType::Document, 'admin'),
            DemoStory::of('vat-invoice', 'c5')->priority(TicketPriority::High)->elapsed(1.2)->auto()->agent(30)->customer(40),
            DemoStory::of('nonprofit-discount', 'c4')->daysAgo(0.8)->auto()->agent(25)->customer(30, DemoFileType::Document),
            DemoStory::of('onboarding-call', 'c7')->elapsed(0.7)->auto()->agent(15),
            DemoStory::of('data-retention', 'c11')->elapsed(0.82)->auto()->agent(20)->customer(30),
        ];
    }

    /** @return list<DemoStory> */
    private static function waitingForCustomer(): array
    {
        return [
            DemoStory::of('password-reset', 'c4')->daysAgo(1.3)->auto()->askForDetails(35),
            DemoStory::of('transfer-ownership', 'c8')->daysAgo(3.4)->claim('a5', self::HOUR)->askForDetails(20),
            DemoStory::of('security-questionnaire', 'c6')->daysAgo(5.2)->file(DemoFileType::Document)->auto()->agent(90)->customer(200)->askForDetails(60),
            DemoStory::of('refund-downgrade', 'c7')->daysAgo(2.6)->auto()->askForDetails(45),
            DemoStory::of('import-encoding', 'c1')->daysAgo(6.5)->file(DemoFileType::Spreadsheet)->claim('a2', 2 * self::HOUR)->askForDetails(30)->customer(10 * self::HOUR)->agent(self::HOUR)->askForDetails(40),
            DemoStory::of('outage-question', 'c12')->daysAgo(0.9)->auto()->askForDetails(20),
            DemoStory::of('vat-invoice', 'c3')->daysAgo(8)->assign('a6', 5 * self::HOUR)->askForDetails(30),
            DemoStory::of('sso-setup', 'c10')->daysAgo(1.6)->claim('a5', 15)->agent(30)->customer(self::HOUR, DemoFileType::Log)->askForDetails(25),
        ];
    }

    /** @return list<DemoStory> */
    private static function resolved(): array
    {
        return [
            DemoStory::of('double-charge', 'c1')->daysAgo(12)->file(DemoFileType::Document)->auto()->agent(40)->customer(3 * self::HOUR, DemoFileType::Screenshot)->note(20)->agent(self::HOUR)->customer(4 * self::HOUR)->resolve(30),
            DemoStory::of('webhook-401', 'c2')->daysAgo(9)->file(DemoFileType::Log)->claim('a1', 15)->agent(10)->customer(45)->resolve(20),
            DemoStory::of('email-notifications', 'c6')->daysAgo(3.5)->auto()->askForDetails(30)->customer(5 * self::HOUR)->agent(40)->resolve(self::HOUR),
            DemoStory::of('calendar-sync', 'c9')->daysAgo(6)->assign('a2', 30)->talk(2, self::HOUR)->resolve(30),
            DemoStory::of('bulk-edit', 'c4')->daysAgo(15)->auto()->agent(200)->customer(10 * self::HOUR)->note(30)->resolve(5 * self::DAY),
            DemoStory::of('nonprofit-discount', 'c11')->daysAgo(10)->auto()->askForDetails(self::HOUR)->customer(400, DemoFileType::Document)->resolve(90),
            DemoStory::of('password-reset', 'c12')->daysAgo(2.1)->auto()->agent(20)->customer(25)->resolve(10),
            DemoStory::of('dashboard-blank', 'c10')->daysAgo(13)->auto(1)->agent(5 * self::HOUR)->customer(20, DemoFileType::Log)->note(10)->agent(3 * self::HOUR)->resolve(20 * self::HOUR),
            DemoStory::of('lost-2fa', 'c7')->daysAgo(18)->assign('a5', 20)->askForDetails(10)->customer(3 * self::HOUR, DemoFileType::Document)->note(30)->agent(self::HOUR)->resolve(5),
            DemoStory::of('seat-proration', 'c3')->daysAgo(4.8)->auto()->agent(90, DemoFileType::Spreadsheet)->customer(2 * self::HOUR)->resolve(30),
            DemoStory::of('data-retention', 'c8')->daysAgo(1.2)->auto()->agent(30)->customer(20)->resolve(10),
            DemoStory::of('dark-mode', 'c6')->daysAgo(9.1)->auto()->agent(5 * self::HOUR)->customer(self::HOUR)->resolve(4 * self::DAY),
            DemoStory::of('account-deletion', 'c2')->daysAgo(3)->auto()->askForDetails(self::HOUR)->customer(200)->resolve(30),
        ];
    }

    /** @return list<DemoStory> */
    private static function closed(): array
    {
        return [
            DemoStory::of('failed-payment', 'c2')->daysAgo(27)->auto()->agent(25)->customer(30)->agent(20)->thank(self::HOUR)->resolve(15)->close(2 * self::HOUR),
            DemoStory::of('annual-switch', 'c1')->daysAgo(25)->auto()->agent(2 * self::HOUR)->customer(5 * self::HOUR)->agent(self::HOUR, DemoFileType::Document)->resolve(self::DAY)->close(3 * self::DAY, null),
            DemoStory::of('receipt-email', 'c8')->daysAgo(20)->auto()->askForDetails(self::HOUR)->customer(2 * self::HOUR)->thank(40)->resolve(30)->close(self::HOUR),
            DemoStory::of('mobile-crash', 'c11')->daysAgo(24)->claim('a2', 30)->agent(20)->customer(self::HOUR, DemoFileType::Log)->note(15, DemoFileType::Log)->agent(self::DAY)->customer(5 * self::HOUR)->resolve(30)->close(3 * self::DAY, null),
            DemoStory::of('api-rate-limit', 'c4')->daysAgo(19)->auto()->talk(4, 90)->resolve(40)->close(2 * self::DAY, null),
            DemoStory::of('account-deletion', 'c9')->daysAgo(29)->claim('a5', self::HOUR)->askForDetails(30)->customer(90)->resolve(20)->close(7 * self::DAY, null),
            DemoStory::of('onboarding-call', 'c10')->daysAgo(17)->auto()->agent(self::HOUR)->customer(2 * self::HOUR)->agent(30)->thank(2 * self::HOUR)->resolve(10)->close(5),
            DemoStory::of('dark-mode', 'c2')->daysAgo(28)->auto()->agent(3 * self::HOUR)->note(10)->resolve(10 * self::DAY)->close(2 * self::DAY, null),
            DemoStory::of('refund-downgrade', 'c12')->daysAgo(16)->auto()->agent(40)->customer(self::HOUR)->note(20)->agent(3 * self::HOUR)->resolve(20)->close(3 * self::DAY, null),
            DemoStory::of('data-retention', 'c5')->daysAgo(21)->auto()->agent(25)->customer(15)->resolve(10)->close(2 * self::DAY),
            DemoStory::of('vat-invoice', 'c7')->daysAgo(26)->auto()->agent(self::HOUR)->customer(2 * self::HOUR)->agent(self::DAY, DemoFileType::Document)->resolve(10)->close(5 * self::DAY, null),
            DemoStory::of('transfer-ownership', 'c1')->daysAgo(23)->claim('a5', 2 * self::HOUR)->askForDetails(20)->customer(self::DAY, DemoFileType::Document)->agent(30)->thank(30)->resolve(5)->close(3),
            DemoStory::of('api-rate-limit', 'c7')->daysAgo(7.2)->claim('a1', 15)->agent(20, DemoFileType::Log)->customer(30)->resolve(20)->close(3 * self::DAY, null),
        ];
    }

    /** @return list<DemoStory> */
    private static function reopened(): array
    {
        return [
            DemoStory::of('email-notifications', 'c1')->daysAgo(9.5)->auto()->agent(40)->resolve(30)->reopen(2 * self::DAY)->agent(30)->note(10)->agent(3 * self::HOUR),
            DemoStory::of('calendar-sync', 'c5')->daysAgo(14)->auto()->agent(self::HOUR)->customer(30)->resolve(20)->reopen(3 * self::DAY, DemoFileType::Screenshot)->agent(45)->thank(4 * self::HOUR)->resolve(30)->close(5),
            DemoStory::of('password-reset', 'c9')->daysAgo(5.5)->claim('a5', 20)->agent(15)->resolve(10)->reopen(20 * self::HOUR)->agent(30)->askForDetails(20),
            DemoStory::of('export-timeout', 'c12')->daysAgo(11)->auto()->talk(2, self::HOUR)->resolve(30)->reopen(36 * self::HOUR)->agent(30)->resolve(2 * self::HOUR),
        ];
    }

    /** @return list<DemoStory> */
    private static function handoffs(): array
    {
        return [
            DemoStory::of('mobile-crash', 'c8')->daysAgo(3.1)->assign('a2', 20)->agent(30)->note(self::HOUR, DemoFileType::Log)->handoff('a5', 2)->agent(30)->customer(90),
            DemoStory::of('double-charge', 'c10')->daysAgo(10.5)->assign('a4', 30)->agent(40)->customer(self::HOUR)->note(30)->handoff('a6', 10)->agent(self::HOUR)->resolve(30)->close(2 * self::DAY, null),
            DemoStory::of('slow-search', 'c11')->daysAgo(6.8)->assign('a1', 10)->agent(40)->note(20)->handoff('a2', 5)->agent(self::HOUR)->customer(2 * self::HOUR)->resolve(40),
        ];
    }

    /** @return list<DemoStory> */
    private static function categoryMoves(): array
    {
        return [
            DemoStory::of('seat-proration', 'c2')->filedUnder('general-inquiry')->daysAgo(5.9)->move('billing', 40)->auto(1)->agent(30, DemoFileType::Spreadsheet)->customer(self::HOUR)->resolve(20),
            DemoStory::of('bulk-edit', 'c7')->filedUnder('technical-support')->daysAgo(2.4)->move('feature-requests', self::HOUR)->assign('a6', 5)->agent(30),
            DemoStory::of('lost-2fa', 'c6')->filedUnder('technical-support')->daysAgo(0.6)->move('account-access', 10)->assign('a5', 3)->askForDetails(5),
        ];
    }

    /** @return list<DemoStory> */
    private static function priorityChanges(): array
    {
        return [
            DemoStory::of('webhook-401', 'c8')->priority(TicketPriority::Medium)->daysAgo(1.0)->auto()->agent(20)->customer(30)->bump(TicketPriority::High, 5),
            DemoStory::of('outage-question', 'c3')->priority(TicketPriority::Low)->daysAgo(3.9)->auto()->bump(TicketPriority::High, 30, 'admin')->agent(20)->note(10)->askForDetails(30),
            DemoStory::of('annual-switch', 'c9')->priority(TicketPriority::Medium)->daysAgo(8.4)->auto()->bump(TicketPriority::Low, 20)->agent(self::HOUR)->customer(2 * self::HOUR)->resolve(30),
            DemoStory::of('failed-payment', 'c4')->priority(TicketPriority::Medium)->daysAgo(0.4)->auto()->customer(20, DemoFileType::Screenshot)->bump(TicketPriority::Critical, 15, 'admin')->agent(10),
            DemoStory::of('slow-search', 'c9')->daysAgo(4.5)->claim('a1', 30)->talk(4, 2 * self::HOUR)->bump(TicketPriority::High, 10),
        ];
    }

    /** @return list<DemoStory> */
    private static function internalOnly(): array
    {
        return [
            DemoStory::of('security-questionnaire', 'c11')->daysAgo(1.9)->file(DemoFileType::Document)->auto()->note(self::HOUR, DemoFileType::Document)->note(2 * self::HOUR, null, 'admin'),
            DemoStory::of('custom-fields-export', 'c8')->daysAgo(4.1)->claim('a6', 90)->note(30, DemoFileType::Spreadsheet)->note(self::DAY),
            DemoStory::of('notification-digest', 'c12')->elapsed(0.45)->auto()->note(20),
        ];
    }

    /** @return list<DemoStory> */
    private static function onBehalf(): array
    {
        return [
            DemoStory::of('outage-question', 'c5')->onBehalfBy('a3')->daysAgo(7.5)->claim('a3', 2)->note(5)->agent(self::HOUR, DemoFileType::Document)->resolve(30),
            DemoStory::of('refund-downgrade', 'c11')->onBehalfBy('admin')->daysAgo(6.3)->assign('a1', 5)->note(5)->agent(30)->customer(self::HOUR)->resolve(20),
            DemoStory::of('notification-digest', 'c3')->onBehalfBy('a6')->daysAgo(11.2)->claim('a6', 1)->agent(5)->resolve(self::DAY)->close(3 * self::DAY, null),
        ];
    }

    /** @return list<DemoStory> */
    private static function longConversations(): array
    {
        return [
            DemoStory::of('export-timeout', 'c6')->daysAgo(22)->auto()->agent(self::HOUR)->customer(2 * self::HOUR, DemoFileType::Spreadsheet)->note(30)->agent(90)->customer(200)->note(self::DAY)->agent(self::HOUR)->resolve(2 * self::DAY)->close(4 * self::DAY, null),
            DemoStory::of('import-encoding', 'c10')->daysAgo(13.5)->file(DemoFileType::Spreadsheet)->auto()->talk(6, 50)->resolve(10)->close(2 * self::DAY, null),
            DemoStory::of('bulk-edit', 'c9')->daysAgo(19.5)->auto()->talk(8, 150)->resolve(self::HOUR)->close(3 * self::DAY, null),
            DemoStory::of('slow-search', 'c5')->daysAgo(16.5)->claim('a2', 40)->talk(5, 3 * self::HOUR)->status(TicketStatus::WaitingForCustomer, 5)->customer(self::DAY)->resolve(2 * self::HOUR),
        ];
    }
}
