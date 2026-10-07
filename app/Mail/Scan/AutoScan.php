<?php

namespace App\Mail\Scan;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Mail\Jobs\ScanAttachments;
use App\Park\Vehicle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Фоновое чтение ТС, которую завела почта (`Park\Actions\AutoRequest`, владелец 07.10.2026: «распознание документов и
 * фото должно корректно в фоне происходить»). Тот же `Reader`, что у читалки «Завести», только поля вписывает сервер:
 * пустое поле с одним вариантом — как браузер в форме (`reader_controller` → `fill`), спорное и другое значение не
 * трогаются — их решает человек строками «в документе иначе» в деле. Сначала документы; не нашлись VIN или марка —
 * фото письма (табличка VIN, ПТС на снимке). Пока метка стоит, строка заявки показывает ход (`state`).
 */
final class AutoScan
{
    /** Фото во втором проходе: ~6 с на кадр у единственного работника `scan` — люди ждут за ним. */
    public const PHOTOS = 8;

    /** Метка живёт дольше любой задачи чтения: упавшая её снимает (`abandon`), забытая гаснет сама. */
    private const TTL = 7200;

    /** Имена фото, на которых вероятнее текст (как предвыбор окна «Из документов»). */
    private const PHOTO_WITH_TEXT = '/vin|вин|стс|птс|sts|pts|шильд|табличк|номер|госзнак/iu';

    public static function start(Vehicle $vehicle): void
    {
        Cache::put(self::key($vehicle->id), 'docs', self::TTL);
        $subject = new VehicleSubject($vehicle);
        Reader::read($subject);
        // Читать нечего или всё уже в кеше — итог сразу, задачи не будет.
        if (! ScanAttachments::reading(Reader::files($subject))) {
            self::finished($subject);
        }
    }

    /** Задача чтения кончилась (`ScanAttachments::handle`): вписать найденное, при нужде — фото, иначе снять метку. */
    public static function finished(Subject $subject): void
    {
        if (! $subject instanceof VehicleSubject || ! ($pass = Cache::get(self::key($subject->vehicle->id)))) {
            return;
        }
        $vehicle = $subject->vehicle->refresh();
        $subject = new VehicleSubject($vehicle);
        // Другая пачка того же предмета ещё читается (человек дописал файл искрой) — итог подведёт она.
        if (ScanAttachments::reading(Reader::files($subject))) {
            return;
        }
        self::fill($subject);
        if ($pass === 'docs' && (! $vehicle->refresh()->vin || ! $vehicle->brand_id) && ($photos = self::photos($subject))) {
            Cache::put(self::key($vehicle->id), 'photos', self::TTL);
            Reader::read($subject, $photos);
            if (ScanAttachments::reading(Reader::files($subject))) {
                self::ping($vehicle);

                return;
            }
            self::fill(new VehicleSubject($vehicle->refresh()));
        }
        Cache::forget(self::key($vehicle->id));
        // Минута «только что» — строка проявляется и вспыхивает у того, кто смотрит (`arrive_controller`).
        Cache::put(self::doneKey($vehicle->id), true, 60);
        self::ping($vehicle);
    }

    /** Задача упала или убита по таймауту — строка не должна навсегда остаться силуэтом. */
    public static function abandon(string $subjectKey): void
    {
        if (str_starts_with($subjectKey, 'v:') && Cache::pull(self::key((int) substr($subjectKey, 2)))) {
            app(Publisher::class)->refresh(Topics::PARK, ['/requests']);
        }
    }

    /**
     * Ход для строк списка — пачкой, без разбора писем у обычных строк:
     * - `ghost` — завели, ещё ничего не прочитано (файлы едут из ящика, чтение ждёт работника);
     * - `reading` — читаем, `i` из `n`, `what` — документы или фото;
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
                $out[$id] = self::progress($vehicles[$id], $pass);
            } elseif ($done[self::doneKey($id)] ?? null) {
                $out[$id] = ['state' => 'done'];
            }
        }

        return $out;
    }

    /** @return array{state: string, i?: int, n?: int, what?: string} */
    private static function progress(Vehicle $vehicle, string $pass): array
    {
        $subject = new VehicleSubject($vehicle);
        $files = Reader::files($subject)->filter(fn (ScanFile $f) => $pass === 'photos' ? $f->isPhoto() : ! $f->isPhoto())->values();
        $read = $files->filter(fn (ScanFile $f) => DocumentText::cached($f) !== null)->count();
        // Ничего ещё не прочитано и работник не взялся (ждёт очереди за чужим чтением) — силуэт.
        if ($pass === 'docs' && ! $read && ! ScanAttachments::running($subject)) {
            return ['state' => 'ghost'];
        }

        return ['state' => 'reading', 'i' => min($read + 1, max($files->count(), 1)), 'n' => max($files->count(), 1), 'what' => $pass];
    }

    /** Пустое поле и один вариант в прочитанном — в карточку; остальное — строками «в документе иначе» в деле. */
    private static function fill(VehicleSubject $subject): void
    {
        try {
            $rows = ScanFields::of($subject->current(), Reader::docs(Reader::files($subject)), $subject->fields());
            $picks = collect($rows)->filter(fn (array $row) => $row['state'] === 'new' && count($row['options']) === 1)
                ->map(fn (array $row) => $row['options'][0]['text'])->all();
            if ($picks) {
                $subject->apply(ScanFields::chosen($rows, $picks), null);
            }
        } catch (Throwable $e) {
            // Не вписалось — строка всё равно становится полной: поля человек заполнит из документов в деле.
            Log::warning('Поля из документов не вписались сами', ['vehicle' => $subject->vehicle->id, 'error' => $e->getMessage()]);
        }
    }

    /** Фото письма, ещё не прочитанные: сначала с «VIN», «СТС» в имени. @return list<string> */
    private static function photos(VehicleSubject $subject): array
    {
        return $subject->files()->filter(fn (ScanFile $f) => $f->isPhoto() && DocumentText::scannable($f) && DocumentText::cached($f) === null)
            ->sortBy(fn (ScanFile $f) => preg_match(self::PHOTO_WITH_TEXT, $f->scanName()) ? 0 : 1)
            ->take(self::PHOTOS)->map->scanId()->values()->all();
    }

    private static function ping(Vehicle $vehicle): void
    {
        app(Publisher::class)->refresh(Topics::PARK, ['/requests', "/cars/{$vehicle->id}"]);
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
