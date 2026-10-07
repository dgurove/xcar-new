<?php

namespace App\Park\Actions;

use App\Cars\Colors;
use App\Cars\IdentityTaken;
use App\Cars\Vin\Vin;
use App\Mail\Candidate;
use App\Mail\CandidateStage;
use App\Mail\CandidateState;
use App\Mail\Extraction\ParkExtractor;
use App\Mail\Message;
use App\Mail\Scan\AutoScan;
use App\Mail\Scope;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\VehicleFields;
use App\Support\Nav;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Письмо-заявка вендора → ТС и заявка сами (владелец 07.10.2026: «пусть заявки на приём и на эвакуацию автоматически
 * заводятся сами»). Те же двери, что «Завести» (`RequestController::store`): `CreateRequest` и `PromoteCandidate::attach`
 * — ветки к делу, файлы в дело. Марка, VIN, год, которых нет в письме, — из документов в фоне (`AutoScan`).
 * Просят эвакуацию (`ParkExtractor::asksEvacuation`) — сразу «Эвакуация» с адресом, иначе приём, который ждёт звонка.
 *
 * Не заводит — цепочка остаётся в «Из писем» человеку: уже принятая, проданная, выданная (принятую нашим письмом
 * заводит `StoreByLetters`), письмо о нескольких машинах, письмо старше недели (пришло историей ящика), номер выданной
 * ТС и прочий отказ `CreateRequest`.
 */
final class AutoRequest
{
    /** Письмо старше — не новая заявка, а догнавшая история: решит человек. */
    private const FRESH_DAYS = 7;

    public function __construct(private CreateRequest $create, private PromoteCandidate $promote) {}

    /** Почему цепочка не заводится сама; null — заводится. `$old` — прогон руками: возраст письма не в счёт. */
    public function blocker(Candidate $candidate, bool $old = false): ?string
    {
        if ($candidate->scope !== Scope::Park || $candidate->state !== CandidateState::New) {
            return 'не ждёт в «Из писем»';
        }
        if (($candidate->stage ?? CandidateStage::Intake) !== CandidateStage::Intake) {
            return 'по письмам уже не заявка: '.$candidate->stageLabel();
        }
        $letter = $this->letter($candidate);
        if (! $letter) {
            return 'письма вендора нет';
        }
        if (! $old && $letter->date_at && $letter->date_at->lt(now()->subDays(self::FRESH_DAYS))) {
            return 'письмо старше недели';
        }
        if (count(array_filter($letter->keys(), fn ($k) => str_starts_with($k, 'code:'))) > 1) {
            return 'письмо о нескольких машинах';
        }

        return null;
    }

    public function __invoke(Candidate $candidate, bool $old = false): ?Request
    {
        $candidate->load('messages');
        if ($this->blocker($candidate, $old)) {
            return null;
        }
        $letter = $this->letter($candidate);
        $type = ParkExtractor::asksEvacuation($letter->subject.' '.$letter->ownText()) ? RequestType::Tow : RequestType::Intake;
        $vehicle = $this->promote->existing($candidate);
        // ТС уже завели: заявка на неё — только пока машины нет на парковке и такой заявки ещё нет; иначе письма к делу.
        if ($vehicle && (! $type->allowedFor($vehicle->state) || ! RequestType::Intake->allowedFor($vehicle->state)
            || $vehicle->requests()->whereIn('type', [RequestType::Intake, RequestType::Tow])->whereIn('state', RequestState::open())->exists())) {
            $this->promote->attach($candidate, $vehicle);

            return null;
        }
        $data = $this->fields($candidate->vehicleData()) + [
            'thread_id' => $candidate->thread_id,
            'planned_at' => $candidate->value('planned_at'),
            'from_address' => $type === RequestType::Tow ? $candidate->value('location') : null,
            'letter_at' => $letter->date_at,
            'letter_id' => $letter->id,
            'by_mail' => true,
        ];
        try {
            $request = ($this->create)(null, $type, $vehicle, $data);
        } catch (IdentityTaken|ValidationException $e) {
            Log::info('Заявка из письма не завелась сама', ['candidate' => $candidate->id, 'error' => $e->getMessage()]);

            return null;
        }
        $this->promote->attach($candidate, $request->vehicle);
        Nav::forgetStaffCounts();
        AutoScan::start($request->vehicle);

        return $request;
    }

    /**
     * Ждущие цепочки на приём — для прогона руками (`park:auto-requests`): у каждой либо заведётся, либо причина.
     *
     * @return Collection<int, Candidate>
     */
    public function waiting(): Collection
    {
        return Candidate::where('scope', Scope::Park)->where('state', CandidateState::New)
            ->where(fn ($q) => $q->whereNull('stage')->orWhere('stage', CandidateStage::Intake))
            ->orderBy('id')->get();
    }

    /** Письмо-заявка: то, с которого цепочка началась, иначе первое письмо вендора. */
    private function letter(Candidate $candidate): ?Message
    {
        return $candidate->message ?? $candidate->messages->sortBy('date_at')->first(fn (Message $m) => ! $m->isOurs());
    }

    /**
     * Поля ТС из письма тем же правилам, что форма (`VehicleFields`): не прошедшее (VIN короче 17, год из будущего)
     * выбрасывается, а не валит заведение. Марка и модель необязательны — их найдёт чтение документов.
     */
    private function fields(array $data): array
    {
        $data['vin'] = $data['vin'] ? Vin::normalize((string) $data['vin']) : null;
        if (! empty($data['color'])) {
            $data['color'] = Colors::normalize((string) $data['color'], fuzzy: false) ?? trim((string) $data['color']);
        }
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
        $errors = Validator::make($data, array_intersect_key(VehicleFields::rules(identity: false), $data))->errors();

        return array_diff_key($data, array_flip($errors->keys())) + ['flags' => [], 'docs_required' => []];
    }
}
