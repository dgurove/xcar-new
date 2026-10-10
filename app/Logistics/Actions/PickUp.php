<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportMoved;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Media\PhotoIngest;
use App\Users\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * «Забрал»: исполнитель (или логист и админ за него — у внешнего перевозчика) — машина в пути, кадры погрузки. Забрать
 * рано (Альфа до оплаты, документы не готовы) — нельзя, кроме админа (`Transport::pickupBlocker`).
 */
final class PickUp
{
    public function __construct(private PhotoIngest $photos) {}

    /** @param  list<UploadedFile>  $files */
    public function __invoke(Transport $t, User $by, array $files = []): Transport
    {
        if (! $t->isExecutedBy($by) && ! $by->seesAllTransports()) {
            throw ValidationException::withMessages(['transport' => 'Перевозка поручена не вам']);
        }
        if ($t->state !== TransportState::Assigned) {
            throw ValidationException::withMessages(['transport' => 'Перевозка '.mb_strtolower($t->state->label())]);
        }
        if ($reason = $t->pickupBlocker($by)) {
            throw ValidationException::withMessages(['transport' => $reason]);
        }
        foreach ($files as $file) {
            $this->photos->fromUpload($t, 'photos', $file, ['at' => 'pickup']);
        }
        $t->update(['state' => TransportState::Moving, 'picked_at' => now()]);
        TransportMoved::dispatch($t, $by);

        return $t;
    }
}
