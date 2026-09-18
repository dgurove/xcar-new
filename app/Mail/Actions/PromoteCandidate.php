<?php

namespace App\Mail\Actions;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\DB;

/** Кандидат → черновик оффера: поля из письма, вендор по отправителю, ветка писем привязана — её файлы едут в черновик. */
final class PromoteCandidate
{
    public function __construct(private CreateOffer $create, private UpdateOffer $update, private LinkThread $link) {}

    public function __invoke(Candidate $candidate, User $by): Offer
    {
        $offer = DB::transaction(function () use ($candidate, $by) {
            $v = fn (string $f) => $candidate->value($f);
            $brand = $v('brand') ? Brand::resolve($this->clean($v('brand'))) : null;
            $model = $brand && $v('model') ? CarModel::resolve($brand, $this->clean($v('model'))) : null;
            // Кандидаты до вендоров несли только имя страховой — старым ещё нужен поиск по нему.
            $vendor = $v('vendor_id') ? Vendor::find($v('vendor_id')) : Vendor::forSender($v('sender'))
                ?? ($v('insurer') ? Vendor::whereRaw('lower(name) = ?', [mb_strtolower($v('insurer'))])->first() : null);

            $offer = ($this->create)($by);
            ($this->update)($offer, array_filter([
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
                'floor_price' => $v('floor_price'),
                'inspection_address' => $v('location'),
                'claim_ref' => $candidate->code,
                'vendor_id' => $vendor?->id,
                'prices_include_vat' => $v('vat') ?? $vendor?->vat_included,
                'answer_by' => $v('answer_by'),
                'insured_name' => $v('insured_name'),
                'insured_phone' => $v('insured_phone'),
                'flags' => $v('flags') ?: null,
                'holder' => $v('holder'),
                'docs_required' => $v('docs_required') ?: null,
                'contact_name' => $v('contact_name') ?? $candidate->message?->from_name,
                'contact_email' => $v('sender'),
                'description' => $v('photos_url') ? 'Фото: '.$v('photos_url') : null,
            ], fn ($x) => $x !== null && $x !== ''), $by);

            $candidate->update(['state' => CandidateState::Promoted, 'offer_id' => $offer->id]);
            if ($candidate->thread) {
                ($this->link)($candidate->thread, $offer);
            }

            return $offer;
        });

        return $offer;
    }

    /** Мобильный Mail рвёт «T 7» на два слова и оборачивает в звёздочки. */
    private function clean(string $value): string
    {
        $value = trim((string) preg_replace('/[*_]+/u', '', $value));

        return (string) preg_replace('/\b([A-Za-z])\s+(\d)\b/u', '$1$2', $value);
    }
}
