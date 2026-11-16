<?php

namespace App\Offers;

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Договор от страховой при «страховой напрямую» (16.11.2026, владелец: «иногда они его нам по почте отправляют — тогда надо
 * отображать, чтобы менеджеры и сотрудники могли распечатать; иногда отправляют на подписание и прикрепляют уже подписанный —
 * тогда только прикладывание подписанного»). Свой договор у этой схемы мы не делаем (`Deal::hasContract`), поэтому строка
 * «Договор от страховой» в блоке «Поставщику» «Продажи» — над «Подписанным договором»: файл из письма страховой с «договор» /
 * «ДКП» в имени (`detect`, из `ShareReplyFiles`) или приложенный руками (`attach`). Открыт менеджеру (`managers`), как счёт
 * поставщика: договор покупатель подписывает сам.
 */
final class SupplierContract
{
    public const PROP = 'supplier_contract';

    private const NAME = '/догов|дкп|dkp|dogovor/ui';

    /** Строка нужна: покупатель платит страховой сам по её договору. */
    public static function applies(Deal $deal): bool
    {
        return $deal->schemeOf() === DealScheme::Insurer;
    }

    public static function of(Offer $offer): ?Media
    {
        return $offer->getMedia('papers')->filter(fn (Media $m) => $m->getCustomProperty(self::PROP))->sortByDesc('id')->first();
    }

    /** Письмо страховой по идущей сделке «страховой напрямую» принесло договор — отметить. */
    public static function detect(Offer $offer, int $messageId): void
    {
        $deal = $offer->deal()->first();
        if (! $deal || ! self::applies($deal)) {
            return;
        }
        $offer->getMedia('papers')
            ->filter(fn (Media $m) => (int) $m->getCustomProperty('letter') === $messageId && ! $m->getCustomProperty(SupplierInvoice::PROP)
                && preg_match(self::NAME, $m->file_name.' '.$m->name))
            ->each(fn (Media $m) => self::mark($m));
    }

    public static function attach(Offer $offer, UploadedFile $file): Media
    {
        // Как документ из «Показать менеджеру» (`DisclosureController::invoice`): безопасное имя и отпечаток.
        $media = $offer->addMedia($file)->usingFileName(\App\Http\Admin\OfferPhotoController::safeName($file->getClientOriginalName()))
            ->usingName('Договор от страховой')->withCustomProperties(['sha' => hash_file('sha256', $file->getRealPath())])->toMediaCollection('papers');
        self::mark($media);

        return $media;
    }

    public static function mark(Media $m): void
    {
        $m->setCustomProperty(self::PROP, true)->setCustomProperty('managers', true)->save();
    }
}
