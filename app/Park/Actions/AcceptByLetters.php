<?php

namespace App\Park\Actions;

use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Park\AcceptHints;
use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Vendors\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ждущая ТС, о приёме которой мы сами написали вендору («Направляю Вам фотоотчёт … по принятому ТС» — наше письмо со
 * смыслом `Intent::Accepted`), встаёт на хранение сама с датой этого письма (02.11.2026: управляющий отвечает из
 * «Почты Mail.ru», а приём в системе не отмечал — 20 из 35 «Заявок на приём» уже стояли). Пара к `ReleaseByLetters`;
 * ТС, которой ещё нет в системе, по тому же письму заводит `StoreByLetters`. Письмо — не раньше дня открытой заявки на
 * приём (иначе это прошлый заезд той же машины), и вендор после него не писал «не выдавать» или отмену. Зовут
 * `Mail\OnMessage` (наше письмо в ветку ТС) и `park:tick`; разовый прогон — `park:accept-by-letters`. Откат — «Отменить приём».
 * Письма о приёме нет, а наше «подписанный АПП» уже ушло — ТС принята днём заявки вендора и сразу выдана по этому письму
 * (`ReleaseByLetters`; 02.11.2026, владелец: Exeed 01373/25 стоял в «Заявках» выданным, Сергей принял его сегодняшним днём).
 */
final class AcceptByLetters
{
    /** Что после нашего письма о приёме значит «ещё не принята». */
    private const AFTER = [Intent::Hold, Intent::CancelRelease];

    public function __construct(private IntakePast $intake) {}

    /** Письмо, по которому ТС принята, — или null. */
    public function letter(Vehicle $vehicle): ?Message
    {
        if ($vehicle->state !== VehicleState::Expected) {
            return null;
        }
        // Порог — открытая заявка на приём (ТС, которую снова ждут, приняли по письму прошлого заезда — нет), без неё — любая.
        $since = $this->since($vehicle);
        $messages = Message::whereIn('thread_id', Thread::park()->where('vehicle_id', $vehicle->id)->select('id'))
            ->when($since, fn ($q) => $q->where('date_at', '>=', $since->copy()->startOfDay()))
            ->with('attachments')->orderBy('date_at')->get();
        // Смысл перепроверяется по нынешним правилам: письмо могли прочитать старой версией. Без письма о приёме — о выдаче.
        $ours = fn (Intent $intent) => $messages->first(fn (Message $m) => $m->intent === $intent->value && $m->isOurs()
            && Intent::ofMessage($m) === $intent);
        $accepted = $ours(Intent::Accepted) ?? $ours(Intent::Released);
        if (! $accepted) {
            return null;
        }
        $after = array_map(fn (Intent $i) => $i->value, self::AFTER);
        $undone = $messages->contains(fn (Message $m) => $m->date_at->gt($accepted->date_at) && ! $m->isOurs() && in_array($m->intent, $after, true))
            // Приём по этому письму отменили руками («Отменить приём») — человек решил, письмо снова не принимает.
            || $vehicle->events()->reorder()->where('type', EventType::IntakeUndone)->where('created_at', '>=', $accepted->date_at)->exists();

        return $undone ? null : $accepted;
    }

    public function __invoke(Vehicle $vehicle): ?Message
    {
        if (! ($letter = $this->letter($vehicle))) {
            return null;
        }

        // По письму о выдаче день приёма неизвестен — день письма-заявки вендора перед ним, иначе заявки в системе (её
        // заводят и через месяц после письма: у Exeed письмо 02.09, заявка 07.10, принят по акту 03.09); Сергей поправит в деле.
        $released = $letter->intent === Intent::Released->value;
        $at = ! $released ? $letter->date_at->copy() : AcceptHints::asked($vehicle, $letter->date_at) ?? $this->since($vehicle) ?? $letter->date_at->copy();
        if (! ($this->intake)($vehicle, $this->yard($vehicle), $at, ['by_letters' => true, 'message' => $letter->id])) {
            return null;
        }
        if ($released) {
            app(ReleaseByLetters::class)($vehicle->refresh());
        }

        return $letter;
    }

    /** Время открытой заявки на приём, без неё — первой. */
    private function since(Vehicle $vehicle): ?Carbon
    {
        $intake = Request::where('vehicle_id', $vehicle->id)->where('type', RequestType::Intake);
        $since = (clone $intake)->whereIn('state', RequestState::open())->min('created_at') ?? $intake->min('created_at');

        return $since ? Carbon::parse($since) : null;
    }

    /**
     * Где стоит: парковка заявки, иначе по адресу того, кто из вендора пишет (у эксперта свой город), иначе парковка вендора
     * по умолчанию — как у `StoreByLetters`. Не нашлась — ТС без парковки, в деле шаг «Нужно указать парковку».
     */
    public function yard(Vehicle $vehicle): ?Yard
    {
        $planned = Request::where('vehicle_id', $vehicle->id)->where('type', RequestType::Intake)->whereNotNull('yard_id')->latest('id')->value('yard_id');
        if ($planned && ($yard = Yard::find($planned))) {
            return $yard;
        }
        $senders = Message::whereIn('thread_id', Thread::park()->where('vehicle_id', $vehicle->id)->select('id'))
            ->where('direction', 'in')->orderBy('date_at')->pluck('from_email');
        foreach ($senders as $email) {
            if ($yard = Contact::yardFor($email)) {
                return $yard;
            }
        }

        return $vehicle->vendor?->homeYard;
    }

    /**
     * Ждущие ТС с письмом о приёме не раньше дня открытой заявки и без ручной отмены приёма после него — отбор в SQL, чтобы
     * `park:tick` раз в 5 минут не перечитывал письма ТС, которые не примутся никогда. Точно решает `letter`.
     *
     * @return Collection<int, Vehicle>
     */
    public function suspects(): Collection
    {
        $open = "'".implode("','", array_map(fn ($s) => $s->value, RequestState::open()))."'";

        return Vehicle::with('vendor')->where('state', VehicleState::Expected)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('mail_messages as m')
                ->join('mail_threads as t', 't.id', '=', 'm.thread_id')
                ->join('mail_accounts as a', fn ($j) => $j->on('a.id', '=', 't.account_id')->where('a.scope', Scope::Park->value))
                ->whereColumn('t.vehicle_id', 'park_vehicles.id')->whereIn('m.intent', [Intent::Accepted->value, Intent::Released->value])
                ->whereRaw("m.date_at >= coalesce((select date_trunc('day', min(r.created_at)) from park_requests r where r.vehicle_id = park_vehicles.id and r.type = ? and r.state in ({$open})),
                    (select date_trunc('day', min(r.created_at)) from park_requests r where r.vehicle_id = park_vehicles.id and r.type = ?), m.date_at)", [RequestType::Intake->value, RequestType::Intake->value])
                ->whereNotExists(fn ($e) => $e->selectRaw('1')->from('park_vehicle_events as e')->whereColumn('e.vehicle_id', 'park_vehicles.id')
                    ->where('e.type', EventType::IntakeUndone->value)->whereColumn('e.created_at', '>=', 'm.date_at')))
            ->get();
    }

    /** Все подходящие — `park:tick`. @return int сколько принято */
    public function all(): int
    {
        return $this->suspects()->filter(fn (Vehicle $v) => ($this)($v) !== null)->count();
    }
}
