<?php

namespace App\Models;

use App\Support\OrganizationName;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Department $department): void {
            $department->name = OrganizationName::display($department->name);
            $department->name_key = OrganizationName::key($department->name);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['deactivated_at' => 'datetime'];
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** @return HasMany<DepartmentRolePermission, $this> */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(DepartmentRolePermission::class);
    }
}
