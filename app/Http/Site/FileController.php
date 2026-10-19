<?php

namespace App\Http\Site;

use App\Billing\Party;
use App\Cars\Car;
use App\Logistics\FileAccess;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Media\ForManagers;
use App\Media\PhotoIngest;
use App\Media\Thumb;
use App\Park\Vehicle;
use App\Support\InternalRefs;
use App\Support\OfficePreview;
use App\Support\Surface;
use App\Users\Section;
use App\Users\User;
use App\Workflow\Requirement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Файлы с закрытого диска — одной дверью: документы предложения (ПТС,
 * договоры), документы машины на стоянке, файлы просьб по сделке. Кто видит —
 * решает владелец файла; остальным 404, чтобы не подсказывать, что он есть.
 */
final class FileController
{
    public function show(Request $request, Media $media, PhotoIngest $photos)
    {
        abort_unless($media->disk === 'private' && $this->allowed($request->user(), $media), 404);
        if ($request->boolean('preview')) {
            return OfficePreview::response($media->getPath(), $media->file_name);
        }
        // HEIC с айфона для шторки документов: Chrome его не рисует — JPEG, посчитанный раз (cache, storage:gc).
        if ($request->boolean('jpeg') && preg_match('/\.hei[cf]$/i', $media->file_name)) {
            $jpeg = Storage::disk('cache')->path("files/jpeg-{$media->id}.jpg");
            if (! is_file($jpeg)) {
                @mkdir(dirname($jpeg), 0775, true);
                try {
                    rename($photos->toJpeg($media->getPath()), $jpeg);
                } catch (\Throwable) {
                }
            }
            if (is_file($jpeg)) {
                return response()->file($jpeg, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
            }
        }
        $image = (str_starts_with((string) $media->mime_type, 'image/') && $media->mime_type !== 'image/svg+xml') || preg_match('/\.hei[cf]$/i', $media->file_name) === 1;
        $pdf = $media->mime_type === 'application/pdf';
        // ?thumb — плитка документа в «✨ Распознать»: первая страница PDF или ужатая картинка, раз и в cache/mail.
        if ($request->boolean('thumb') && ($image || $pdf)) {
            $small = Thumb::of($media->getPath(), $pdf, "thumb-m{$media->id}");
            abort_unless($small, 404);

            return response()->file($small, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=86400']);
        }
        $inline = (str_starts_with((string) $media->mime_type, 'image/') && $media->mime_type !== 'image/svg+xml') || $pdf;

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode(Surface::current() === Surface::Site ? InternalRefs::fileName($media->file_name, InternalRefs::refsOf($media->model)) : $media->file_name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function allowed(User $user, Media $media): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        // Файл машины (`Cars\Car`) — её продажи и её хранения.
        $car = $media->model_type === Car::class ? Car::find($media->model_id) : null;
        $offer = $car?->offer;
        // Логистика (19.10.2026): файл машины задачи вывоза — ведущим, логисту и исполнителю, что ему открыт глазом
        // (`Logistics\FileAccess`). До модератора: Катя и Лиза ведут вывоз и машин, что не правят в CRM.
        // Привезённая и отменённая задача логисту и исполнителю доступа не даёт (`FileAccess::live`): отметки глаза — у файла
        // машины, и исполнитель прошлого отрезка иначе видел бы открытое следующему.
        if ($car && Transport::where('car_id', $car->id)->where('state', '!=', TransportState::Cancelled)->get()->contains(fn (Transport $t) => FileAccess::belongs($t, $media) && FileAccess::visible($t, $user, $media))) {
            return true;
        }
        // Модератору — документы и фото только тех предложений, что ему можно открыть в CRM.
        if ($user->isModerator()) {
            return (bool) $offer?->isEditableBy($user);
        }
        // Файлы ТС — менеджеру, который с ней работает как продавец (гараж, сделка); документы — только отмеченные админом.
        // Исполнитель вывоза — только через `FileAccess` выше (19.10.2026: «Видно менеджеру» и скрытые кадры ему не открывать).
        $works = fn () => $offer && $offer->managedBy($user) && ($media->collection_name !== 'papers' || ForManagers::is($media));

        return match ($media->model_type) {
            // На парковке — ТС, которую человек видит: у управляющего с городом область `Park\City` прячет чужие.
            Car::class => $works() || ($user->canAccess(Section::Park) && Vehicle::where('car_id', $car?->id)->exists()),
            // Файл просьбы — тому, кого просили.
            Requirement::class => Requirement::whereKey($media->model_id)->where('user_id', $user->id)->exists(),
            // Скан паспорта — самому человеку и его менеджеру.
            Party::class => (bool) Party::find($media->model_id)?->managedBy($user),
            default => false,
        };
    }
}
