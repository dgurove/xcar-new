<?php

namespace App\Park\Actions;

use App\Mail\Actions\StoreOutboxFile;
use App\Park\Pass;
use App\Park\PassQr;
use App\Support\Surface;

/** Пропуск покупателю на почту: брендовое письмо, QR и знак XCar — картинками в теле по Content-ID. */
final class MailPass
{
    public function __construct(private ParkLetter $letters, private StoreOutboxFile $outbox) {}

    public function __invoke(Pass $pass): bool
    {
        $pass->loadMissing(['vehicle.yard.settlement', 'vehicle.brand', 'vehicle.model']);
        $qr = $this->outbox->put('qr-'.$pass->code.'.png', PassQr::png($pass));
        $logo = $this->outbox->put('xcar.png', (string) file_get_contents(public_path('images/xcar-mail.png')));
        $cid = ['qr' => 'qr-'.strtolower($pass->code).'@xcar.ru', 'logo' => 'logo@xcar.ru'];
        $html = view('emails.pass', ['pass' => $pass, 'qrCid' => $cid['qr'], 'logoCid' => $cid['logo'],
            'pageUrl' => Surface::Site->url('/pickup/'.$pass->vehicle->pickup_code)])->render();
        $message = $this->letters->toBuyer($pass->vehicle, $pass->email, 'Пропуск на получение ТС: '.$pass->vehicle->titleWithYear(), $html, [$qr => $cid['qr'], $logo => $cid['logo']]);
        if ($message) {
            $pass->update(['mailed_at' => now()]);
        }

        return (bool) $message;
    }
}
