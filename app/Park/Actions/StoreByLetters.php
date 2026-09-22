<?php

namespace App\Park\Actions;

use App\Mail\Candidate;
use App\Mail\Message;
use App\Mail\CandidateStage;
use App\Mail\CandidateState;
use App\Mail\Scope;
use App\Park\Vehicle;
use App\Park\Yard;
use App\Vendors\Contact;
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
 */
final class StoreByLetters
{
    public function __construct(private RegisterFromLetters $register, private PromoteCandidate $promote) {}

    /** Почему цепочка не заводится сама; null — заводится. */
    public function blocker(Candidate $candidate): ?string
    {
        if ($candidate->scope !== Scope::Park || $candidate->state !== CandidateState::New) {
            return 'не ждёт в «Из писем»';
        }
        // Последний этап цепочки: у выданной он `released` — такую заводить стоящей нельзя, а закрывает её `CloseChain`.
        if (! in_array($candidate->stage, [CandidateStage::Stored, CandidateStage::Sold], true)) {
            return $candidate->stage === CandidateStage::Released ? 'по письмам уже выдана' : 'о приёме мы не писали';
        }
        $stored = $candidate->stageOf(CandidateStage::Stored);
        if (! $stored) {
            return 'о приёме мы не писали';
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
            ->whereIn('stage', [CandidateStage::Stored, CandidateStage::Sold])
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
        $vehicle = ($this->register)(null, $candidate, $candidate->vehicleData(), [
            'stored' => true,
            'yard_id' => $this->yard($candidate)?->id,
            'sold' => (bool) $sold,
            'pickup_name' => $sold['name'] ?? null,
            'pickup_phone' => $sold['phone'] ?? null,
        ]);
        $this->promote->attach($candidate, $vehicle);

        return $vehicle;
    }

    /** Где стоит: по адресу того, кто из вендора пишет — у каждого эксперта свой город. */
    public function yard(Candidate $candidate): ?Yard
    {
        foreach ($candidate->messages->sortBy('date_at') as $message) {
            if (! $message->isOurs() && ($yard = Contact::yardFor($message->from_email))) {
                return $yard;
            }
        }

        return null;
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
