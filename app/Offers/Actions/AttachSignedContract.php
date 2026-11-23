<?php

namespace App\Offers\Actions;

use App\Live\RefreshDeal;
use App\Media\PhotoIngest;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Подписанный договор, сделанный вне системы (15.11.2026, владелец: «часто договор формируется вручную вне системы —
 * ОБЯЗАТЕЛЬНО возможность и у менеджера в сделке, и у сотрудников в CRM прикрепить подписанный договор, сразу, без
 * необходимости указывать покупателя»; 735163/2026 стояла на «Оплате поставщику», и приложить было некуда — договор
 * принимала только просьба шага «Подписание договора»). Одна дверь сайта (`Cabinet\DealController::signed`) и CRM
 * (`Admin\DealController::signed`): файл — в сделку (`signed_contract`), первый — отметка «договор подписан»
 * (`MarkContractSigned`: покупателя и его данных не просим, стоит на шаге договора — проходит его). Дошла сделка до шага
 * договора потом — файлы встают в его просьбу сами (`Requirement::askFor`).
 */
final class AttachSignedContract
{
    public function __construct(private PhotoIngest $ingest, private MarkContractSigned $mark) {}

    /** Приложить можно всегда, кроме отменённой и аукциона (у аукциона договора с менеджером нет). */
    public static function allows(?Deal $deal): bool
    {
        return $deal !== null && $deal->state !== DealState::Cancelled && ! $deal->isAuction();
    }

    public function __invoke(Deal $deal, UploadedFile $file, User $by): Media
    {
        if (! self::allows($deal)) {
            throw ValidationException::withMessages(['file' => 'К этой сделке договор не приложить']);
        }
        $media = self::store($this->ingest, $deal, $file);
        Offer::find($deal->offer_id)?->log(OfferEventType::Note, $by, ['text' => 'Подписанный договор приложил '.$by->shortName()]);
        if (MarkContractSigned::allows($deal)) {
            ($this->mark)($deal, $by);
            // Отметку ставит свежая строка под замком — эта копия о ней не знает, и второй файл той же отправки снова звал отметку
            // и падал «Отметить договор подписанным сейчас нельзя» (24.11.2026, сделка 351: четыре страницы договора разом).
            $deal->refresh();
        }
        RefreshDeal::of($deal);

        return $media;
    }

    /** Снимок — через `PhotoIngest` (HEIC — в JPEG, размер), остальное — как есть с чистым именем, как файл шага. */
    private static function store(PhotoIngest $ingest, Deal $deal, UploadedFile $file): Media
    {
        if (str_starts_with((string) $file->getMimeType(), 'image/')) {
            // Имя снимка — то, с каким его загрузили («договор стр2»), а не случайное имя файла на диске (`DocName`).
            $title = mb_substr(trim(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), 0, \App\Media\DocName::MAX);

            return $ingest->fromUpload($deal, 'signed_contract', $file, $title !== '' ? ['title' => $title] : []);
        }
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return $deal->addMedia($file)->usingFileName((trim(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $name), '-.') ?: 'dogovor').'.'.strtolower($file->getClientOriginalExtension()))
            ->toMediaCollection('signed_contract');
    }

    /** Убрать файл: отметку «подписан» не снимает (шаги уже пройдены). */
    public static function remove(Deal $deal, Media $media, User $by): void
    {
        abort_unless($media->model_type === Deal::class && (int) $media->model_id === $deal->id && $media->collection_name === 'signed_contract', 404);
        $media->delete();
        Offer::find($deal->offer_id)?->log(OfferEventType::Note, $by, ['text' => 'Файл подписанного договора убрал '.$by->shortName()]);
        RefreshDeal::of($deal);
    }
}
