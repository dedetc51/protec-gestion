<?php

namespace App\Authorization;

use App\Models\Branch;
use App\Models\Department;

final readonly class AuthorizationContext
{
    private function __construct(
        public string $scopeType,
        public ?int $scopeId,
        public ?int $departmentId,
        public bool $isPersisted,
    ) {}

    public static function global(): self
    {
        return new self('global', null, null, true);
    }

    public static function department(Department $department): self
    {
        return new self('department', $department->getKey(), $department->getKey(), $department->exists);
    }

    public static function branch(Branch $branch): self
    {
        return new self('branch', $branch->getKey(), $branch->department_id, $branch->exists);
    }
}
