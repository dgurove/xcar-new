<?php

namespace App\Park\Actions;

use App\Cars\Vin\RememberVin;
use App\Mail\Candidate;
use App\Mail\CandidateStage;
use App\Mail\Direction;
use App\Mail\Message;
use App\Park\DocState;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ТС по цепочке писем, которую менеджер проверил: письма говорят, что она уже принята (наш ответ с актом) и,
 * возможно, продана. Заводится сразу стоящей — с датой приёма, парковкой и местом, событием приёма
 * (по нему считается хранение), бумаги вендору открыты и отмечены отправленными нашим ответом; продана —
 * `MarkSold` с покупателем из письма → заявка на выдачу.
 */
final class RegisterFromLetters
{
    public function __construct(private OpenDocs $openDocs, private MarkSold $sold, private MarkDoc $markDoc) {}

    /**
     * @param  array{accepted_at?: ?string, yard_id: int, spot?: ?string, sold?: bool, sold_at?: ?string, pickup_name?: ?string, pickup_phone?: ?string}  $stages
     */
    public function __invoke(User $by, Candidate $candidate, array $data, array $stages): Vehicle
    {
        Nav::forgetStaffCounts();
        $stored = $candidate->stageOf(CandidateStage::Stored);
        $soldStage = $candidate->stageOf(CandidateStage::Sold);
        $acceptedAt = ! empty($stages['accepted_at']) ? Carbon::parse($stages['accepted_at']) : (($stored['at'] ?? null) ? Carbon::parse($stored['at']) : null);
        $yard = Yard::findOrFail($stages['yard_id']);

        $vehicle = DB::transaction(function () use ($by, $candidate, $data, $stages, $stored, $yard, $acceptedAt) {
            $spot = Vehicle::takeSpot($yard, $stages['spot'] ?? null);
            $vehicle = Vehicle::create([
                'ref' => $data['ref'] ?? null, 'vin' => $data['vin'] ?? null, 'plate' => $data['plate'] ?? null, 'year' => $data['year'] ?? null,
                'brand_id' => $data['brand_id'] ?? null, 'model_id' => $data['model_id'] ?? null, 'vendor_id' => $data['vendor_id'] ?? null,
                'category' => $data['category'] ?? null,
                'contact_name' => $data['contact_name'] ?? null, 'contact_phone' => $data['contact_phone'] ?? null,
                'flags' => $data['flags'] ?? [], 'docs_required' => $data['docs_required'] ?? [], 'value' => $data['value'] ?? null,
                'state' => VehicleState::Stored, 'yard_id' => $yard->id, 'spot' => $spot, 'accepted_at' => $acceptedAt ?? now(),
            ]);
            $intake = $candidate->stageOf(CandidateStage::Intake);
            $vehicle->log(EventType::Created, $by, ['by_letters' => true, 'letter_at' => $intake['at'] ?? null]);
            $day = ($acceptedAt ?? now())->toDateString();
            $vehicle->log(EventType::Accepted, $by, array_filter(['yard' => $yard->name, 'yard_id' => $yard->id, 'spot' => $spot, 'day' => $day, 'by_letters' => true]));
            (new RememberVin)($vehicle);
            (new LinkOffer)($vehicle, null, $by);
            ($this->openDocs)($vehicle);
            // Наш ответ с актом уже ушёл вендору — бумаги «отправлено» тем письмом.
            $reply = ($stored['message_id'] ?? null) ? Message::find($stored['message_id']) : null;
            if ($reply && $reply->direction === Direction::Out) {
                foreach ($vehicle->docs()->where('direction', 'out')->where('state', DocState::Pending)->get() as $doc) {
                    ($this->markDoc)($doc, $by, DocState::Sent, $reply->date_at, null, $reply->thread_id);
                }
            }

            return $vehicle;
        });

        if (! empty($stages['sold'])) {
            $soldAt = ! empty($stages['sold_at']) ? Carbon::parse($stages['sold_at']) : (($soldStage['at'] ?? null) ? Carbon::parse($soldStage['at']) : now());
            $message = ($soldStage['message_id'] ?? null) ? Message::find($soldStage['message_id']) : null;
            ($this->sold)($vehicle, $by, $soldAt, $stages['pickup_name'] ?? null, $stages['pickup_phone'] ?? null, $soldStage['note'] ?? null, $message);
        }

        return $vehicle->refresh();
    }
}
