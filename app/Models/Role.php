<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'allows_global', 'allows_department', 'allows_branch'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allows_global' => 'boolean',
            'allows_department' => 'boolean',
            'allows_branch' => 'boolean',
        ];
    }

    public function allowsScope(string $scope): bool
    {
        return match ($scope) {
            'global' => $this->allows_global,
            'department' => $this->allows_department,
            'branch' => $this->allows_branch,
            default => false,
        };
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withPivot('granted');
    }

    /** @return HasMany<RoleAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }
}
