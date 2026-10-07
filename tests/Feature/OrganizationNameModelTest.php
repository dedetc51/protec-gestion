<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationNameModelTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('organizationModels')]
    public function test_current_case_only_renames_keep_keys_and_reject_equivalent_model_inserts(string $modelClass): void
    {
        $organization = $modelClass::factory()->create(['name' => 'Alpha']);

        $this->assertTrue($organization->update(['name' => 'ALPHA']));

        $this->assertDatabaseHas($organization->getTable(), ['id' => $organization->id, 'name' => 'ALPHA', 'name_key' => 'alpha']);
        $this->expectException(UniqueConstraintViolationException::class);
        $modelClass::create(['name' => 'alpha', ...$organization->only('department_id')]);
    }

    public static function organizationModels(): array
    {
        return ['department' => [Department::class], 'branch' => [Branch::class]];
    }

    #[DataProvider('organizationModels')]
    public function test_key_restoration_conflicts_roll_back_the_whole_rename(string $modelClass): void
    {
        $organization = $modelClass::factory()->create(['name' => 'Alpha']);
        Event::listen('eloquent.updated: '.$modelClass, function ($updated) use ($modelClass): void {
            $modelClass::create(['name' => 'alpha', ...$updated->only('department_id')]);
        });

        try {
            $organization->update(['name' => 'ALPHA']);
            $this->fail('A key conflict must roll back both the rename and nested model write.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseHas($organization->getTable(), ['id' => $organization->id, 'name' => 'Alpha', 'name_key' => 'alpha']);
            $this->assertDatabaseMissing($organization->getTable(), ['name' => 'alpha']);
        }
    }
}
