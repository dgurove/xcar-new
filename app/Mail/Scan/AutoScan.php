<?php

namespace App\Mail\Scan;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Jobs\ScanAttachments;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Actions\AutoSale;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Фоновое чтение ТС, которую завела почта (`Park\Actions\AutoRequest`, владелец 07.10.2026: «распознание документов и
 * фото должно корректно в фоне происходить»). Тот же `Reader`, что у читалки «Завести», только поля вписывает сервер:
 * пустое поле с одним вариантом — как браузер в форме (`reader_controller` → `fill`), спорное и другое значение не
 * трогаются — их решает человек строками «в документе иначе» в деле. Пока метка стоит, строка заявки показывает ход
 * (`state`).
 *
 * Владелец 08.10.2026: «чтобы заявки автоматически полностью распознавались, фотки не OCRить, только все вложенные
 * документы». Поэтому:
 * - только документы, фото не читаются (их читает человек искрой шторки);
 * - пустое поле берёт первый вариант при любом их числе, как отмечено по умолчанию в окне «Распознать»;
 * - итог подводит любая кончившаяся задача с файлами ТС (`settle`), а не только своя: файлы бывает читает цепочка
 *   «Из писем» или другой предмет;
 * - часы парковки дочитывают недочитанное (`sweep`): задачу убила выкладка, документ пришёл позже заведения.
 *
 * С 12.10.2026 так же черновик предложения, который завела почта (`Offers\Actions\AutoOffer`, владелец: «чтобы письма
 * сами превращались в черновики, и строки появлялись с анимацией, как заявки на парковке»): предмет `o:{id}`. Сначала
 * едут файлы письма (`ImportThreadFiles`: архивы, облако — документы бывают только там), строка — «Забираем фото»;
 * задача файлов кончилась — читаем документы (`filesArrived`), итог вписывает пустые поля через `UpdateOffer`.
 */
final class AutoScan
{
    /** Метка живёт дольше любой задачи чтения: упавшая её снимает (`abandon`), забытая гаснет сама. */
    private const TTL = 7200;

    /** Предметы, ждущие итога: `v:12` / `o:34` → когда начали. По нему `settle` и `sweep` находят их без перебора. */
    private const PENDING = 'scan:auto:pending';

    /** Ничего не читается, а итога всё нет столько минут — подвести самим: силуэт строки не висит вечно. */
    private const STALE_MINUTES = 20;

    /** Дочитывать документы ТС и черновиков, заведённых из писем не раньше стольких дней назад. */
    private const SWEEP_DAYS = 7;

    /** Не чаще — пинг списку о ходе фото: они ложатся по одному, а морф списка из сотни строк не бесплатный. */
    private const NUDGE_SECONDS = 2;

    /**
     * Метка предмета — ход: `pass` (files — едут файлы письма, docs — читаем документы), `i` прочитано из `n`. Строке
     * списка ход берётся отсюда и из хода файлов (`ImportThreadFiles::progressOf`), без разбора писем на каждую отрисовку.
     *
     * @param  list<string>  $ids  дописать в чтение и эти файлы — фото (`park:find-values --photos`); пусто — документы
     */
    public static function start(Vehicle|Offer $model, array $ids = []): void
    {
        $subject = self::subject($model);
        $files = Reader::files($subject);
        $read = $files->filter(fn (ScanFile $f) => DocumentText::isCached($f))->count();
        Cache::put(self::key($subject->key()), ['pass' => 'docs', 'i' => $read, 'n' => $files->count()], self::TTL);
        self::register($subject->key());
        Reader::read($subject, $ids, background: true);
        // Читать нечего или всё уже в кеше — итог сразу, задачи не будет.
        if (! ScanAttachments::reading(Reader::files($subject))) {
            self::finished($subject);
        }
    }

    /**
     * Черновик завела почта: строка встаёт сразу, а читать нечего, пока файлы едут из ящика (`ImportThreadFiles`). Метка
     * «ждём файлы»; задача файлов кончилась — `filesArrived` ставит чтение.
     */
    public static function await(Offer $offer): void
    {
        Cache::put(self::key('o:'.$offer->id), ['pass' => 'files', 'i' => 0, 'n' => 0], self::TTL);
        self::register('o:'.$offer->id);
    }

    /**
     * Задача файлов ветки кончилась (`ImportThreadFiles::handle`): у черновика из письма — читать документы, когда
     * доехали файлы всех его веток (`ImportThreadFiles::queued`), а не первой.
     */
    public static function filesArrived(Offer|Vehicle|null $model): void
    {
        if ($model instanceof Offer && Cache::get(self::key('o:'.$model->id)) && ! ImportThreadFiles::queued($model)) {
            self::start($model);
        }
    }

    /** Файл прочитан задачей предмета (`ScanAttachments`): ход строки на шаг вперёд. */
    public static function advance(string $subjectKey): void
    {
        $mark = self::mark($subjectKey);
        if (! $mark || $mark['pass'] !== 'docs') {
            return;
        }
        // Список перечитывает событие `scan`, что задача шлёт за каждым файлом (`arrive_controller`), — здесь только счёт.
        Cache::put(self::key($subjectKey), ['i' => min($mark['i'] + 1, max($mark['n'], $mark['i'] + 1))] + $mark, self::TTL);
    }

    /** Фото черновика из письма легло (`ImportThreadFiles`): строке — новый ход, не чаще раза в `NUDGE_SECONDS`. */
    public static function photoArrived(Offer $offer): void
    {
        if (self::mark('o:'.$offer->id)) {
            self::nudge();
        }
    }

    /** Задача чтения кончилась (`ScanAttachments::handle`): вписать найденное и снять метку. */
    public static function finished(Subject $subject): void
    {
        if (! $subject instanceof VehicleSubject && ! $subject instanceof OfferSubject) {
            return;
        }
        $key = $subject->key();
        $mark = self::mark($key);
        if (! $mark) {
            self::unregister($key);

            return;
        }
        $subject = self::subject(self::model($subject)->refresh());
        // Файлы ещё едут — читать будем после них (`filesArrived`). Другая пачка тех же файлов ещё читается — итог подведёт она.
        $offer = $subject instanceof OfferSubject;
        if (($offer && ($mark['pass'] === 'files' || ImportThreadFiles::progressOf($subject->offer) || ImportThreadFiles::queued($subject->offer)))
            || ScanAttachments::reading(Reader::files($subject))) {
            return;
        }
        self::fill($subject);
        Cache::forget(self::key($key));
        self::unregister($key);
        // Минута «только что» — строка проявляется и вспыхивает у того, кто смотрит (`arrive_controller`).
        Cache::put(self::doneKey($key), true, 60);
        self::ping($subject);
        // Вендор «Сразу в продажу» — прочитанная ТС уходит в продажу, если и фото доехали.
        $subject instanceof VehicleSubject && app(AutoSale::class)($subject->vehicle);
    }

    /** Документы предмета ещё читаются сами (метка стоит). */
    public static function busy(Vehicle|Offer $model): bool
    {
        return self::mark(($model instanceof Offer ? 'o:' : 'v:').$model->id) !== null;
    }

    /**
     * Черновик встал в CRM без чтения (`Park\Actions\AutoSale`): минуту строка — `arrived`, и `arrive_controller` вводит её
     * в список, как черновик из письма. Своё состояние, а не `done`: тот бывает и у давно стоящих строк.
     */
    public static function arrived(Offer $offer): void
    {
        Cache::put(self::arrivedKey($offer->id), true, 60);
        app(Publisher::class)->refresh(Topics::STAFF, ['/']);
    }

    /**
     * Кончилась задача с этими файлами (любого предмета): предметы из реестра, чьи файлы в ней были и больше не читаются,
     * — итог. Иначе ТС, чьи файлы при заведении уже читала цепочка «Из писем», ждала бы итога своей задачи, которой нет.
     *
     * @param  list<int|string>  $ids
     */
    public static function settle(array $ids): void
    {
        $ids = array_map('strval', $ids);
        foreach (array_keys(self::pending()) as $key) {
            $subject = Subjects::find($key);
            if (! $subject) {
                self::unregister($key);

                continue;
            }
            if (array_intersect($ids, Reader::files($subject)->map->scanId()->all())) {
                self::finished($subject);
            }
        }
    }

    /**
     * Часы парковки и CRM (`park:tick`, `offers:tick`): у ТС открытой заявки из письма и у черновика из письма за неделю
     * есть непрочитанные документы и ничего не читается — читать (задачу убила выкладка, документ пришёл позже
     * заведения); файл, что не прочёлся, — не раньше чем через сутки (`ScanAttachments::lost`). Ждёт итога давно, а
     * читать нечего — подвести. Возвращает, скольким предметам поставлено чтение.
     */
    public static function sweep(bool $offers = false): int
    {
        $started = 0;
        $models = $offers
            ? Offer::where('state', OfferState::Draft)->whereNull('moderator_id')->where('created_at', '>', now()->subDays(self::SWEEP_DAYS))
                ->whereExists(fn ($t) => $t->from('mail_threads')->whereColumn('mail_threads.offer_id', 'offers.id'))->get()
            : Request::whereNotNull('letter_id')->whereIn('state', RequestState::open())
                ->where('created_at', '>', now()->subDays(self::SWEEP_DAYS))->with('vehicle')->get()
                ->pluck('vehicle')->filter()->unique('id');
        foreach ($models as $model) {
            $subject = self::subject($model);
            // Ничего не пришло с прошлого прохода (ни письма в ветках, ни файла у предмета) — файлы не перебираем: проход по
            // письмам и вложениям сотни ТС и черновиков держал часы 5–8 с. Раз в сутки — всё равно: файл, что не
            // прочёлся, повторяется через сутки (`ScanAttachments::lost`).
            $sig = self::signature($model);
            if (Cache::get(self::sweptKey($subject->key())) === $sig) {
                continue;
            }
            $files = Reader::files($subject);
            if (ScanAttachments::queuedFor($subject) || ScanAttachments::reading($files) || ImportThreadFiles::progressOf($model)
                || ($model instanceof Offer && ImportThreadFiles::queued($model))) {
                continue;
            }
            $unread = $files->filter(fn (ScanFile $f) => ! DocumentText::isCached($f) && DocumentText::scannable($f) && ! ScanAttachments::lost($f->scanId()));
            if ($unread->isNotEmpty()) {
                self::start($model);
                $started++;
            }
            Cache::put(self::sweptKey($subject->key()), $sig, 86400);
        }
        $prefix = $offers ? 'o:' : 'v:';
        foreach (self::pending() as $key => $since) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }
            $subject = Subjects::find($key);
            if (! $subject) {
                self::unregister($key);
            } elseif ($since < now()->subMinutes(self::STALE_MINUTES)->getTimestamp() && ! ScanAttachments::reading(Reader::files($subject))) {
                // Файлы так и не доехали (задачу убила выкладка) — читаем что есть, итог подведёт чтение.
                if ($subject instanceof OfferSubject) {
                    if ((self::mark($key)['pass'] ?? null) === 'files') {
                        self::start($subject->offer);

                        continue;
                    }
                }
                self::finished($subject);
            }
        }

        return $started;
    }

    /** Задача упала или убита по таймауту — строка не должна навсегда остаться силуэтом. */
    public static function abandon(string $subjectKey): void
    {
        if (! str_starts_with($subjectKey, 'v:') && ! str_starts_with($subjectKey, 'o:')) {
            return;
        }
        self::unregister($subjectKey);
        if (Cache::pull(self::key($subjectKey))) {
            str_starts_with($subjectKey, 'v:')
                ? app(Publisher::class)->refresh(Topics::PARK, ['/requests'])
                : app(Publisher::class)->refresh(Topics::STAFF, ['/']);
        }
    }

    /**
     * Ход для строк списка — пачкой, без разбора писем у обычных строк:
     * - `ghost` — завели, ещё ничего не прочитано (файлы едут из ящика, чтение ждёт работника);
     * - `files` — у черновика едут файлы письма, `i` из `n`;
     * - `reading` — читаем, `i` из `n` документов;
     * - `done` — только что закончили.
     *
     * @param  iterable<Vehicle|Offer>  $models
     * @return array<int, array{state: string, i?: int, n?: int, what?: string}>
     */
    public static function states(iterable $models): array
    {
        $models = collect($models)->filter()->keyBy('id');
        if ($models->isEmpty()) {
            return [];
        }
        $prefix = $models->first() instanceof Offer ? 'o:' : 'v:';
        $ids = $models->keys();
        $passes = Cache::many($ids->map(fn ($id) => self::key($prefix.$id))->all());
        $running = Cache::many($ids->map(fn ($id) => "scan:run:{$prefix}{$id}")->all());
        $done = Cache::many($ids->map(fn ($id) => self::doneKey($prefix.$id))->all());
        $arrived = $prefix === 'o:' ? Cache::many($ids->map(fn ($id) => self::arrivedKey($id))->all()) : [];
        $out = [];
        foreach ($ids as $id) {
            if ($pass = $passes[self::key($prefix.$id)] ?? null) {
                $out[$id] = self::progress($models[$id], self::normalize($pass), (bool) ($running["scan:run:{$prefix}{$id}"] ?? false));
            } elseif ($done[self::doneKey($prefix.$id)] ?? null) {
                $out[$id] = ['state' => 'done'];
            } elseif ($arrived[self::arrivedKey($id)] ?? null) {
                $out[$id] = ['state' => 'arrived'];
            }
        }

        return $out;
    }

    /**
     * Ход строки — из метки и хода файлов, без разбора писем (у черновиков из письма их на странице десятки):
     * - `files` — едут фото, `i` из `n`;
     * - `ghost` — ждём: `what` = files (файлы в очереди за чужими) или docs (работник читает чужое);
     * - `reading` — читаем документы, `i` из `n`.
     *
     * @return array{state: string, i?: int, n?: int, what?: string}
     */
    private static function progress(Vehicle|Offer $model, array $pass, bool $running): array
    {
        $import = $model instanceof Offer ? ImportThreadFiles::progressOf($model) : null;
        // Задача файлов ещё не взята работником — ход ставит `attachNow` заранее («Прикрепляем фото», ноль из N): ждём.
        if ($import && ($import['stage'] ?? null) === ImportThreadFiles::QUEUED && ! $import['i']) {
            return ['state' => 'ghost', 'what' => 'files'];
        }
        if ($import && ($import['n'] ?? 0)) {
            return ['state' => 'files', 'i' => min((int) $import['i'] + 1, (int) $import['n']), 'n' => (int) $import['n'], 'what' => 'photos'];
        }
        if ($pass['pass'] === 'files') {
            return ['state' => 'ghost', 'what' => 'files'];
        }
        // Ничего ещё не прочитано и работник не взялся (ждёт очереди за чужим чтением) — силуэт.
        if (! $pass['i'] && ! $running) {
            return ['state' => 'ghost', 'what' => 'docs'];
        }
        $n = max($pass['n'], 1);

        return ['state' => 'reading', 'i' => min($pass['i'] + 1, $n), 'n' => $n, 'what' => 'docs'];
    }

    /**
     * Пустое поле — первым вариантом прочитанного, как отмечено по умолчанию в окне «Распознать» (письмо, потом
     * документы по `Files::rank`: СТС и ПТС первыми); заполненное — строками «в документе иначе» в деле и редакторе.
     */
    private static function fill(VehicleSubject|OfferSubject $subject): void
    {
        try {
            $rows = ScanFields::of($subject->current(), Reader::docs(Reader::files($subject)), $subject->fields());
            $picks = collect($rows)->filter(fn (array $row) => $row['state'] === 'new' && $row['options'])
                ->map(fn (array $row) => $row['options'][0]['text'])->all();
            if ($picks) {
                $subject->apply(ScanFields::chosen($rows, $picks), null);
            }
        } catch (Throwable $e) {
            // Не вписалось — строка всё равно становится полной: поля человек заполнит из документов в деле.
            Log::warning('Поля из документов не вписались сами', ['subject' => $subject->key(), 'error' => $e->getMessage()]);
        }
    }

    private static function ping(VehicleSubject|OfferSubject $subject): void
    {
        $subject instanceof VehicleSubject
            ? app(Publisher::class)->refresh(Topics::PARK, ['/requests', "/cars/{$subject->vehicle->id}"])
            : app(Publisher::class)->refresh(Topics::STAFF, ['/', "/offers/{$subject->offer->number}"]);
    }

    /** Пинг списку CRM о ходе фото — не чаще раза в `NUDGE_SECONDS`: у сотни строк в пути. */
    private static function nudge(): void
    {
        if (Cache::add('scan:auto:nudge', true, self::NUDGE_SECONDS)) {
            app(Publisher::class)->refresh(Topics::STAFF, ['/']);
        }
    }

    /** @return array{pass: string, i: int, n: int}|null метка предмета; до 12.10.2026 — строкой 'docs' */
    private static function mark(string $subjectKey): ?array
    {
        $mark = Cache::get(self::key($subjectKey));

        return $mark ? self::normalize($mark) : null;
    }

    /** @return array{pass: string, i: int, n: int} */
    private static function normalize(array|string $mark): array
    {
        return is_array($mark) ? $mark + ['pass' => 'docs', 'i' => 0, 'n' => 0] : ['pass' => $mark, 'i' => 0, 'n' => 0];
    }

    private static function subject(Vehicle|Offer $model): VehicleSubject|OfferSubject
    {
        return $model instanceof Vehicle ? new VehicleSubject($model) : new OfferSubject($model);
    }

    private static function model(VehicleSubject|OfferSubject $subject): Vehicle|Offer
    {
        return $subject instanceof VehicleSubject ? $subject->vehicle : $subject->offer;
    }

    /** Отпечаток того, что у предмета есть: последнее письмо его веток и последний файл. Пришло новое — отпечаток другой. */
    private static function signature(Vehicle|Offer $model): string
    {
        $column = $model instanceof Offer ? 'offer_id' : 'vehicle_id';
        $letter = DB::table('mail_messages')->whereIn('thread_id', DB::table('mail_threads')->where($column, $model->id)->select('id'))->max('id');
        $file = DB::table('media')->where('model_type', $model->getMorphClass())->where('model_id', $model->id)->max('id');

        return $letter.':'.$file;
    }

    private static function sweptKey(string $subjectKey): string
    {
        return "scan:swept:{$subjectKey}";
    }

    /** @return array<string, int> ключ предмета → когда начали; старые записи — id ТС числом */
    private static function pending(): array
    {
        $out = [];
        foreach ((array) Cache::get(self::PENDING, []) as $key => $since) {
            $out[is_int($key) ? 'v:'.$key : (string) $key] = $since;
        }

        return $out;
    }

    private static function register(string $key): void
    {
        Cache::forever(self::PENDING, [$key => now()->getTimestamp()] + self::pending());
    }

    private static function unregister(string $key): void
    {
        $pending = self::pending();
        if (isset($pending[$key])) {
            unset($pending[$key]);
            Cache::forever(self::PENDING, $pending);
        }
    }

    private static function key(string $subjectKey): string
    {
        return "scan:auto:{$subjectKey}";
    }

    private static function arrivedKey(int $offerId): string
    {
        return "scan:auto:arrived:o:{$offerId}";
    }

    private static function doneKey(string $subjectKey): string
    {
        return "scan:auto:done:{$subjectKey}";
    }
}
