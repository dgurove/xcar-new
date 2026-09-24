<?php

namespace App\Http\Site;

use App\Park\Actions\MailPass;
use App\Park\Actions\SubmitBuyerForm;
use App\Park\Pass;
use App\Park\PassQr;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Surface;
use App\Users\Section;
use Illuminate\Http\Request;

/**
 * Выдача по QR, сторона покупателя (без входа). `/pickup/{код ТС}` — анкета, после отправки — пропуск с QR;
 * `/p/{код пропуска}` — то, что открывается камерой телефона по QR: пояснение для человека, а вошедшему сотруднику
 * парковки — сразу дело ТС с кодом в форме выдачи.
 */
class PickupController
{
    public function show(string $code)
    {
        $vehicle = $this->vehicle($code);
        $pass = $vehicle->pass();
        $editing = request()->query('edit');

        if ($pass && ! $editing) {
            return view('site.pickup.pass', ['vehicle' => $vehicle, 'pass' => $pass, 'qr' => PassQr::svg($pass)]);
        }

        return view('site.pickup.form', ['vehicle' => $vehicle, 'pass' => $pass, 'dateOnly' => $pass?->isConfirmed(), 'closed' => $vehicle->state !== VehicleState::Stored]);
    }

    public function store(Request $request, string $code, SubmitBuyerForm $submit)
    {
        $vehicle = $this->vehicle($code);
        // Приманка для роботов: людям поле не видно.
        if (filled($request->input('website'))) {
            return redirect('/pickup/'.$code);
        }
        abort_unless($vehicle->state === VehicleState::Stored, 410);
        $dateOnly = (bool) $vehicle->pass()?->isConfirmed();
        $data = $request->validate(($dateOnly ? [] : [
            'name' => ['required', 'string', 'min:5', 'max:160', 'regex:/\S+\s+\S+/u'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[\d\s()+\-]{10,20}$/'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'consent' => ['accepted'],
        ]) + [
            'pickup_on' => ['required', 'date', 'after_or_equal:today', 'before:+6 months'],
        ], [
            'name.required' => 'Укажите фамилию, имя и отчество', 'name.min' => 'Укажите фамилию, имя и отчество',
            'name.regex' => 'Укажите фамилию, имя и отчество',
            'phone.required' => 'Укажите телефон', 'email.required' => 'Укажите почту: на неё придёт QR-код',
            'email.email' => 'Проверьте почту', 'pickup_on.required' => 'Выберите, когда заберёте ТС', 'pickup_on.before' => 'Выберите дату в ближайшие полгода',
            'phone.regex' => 'Проверьте номер телефона',
            'pickup_on.after_or_equal' => 'Выберите сегодня или позже',
            'consent.accepted' => 'Нужно согласие на обработку данных',
        ]);
        $submit($vehicle, $data);

        return redirect('/pickup/'.$code)->with('toast', 'Готово');
    }

    public function resend(string $code, MailPass $mail)
    {
        $pass = $this->vehicle($code)->pass();
        abort_unless($pass?->isLive(), 404);
        $mail($pass);

        return redirect('/pickup/'.$code)->with('toast', 'Отправили на '.$pass->email);
    }

    /** QR отсканировали обычной камерой телефона. */
    public function scanned(Request $request, string $code)
    {
        $pass = Pass::byCode($code)?->load(['vehicle.yard.settlement', 'vehicle.brand', 'vehicle.model']);
        $user = $request->user();
        if ($pass && $user?->canAccess(Section::Park)) {
            return redirect()->away(Surface::Park->url('/cars/'.$pass->vehicle_id.'?pass='.$pass->code));
        }

        return view('site.pickup.scanned', ['pass' => $pass]);
    }

    private function vehicle(string $code): Vehicle
    {
        $vehicle = Vehicle::where('pickup_code', strtoupper($code))->with(['yard.settlement', 'brand', 'model', 'vendor'])->first();
        abort_unless($vehicle, 404);

        return $vehicle;
    }
}
