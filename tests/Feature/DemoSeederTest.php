<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_is_clearly_fictitious(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, Promotion::count());
        $this->assertSame(0, Promotion::where('is_demo', false)->count());
        $this->assertSame(0, Promotion::where('title', 'not like', '[FICTÍCIA]%')->count());
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->app->make(DatabaseSeeder::class)->run();
    }
}
