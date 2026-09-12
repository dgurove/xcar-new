<?php

namespace App\Http\Site;

use App\Offers\Offer;
use App\Park\Vehicle;
use App\Users\Section;
use App\Users\User;
use App\Workflow\Requirement;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Файлы с закрытого диска — одной дверью: документы предложения (ПТС,
 * договоры), документы машины на стоянке, файлы просьб по сделке. Кто видит —
 * решает владелец файла; остальным 404, чтобы не подсказывать, что он есть.
 */
final class FileController
{
    public function show(Request $request, Media $media)
    {
        abort_unless($media->disk === 'private' && $this->allowed($request->user(), $media), 404);
        $inline = (str_starts_with((string) $media->mime_type, 'image/') && $media->mime_type !== 'image/svg+xml') || $media->mime_type === 'application/pdf';

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($media->file_name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function allowed(User $user, Media $media): bool
    {
        if ($user->isStaff()) {
            return true;
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
