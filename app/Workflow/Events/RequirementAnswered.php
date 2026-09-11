<?php

namespace App\Workflow\Events;

use App\Users\User;
use App\Workflow\Requirement;
use Illuminate\Foundation\Events\Dispatchable;

final class RequirementAnswered
{
    use Dispatchable;

    public function __construct(public Requirement $requirement, public User $by) {}
}
