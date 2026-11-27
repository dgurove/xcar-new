<?php

namespace App\Park\Actions;

use App\Cars\IdentityTaken;
use App\Mail\Candidate;
use App\Mail\CandidateStage;
use App\Mail\CandidateState;
use App\Mail\Actions\CloseChain;
use App\Mail\Message;
use App\Mail\Scope;
use App\Park\Vehicle;
use App\Park\Yard;
use App\Vendors\Contact;
use App\Vendors\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Мы сами написали вендору, что ТС принята, а в системе её нет — значит она стоит, и заводить её руками незачем:
 * цепочка превращается в дело сама, стоящей, с датой приёма из письма. Парковка — у контакта вендора, который
 * написал (`Vendors\Contact::yardFor`: питерский эксперт ведёт питерские машины); нет такого контакта — ТС встаёт
 * без парковки и в деле ждёт шага «Нужно указать парковку», где заодно сверят факт.
 * В «Из писем» остаются только новые заявки.
 *
 * Не заводит: цепочку из архива («Не заявка» — решение человека), цепочку, у которой этап «принята» подтверждён
 * письмом вендора, а не нашим (вопрос о документах приёмом не является, `Chains\ChainBuilder::stages`), и цепочку
 * без марки — ТС без имени в списках не нужна, её разберут по скану заявки. Уже заведённой ТС просто отдаёт письма.
 *
 * Выдана по письмам (мы писали и о приёме, и о выдаче) — заводится выданной, а не уходит в архив (27.11.2026, владелец про
 * архивные цепочки: «не надо было такие архивировать, они ж норм письма хорошие»). Выданная до сентября 2026 выставлена
 * прежней бухгалтерией — «выставлено по» её дню выдачи (`BILLED_OUTSIDE`). Не завелась — цепочка закрыта, как раньше.
 */
final class StoreByLetters
{
    /** По какой день хранение выставляла прежняя бухгалтерия (`notes/zakrytie-mesyatsa-2026-11-05.md`). */
    public const BILLED_OUTSIDE = '2026-08-31';

    public function __construct(private RegisterFromLetters $register, private RegisterCandidate $promote, private CloseChain $close) {}

    /** Почему цепочка не заводится сама; null — заводится. */
    public function blocker(Candidate $candidate): ?string
    {
        if ($candidate->scope !== Scope::Park || $candidate->state !== CandidateState::New) {
            return 'не ждёт в «Из писем»';
        }
        // Последний этап цепочки: у выданной он `released` — такую заводить стоящей нельзя, а закрывает её `CloseChain`.
        if (! in_array($candidate->stage, [CandidateStage::Stored, CandidateStage::Sold, CandidateStage::Released], true)) {
            return 'о приёме мы не писали';
        }
        $stored = $candidate->stageOf(CandidateStage::Stored);
        if (! $stored) {
            return 'о приёме мы не писали';
        }
        $released = $candidate->stage === CandidateStage::Released ? $candidate->stageOf(CandidateStage::Released) : null;
        if ($released && Carbon::parse($released['at'])->startOfDay()->lte(Carbon::parse($stored['at'])->startOfDay())) {
            return 'выдача по письмам не позже приёма';
        }
        $message = Message::find($stored['message_id'] ?? null);
        if (! $message?->isOurs()) {
            return 'о приёме написал вендор, не мы';
        }
        if (! $candidate->cars()[0]) {
            return 'марки в письме нет';
        }

        return null;
    }

    /** Цепочки, которые ждут заведения: по стадии грубо, точно — `blocker`. @return Collection<int, Candidate> */
    public function waiting(): Collection
    {
        return Candidate::where('scope', Scope::Park)->where('state', CandidateState::New)
            ->whereIn('stage', [CandidateStage::Stored, CandidateStage::Sold, CandidateStage::Released])
            ->orderBy('id')->get();
    }

    public function __invoke(Candidate $candidate): ?Vehicle
    {
        if ($this->blocker($candidate)) {
            return null;
        }
        // Цепочку только что свернули с новым письмом — состав писем читаем заново, а не из памяти.
        $candidate->load('messages');
        // Такую ТС уже завели руками — второй не будет, письма просто идут к делу.
        if ($vehicle = $this->promote->existing($candidate)) {
            $this->promote->attach($candidate, $vehicle);

            return $vehicle;
        }
        $sold = $candidate->stageOf(CandidateStage::Sold);
        $released = $candidate->stage === CandidateStage::Released ? $candidate->stageOf(CandidateStage::Released) : null;
        try {
            $vehicle = ($this->register)(null, $candidate, $candidate->vehicleData(), [
                'stored' => true,
                'yard_id' => $this->yard($candidate)?->id,
                'sold' => (bool) $sold,
                'pickup_name' => $sold['name'] ?? null,
                'pickup_phone' => $sold['phone'] ?? null,
            ] + ($released ? ['released_at' => $released['at'], 'released_note' => 'по письмам'] : []));
        } catch (IdentityTaken) {
            // Номер уже у выданной или отменённой ТС — сам не заводим: второй ТС с тем же номером не бывает, решит человек.
            // Выданная по письмам — в архив, как было.
            if ($released) {
                ($this->close)($candidate, 'released');
            }

            return null;
        }
        $this->promote->attach($candidate, $vehicle);
        if ($released && ($day = Carbon::parse($released['at'])->toDateString()) <= self::BILLED_OUTSIDE) {
            $vehicle->forceFill(['storage_billed_until' => $day])->save();
        }

        return $vehicle;
    }

    /**
     * Где стоит: по адресу того, кто из вендора пишет — у каждого эксперта свой город; иначе парковка вендора по
     * умолчанию (`vendors.home_yard_id`: у Альфы СПб — Краснопутиловская, у Филипповой своего контакта нет).
     */
    public function yard(Candidate $candidate): ?Yard
    {
        foreach ($candidate->messages->sortBy('date_at') as $message) {
            if (! $message->isOurs() && ($yard = Contact::yardFor($message->from_email))) {
                return $yard;
            }
        }

        return $candidate->vendor_id ? Vendor::find($candidate->vendor_id)?->homeYard : null;
    }

    /** Все ждущие разом: письмо пришло, пересчитали цепочки, разгребаем хвост. */
    public function all(): int
    {
        $done = 0;
        foreach ($this->waiting() as $candidate) {
            $done += $this($candidate) ? 1 : 0;
        }

        return $done;
    }
}
