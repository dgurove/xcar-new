<?php

namespace App\Mail\Scan;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Colors;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Settlement;
use App\Cars\Transmission;
use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Account;
use App\Mail\Extraction\DocumentText;
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
use Illuminate\Validation\Rule;

/**
 * «✨» у предложения CRM: файлы всех писем его веток в ящиках CRM, наших пересылок тоже (как окно писем предложения),
 * и документы предложения — загруженные руками тоже (заведённое модератором без писем); письма — только тем, кому
 * открыта почта CRM (`$mail`). Что выбрал человек — в карточку через `UpdateOffer` (правка в истории предложения).
 * Поля — машина, VIN, год, цвет и характеристики (`ScanFields::SPECS`, только в пустые): госномера и оценочной
 * стоимости у предложения нет.
 */
final class OfferSubject implements Subject
{
    public function __construct(public readonly Offer $offer, private readonly bool $mail = true) {}

    /** Для того, кто смотрит: письма — с галкой «Почта» или админу. */
    public static function for(Offer $offer, ?User $user): self
    {
        return new self($offer, (bool) $user?->canCrmMail());
    }

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
        return [...ScanFields::CAR, ...array_keys(ScanFields::SPECS)];
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
        $messages = $this->mail ? Message::whereIn('thread_id', $threads)->with('attachments')->get() : [];

        return Files::of($messages, $this->papers());
    }

    /** Документы предложения, которые «✨» прочтёт: PDF и картинки. Без запроса к почте — для кнопки. @return Collection<int, Paper> */
    public function papers(): Collection
    {
        return collect(Paper::wrap($this->offer->papers()))->filter(fn (Paper $p) => DocumentText::scannable($p))->values();
    }

    public function current(): array
    {
        return ScanFields::ofOffer($this->offer->loadMissing(['brand', 'model', 'settlement']));
    }

    /**
     * Выбранное — в карточку по правилам формы (`OfferRequest`): VIN заглавными, цвет словарём, марка и модель по
     * справочнику, город по справочнику городов; сменили марку без модели — модель прежней марки снимается.
     * Характеристики ложатся только в пустые поля. Не прошедшее правила поле пропускается.
     */
    public function apply(array $chosen, User $by): void
    {
        $data = [];
        foreach ($chosen as $field => $item) {
            $value = $item['value'];
            match ($field) {
                'brand' => ($id = Brand::known((string) $value)?->id) ? $data['brand_id'] = $id : null,
                'model' => null,
                'vin' => $data['vin'] = strtoupper((string) $value),
                'color' => $data['color'] = Colors::normalize((string) $value) ?? (string) $value,
                'year' => $data['year'] = (int) $value,
                'mileage', 'engine_volume', 'engine_power' => $data[$field] = (int) $value,
                'transmission', 'drive', 'fuel' => $data[$field] = (string) $value,
                'city' => ($id = Settlement::named((string) $value)['id'] ?? null) ? $data['settlement_id'] = $id : null,
                default => null,
            };
        }
        foreach (['mileage', 'transmission', 'drive', 'fuel', 'engine_volume', 'engine_power', 'settlement_id'] as $spec) {
            if (isset($data[$spec]) && ! blank($this->offer->{$spec})) {
                unset($data[$spec]);
            }
        }
        $brandId = $data['brand_id'] ?? $this->offer->brand_id;
        // Марки документа нет в справочнике — его модель не заводится под прежней маркой («Kia H5» из документа Hongqi).
        $unknown = isset($chosen['brand']) && ! Brand::known((string) $chosen['brand']['value']);
        if (! $unknown && isset($chosen['model']['value']) && ($brand = Brand::find($brandId))) {
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
            'mileage' => ['integer', 'between:1,5000000'],
            'transmission' => [Rule::enum(Transmission::class)],
            'drive' => [Rule::enum(Drive::class)],
            'fuel' => [Rule::enum(Fuel::class)],
            'engine_volume' => ['integer', 'between:1,20000'],
            'engine_power' => ['integer', 'between:1,3000'],
            'settlement_id' => ['exists:settlements,id'],
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
