<?php

namespace App\Models;

use App\Support\OrganizationName;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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

    /** @param Builder<static> $query */
    protected function performUpdate(Builder $query): bool
    {
        if (! $this->isDirty('name') || $this->isDirty('name_key')) {
            return parent::performUpdate($query);
        }

        return $this->getConnection()->transaction(function () use ($query): bool {
            $saved = parent::performUpdate($query);
            if ($saved) {
                // Legacy guards invalidate unchanged keys; restore before this update commits.
                $this->name_key = OrganizationName::key($this->name);
                $this->setKeysForSaveQuery($this->newModelQuery())->toBase()->update(['name_key' => $this->name_key]);
            }

            return $saved;
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
