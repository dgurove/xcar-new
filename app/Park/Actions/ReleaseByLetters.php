<?php

namespace App\Park\Actions;

use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Thread;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Support\Collection;

/**
 * Стоящая ТС, о выдаче которой мы сами написали вендору («подписанный АПП», акт выдачи, «ТС вывезли» — наше письмо
 * со смыслом `Intent::Released`), выдаётся сама с датой этого письма (решение владельца 26.09.2026: до этого такие ТС
 * так и числились на парковке — письмо действовало, только пока ТС не заведена). Письмо должно быть после приёма
 * (иначе это прошлый заезд той же машины), и вендор после него не писал «не выдавать», «продано» или новую заявку.
 * Письма вендора «вывез» сами не выдают — это только наше слово. Зовут `Mail\OnMessage` (наше письмо в ветку ТС) и
 * `park:tick`; разовый прогон — `park:release-by-letters`. Откат — «Выдана по ошибке».
 */
final class ReleaseByLetters
{
    /** Что после нашего письма о выдаче значит «ещё не выдана». */
    private const AFTER = [Intent::Hold, Intent::CancelRelease, Intent::Sold, Intent::Intake];

    public function __construct(private ReleasePast $release) {}

    /** Письмо, по которому ТС выдана, — или null. */
    public function letter(Vehicle $vehicle): ?Message
    {
        if ($vehicle->state !== VehicleState::Stored || ! $vehicle->accepted_at) {
            return null;
        }
        $messages = Message::whereIn('thread_id', Thread::park()->where('vehicle_id', $vehicle->id)->select('id'))
            ->where('date_at', '>=', $vehicle->accepted_at->copy()->startOfDay())->with('attachments')->orderBy('date_at')->get();
        // Смысл перепроверяется по нынешним правилам: письмо могли прочитать старой версией (приём СОГАЗа с файлом «Апп»).
        $released = $messages->last(fn (Message $m) => $m->intent === Intent::Released->value && $m->isOurs()
            && Intent::ofMessage($m) === Intent::Released);
        if (! $released) {
            return null;
        }
        $after = array_map(fn (Intent $i) => $i->value, self::AFTER);
        $undone = $messages->contains(fn (Message $m) => $m->date_at->gt($released->date_at) && ! $m->isOurs() && in_array($m->intent, $after, true));

        return $undone ? null : $released;
    }

    public function __invoke(Vehicle $vehicle): ?Message
    {
        if (! ($letter = $this->letter($vehicle))) {
            return null;
        }
        $mark = ['by_letters' => true, 'message' => $letter->id];
        $vehicle->log(EventType::ReportSent, null, ['what' => 'Акт выдачи', 'thread' => $letter->thread_id] + $mark);
        ($this->release)($vehicle, null, $letter->date_at->copy(), null, $mark);

        return $letter;
    }

    /** @return Collection<int, Vehicle> стоящие ТС, у которых в ветках есть наше письмо о выдаче */
    public function suspects(): Collection
    {
        return Vehicle::where('state', VehicleState::Stored)->whereNotNull('accepted_at')
            ->whereIn('id', Thread::park()->whereNotNull('vehicle_id')
                ->whereIn('id', Message::where('intent', Intent::Released->value)->select('thread_id'))->select('vehicle_id'))
            ->get();
    }

    /** Все подходящие — `park:tick`. @return int сколько выдано */
    public function all(): int
    {
        return $this->suspects()->filter(fn (Vehicle $v) => ($this)($v) !== null)->count();
    }
}
