<?php

namespace App\Http\Site;

use App\Media\PhotoIngest;
use App\Media\Thumb;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Support\OfficePreview;
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
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($media->file_name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function allowed(User $user, Media $media): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        // Модератору — документы и фото только тех предложений, что ему можно открыть в CRM.
        if ($user->isModerator()) {
            return $media->model_type === Offer::class && Offer::find($media->model_id)?->isEditableBy($user);
        }

        return match ($media->model_type) {
            // Документы предложения — покупателю по сделке.
            Offer::class => Offer::whereKey($media->model_id)->whereHas('deal', fn ($q) => $q->where('buyer_id', $user->id))->exists(),
            // Файл просьбы — тому, кого просили.
            Requirement::class => Requirement::whereKey($media->model_id)->where('user_id', $user->id)->exists(),
            Vehicle::class => $user->canAccess(Section::Park),
            default => false,
        };
    }
}
