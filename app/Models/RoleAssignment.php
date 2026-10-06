<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\RoleAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable(['user_id', 'role_id', 'scope_type', 'scope_id', 'starts_at', 'ends_at'])]
class RoleAssignment extends Model
{
    /** @use HasFactory<RoleAssignmentFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (RoleAssignment $assignment): void {
            $assignment->validateScope();
        });
    }

    /** @param Builder<RoleAssignment> $query
     * @return Builder<RoleAssignment>
     */
    public function scopeActive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $at);
            });
    }

    public function isActiveAt(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return ($this->starts_at === null || $this->starts_at->lte($at))
            && ($this->ends_at === null || $this->ends_at->gt($at));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    private function validateScope(): void
    {
        $validTarget = match ($this->scope_type) {
            'global' => $this->scope_id === null,
            'department' => $this->scope_id !== null && Department::query()->whereKey($this->scope_id)->exists(),
            'branch' => $this->scope_id !== null && Branch::query()->whereKey($this->scope_id)->exists(),
            default => false,
        };

        $role = Role::query()->find($this->role_id);

        if (! $validTarget || $role === null || ! $role->allowsScope((string) $this->scope_type)) {
            throw new InvalidArgumentException('The assignment scope is invalid or not supported by its role.');
        }
    }
}
