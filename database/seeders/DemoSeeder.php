<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Admin\CreateCategory;
use App\Actions\Admin\CreateHoliday;
use App\Actions\Admin\CreateUser;
use App\Actions\Admin\SyncAgentProfile;
use App\Actions\Assignment\AssignTicket;
use App\Actions\Imports\StartTicketImport;
use App\Actions\Notifications\MarkAllNotificationsRead;
use App\Actions\SavedReplies\CreateSavedReply;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\AgentSelector;
use App\Services\Sla\BusinessCalendar;
use App\Services\Sla\SlaSettingsRepository;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoAvatars;
use Database\Seeders\Demo\DemoCast;
use Database\Seeders\Demo\DemoContent;
use Database\Seeders\Demo\DemoDirector;
use Database\Seeders\Demo\DemoEnvironment;
use Database\Seeders\Demo\DemoPeople;
use Database\Seeders\Demo\DemoPlanner;
use Database\Seeders\Demo\DemoStories;
use Database\Seeders\Demo\DemoTimeline;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

final class DemoSeeder extends Seeder
{
    public const int FAKER_SEED = 20261101;

    private const int SETUP_DAYS_AGO = 40;

    private const int SAVED_REPLIES_DAYS_AGO = 29;

    private const int SCAN_EVERY_MINUTES = 120;

    private const int LEAVE_DAYS_AGO = 6;

    private const int IMPORT_HOURS_AGO = 70;

    private const string PASSWORD = 'password';

    private const array FILE_WIPE_ENVIRONMENTS = ['local', 'testing'];

    public function run(DemoEnvironment $environment): void
    {
        if (User::query()->where('email', 'admin@example.com')->exists()) {
            return;
        }

        if (app()->environment(self::FILE_WIPE_ENVIRONMENTS) && Ticket::query()->doesntExist()) {
            $this->removeOrphanedFiles();
        }

        $environment->isolate(function (): void {
            $this->seedDemo(CarbonImmutable::now()->startOfSecond());
        });
    }

    private function seedDemo(CarbonImmutable $now): void
    {
        $faker = FakerFactory::create();
        $faker->seed(self::FAKER_SEED);
        mt_srand(self::FAKER_SEED);

        $cast = new DemoCast;

        DemoTimeline::travelTo($now->subDays(self::SETUP_DAYS_AGO));
        $this->categories($cast);
        $this->holidays($now);
        $this->people($cast);

        DemoTimeline::travelTo($now->subDays(self::SAVED_REPLIES_DAYS_AGO));
        $this->savedReplies($cast);

        $settings = app(SlaSettingsRepository::class)->current();
        $planner = new DemoPlanner($now, app(BusinessCalendar::class), $settings->resolutionMinutes, $faker, $cast);
        $director = app()->make(DemoDirector::class, ['cast' => $cast]);
        $timeline = new DemoTimeline;

        foreach (DemoStories::all() as $story) {
            $planner->prepare($story);
            $moments = $planner->moments($story);

            $timeline->at($moments[0], static fn () => $director->create($story));

            foreach ($story->steps as $position => $step) {
                $timeline->at($moments[$position + 1], static fn () => $director->perform($story, $step));
            }
        }

        $timeline->at($now->subDays(self::LEAVE_DAYS_AGO)->addMinutes(75), fn () => $this->sendOnLeave($cast, 'a4'));
        $timeline->at($now->subHours(self::IMPORT_HOURS_AGO), fn () => $this->import($cast, $now));
        $timeline->at($now->subHours(50), fn () => $this->readNotifications($cast, 'a2'));
        $timeline->at($now->subHours(26), fn () => $this->readNotifications($cast, 'c1'));
        $timeline->at($now->subHours(20), fn () => $this->readNotifications($cast, 'a1'));
        $timeline->at($now->subHours(4), fn () => $this->readNotifications($cast, 'admin'));

        $earliest = $timeline->earliest() ?? $now;

        for ($scanAt = $earliest->addMinutes(self::SCAN_EVERY_MINUTES); $scanAt->lessThan($now); $scanAt = $scanAt->addMinutes(self::SCAN_EVERY_MINUTES)) {
            $timeline->at($scanAt, self::scan(...));
        }

        $timeline->at($now, self::scan(...));
        $timeline->run();

        $this->presence($cast, $now);
    }

    private function removeOrphanedFiles(): void
    {
        $directories = [
            [config('avatars.disk'), config('avatars.directory')],
            [config('attachments.disk'), config('attachments.directory')],
            [config('imports.disk'), StartTicketImport::DIRECTORY],
        ];

        foreach ($directories as [$disk, $directory]) {
            Storage::disk((string) $disk)->deleteDirectory((string) $directory);
        }
    }

    private static function scan(): void
    {
        Artisan::call('sla:scan');
    }

    private function categories(DemoCast $cast): void
    {
        $create = app(CreateCategory::class);

        foreach (DemoPeople::CATEGORIES as $slug => [$name, $active]) {
            $cast->addCategory($create->handle(['name' => $name, 'slug' => $slug, 'is_active' => $active]));
        }
    }

    private function holidays(CarbonImmutable $now): void
    {
        $settings = app(SlaSettingsRepository::class);
        $create = app(CreateHoliday::class);

        foreach (DemoContent::holidays($now->setTimezone($settings->timezone())->startOfDay()) as $holiday) {
            $create->handle($holiday);
        }

        app()->forgetScopedInstances();
    }

    private function people(DemoCast $cast): void
    {
        $password = Hash::make(self::PASSWORD);
        $avatars = app(DemoAvatars::class);
        $createUser = app(CreateUser::class);
        $admin = null;

        foreach (DemoPeople::all() as $key => $person) {
            if ($person['role'] === Role::Admin) {
                $user = User::query()->create(['name' => $person['name'], 'email' => $person['email'], 'password' => $password]);
                $user->assignRole(Role::Admin);
                $admin = $user;
            } else {
                $user = $createUser->handle($admin ?? $cast->admin(), [
                    'name' => $person['name'],
                    'email' => $person['email'],
                    'password' => $password,
                    'role' => $person['role']->value,
                    'category_ids' => array_map(static fn (string $slug): int => $cast->category($slug)->id, $person['categories']),
                    'is_available' => true,
                ]);
            }

            $cast->addUser($key, $user, $person['company']);

            if ($person['photo'] !== null) {
                $avatars->attach($user, $person['photo']);
            }
        }
    }

    private function savedReplies(DemoCast $cast): void
    {
        $create = app(CreateSavedReply::class);

        foreach (DemoContent::savedReplies() as $reply) {
            $create->handle($reply['owner'] === null ? $cast->admin() : $cast->user($reply['owner']), [
                'title' => $reply['title'],
                'body' => $reply['body'],
                'category_id' => $reply['category'] === null ? null : $cast->category($reply['category'])->id,
                'shared' => $reply['owner'] === null,
            ]);
        }
    }

    private function sendOnLeave(DemoCast $cast, string $key): void
    {
        $agent = $cast->user($key);
        $categoryIds = $agent->categories()->pluck('categories.id')->map(static fn (mixed $id): int => (int) $id)->values()->all();

        app(SyncAgentProfile::class)->handle($cast->admin(), $agent, $categoryIds, false);

        $selector = app(AgentSelector::class);
        $assign = app(AssignTicket::class);

        Ticket::query()
            ->where('assigned_agent_id', $agent->id)
            ->whereIn('status', array_map(static fn (TicketStatus $status): string => $status->value, TicketStatus::active()))
            ->lazyById()
            ->each(static function (Ticket $ticket) use ($selector, $assign, $agent): void {
                $replacement = $selector->selectFor($ticket);

                if ($replacement !== null) {
                    $assign->handle($agent, $ticket, $replacement);
                }
            });
    }

    private function import(DemoCast $cast, CarbonImmutable $now): void
    {
        $timezone = app(SlaSettingsRepository::class)->timezone();
        $lines = ['external_reference,subject,description,priority,category,customer_email,status,created_at'];

        foreach (DemoContent::importRows() as [$reference, $subject, $description, $priority, $category, $email, $status, $daysAgo]) {
            $createdAt = $now->setTimezone($timezone)->subDays($daysAgo)->setTime(10, 30)->format('Y-m-d H:i');
            $lines[] = implode(',', array_map(
                static fn (string $value): string => '"'.str_replace('"', '""', $value).'"',
                [$reference, $subject, $description, $priority, $category, $email, $status, $createdAt],
            ));
        }

        app(StartTicketImport::class)->handle(
            $cast->admin(),
            UploadedFile::fake()->createWithContent('legacy-helpdesk-export.csv', implode("\n", $lines)."\n"),
        );
    }

    private function readNotifications(DemoCast $cast, string $key): void
    {
        app(MarkAllNotificationsRead::class)->handle($cast->user($key));
    }

    private function presence(DemoCast $cast, CarbonImmutable $now): void
    {
        foreach (DemoPeople::all() as $key => $person) {
            $minutes = $person['last_seen_minutes'];
            $cast->user($key)->forceFill(['last_seen_at' => $minutes === null ? null : $now->subMinutes($minutes)])->saveQuietly();
        }
    }
}
