<?php

namespace App\Garage\Actions;

use App\Garage\Cost;

final class RemoveCost
{
    public function __invoke(Cost $cost): void
    {
        $cost->delete();
    }
}
