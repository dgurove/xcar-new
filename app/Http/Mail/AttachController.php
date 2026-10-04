<?php

namespace App\Http\Mail;

use App\Mail\Scope;
use App\Offers\Offer;
use App\Park\Vehicle;
use Illuminate\Http\Request;

/**
 * Кадры письма прикрепляются (`ImportThreadFiles`): строка хода, ряд фото с заглушками и документы — потоком для
 * attach_controller, который переспрашивает, пока строка хода есть.
 */
final class AttachController
{
    public function offer(Offer $offer)
    {
        return $this->stream($offer->load('media'));
    }

    public function vehicle(Request $request, Vehicle $vehicle)
    {
        abort_unless(Scope::allows($request->user(), $vehicle), 404);

        return $this->stream($vehicle->load('media'));
    }

    private function stream(Offer|Vehicle $model)
    {
        return response()->view('admin.mail.attach-stream', ['model' => $model])->header('Content-Type', 'text/vnd.turbo-stream.html');
    }
}
