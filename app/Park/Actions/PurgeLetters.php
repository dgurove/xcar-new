<?php

namespace App\Park\Actions;

use App\Mail\Actions\FreezeMessages;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Mail\Thread;
use App\Park\Vehicle;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * ТС выдана: то, что пришло из писем, больше не хранится — кадры и документы из писем в галерее и бумагах ТС
 * стираются, письма её веток замораживаются (`FreezeMessages`), кадр кандидата снимается. Снятое в приложении
 * остаётся: в ящике его нет. Кадры ТС с предложением не стираются — это фото продажи. При возврате выдачи `ImportThreadFiles` принесёт стёртое из ящика снова.
 */
final class PurgeLetters
{
    public function __construct(private FreezeMessages $freeze) {}

    public function __invoke(Vehicle $vehicle): int
    {
        foreach (self::media($vehicle) as $media) {
            $media->delete();
        }
        $threads = Thread::where('vehicle_id', $vehicle->id)->park()->pluck('id');

        return $threads->isEmpty() ? 0 : $this->freeze->freeze(Message::whereIn('thread_id', $threads)->pluck('id')->all());
    }

    /**
     * Что стирается у выданной: файлы из писем парковки (`park`) — пришедшие письмами CRM в ящике парковки не лежат, `undo`
     * их не вернёт; кадры — только если машина не в продаже (на них стоят предложение, сделка и гараж). Им же письмо о
     * выдаче не берёт эти кадры: очистка идёт фоном и могла стереть файл посреди сборки письма.
     *
     * @return Collection<int, Media>
     */
    public static function media(Vehicle $vehicle): Collection
    {
        $letters = $vehicle->media()->where('custom_properties->source', 'mail')->where('custom_properties->park', true)
            ->whereIn('collection_name', $vehicle->offer()->exists() ? ['papers'] : ['photos', 'papers']);

        return $letters->get();
    }

    /** Выдачу вернули: письма читаются заново, файлы и кадры едут в дело из ящика. */
    public function undo(Vehicle $vehicle): void
    {
        $threads = Thread::where('vehicle_id', $vehicle->id)->park()->pluck('id');
        if ($threads->isEmpty()) {
            return;
        }
        $this->freeze->thaw(Message::whereIn('thread_id', $threads)->whereNotNull('frozen_at')->pluck('id')->all());
        foreach ($threads as $id) {
            ImportThreadFiles::dispatch($id);
        }
    }
}
