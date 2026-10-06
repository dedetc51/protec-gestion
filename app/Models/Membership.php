<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'branch_id', 'starts_at', 'ends_at'])]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** @param Builder<Membership> $query
     * @return Builder<Membership>
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

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
