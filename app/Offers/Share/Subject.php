<?php

namespace App\Offers\Share;

use App\Offers\Offer;
use App\Purchases\Car;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

/**
 * Чем делимся: оффер или машина закупки. Всё, что нужно PDF и подписи, —
 * фото, метка на знак, дата, имя файла и строки текста.
 */
final class Subject
{
    public const LOCKED = 'Недоступно, пока администратор не согласует цену менеджера';

    private function __construct(
        public readonly HasMedia&Model $model,
        public readonly string $label,
        public readonly ?CarbonInterface $date,
        public readonly string $title,
        public readonly string $url,
    ) {}

    public static function offer(Offer $offer): self
    {
        return new self($offer, (string) $offer->number, $offer->published_at ?? $offer->created_at, $offer->titleWithYear(), "/offers/{$offer->number}/pdf");
    }

    public static function car(Car $car): self
    {
        $car->loadMissing('purchase');
        $dl = str_starts_with(mb_strtoupper($car->dl), 'ДЛ') ? $car->dl : 'ДЛ '.$car->dl;

        return new self($car, $dl, $car->purchase->offers_close_at, $car->titleWithYear(), "/zakupki/{$car->purchase->number}/{$car->ref}/pdf");
    }

    public function fields(?User $user): array
    {
        return $this->model instanceof Offer ? Caption::fields($this->model, $user) : Caption::car($this->model, $user);
    }

    /** Шеринг запрещён тумблером в редакторе оффера или машины закупки — до согласования цены менеджера администратором. */
    public function locked(): bool
    {
        return (bool) $this->model->share_locked;
    }

    public function vatMark(): string
    {
        return $this->model instanceof Offer ? Caption::vatMark($this->model) : '';
    }

    public function fileName(): string
    {
        $name = trim(preg_replace('~[\\\\/:*?"<>|%\x00-\x1F]+~u', ' ', $this->label.' '.$this->title));

        return (preg_replace('~\s+~u', ' ', $name) ?: 'xcar').'.pdf';
    }

    /** Каталог кэша: по таблице и id — офферы и машины не пересекаются. */
    public function cacheDir(): string
    {
        return 'share/'.$this->model->getTable().'/'.$this->model->getKey();
    }
}
