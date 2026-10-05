<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_readiness_is_successful_without_sensitive_details(): void
    {
        $this->get('/up')->assertOk()->assertSee('OK')->assertDontSee('DB_');
    }

    public function test_database_failure_returns_503_without_starting_a_session(): void
    {
        config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => '/missing/path/database.sqlite']]);
        DB::setDefaultConnection('broken');
        $this->get('/up')->assertStatus(503)->assertSee('Unavailable')->assertHeaderMissing('Set-Cookie');
    }
}
