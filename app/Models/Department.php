<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** @return BelongsToMany<Permission, $this> */
    public function rolePermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'department_role_permissions')->withPivot(['role_id', 'state']);
    }
}
