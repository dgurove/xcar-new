<?php

namespace App\Http\Logistics;

use App\Live\Stream;
use App\Logistics\Actions\Call;
use App\Logistics\Actions\Deliver;
use App\Logistics\Actions\Docs;
use App\Logistics\Actions\PickUp;
use App\Logistics\FileAccess;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Media\Actions\RotatePhoto;
use App\Media\PhotoIngest;
use App\Users\User;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Страница задачи для исполнителя без входа (18.10.2026, владелец: «сразу»): логист отправляет ссылку в WhatsApp, водитель
 * открывает её без приложения и пароля — машина, откуда и контакт, куда, «Созвонились», «Получил документы», «Забрал» с
 * фото и ключами, «Привёз». Действует от имени исполнителя задачи: те же действия и те же блокировки, что в приложении.
 * Ссылка своя у исполнителя (`Transport::trip_code`): сменили исполнителя или отменили задачу — прежняя отвечает 410.
 */
class TripController
{
    public function show(string $code)
    {
        $t = $this->task($code);
        $t->load(['car.media', 'executor', 'logist', 'offer', 'vehicle']);

        return view('logistics.trip.show', ['t' => $t, 'by' => $t->executor, 'code' => $code]);
    }

    /** Фото или документ машины, что исполнителю открыли глазом (`FileAccess::forExecutor`); ссылка погасла — 410. */
    public function file(string $code, Media $media)
    {
        $t = $this->task($code);
        abort_unless(FileAccess::belongs($t, $media) && FileAccess::forExecutor($t, $media), 404);
        $inline = str_starts_with((string) $media->mime_type, 'image/') && $media->mime_type !== 'image/svg+xml' || $media->mime_type === 'application/pdf';

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($media->file_name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function call(Request $request, string $code, Call $call)
    {
        $t = $this->open($code);
        $call->agree($t, $request->validate(Call::rules(), Call::messages()), $t->executor);

        return back()->with('toast', 'Созвонились');
    }

    public function missed(string $code, Call $call)
    {
        $t = $this->open($code);
        $call->missed($t, $t->executor);

        return back()->with('toast', 'Не дозвонились');
    }

    public function received(string $code, Docs $docs)
    {
        $t = $this->open($code);
        $docs->receive($t, $t->executor);

        return back()->with('toast', 'Документы у вас');
    }

    public function pickup(Request $request, string $code, PickUp $pickUp)
    {
        $t = $this->open($code);
        $pickUp($t, $t->isPickup() ? $request->validate(PickUp::rules(), PickUp::messages()) : [], $t->executor);

        return back()->with('toast', 'Забрали: '.$t->title());
    }

    public function deliver(string $code, Deliver $deliver)
    {
        $t = $this->open($code);
        $deliver($t, $t->executor);

        return back()->with('toast', 'Привезли: '.$t->title());
    }

    /** Кадры — как в приложении (`TransportController::upload`), пока задача открыта. */
    public function upload(Request $request, string $code, PhotoIngest $ingest)
    {
        $t = $this->open($code);
        $request->validate(['file' => ['required', 'file', 'max:65536']]);
        $at = $request->input('stage') === 'delivery' ? 'delivery' : 'pickup';
        try {
            $ingest->fromPhone($t->car, 'photos', $request, properties: ['stage' => 'pickup', 'source' => 'app', 'transport' => $t->id, 'at' => $at, 'hidden' => true]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->photos($t, $at, $code);
    }

    public function order(Request $request, string $code)
    {
        $t = $this->open($code);
        $ids = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']])['order'];
        Media::setNewOrder(array_values(array_intersect($ids, $t->photos()->pluck('id')->all())));

        return $this->photos($t, $request->input('stage') === 'delivery' ? 'delivery' : 'pickup', $code);
    }

    public function rotate(Request $request, string $code, Media $media, RotatePhoto $rotate)
    {
        $t = $this->open($code);
        abort_unless($this->owns($t, $media), 404);
        $rotate($media, RotatePhoto::turns($request));

        return $this->photos($t, (string) $media->getCustomProperty('at'), $code);
    }

    /** Удалить кадр — пока машину не забрали. */
    public function destroyMedia(string $code, Media $media)
    {
        $t = $this->open($code);
        abort_unless($this->owns($t, $media) && ! $t->state->picked(), 404);
        $at = (string) $media->getCustomProperty('at');
        $media->delete();

        return $this->photos($t, $at, $code);
    }

    private function photos(Transport $t, string $at, string $code)
    {
        $t->unsetRelation('car');

        return Stream::view('logistics.transports.photos-stream', ['t' => $t, 'at' => $at, 'url' => '/trip/'.$code.'/media']);
    }

    private function owns(Transport $t, Media $media): bool
    {
        return $media->model_type === $t->car?->getMorphClass() && $media->model_id === $t->car_id
            && (int) $media->getCustomProperty('transport') === $t->id;
    }

    /** Задача по коду: нет такой — ссылка больше не действует. */
    private function task(string $code): Transport
    {
        $t = strlen($code) >= 16 ? Transport::where('trip_code', $code)->first() : null;
        if (! $t || ! $t->executor instanceof User) {
            abort(response()->view('logistics.trip.gone', [], 410));
        }

        return $t;
    }

    /** Действовать — только по открытой задаче. */
    private function open(string $code): Transport
    {
        $t = $this->task($code);
        abort_if(in_array($t->state, [TransportState::Delivered, TransportState::Cancelled], true), 410);

        return $t;
    }
}
