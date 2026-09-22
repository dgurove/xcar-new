<?php

namespace App\Park\Actions;

use App\Cars\Vin\RememberVin;
use App\Mail\Candidate;
use App\Mail\CandidateStage;
use App\Mail\Message;
use App\Park\DocState;
use App\Park\Events\VehicleReleased;
use App\Park\EventType;
use App\Park\Idle;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ТС, которая уже стоит (или уже выдана), заводится задним числом: по цепочке писем, которую менеджер проверил
 * (наш ответ с актом — принята, «реализовано» — продана, «подписанный АПП» — выдана), или по факту без писем
 * (`park:fact`, `$candidate = null`), или сама, когда мы уже написали вендору, что приняли (`StoreByLetters`,
 * `$by = null`). Сразу стоящей — с датой приёма, событием приёма (по нему считается хранение),
 * парковкой и местом, если известны (иначе шаг «Нужно указать парковку»); бумаги вендору открыты и отмечены
 * отправленными нашим ответом; продана — `MarkSold` → заявка на выдачу; выдана — состояние и событие выдачи без
 * осмотра и проверки долга (счета за прошлое выставит закрытие месяца).
 */
final class RegisterFromLetters
{
    public function __construct(private OpenDocs $openDocs, private MarkSold $sold, private MarkDoc $markDoc, private PurgeLetters $purge) {}

    /**
     * @param  array{accepted_at?: ?string, yard_id?: ?int, spot?: ?string, sold?: bool, sold_at?: ?string, pickup_name?: ?string, pickup_phone?: ?string, released_at?: ?string, released_note?: ?string, source?: string}  $stages
     */
    public function __invoke(?User $by, ?Candidate $candidate, array $data, array $stages): Vehicle
    {
        Nav::forgetStaffCounts();
        $stored = $candidate?->stageOf(CandidateStage::Stored);
        $soldStage = $candidate?->stageOf(CandidateStage::Sold);
        $acceptedAt = ! empty($stages['accepted_at']) ? Carbon::parse($stages['accepted_at']) : (($stored['at'] ?? null) ? Carbon::parse($stored['at']) : null);
        $yard = ! empty($stages['yard_id']) ? Yard::findOrFail($stages['yard_id']) : null;
        $releasedAt = ! empty($stages['released_at']) ? Carbon::parse($stages['released_at']) : null;
        $mark = ['by_letters' => true] + (($stages['source'] ?? null) === 'fact' ? ['by_fact' => true] : []);

        $vehicle = DB::transaction(function () use ($by, $candidate, $data, $stages, $stored, $yard, $acceptedAt, $mark) {
            $spot = Vehicle::takeSpot($yard, $stages['spot'] ?? null);
            $vehicle = Vehicle::create([
                'ref' => $data['ref'] ?? null, 'vin' => $data['vin'] ?? null, 'plate' => $data['plate'] ?? null, 'year' => $data['year'] ?? null,
                'brand_id' => $data['brand_id'] ?? null, 'model_id' => $data['model_id'] ?? null, 'vendor_id' => $data['vendor_id'] ?? null,
                'category' => $data['category'] ?? null, 'color' => $data['color'] ?? null, 'policy_no' => $data['policy_no'] ?? null,
                'pts' => $data['pts'] ?? null, 'sts' => $data['sts'] ?? null, 'notes' => $data['notes'] ?? null,
                'contact_name' => $data['contact_name'] ?? null, 'contact_phone' => $data['contact_phone'] ?? null,
                'flags' => $data['flags'] ?? [], 'docs_required' => $data['docs_required'] ?? [], 'value' => $data['value'] ?? null,
                'state' => VehicleState::Stored, 'yard_id' => $yard?->id, 'spot' => $spot, 'accepted_at' => $acceptedAt ?? now(),
                // Заводится задним числом: «стоит долго» о ней уже не новость, и пачка таких ТС не должна звонить всем сразу.
                'idle_noticed_at' => ($acceptedAt ?? now())->lt(now()->subDays(Idle::warn())) ? now() : null,
            ]);
            $intake = $candidate?->stageOf(CandidateStage::Intake);
            $vehicle->log(EventType::Created, $by, $mark + ['letter_at' => $intake['at'] ?? null]);
            $day = ($acceptedAt ?? now())->toDateString();
            $vehicle->log(EventType::Accepted, $by, array_filter(['yard' => $yard?->name, 'yard_id' => $yard?->id, 'spot' => $spot, 'day' => $day]) + $mark);
            (new RememberVin)($vehicle);
            (new LinkOffer)($vehicle, null, $by);
            ($this->openDocs)($vehicle);
            // Наш ответ с актом уже ушёл вендору — бумаги «отправлено» тем письмом.
            $reply = ($stored['message_id'] ?? null) ? Message::find($stored['message_id']) : null;
            if ($reply && $reply->isOurs()) {
                foreach ($vehicle->docs()->where('direction', 'out')->where('state', DocState::Pending)->get() as $doc) {
                    ($this->markDoc)($doc, $by, DocState::Sent, $reply->date_at, null, $reply->thread_id);
                }
                // Шаг «Отчёт вендору» в деле сделан этим письмом.
                $vehicle->log(EventType::ReportSent, $by, ['what' => 'Акт приёма', 'thread' => $reply->thread_id] + $mark);
            }
            // Выдана по письмам: наш «подписанный АПП» — отчёт о выдаче.
            $releasedStage = $candidate?->stageOf(CandidateStage::Released);
            $app = ($releasedStage['message_id'] ?? null) ? Message::find($releasedStage['message_id']) : null;
            if ($app && $app->isOurs() && ! empty($stages['released_at'])) {
                $vehicle->log(EventType::ReportSent, $by, ['what' => 'Акт выдачи', 'thread' => $app->thread_id] + $mark);
            }

            return $vehicle;
        });

        if (! empty($stages['sold'])) {
            $soldAt = ! empty($stages['sold_at']) ? Carbon::parse($stages['sold_at']) : (($soldStage['at'] ?? null) ? Carbon::parse($soldStage['at']) : now());
            $message = ($soldStage['message_id'] ?? null) ? Message::find($soldStage['message_id']) : null;
            ($this->sold)($vehicle, $by, $soldAt, $stages['pickup_name'] ?? null, $stages['pickup_phone'] ?? null, $soldStage['note'] ?? null, $message);
        }

        if ($releasedAt) {
            $this->release($vehicle, $by, $releasedAt->lt($vehicle->accepted_at) ? $vehicle->accepted_at : $releasedAt, $stages['released_note'] ?? null, $mark);
        }

        return $vehicle->refresh();
    }

    /** Выдана задним числом: как `Release`, но без осмотра и проверки долга — выдача уже случилась. */
    private function release(Vehicle $vehicle, ?User $by, Carbon $at, ?string $note, array $mark): void
    {
        DB::transaction(function () use ($vehicle, $by, $at, $note, $mark) {
            $vehicle->update(['state' => VehicleState::Released, 'released_at' => $at, 'spot' => null]);
            $vehicle->log(EventType::Released, $by, array_filter(['note' => $note, 'day' => $at->toDateString()]) + $mark);
            Request::where('vehicle_id', $vehicle->id)->whereIn('state', RequestState::open())
                ->update(['state' => RequestState::Done, 'done_at' => now(), 'done_by' => $by?->id]);
        });
        VehicleReleased::dispatch($vehicle, null, $by);
        ($this->purge)($vehicle);
    }
}
