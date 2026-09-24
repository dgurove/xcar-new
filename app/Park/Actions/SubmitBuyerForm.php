<?php

namespace App\Park\Actions;

use App\Park\Events\BuyerFormSubmitted;
use App\Park\EventType;
use App\Park\Pass;
use App\Park\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Анкета покупателя по ссылке. Первая отправка заводит пропуск: QR сразу на экране и на почте, страховой — запрос
 * «подтвердите, что это покупатель» (без телефона). Правка до подтверждения меняет данные, и запрос уходит заново;
 * после подтверждения меняется только дата — подтверждали человека, а не день.
 */
final class SubmitBuyerForm
{
    public function __construct(private ParkLetter $letters, private MailPass $mail) {}

    /** @param array{name?: string, phone?: string, email?: string, pickup_on: string} $data */
    public function __invoke(Vehicle $vehicle, array $data): Pass
    {
        $pass = $vehicle->pass();
        if ($pass?->used_at) {
            throw ValidationException::withMessages(['name' => 'ТС уже выдано']);
        }
        $locked = $pass?->isConfirmed();
        $fields = $locked ? ['pickup_on' => $data['pickup_on']] : [
            'name' => $data['name'], 'phone' => $data['phone'], 'email' => mb_strtolower($data['email']), 'pickup_on' => $data['pickup_on'],
        ];
        $identity = fn (?Pass $p) => $p ? [$p->name, $p->email, $p->pickup_on?->toDateString()] : null;
        $before = $identity($pass);
        $emailBefore = $pass?->email;

        $pass = DB::transaction(function () use ($vehicle, $pass, $fields) {
            if ($pass) {
                $pass->update($fields);

                return $pass;
            }
            do {
                $code = Pass::freshCode();
            } while (Pass::where('code', $code)->exists());

            return Pass::create($fields + ['vehicle_id' => $vehicle->id, 'code' => $code, 'submitted_at' => now()]);
        });
        $after = $identity($pass->fresh());
        if ($before === $after) {
            return $pass;
        }

        $vehicle->log(EventType::BuyerForm, null, ['name' => $pass->name, 'date' => $pass->pickup_on->translatedFormat('j M')]);
        // Страховой — только пока не подтвердила: после подтверждения новая дата её не касается.
        if (! $locked) {
            $message = $this->letters->toVendor($vehicle, 'buyer-check', [
                'buyer_name' => $pass->name, 'buyer_email' => $pass->email, 'pickup_date' => $pass->pickup_on->translatedFormat('j F Y'),
            ]);
            $pass->update(['request_message_id' => $message?->id ?? $pass->request_message_id]);
        }
        // QR на почту — при первой отправке и на новый адрес; смена даты письма не шлёт, пропуск тот же.
        if (! $emailBefore || $emailBefore !== $pass->email) {
            ($this->mail)($pass);
        }
        BuyerFormSubmitted::dispatch($pass);

        return $pass;
    }
}
