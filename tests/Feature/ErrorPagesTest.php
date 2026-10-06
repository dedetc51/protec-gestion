<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_production_error_views_are_generic_and_in_french(): void
    {
        foreach ([403, 404, 419, 500, 503] as $status) {
            $html = view("errors.{$status}")->render();

            $this->assertStringContainsString('Une erreur est survenue', $html);
            $this->assertStringContainsString('Veuillez réessayer', $html);
            $this->assertStringNotContainsString('Exception', $html);
            $this->assertStringNotContainsString('Stack trace', $html);
        }
    }
}
