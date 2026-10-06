<?php

namespace App\Models;

use App\Support\OrganizationName;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['department_id', 'name'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Branch $branch): void {
            $branch->name = OrganizationName::display($branch->name);
            $branch->name_key = OrganizationName::key($branch->name);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['deactivated_at' => 'datetime'];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
