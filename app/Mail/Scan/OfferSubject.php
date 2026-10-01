<?php

namespace App\Mail\Scan;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Colors;
use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Extraction\ScanFields;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * «✨» у предложения CRM: файлы входящих писем его веток в ящиках CRM (как окно писем предложения); что выбрал человек — в карточку через `UpdateOffer` (правка в
 * истории предложения). Поля — машина, VIN, год, цвет: госномера и страховой стоимости у предложения нет.
 */
final class OfferSubject implements Subject
{
    public function __construct(public readonly Offer $offer) {}

    public function key(): string
    {
        return 'o:'.$this->offer->id;
    }

    public function url(): string
    {
        return "/offers/{$this->offer->number}/scan";
    }

    public function mail(): string
    {
        return '/work/mail';
    }

    public function topic(): string
    {
        return Topics::STAFF;
    }

    public function fields(): array
    {
        return ScanFields::CAR;
    }

    public function title(): string
    {
        return $this->offer->titleWithYear();
    }

    public function hasCar(): bool
    {
        return (bool) $this->offer->brand_id;
    }

    public function vendor(): ?Vendor
    {
        return $this->offer->vendor;
    }

    public function ref(): ?string
    {
        return $this->offer->claim_ref;
    }

    public function creates(): bool
    {
        return false;
    }

    public function files(): Collection
    {
        $threads = Thread::where('offer_id', $this->offer->id)->whereIn('account_id', Account::where('scope', Scope::Offers)->select('id'))->select('id');

        return Files::of(Message::whereIn('thread_id', $threads)->where('direction', Direction::In)->with('attachments')->get());
    }

    public function current(): array
    {
        return ScanFields::ofOffer($this->offer->loadMissing(['brand', 'model']));
    }

    /**
     * Выбранное — в карточку по правилам формы (`OfferRequest`): VIN заглавными, цвет словарём, марка и модель по
     * справочнику; сменили марку без модели — модель прежней марки снимается. Не прошедшее правила поле пропускается.
     */
    public function apply(array $chosen, User $by): void
    {
        $data = [];
        foreach ($chosen as $field => $item) {
            $value = $item['value'];
            match ($field) {
                'brand' => $data['brand_id'] = Brand::known((string) $value)?->id ?? $this->offer->brand_id,
                'model' => null,
                'vin' => $data['vin'] = strtoupper((string) $value),
                'color' => $data['color'] = Colors::normalize((string) $value) ?? (string) $value,
                'year' => $data['year'] = (int) $value,
                default => null,
            };
        }
        $brandId = $data['brand_id'] ?? $this->offer->brand_id;
        if (isset($chosen['model']['value']) && ($brand = Brand::find($brandId))) {
            $data['model_id'] = CarModel::resolve($brand, (string) $chosen['model']['value'])->id;
        } elseif ($brandId !== $this->offer->brand_id) {
            $data['model_id'] = null;
        }
        $errors = Validator::make($data, array_intersect_key([
            'brand_id' => ['nullable', 'exists:brands,id'],
            'model_id' => ['nullable', 'exists:car_models,id'],
            'year' => ['integer', 'between:1950,'.(now()->year + 1)],
            'vin' => ['string', 'size:17'],
            'color' => ['string', 'max:32'],
        ], $data))->errors();
        $data = array_diff_key($data, array_flip($errors->keys()));
        if ($data) {
            app(UpdateOffer::class)($this->offer, $data, $by);
        }
    }

    public function refresh(): void
    {
        app(Publisher::class)->refresh(Topics::STAFF, ['/offers/'.$this->offer->number]);
    }
}
