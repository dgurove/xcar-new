<?php

namespace App\Mail\Scan;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Mail\Jobs\ScanAttachments;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use Illuminate\Support\Facades\Cache;
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
 */
final class AutoScan
{
    /** Метка живёт дольше любой задачи чтения: упавшая её снимает (`abandon`), забытая гаснет сама. */
    private const TTL = 7200;

    /** ТС, ждущие итога: id → когда начали. По нему `settle` и `sweep` находят их без перебора всех ТС. */
    private const PENDING = 'scan:auto:pending';

    /** Ничего не читается, а итога всё нет столько минут — подвести самим: силуэт строки не висит вечно. */
    private const STALE_MINUTES = 20;

    /** Дочитывать документы ТС, чья заявка из письма заведена не раньше стольких дней назад. */
    private const SWEEP_DAYS = 7;

    public static function start(Vehicle $vehicle): void
    {
        Cache::put(self::key($vehicle->id), 'docs', self::TTL);
        self::register($vehicle->id);
        $subject = new VehicleSubject($vehicle);
        Reader::read($subject);
        // Читать нечего или всё уже в кеше — итог сразу, задачи не будет.
        if (! ScanAttachments::reading(Reader::files($subject))) {
            self::finished($subject);
        }
    }

    /** Задача чтения кончилась (`ScanAttachments::handle`): вписать найденное и снять метку. */
    public static function finished(Subject $subject): void
    {
        if (! $subject instanceof VehicleSubject) {
            return;
        }
        if (! Cache::get(self::key($subject->vehicle->id))) {
            self::unregister($subject->vehicle->id);

            return;
        }
        $vehicle = $subject->vehicle->refresh();
        $subject = new VehicleSubject($vehicle);
        // Другая пачка тех же файлов ещё читается (человек дописал файл искрой, читает цепочка) — итог подведёт она.
        if (ScanAttachments::reading(Reader::files($subject))) {
            return;
        }
        self::fill($subject);
        Cache::forget(self::key($vehicle->id));
        self::unregister($vehicle->id);
        // Минута «только что» — строка проявляется и вспыхивает у того, кто смотрит (`arrive_controller`).
        Cache::put(self::doneKey($vehicle->id), true, 60);
        self::ping($vehicle);
    }

    /**
     * Кончилась задача с этими файлами (любого предмета): ТС из реестра, чьи файлы в ней были и больше не читаются, —
     * итог. Иначе ТС, чьи файлы при заведении уже читала цепочка «Из писем», ждала бы итога своей задачи, которой нет.
     *
     * @param  list<int|string>  $ids
     */
    public static function settle(array $ids): void
    {
        $ids = array_map('strval', $ids);
        foreach (array_keys(self::pending()) as $id) {
            $vehicle = Vehicle::find($id);
            if (! $vehicle) {
                self::unregister($id);

                continue;
            }
            $subject = new VehicleSubject($vehicle);
            if (array_intersect($ids, Reader::files($subject)->map->scanId()->all())) {
                self::finished($subject);
            }
        }
    }

    /**
     * Часы парковки (`park:tick`): у ТС открытой заявки из письма за неделю есть непрочитанные документы и ничего не
     * читается — читать (задачу убила выкладка, документ пришёл позже заведения); файл, что не прочёлся, — не раньше
     * чем через сутки (`ScanAttachments::lost`). Ждёт итога давно, а читать нечего — подвести. Возвращает, скольким
     * ТС поставлено чтение.
     */
    public static function sweep(): int
    {
        $started = 0;
        $vehicles = Request::whereNotNull('letter_id')->whereIn('state', RequestState::open())
            ->where('created_at', '>', now()->subDays(self::SWEEP_DAYS))->with('vehicle')->get()
            ->pluck('vehicle')->filter()->unique('id');
        foreach ($vehicles as $vehicle) {
            $subject = new VehicleSubject($vehicle);
            $files = Reader::files($subject);
            if (ScanAttachments::reading($files)) {
                continue;
            }
            $unread = $files->filter(fn (ScanFile $f) => DocumentText::cached($f) === null && DocumentText::scannable($f) && ! ScanAttachments::lost($f->scanId()));
            if ($unread->isNotEmpty()) {
                self::start($vehicle);
                $started++;
            }
        }
        foreach (self::pending() as $id => $since) {
            if ($since < now()->subMinutes(self::STALE_MINUTES)->getTimestamp() && ($vehicle = Vehicle::find($id))
                && ! ScanAttachments::reading(Reader::files(new VehicleSubject($vehicle)))) {
                self::finished(new VehicleSubject($vehicle));
            } elseif (! Vehicle::whereKey($id)->exists()) {
                self::unregister($id);
            }
        }

        return $started;
    }

    /** Задача упала или убита по таймауту — строка не должна навсегда остаться силуэтом. */
    public static function abandon(string $subjectKey): void
    {
        if (str_starts_with($subjectKey, 'v:')) {
            self::unregister((int) substr($subjectKey, 2));
            if (Cache::pull(self::key((int) substr($subjectKey, 2)))) {
                app(Publisher::class)->refresh(Topics::PARK, ['/requests']);
            }
        }
    }

    /**
     * Ход для строк списка — пачкой, без разбора писем у обычных строк:
     * - `ghost` — завели, ещё ничего не прочитано (файлы едут из ящика, чтение ждёт работника);
     * - `reading` — читаем, `i` из `n` документов;
     * - `done` — только что закончили.
     *
     * @param  iterable<Vehicle>  $vehicles
     * @return array<int, array{state: string, i?: int, n?: int, what?: string}>
     */
    public static function states(iterable $vehicles): array
    {
        $vehicles = collect($vehicles)->keyBy('id');
        if ($vehicles->isEmpty()) {
            return [];
        }
        $ids = $vehicles->keys();
        $passes = Cache::many($ids->map(fn ($id) => self::key($id))->all());
        $done = Cache::many($ids->map(fn ($id) => self::doneKey($id))->all());
        $out = [];
        foreach ($ids as $id) {
            if ($pass = $passes[self::key($id)] ?? null) {
                $out[$id] = self::progress($vehicles[$id]);
            } elseif ($done[self::doneKey($id)] ?? null) {
                $out[$id] = ['state' => 'done'];
            }
        }

        return $out;
    }

    /** @return array{state: string, i?: int, n?: int, what?: string} */
    private static function progress(Vehicle $vehicle): array
    {
        $subject = new VehicleSubject($vehicle);
        $files = Reader::files($subject)->reject->isPhoto()->values();
        $read = $files->filter(fn (ScanFile $f) => DocumentText::cached($f) !== null)->count();
        // Ничего ещё не прочитано и работник не взялся (ждёт очереди за чужим чтением) — силуэт.
        if (! $read && ! ScanAttachments::running($subject)) {
            return ['state' => 'ghost'];
        }

        return ['state' => 'reading', 'i' => min($read + 1, max($files->count(), 1)), 'n' => max($files->count(), 1), 'what' => 'docs'];
    }

    /**
     * Пустое поле — первым вариантом прочитанного, как отмечено по умолчанию в окне «Распознать» (письмо, потом
     * документы по `Files::rank`: СТС и ПТС первыми); заполненное — строками «в документе иначе» в деле.
     */
    private static function fill(VehicleSubject $subject): void
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
            Log::warning('Поля из документов не вписались сами', ['vehicle' => $subject->vehicle->id, 'error' => $e->getMessage()]);
        }
    }

    private static function ping(Vehicle $vehicle): void
    {
        app(Publisher::class)->refresh(Topics::PARK, ['/requests', "/cars/{$vehicle->id}"]);
    }

    /** @return array<int, int> */
    private static function pending(): array
    {
        return (array) Cache::get(self::PENDING, []);
    }

    private static function register(int $vehicleId): void
    {
        Cache::forever(self::PENDING, [$vehicleId => now()->getTimestamp()] + self::pending());
    }

    private static function unregister(int $vehicleId): void
    {
        $pending = self::pending();
        if (isset($pending[$vehicleId])) {
            unset($pending[$vehicleId]);
            Cache::forever(self::PENDING, $pending);
        }
    }

    private static function key(int $vehicleId): string
    {
        return "scan:auto:v:{$vehicleId}";
    }

    private static function doneKey(int $vehicleId): string
    {
        return "scan:auto:done:v:{$vehicleId}";
    }
}
