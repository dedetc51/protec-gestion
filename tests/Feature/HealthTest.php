<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_readiness_is_successful_without_sensitive_details(): void
    {
        $this->get('/up')->assertOk()->assertSee('OK')->assertDontSee('DB_');
    }
}
