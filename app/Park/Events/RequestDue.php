<?php

namespace App\Park\Events;

use App\Park\Request;
use Illuminate\Foundation\Events\Dispatchable;

/** Срок заявки подходит (через два часа) или вышел. */
final class RequestDue
{
    use Dispatchable;

    public function __construct(public Request $request, public bool $overdue) {}
}
