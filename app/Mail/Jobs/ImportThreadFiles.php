<?php

namespace App\Mail\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Actions\PinThread;
use App\Mail\Attachment;
use App\Mail\Extraction\AttachmentImporter;
use App\Mail\Extraction\CloudShare;
use App\Mail\Extraction\Intent;
use App\Mail\Scan\AutoScan;
use App\Mail\Thread;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\PhotoStage;
use App\Park\Vehicle;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ветка привязана к машине — её файлы в медиатеке этой машины. Одна джоба на
 * все случаи: привязка сама по коду или VIN, руками, «Завести» из кандидата,
 * новое письмо в уже привязанной ветке. Сначала закрепить вложения в blobs
 * (письма страховых — по 30 кадров, из ящика это минуты), потом разобрать:
 * документы в `papers`, фото и архивы в `photos`. Что уже лежит — по
 * отпечатку `sha` — не дублируется. У предложения с ТС парковки файлы — у ТС
 * (машина одна). В оффер, который уже не черновик, кадры ложатся скрытыми: что
 * на сайте — решает сотрудник глазом в полосе.
 */
final class ImportThreadFiles implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Ход до того, как работник взял задачу (`attachNow`). */
    public const QUEUED = 'Прикрепляем фото';

    public int $timeout = 1500;

    public int $tries = 2;

    /** Замок «эта ветка уже стоит» — не дольше задачи: не поставленная не держит его вечно. */
    public int $uniqueFor = 1800;

    /** @param ?int $messageId только одно письмо (пришло в привязанную ветку); null — вся ветка */
    public function __construct(public int $threadId, public ?int $messageId = null)
    {
        $this->onConnection('database-long')->onQueue('files')->afterCommit();
    }

    /** Замок на ветку и письмо: ждущая джоба одного письма не должна глотать джобу следующего. */
    public function uniqueId(): string
    {
        return $this->threadId.':'.($this->messageId ?? 'all');
    }

    public function handle(PinThread $pin, AttachmentImporter $importer, Publisher $publish): void
    {
        $thread = Thread::find($this->threadId);
        [$model, $vehicle, $offer, $car] = self::target($thread);
        if (! $model) {
            return;
        }
        $key = self::keyFor($model);
        // Сколько всего — из прошлого шага, пока свой счёт не начался: заглушки в ряду фото не мигают.
        $report = function (string $stage, ?int $i = null, ?int $n = null) use ($key, $offer) {
            $was = Cache::get($key) ?? [];
            Cache::put($key, ['stage' => $stage, 'i' => $i ?? ($was['i'] ?? 0), 'n' => $n ?? ($was['n'] ?? null)], 1800);
            // Черновик из письма — строка списка видит ход фото («Забираем фото, 3 из 23»).
            $offer && AutoScan::photoArrived($offer);
        };
        try {
            $report('Забираем файлы из ящика');
            $pin($thread);
            $report('Читаем письмо');
            $attachments = $importer->attachmentsOf($this->messageId, $this->messageId ? null : $thread->id);
            $hidden = $offer && $offer->state !== OfferState::Draft ? ['hidden' => true] : [];
            $properties = $vehicle ? fn (Attachment $a) => self::stageOf($a) + $hidden : $hidden;
            $added = $importer->import($model, $attachments, 'photos', 'papers', $properties, $report);
            if ($offer) {
                $cloud = self::cloud($thread, $offer, $importer, $hidden, $report);
                $added = ['photos' => $added['photos'] + $cloud['photos'], 'documents' => $added['documents'] + $cloud['documents']];
            }
        } finally {
            Cache::forget($key);
            if ($offer) {
                // Черновик завела почта — файлы всех веток доехали, теперь документы читаются сами (`AutoScan`).
                AutoScan::filesArrived($offer);
            }
        }
        if ($offer) {
            OfferStateChanged::dispatch($offer->fresh());
        }
        if ($car) {
            $publish->refresh(Topics::PARK, ["/cars/{$car->id}"]);
        }
        if ($added['photos'] || $added['documents']) {
            $what = 'Из письма: '.implode(', ', array_filter([$added['photos'] ? "фото: {$added['photos']}" : null, $added['documents'] ? "документов: {$added['documents']}" : null]));
            $offer && $publish->toast(Topics::STAFF, $what, "/offers/{$offer->number}", 'photo');
            $car && $publish->toast(Topics::PARK, $what, "/cars/{$car->id}", 'photo');
        }
    }

    /**
     * «Фото и документы по ссылке» (12.10.2026, Инсайт: облако Nextcloud в письме вместо вложений) — ссылки на облако из
     * писем вендора в ветке скачиваются тем же `CloudShare`, что ответы по сделке, и кладутся к предложению: кадры в фото,
     * остальное в документы. Каждая ссылка — один раз на предложение; не скачалась — запись в «Истории», без повтора.
     *
     * @return array{photos: int, documents: int}
     */
    private static function cloud(Thread $thread, Offer $offer, AttachmentImporter $importer, array $photos, callable $report): array
    {
        $added = ['photos' => 0, 'documents' => 0];
        $links = $thread->messages()->get()->reject(fn ($m) => $m->isOurs())
            ->flatMap(fn ($m) => CloudShare::links($m->ownText()."\n".$m->replyText(marks: true)))->unique('token');
        foreach ($links as $link) {
            if (! Cache::add('cloud-import:'.$offer->id.':'.$link['token'], 1, now()->addDays(60))) {
                continue;
            }
            $path = sys_get_temp_dir().'/cloud-'.Str::uuid();
            try {
                $report('Забираем файлы по ссылке из письма');
                $name = app(CloudShare::class)->download($link, $path);
                $got = str_ends_with(mb_strtolower($name), '.zip')
                    ? $importer->importArchive($offer, $path, 'photos', 'papers', $report, [], 'mail', $photos)
                    : ['documents' => (int) $importer->importDocument($offer, 'papers', $name, (string) file_get_contents($path), 'mail'), 'photos' => 0];
                $added = ['photos' => $added['photos'] + ($got['photos'] ?? 0), 'documents' => $added['documents'] + ($got['documents'] ?? 0)];
                $offer->log(OfferEventType::Note, null, ['text' => 'Файлы по ссылке из письма: '.($got === null ? 'архив не открылся' : 'фото '.$got['photos'].', документов '.$got['documents'])]);
            } catch (Throwable $e) {
                Log::warning('Облако из письма не скачалось', ['offer' => $offer->id, 'url' => $link['url'], 'error' => $e->getMessage()]);
                $offer->log(OfferEventType::Note, null, ['text' => 'Файлы по ссылке из письма не скачались: '.$e->getMessage()]);
            } finally {
                @unlink($path);
            }
        }

        return $added;
    }

    /**
     * Чей кадр: письмо страховой — «от страховой», наше — «при приёме», а наш отчёт о выдаче — «при выдаче».
     * `source` отличает приехавшее из письма от снятого в приложении: при выдаче первое стирается, второе остаётся.
     */
    private static function stageOf(Attachment $attachment): array
    {
        $message = $attachment->message;
        $stage = match (true) {
            ! $message?->isOurs() => PhotoStage::Vendor,
            $message->intent === Intent::Released->value => PhotoStage::Release,
            default => PhotoStage::Intake,
        };

        return ['stage' => $stage->value, 'source' => 'mail'];
    }

    public static function progress(int $offerId): ?array
    {
        return Cache::get(self::key($offerId));
    }

    /** Ключ хода разбора — его же пишет разбор архива, брошенного руками (ImportOfferArchive): пилюля в редакторе одна. */
    public static function key(int $offerId): string
    {
        return "import:offer:{$offerId}";
    }

    /** Ход разбора у машины: предложение — тот же ключ, что у пилюли редактора, ТС — свой. */
    public static function keyFor(Offer|Vehicle $model): string
    {
        return $model instanceof Offer ? self::key($model->id) : "import:vehicle:{$model->id}";
    }

    /** Что ещё прикрепляется: `{stage, i, n}` или null, если ничего. */
    public static function progressOf(Offer|Vehicle $model): ?array
    {
        return Cache::get(self::keyFor($model));
    }

    /**
     * Ждёт ли в очереди задача файлов другой ветки предложения. Ход (`progressOf`) один на предложение, а задача — на
     * ветку: первая кончившаяся стирала ход, и чтение черновика из письма начиналось до архива второй ветки. Смотрим
     * саму очередь (не взятые задачи — взятая и есть та, что сейчас кончается): счётчик сбивался бы на уникальности
     * задачи, вторая на ту же ветку не встаёт.
     */
    public static function queued(Offer $offer): bool
    {
        $threads = Thread::where('offer_id', $offer->id)->pluck('id');
        if ($threads->isEmpty()) {
            return false;
        }
        $conn = config('queue.connections.database-long');

        // В теле задачи — сериализованная команда в JSON: «threadId\";i:123;» (между именем и значением — два знака).
        return DB::connection($conn['connection'] ?? null)->table($conn['table'] ?? 'jobs')
            ->where('queue', 'files')->whereNull('reserved_at')
            ->where(fn ($q) => $threads->each(fn ($id) => $q->orWhere('payload', 'like', '%threadId__;i:'.$id.';%')))
            ->exists();
    }

    /** Заглушек «прикрепляется» в ряду фото: сколько кадров осталось, не больше восьми — остальное скажет строка хода. */
    public static function pending(Offer|Vehicle $model): int
    {
        $p = self::progressOf($model);

        return ($p['n'] ?? null) ? max(0, min(8, $p['n'] - $p['i'])) : 0;
    }

    /**
     * Куда ветка кладёт файлы: машина одна. Ветка ящика парковки — в ТС со стадией кадра; ветка предложения — в
     * предложение, а `SaleMedia` кладёт файл к его ТС («от страховой»), если она есть. Ветка с ТС и чужим предложением
     * — к предложению. @return array{0: Offer|Vehicle|null, 1: ?Vehicle, 2: ?Offer, 3: ?Vehicle}
     */
    public static function target(?Thread $thread): array
    {
        $vehicle = $thread?->vehicle;
        $offer = $thread?->offer ?? $vehicle?->offer;
        if ($vehicle && $thread->offer_id && $thread->offer_id !== $vehicle->offer_id) {
            $vehicle = null;
        }
        $model = $vehicle ?? $offer;

        return [$model, $vehicle, $offer, $vehicle ?? $offer?->parkVehicle];
    }

    /**
     * Ветку привязали (`LinkThread`): документы, что уже лежат у нас, — сразу, в этом же запросе, человек открывает
     * редактор с ними. Фото (знак, обрезка, сжатие — ~1,5 с на кадр) — задачей своего работника `queue-files`, а пока
     * она идёт, ряд фото показывает заглушки и строку хода (`progressOf`).
     */
    public static function attachNow(Thread $thread): void
    {
        [$model] = self::target($thread->fresh());
        if (! $model) {
            return;
        }
        $importer = app(AttachmentImporter::class);
        $attachments = $importer->attachmentsOf(null, $thread->id);
        try {
            $importer->importDocuments($model, $attachments, 'papers');
        } catch (Throwable $e) {
            Log::warning('Документы письма не прикрепились сразу', ['thread' => $thread->id, 'error' => $e->getMessage()]);
        }
        if ($n = $importer->photosToCome($attachments)) {
            Cache::put(self::keyFor($model), ['stage' => self::QUEUED, 'i' => 0, 'n' => $n], 1800);
        }
        self::dispatch($thread->id);
    }
}
