<?php

namespace App\Offers\Share;

use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Park\PhotoStage;
use App\Park\Vehicle;
use App\Purchases\Car;
use App\Support\Surface;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Чем делимся: оффер или машина закупки. Всё, что нужно PDF и подписи, —
 * фото, метка на знак, дата, имя файла и строки текста.
 */
final class Subject
{
    public const LOCKED = 'Недоступно, пока администратор не согласует цену менеджера';

    private function __construct(
        public readonly Offer|Car|Vehicle $model,
        public readonly string $label,
        public readonly ?CarbonInterface $date,
        public readonly string $title,
        public readonly string $url,
    ) {}

    public static function offer(Offer $offer): self
    {
        // Оценённым черновиком делятся — номер с датой выдаётся сразу (`OfferNumber::reserve`): в тексте, знаке PDF и имени
        // файла он тот же, что будет на сайте. Без цены — нет: кнопка рисуется в каждой карточке, мусорный черновик из
        // письма боевого номера не съедает, а временный в текст не идёт.
        if ($offer->asking_price) {
            OfferNumber::reserve($offer);
        }

        return new self($offer, (string) OfferNumber::shared($offer), $offer->published_at ?? $offer->created_at, $offer->titleWithYear(), "/offers/{$offer->number}/pdf");
    }

    public static function car(Car $car): self
    {
        $car->loadMissing('purchase');

        return new self($car, $car->shownNumber(), $car->purchase->offers_close_at, $car->titleWithYear(), "/purchases/{$car->purchase->number}/{$car->ref}/pdf");
    }

    /**
     * ТС парковки — только админам и администраторам парковки (02.11.2026, владелец): страховая просит фото машины, а
     * отдать было нечем. Метка на знаке — номер убытка, иначе госномер; дата — приём.
     */
    public static function vehicle(Vehicle $vehicle): self
    {
        return new self($vehicle, (string) ($vehicle->ref ?: $vehicle->plate ?: 'ТС'), $vehicle->accepted_at ?? $vehicle->created_at, $vehicle->titleWithYear(), "/cars/{$vehicle->id}/pdf");
    }

    public function fields(?User $user): array
    {
        return match (true) {
            $this->model instanceof Offer => Caption::fields($this->model, $user),
            $this->model instanceof Vehicle => Caption::vehicle($this->model),
            default => Caption::car($this->model, $user),
        };
    }

    /** Шеринг запрещён тумблером в редакторе оффера или машины закупки, у оффера ещё — этому человеку, пока он оценивает. */
    public function locked(?User $user): bool
    {
        return match (true) {
            $this->model instanceof Offer => $this->model->shareLockedFor($user),
            $this->model instanceof Vehicle => false,
            default => (bool) $this->model->share_locked,
        };
    }

    public function vatMark(): string
    {
        return $this->model instanceof Offer ? Caption::vatMark($this->model) : '';
    }

    public function fileName(): string
    {
        return $this->baseName().'.pdf';
    }

    /** Кадр по порядку на карточке: «… 3.jpg» — в галерее получателя фото не путаются между машинами. */
    public function photoName(int $n): string
    {
        return $this->baseName()." {$n}.jpg";
    }

    /** Адрес кадра для шторки: `{id}` подставляет клиент. */
    public function photoUrl(): string
    {
        return preg_replace('~/pdf$~', '/photo/{id}', $this->url);
    }

    private function baseName(): string
    {
        $name = trim(preg_replace('~[\\\\/:*?"<>|%\x00-\x1F]+~u', ' ', $this->label.' '.$this->title));

        return preg_replace('~\s+~u', ' ', $name) ?: 'xcar';
    }

    /**
     * Кадры шторки «Поделиться» группами (02.11.2026, владелец: «от страховой и при приёме — два блока, выбирать и то и то
     * без ограничений»): «Фото от страховой», «Фото при приёме» по `PhotoStage`; у машины закупки — одна группа без
     * подписи. Порядок внутри группы — как на карточке.
     *
     * @return list<array{label: ?string, folder: ?string, photos: Collection<int, Media>}>
     */
    public function photoGroups(): array
    {
        $photos = $this->photos();
        if ($this->model instanceof Car) {
            return [['label' => null, 'folder' => null, 'photos' => $photos]];
        }
        $groups = $photos->groupBy(fn (Media $m) => PhotoStage::of($m)->value);

        return collect(PhotoStage::cases())->filter(fn (PhotoStage $s) => $groups->has($s->value))
            ->map(fn (PhotoStage $s) => ['label' => $s->label(), 'folder' => $s->label(), 'photos' => $groups[$s->value]->values()])
            ->values()->pipe(fn ($g) => $g->count() > 1 ? $g->all() : [['label' => null, 'folder' => null, 'photos' => $photos]]);
    }

    /**
     * Чьи кадры можно отдать: в CRM — все кадры машины, и скрытые с витрины (02.11.2026: фото при приёме у опубликованной
     * скрыты по умолчанию — `Hidden::incoming`, и в шеринг сотрудника не попадали вовсе); на сайте — только видимые.
     *
     * @return Collection<int, Media>
     */
    public function photos(): Collection
    {
        return (Surface::current() === Surface::Crm ? $this->model->photos() : $this->model->visiblePhotos())->values();
    }

    /** ZIP с кадрами: «… фото.zip». */
    public function zipName(): string
    {
        return $this->baseName().' фото.zip';
    }

    /** Каталог кэша: по таблице и id — офферы и машины не пересекаются. */
    public function cacheDir(): string
    {
        return 'share/'.$this->model->getTable().'/'.$this->model->getKey();
    }
}
