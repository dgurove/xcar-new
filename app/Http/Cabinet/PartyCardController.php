<?php

namespace App\Http\Cabinet;

use App\Billing\Party;
use App\Media\PhotoIngest;
use App\Offers\Actions\SyncDealInvoices;
use Illuminate\Http\Request;

/**
 * Карточка организации файлами (27.11.2026, менеджеры xcar и сотрудники CRM: «как с паспортом — просто приложить файлы с карточкой
 * организации с реквизитами, хоть pdf, хоть docx, хоть фотки, несколько»): у покупателя-ИП и организации — коллекция `card`
 * контрагента. Приложили — поля реквизитов не обязательны (`PartyRules::passport(files:)`), вносим мы во вкладке «Проверить
 * реквизиты» подгруппой «Заполнить из файлов» (`Party::cardOnly`, `DealTabs`). Распознавания пока нет. Доступ — как у скана паспорта.
 * Ответ — заново нарисованный блок файлов (`x-billing.card-files`), его ставит `card_files_controller`.
 */
class PartyCardController
{
    /** Фото (и HEIC с айфона), PDF и Word — до 30 МБ каждый. */
    public const FILE = ['file', 'max:30720', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip,application/octet-stream'];

    public function store(Request $request, Party $party, PhotoIngest $ingest, PassportScanController $scan, SyncDealInvoices $invoices)
    {
        $me = $request->user();
        abort_unless(PassportScanController::staff($me, $party) || $party->managedBy($me), 404);
        $request->validate(['files' => ['required', 'array', 'max:20'], 'files.*' => self::FILE],
            ['files.required' => 'Выберите файлы', 'files.*.max' => 'Файл больше 30 МБ', 'files.*.mimetypes' => 'Нужен PDF, Word или фото']);
        foreach ($request->file('files') as $file) {
            str_starts_with((string) $file->getMimeType(), 'image/')
                ? $ingest->fromUpload($party, 'card', $file, ['by' => $me->id])
                : $party->addMedia($file)->usingFileName(self::safeName($file->getClientOriginalName()))->withCustomProperties(['by' => $me->id])->toMediaCollection('card');
        }
        $scan->resync($party->fresh(), $me, $invoices);

        return $this->block($request, $party->fresh());
    }

    public function destroy(Request $request, Party $party, int $media, PassportScanController $scan, SyncDealInvoices $invoices)
    {
        $me = $request->user();
        abort_unless(PassportScanController::staff($me, $party) || $party->managedBy($me), 404);
        $party->getMedia('card')->firstWhere('id', $media)?->delete() ?? abort(404);
        $scan->resync($party->fresh(), $me, $invoices);

        return $this->block($request, $party->fresh());
    }

    private function block(Request $request, Party $party)
    {
        return response(view('components.billing.card-files', ['party' => $party, 'key' => (string) $request->input('key', 'card')])->render());
    }

    private static function safeName(string $name): string
    {
        $name = preg_replace('/[^\pL\pN._ -]+/u', '', $name) ?: 'file';

        return mb_substr($name, -120);
    }
}
