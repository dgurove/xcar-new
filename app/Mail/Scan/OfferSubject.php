<?php

namespace App\Mail\Scan;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Account;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Actions\ApplyCarFields;
use App\Offers\Offer;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;

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

    /** Выбранное — в карточку по правилам формы (`ApplyCarFields`): характеристики — только в пустые. */
    public function apply(array $chosen, User $by): void
    {
        app(ApplyCarFields::class)($this->offer, $chosen, $by);
    }

    public function refresh(): void
    {
        app(Publisher::class)->refresh(Topics::STAFF, ['/offers/'.$this->offer->number]);
    }
}
