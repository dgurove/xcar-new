<?php

namespace App\Billing;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** «В течение трёх рабочих дней» — без выходных; праздники не учитываются. */
final class WorkDays
{
    public static function add(CarbonInterface $from, int $days): Carbon
    {
        $at = Carbon::instance($from);
        while ($days > 0) {
            $at->addDay();
            if ($at->isWeekday()) {
                $days--;
            }
        }

        return $at;
    }
}
