<?php

namespace App\Park\Actions;

use App\Park\Delivery;
use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestType;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Звонок страхователю по новой заявке: эвакуатор — заявка становится эвакуацией и, если срок известен, назначается;
 * привезёт сам — срок приёма; не дозвонились — когда позвонить снова (напомнит `park:tick`).
 */
final class Contact
{
    public function __construct(private ScheduleTow $schedule) {}

    public function __invoke(Request $request, User $by, string $outcome, array $data): Request
    {
        if (! in_array($request->type, [RequestType::Intake, RequestType::Tow], true) || ! $request->isOpen()) {
            throw ValidationException::withMessages(['state' => 'Заявка не на приём или уже закрыта']);
        }
        Nav::forgetStaffCounts();

        return DB::transaction(function () use ($request, $by, $outcome, $data) {
            $contact = array_filter(['contact_name' => $data['contact_name'] ?? null, 'contact_phone' => $data['contact_phone'] ?? null], fn ($v) => $v !== null && $v !== '');
            if ($outcome === 'missed') {
                $again = ! empty($data['next_call_at']) ? Carbon::parse($data['next_call_at']) : now()->addHours(2);
                $request->update($contact + ['next_call_at' => $again, 'reminded_at' => null]);
                $request->vehicle->log(EventType::Called, $by, ['reached' => false, 'again' => $again->translatedFormat('j M, H:i')]);

                return $request;
            }
            $delivery = Delivery::from($outcome);
            $request->update($contact + ['delivery' => $delivery, 'contacted_at' => now(), 'next_call_at' => null,
                'type' => $delivery === Delivery::Tow ? RequestType::Tow : RequestType::Intake]);
            if ($delivery === Delivery::Self) {
                $request->update(array_filter(['planned_at' => $data['planned_at'] ?? null, 'yard_id' => $data['yard_id'] ?? null]) + ['reminded_at' => null, 'overdue_at' => null]);
            }
            $request->vehicle->log(EventType::Called, $by, ['reached' => true, 'outcome' => mb_strtolower($delivery->label()),
                'at' => ! empty($data['planned_at']) ? Carbon::parse($data['planned_at'])->translatedFormat('j M, H:i') : null]);
            if ($delivery === Delivery::Tow && ! empty($data['planned_at'])) {
                $request = ($this->schedule)($request->fresh(), $by, $data);
            }

            return $request;
        });
    }
}
