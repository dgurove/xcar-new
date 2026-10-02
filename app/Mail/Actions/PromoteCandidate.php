<?php

namespace App\Mail\Actions;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Settlement;
use App\Cars\Vin\VinAutofill;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\Code;
use App\Mail\Scope;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Кандидат → черновик оффера: поля из писем, вендор по отправителю, все ветки кандидата привязаны —
 * их файлы едут в черновик. Если предложение с тем же убытком или VIN уже есть, второго не будет: письма к нему.
 */
final class PromoteCandidate
{
    public function __construct(private CreateOffer $create, private UpdateOffer $update, private LinkThread $link) {}

    public function __invoke(Candidate $candidate, User $by): Offer
    {
        if ($offer = $this->existing($candidate)) {
            $candidate->update(['state' => CandidateState::Promoted, 'offer_id' => $offer->id]);
            $this->linkAll($candidate, $offer);

            return $offer;
        }
        $offer = DB::transaction(function () use ($candidate, $by) {
            $offer = ($this->create)($by);
            ($this->update)($offer, $this->data($candidate), $by);
            $candidate->update(['state' => CandidateState::Promoted, 'offer_id' => $offer->id]);

            return $offer;
        });
        // Ветки — после транзакции: импорт файлов берёт уникальную блокировку в базе, и если прежний импорт этой ветки
        // ещё в очереди (черновик завели, отменили и завели снова), неудачная вставка блокировки обрывала транзакцию.
        $this->linkAll($candidate, $offer);

        return $offer;
    }

    /**
     * Черновик из свёртки писем.
     * Марка — только из справочника (разбор уже сверил её со словарём, новой марки из письма не заводим).
     * Чего письмо не сказало — из VIN (`VinAutofill`: кузов, КПП, привод, топливо, объём, мощность; год — только
     * у марок, которые его кодируют): письмо важнее. Город — из справочника, срок страховой — из «ответить до».
     *
     * @return array<string, mixed>
     */
    public function data(Candidate $candidate): array
    {
        $v = fn (string $f) => $candidate->value($f);
        $brand = $v('brand') ? Brand::known($this->clean($v('brand'))) : null;
        $model = $brand && $v('model') ? CarModel::resolve($brand, $this->clean($v('model'))) : null;
        $vendor = $v('vendor_id') ? Vendor::find($v('vendor_id')) : Vendor::forSender($v('sender'), Scope::Offers);
        $answerBy = $v('answer_by') ? Carbon::parse($v('answer_by')) : null;

        $data = array_filter([
            'brand_id' => $brand?->id,
            'model_id' => $model?->id,
            'year' => $v('year') ? (int) $v('year') : null,
            'vin' => $v('vin'),
            'mileage' => $v('mileage'),
            'fuel' => $v('fuel'),
            'transmission' => $v('transmission'),
            'drive' => $v('drive'),
            'engine_volume' => $v('engine_volume'),
            'engine_power' => $v('engine_power'),
            'color' => $v('color'),
            'floor_price' => $v('floor_price'),
            'inspection_address' => $v('location'),
            // Город письма, а нет его — место из адреса осмотра («Пермский край, д. Ванюки, …»).
            'settlement_id' => ($v('city') ? Settlement::named($v('city'))['id'] ?? null : null) ?? Settlement::inAddress($v('location'))['id'] ?? null,
            'claim_ref' => $candidate->code,
            'vendor_id' => $vendor?->id,
            'prices_include_vat' => $v('vat') ?? Vendor::offersVat($vendor?->id),
            'answer_by' => $v('answer_by'),
            'insurer_deadline_at' => $answerBy?->toDateString(),
            'insured_name' => $v('insured_name'),
            'insured_phone' => $v('insured_phone'),
            'flags' => $v('flags') ?: null,
            'holder' => $v('holder'),
            'docs_required' => $v('docs_required') ?: null,
            'contact_name' => $v('contact_name') ?? $candidate->message?->from_name,
            'contact_email' => $v('sender'),
            'description' => $v('photos_url') ? 'Фото: '.$v('photos_url') : null,
        ], fn ($x) => $x !== null && $x !== '');
        if ($data['vin'] ?? null) {
            $byVin = app(VinAutofill::class)->suggest($data['vin'])['values'];
            // Марку сказало письмо, а VIN — другую: модель по VIN к этой марке не подходит.
            if (isset($data['brand_id'], $byVin['brand_id']) && $data['brand_id'] !== $byVin['brand_id']) {
                unset($byVin['model_id']);
            }
            foreach ($byVin as $field => $value) {
                if ($value !== null && ! isset($data[$field])) {
                    $data[$field] = $value instanceof \BackedEnum ? $value->value : $value;
                }
            }
        }

        return $data;
    }

    private function linkAll(Candidate $candidate, Offer $offer): void
    {
        foreach ($candidate->threads() as $thread) {
            if ($thread->offer_id !== $offer->id) {
                ($this->link)($thread, $offer);
            }
        }
        $this->link->forOffer($offer);
    }

    /** Предложение с тем же убытком или VIN, не в архиве. */
    public function existing(Candidate $candidate): ?Offer
    {
        $live = fn () => Offer::where('state', '!=', OfferState::Archived)->latest();
        $vin = $candidate->value('vin') ? strtoupper((string) $candidate->value('vin')) : null;

        return ($candidate->code ? $live()->where('claim_ref_key', Code::key($candidate->code))->first() : null)
            ?? ($vin ? $live()->where('vin', $vin)->first() : null);
    }

    /** Мобильный Mail рвёт «T 7» на два слова и оборачивает в звёздочки. */
    private function clean(string $value): string
    {
        $value = trim((string) preg_replace('/[*_]+/u', '', $value));

        return (string) preg_replace('/\b([A-Za-z])\s+(\d)\b/u', '$1$2', $value);
    }
}
