<?php

namespace App\Cars;

use App\Users\User;
use Illuminate\Support\Carbon;

/** Строка «Истории» — правка машины с другой дорожки (`LaneHistory`): когда, кто, где правили («в CRM»), что. */
final class LaneEdit
{
    public function __construct(public Carbon $created_at, public ?User $user, public string $where, private string $text) {}

    public function text(): string
    {
        return $this->text;
    }
}
