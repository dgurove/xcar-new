<?php

namespace App\Park\Events;

use App\Park\Request;
use Illuminate\Foundation\Events\Dispatchable;

/** Пора перезвонить страхователю (`next_call_at` наступил). */
final class RequestCall
{
    use Dispatchable;

    public function __construct(public Request $request) {}
}
