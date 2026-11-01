<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    private const array DEMO_ENVIRONMENTS = ['local', 'testing'];

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SlaDefaultsSeeder::class,
        ]);

        if (app()->environment(self::DEMO_ENVIRONMENTS)) {
            $this->call(DemoSeeder::class);
        }
    }
}
