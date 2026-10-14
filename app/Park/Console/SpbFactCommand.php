<?php

namespace App\Park\Console;

use App\Cars\CarModel;
use App\Cars\Category;
use App\Cars\Colors;
use App\Cars\IdentityTaken;
use App\Cars\Names;
use App\Cars\Vin\Vin;
use App\Mail\Actions\CloseChain;
use App\Mail\Actions\FreezeMessages;
use App\Mail\Actions\LinkThread;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\Keys;
use App\Mail\Extraction\Patterns;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Mail\Thread;
use App\Park\Actions\RegisterFromLetters;
use App\Park\Actions\ReleasePast;
use App\Park\Actions\Transfer;
use App\Park\City;
use App\Park\DocState;
use App\Park\Events\VehicleAccepted;
use App\Park\Events\VehicleReleased;
use App\Park\Events\VehicleSold;
use App\Park\EventType;
use App\Park\Idle;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Nav;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Питер к факту по таблице управляющего («Питер.xlsx», 09.10.2026) — разовая команда, как `park:fact` для Москвы
 * 22.09.2026. Решения владельца:
 * - даты приёма и выбытия — только из таблицы, письмам по датам не верим;
 * - 2024 не заводим; 2025 — все строки выданными, письма подбираем к строкам, что не сошлось — только список;
 *   2026 — у каждой строки должны быть письма, строка без писем — тревога (без `--force` записи нет);
 * - стоящим — фото и документы из писем, у выданных файлов на диске нет, письма только привязаны (заморожены);
 * - «уехала в мск на базу» — перевод в Москву без парковки (`Transfer`), парковку там укажет Москва;
 * - выбывшее до 01.09.2026 выставлено вне системы (`storage_billed_until`), сентябрь выставляем из системы.
 * Письма, Telegram и синхронизация с продажей при записи молчат (`Event::fake` событий ТС). Повторный запуск
 * безопасен: найденная ТС правится, а не заводится второй. `--audit` — только сверка базы с таблицей.
 */
final class SpbFactCommand extends Command
{
    protected $signature = 'park:spb-fact {file : xlsx управляющего} {--apply : записать} {--audit : только сверить базу с таблицей} {--force : записать и при тревогах 2026} {--report= : куда отчёт (по умолчанию рядом с файлом)}';

    protected $description = 'Питер: ТС и письма к факту по таблице управляющего';

    private const VENDOR = 'АльфаСтрахование СПб';

    private const YARD = 'Краснопутиловская';

    /** Выбывшее раньше выставлено вне системы. */
    private const BILLED_BEFORE = '2026-09-01';

    /** Окно дат письма вокруг стоянки: заявка приходит до приёма, бумаги после выдачи. */
    private const DAYS_BEFORE = 120;

    private const DAYS_AFTER = 90;

    /** @var array<string, list<array>> */
    private array $report = ['Строки' => [], 'Тревоги 2026' => [], 'Письма без строк' => [], 'Расхождения' => [], 'ТС вне таблицы' => [], 'Аудит' => []];

    private Vendor $vendor;

    private Yard $yard;

    /** @var Collection<int, object> */
    private Collection $threads;

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("Нет файла {$file}");

            return self::FAILURE;
        }
        $this->vendor = Vendor::where('name', self::VENDOR)->firstOrFail();
        $this->yard = Yard::withoutGlobalScope(City::SCOPE)->where('name', self::YARD)->firstOrFail();
        $rows = $this->rows($file);
        $this->info('Строк 2025–2026: '.count($rows));

        if ($this->option('audit')) {
            $ok = $this->audit($rows);
            $this->writeReport($file);

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->threads = $this->threadIndex();
        $plans = $this->plan($rows);
        $alarms = collect($plans)->filter(fn ($p) => $p['row']['arrival']->year === 2026 && $p['threads'] === []);
        foreach ($alarms as $p) {
            $this->report['Тревоги 2026'][] = [$p['row']['line'], $p['row']['title'], $p['row']['claim'], $p['row']['vin'], $p['row']['plate'], $p['row']['arrival']->format('d.m.Y'), 'писем не нашлось'];
        }
        $this->lettersWithoutRows($plans);
        $this->outsideTable($plans);

        if (! $this->option('apply')) {
            $this->summary($plans, false);
            $this->writeReport($file);
            $this->warn('Это сверка: записи не было. Для записи — --apply');

            return self::SUCCESS;
        }
        if ($alarms->isNotEmpty() && ! $this->option('force')) {
            $this->summary($plans, false);
            $this->writeReport($file);
            $this->error('Строк 2026 без писем: '.$alarms->count().' — разобрать, иначе --force');

            return self::FAILURE;
        }

        Event::fake([VehicleAccepted::class, VehicleReleased::class, VehicleSold::class]);
        foreach ($plans as $i => $p) {
            try {
                $plans[$i]['result'] = DB::transaction(fn () => $this->apply($p));
            } catch (IdentityTaken $e) {
                $plans[$i]['result'] = 'не записана: '.$e->getMessage();
            } catch (Throwable $e) {
                $plans[$i]['result'] = 'ошибка: '.$e->getMessage();
                report($e);
            }
        }
        Nav::forgetStaffCounts();
        $this->summary($plans, true);
        $this->writeReport($file);

        return self::SUCCESS;
    }

    // Таблица

    /** @return list<array<string, mixed>> строки 2025–2026 */
    private function rows(string $file): array
    {
        $sheet = IOFactory::load($file)->getSheet(0);
        $cells = $sheet->toArray(null, true, true, false);
        $head = null;
        $out = [];
        foreach ($cells as $n => $r) {
            $r = array_map(fn ($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : $v, $r);
            if ($head === null) {
                $idx = array_search('Vin номер', $r, true);
                if ($idx !== false) {
                    $head = array_flip(array_filter($r, fn ($v) => is_string($v) && $v !== ''));
                }

                continue;
            }
            $get = fn (string $name) => isset($head[$name]) ? ($r[$head[$name]] ?? null) : null;
            $title = (string) $get('Марка/модель ТС');
            $arrival = $this->date($get('Факт.дата прибытия'));
            if ($title === '' && ! $get('Vin номер')) {
                continue;
            }
            if (! $arrival) {
                $this->report['Расхождения'][] = [$n + 1, $title, 'дата прибытия не прочитана: '.$get('Факт.дата прибытия')];

                continue;
            }
            if ($arrival->year < 2025) {
                continue;
            }
            $shipRaw = (string) $get('Дата отгрузки ТС');
            $ship = $this->date($shipRaw);
            $state = match (true) {
                $ship !== null => 'released',
                mb_strtolower($shipRaw) === 'стоит' => 'stored',
                (bool) preg_match('/мск|москв/iu', $shipRaw) => 'moscow',
                default => null,
            };
            if ($state === null) {
                throw new \RuntimeException('Строка '.($n + 1).": непонятное выбытие «{$shipRaw}»");
            }
            [$claim, $policyFromClaim] = $this->claim((string) $get('Номер убытка/полиса'));
            $out[] = [
                'line' => $n + 1, 'title' => $title, 'vin' => Vin::normalize((string) $get('Vin номер')) ?: null,
                'year' => ($y = (int) $get('Год выпуска')) >= 1950 ? $y : null, 'color' => $this->color((string) $get('Цвет')),
                'sts' => $this->doc($get('СТС')), 'pts' => $this->doc($get('(Э)ПТС')), 'plate' => Patterns::plateKey($this->plate((string) $get('Рег.номер'))) ?: null,
                'plate_raw' => $this->plate((string) $get('Рег.номер')), 'arrival' => $arrival, 'ship' => $ship, 'state' => $state, 'claim' => $claim,
                'policy' => ((string) $get('№ Полиса')) ?: $policyFromClaim, 'value' => ($v = (int) preg_replace('/\D+/', '', (string) $get('Заявленная стоимость ТС'))) > 0 ? $v : null,
            ];
        }

        return $out;
    }

    private function date(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 30000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->startOfDay();
        }
        if (preg_match('~^(\d{1,2})[./](\d{1,2})[./](\d{4})$~', trim((string) $v), $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
        }

        return null;
    }

    /** Номер убытка: «//», пробелы — прочь; две записи в ячейке — убыток и полис. @return array{0: ?string, 1: ?string} */
    private function claim(string $raw): array
    {
        $parts = preg_split('/\s{2,}|\s+(?=[0-9A-ZА-Я]{4,5}\S*\/)/u', trim($raw)) ?: [];
        $first = preg_replace(['~\s*/\s*~', '~/{2,}~'], ['/', '/'], (string) ($parts[0] ?? ''));

        return [$first !== '' ? $first : null, isset($parts[1]) ? trim($parts[1]) : null];
    }

    /** Цвет словарём с правкой опечаток («Золотимтый» → «Золотистый»); не цвет («/», пусто) — без цвета. */
    private function color(string $v): ?string
    {
        $v = trim($v);

        return preg_match('/\pL/u', $v) ? (Colors::normalize($v, fuzzy: true) ?? mb_convert_case(mb_strtolower($v), MB_CASE_TITLE)) : null;
    }

    /** Госномер кириллицей: в таблице его набирали и латиницей («T119TH193»), а в письмах и в базе он кириллицей. */
    private function plate(string $v): string
    {
        return strtr(mb_strtoupper(preg_replace('/\s+/u', '', $v)), ['A' => 'А', 'B' => 'В', 'E' => 'Е', 'K' => 'К', 'M' => 'М', 'H' => 'Н', 'O' => 'О', 'P' => 'Р', 'C' => 'С', 'T' => 'Т', 'Y' => 'У', 'X' => 'Х']);
    }

    private function doc(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' || $v === '/' ? null : $v;
    }

    // Сопоставление

    /** Ветки ящика парковки с номерами и датами первого и последнего письма. */
    private function threadIndex(): Collection
    {
        return Thread::withoutGlobalScopes()->park()->leftJoinSub(
            DB::table('mail_messages')->selectRaw('thread_id, min(date_at) as first_at, max(date_at) as last_at')->groupBy('thread_id'), 'd', 'd.thread_id', '=', 'mail_threads.id'
        )->get(['mail_threads.id', 'mail_threads.vendor_id', 'mail_threads.vehicle_id', 'mail_threads.candidate_id', 'mail_threads.keys', 'mail_threads.subject', 'd.first_at', 'd.last_at'])
            ->map(fn ($t) => (object) ['id' => $t->id, 'vendor_id' => $t->vendor_id, 'vehicle_id' => $t->vehicle_id, 'candidate_id' => $t->candidate_id, 'subject' => $t->subject,
                'keys' => collect($t->keys ?? [])->map(fn ($k) => mb_strtolower($k))->all(), 'first' => $t->first_at ? Carbon::parse($t->first_at) : null, 'last' => $t->last_at ? Carbon::parse($t->last_at) : null]);
    }

    /** @return list<string> ключи строки теми же правилами, что у писем (`Keys::ofVehicle`) */
    private function keys(array $row, ?string $claim = null): array
    {
        $v = new Vehicle(['ref' => $claim ?? $row['claim'], 'vin' => $row['vin'], 'plate' => $row['plate_raw']]);

        return array_map('mb_strtolower', Keys::ofVehicle($v));
    }

    /** @return list<array{row: array, vehicle: ?Vehicle, threads: list<int>, ref: ?string, notes: list<string>}> */
    private function plan(array $rows): array
    {
        $vehicles = Vehicle::withoutGlobalScopes()->with([])->get();
        $byRef = $vehicles->whereNotNull('ref_key')->groupBy('ref_key');
        $claims = collect($rows)->groupBy(fn ($r) => Vehicle::keyFor((string) $r['claim']));
        $plans = [];
        $taken = [];
        foreach ($rows as $row) {
            $notes = [];
            $key = $row['claim'] ? Vehicle::keyFor($row['claim']) : null;
            $ref = $row['claim'];
            // Один убыток на две строки: чей он — решают письма (номер, VIN и госномер в одной ветке), второй строке — номер её писем.
            if ($key && $claims[$key]->count() > 1) {
                $own = $this->threadsFor($row, byCode: false);
                $codes = collect($own)->flatMap(fn ($t) => array_filter($t->keys, fn ($k) => str_starts_with($k, 'code:')))->unique()->values();
                if ($codes->count() === 1 && $codes->first() !== 'code:'.mb_strtolower($key)) {
                    $ref = $this->codeFromThreads($own) ?? $ref;
                    $notes[] = "убыток по таблице {$row['claim']} у двух ТС, по письмам — {$ref}";
                } elseif ($codes->count() !== 1) {
                    $notes[] = "убыток {$row['claim']} у двух ТС в таблице";
                }
            }
            $vehicle = $this->vehicleFor($row, $ref, $vehicles, $byRef, $taken);
            if ($vehicle) {
                $taken[$vehicle->id] = true;
            }
            $threads = collect($this->threadsFor($row, claim: $ref))->filter(fn ($t) => ! $t->vehicle_id || $t->vehicle_id === $vehicle?->id || ! isset($taken[$t->vehicle_id]))->pluck('id')->all();
            $plans[] = ['row' => $row, 'vehicle' => $vehicle, 'threads' => array_values($threads), 'ref' => $ref, 'notes' => $notes];
        }
        // Ветку получает одна строка: с совпадением по убытку, иначе ближайшая по дате приёма.
        $owners = [];
        foreach ($plans as $i => $p) {
            foreach ($p['threads'] as $id) {
                $owners[$id][] = $i;
            }
        }
        foreach ($owners as $id => $list) {
            if (count($list) < 2) {
                continue;
            }
            $t = $this->threads->firstWhere('id', $id);
            $best = collect($list)->sortBy(fn ($i) => [
                in_array('code:'.mb_strtolower((string) Vehicle::keyFor((string) $plans[$i]['ref'])), $t->keys, true) ? 0 : 1,
                $t->first ? abs($t->first->diffInDays($plans[$i]['row']['arrival'])) : 999,
            ])->first();
            foreach ($list as $i) {
                if ($i !== $best) {
                    $plans[$i]['threads'] = array_values(array_diff($plans[$i]['threads'], [$id]));
                }
            }
        }

        return $plans;
    }

    private function codeFromThreads(array $threads): ?string
    {
        foreach ($threads as $t) {
            $m = Message::where('thread_id', $t->id)->whereNotNull('subject')->pluck('subject')->first();
            if ($m && preg_match('~[0-9A-ZА-Я]{4}/046/\d{5}/\d{2}~u', $m, $x)) {
                return $x[0];
            }
        }

        return null;
    }

    /** Ветки строки: общий ключ и даты в окне стоянки; госномер при другом убытке в ветке — не она (номер переходит). */
    private function threadsFor(array $row, bool $byCode = true, ?string $claim = null): array
    {
        $keys = $this->keys($row, $claim);
        $code = collect($keys)->first(fn ($k) => str_starts_with($k, 'code:'));
        if (! $byCode) {
            $keys = array_values(array_filter($keys, fn ($k) => ! str_starts_with($k, 'code:')));
        }
        $from = $row['arrival']->copy()->subDays(self::DAYS_BEFORE);
        $to = ($row['ship'] ?? now())->copy()->addDays(self::DAYS_AFTER);
        $out = [];
        foreach ($this->threads as $t) {
            $common = array_intersect($keys, $t->keys);
            if (! $common || ! $t->first || $t->first->gt($to) || ($t->last && $t->last->lt($from))) {
                continue;
            }
            $codes = array_filter($t->keys, fn ($k) => str_starts_with($k, 'code:'));
            $byVin = (bool) array_filter($common, fn ($k) => str_starts_with($k, 'vin:'));
            // Госномер с другим убытком в ветке — обычно другой заезд или номер перешёл; но опечатка в убытке письма
            // («806/046/06946/25» вместо «7806/…») или убыток, общий в таблице на две ТС, — та же машина.
            if ($byCode && $code && $codes && ! in_array($code, $codes, true) && ! $byVin && ! $this->nearCode($code, $codes)) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    /** Убыток письма отличается от таблицы на опечатку: одна-две цифры. */
    private function nearCode(string $code, array $codes): bool
    {
        foreach ($codes as $c) {
            if (levenshtein($code, $c) <= 2) {
                return true;
            }
        }

        return false;
    }

    private function vehicleFor(array $row, ?string $ref, Collection $vehicles, Collection $byRef, array $taken): ?Vehicle
    {
        $near = fn (Vehicle $v) => ! isset($taken[$v->id]) && (! $v->accepted_at || abs($v->accepted_at->copy()->startOfDay()->diffInDays($row['arrival'])) <= 45);
        $fits = fn (Vehicle $v) => $near($v) && (! $row['vin'] || ! $v->vin || $v->vin === $row['vin'] || ($row['plate'] && $v->plate === $row['plate']));
        $key = $ref ? Vehicle::keyFor($ref) : null;
        // Убыток — сильнейшее тождество: VIN в таблице с опечаткой не мешает (расхождение — в отчёт при записи).
        if ($key && ($v = ($byRef[$key] ?? collect())->first($near))) {
            return $v;
        }
        if ($row['vin'] && ($v = $vehicles->where('vin', $row['vin'])->first($fits))) {
            return $v;
        }
        if ($row['plate']) {
            return $vehicles->where('vendor_id', $this->vendor->id)->where('plate', $row['plate'])->first($fits);
        }

        return null;
    }

    /** Письма питерской Альфы с номерами, что не легли ни на одну строку (2025–2026). */
    private function lettersWithoutRows(array $plans): void
    {
        $used = collect($plans)->flatMap(fn ($p) => $p['threads'])->flip();
        foreach ($this->threads->where('vendor_id', $this->vendor->id) as $t) {
            if (isset($used[$t->id]) || ! $t->keys || ! $t->first || $t->first->year < 2025) {
                continue;
            }
            $vehicle = $t->vehicle_id ? Vehicle::withoutGlobalScopes()->find($t->vehicle_id) : null;
            $this->report['Письма без строк'][] = [$t->id, $t->first->format('d.m.Y'), $t->last?->format('d.m.Y'), implode(' ', $t->keys), (string) $t->subject,
                $vehicle ? $vehicle->id.' '.$vehicle->state->label() : ''];
        }
    }

    /** ТС Альфы СПб в системе, которых нет в таблице: заявки, что ещё не приехали, и ошибки. */
    private function outsideTable(array $plans): void
    {
        $used = collect($plans)->pluck('vehicle')->filter()->pluck('id')->flip();
        foreach (Vehicle::withoutGlobalScopes()->with([])->where('vendor_id', $this->vendor->id)->get() as $v) {
            if (! isset($used[$v->id])) {
                $this->report['ТС вне таблицы'][] = [$v->id, $v->titleWithYear(), $v->ref, $v->vin, $v->plate, $v->state->label(), $v->created_at?->format('d.m.Y'), $v->accepted_at?->format('d.m.Y')];
            }
        }
    }

    // Запись

    private function apply(array $p): string
    {
        $row = $p['row'];
        $acceptedAt = $row['arrival']->copy()->setTime(12, 0);
        // Выбытие раньше приёма — опечатка таблицы: выдача днём приёма (как `ReleasePast`).
        $releasedAt = $row['ship']?->copy()->max($row['arrival'])->setTime(12, 0);
        $released = $row['state'] === 'released';
        // VIN в таблице с лишним знаком («XW7DFREVX0S0011643») — берём VIN из писем строки, нет — без VIN.
        if ($row['vin'] && strlen($row['vin']) !== 17) {
            $vins = $this->threads->whereIn('id', $p['threads'])->flatMap(fn ($t) => array_filter($t->keys, fn ($k) => str_starts_with($k, 'vin:')))->unique()->values();
            $this->report['Расхождения'][] = [$row['line'], $row['title'], "VIN в таблице {$row['vin']} — не 17 знаков, ".($vins->count() === 1 ? 'взят из писем' : 'не записан')];
            $row['vin'] = $vins->count() === 1 ? strtoupper(substr($vins->first(), 4)) : null;
        }
        $data = $this->fields($row, $p['ref'], $p['vehicle']);
        $did = [];

        if (! $vehicle = $p['vehicle']) {
            $vehicle = app(RegisterFromLetters::class)(null, null, $data + ['vendor_id' => $this->vendor->id], ['accepted_at' => $acceptedAt->toDateTimeString(), 'yard_id' => $this->yard->id], ['by_fact' => true]);
            $vehicle->log(EventType::Note, null, ['text' => 'Заведена по таблице Питера 09.10.2026, строка '.$row['line']]);
            $this->settleDocs($vehicle, $acceptedAt);
            $did[] = 'заведена';
        } else {
            $vehicle = Vehicle::withoutGlobalScopes()->lockForUpdate()->findOrFail($vehicle->id);
            $changes = $this->changes($vehicle, $data);
            if ($changes) {
                $vehicle->forceFill($changes)->save();
                $did[] = 'поля: '.implode(', ', array_keys($changes));
            }
            if ($vehicle->state === VehicleState::Expected) {
                $this->acceptPast($vehicle, $acceptedAt);
                $did[] = 'принята '.$acceptedAt->format('d.m');
            } elseif (! $vehicle->accepted_at?->isSameDay($acceptedAt)) {
                $did[] = 'приём '.($vehicle->accepted_at?->format('d.m') ?? '—').' → '.$acceptedAt->format('d.m');
                $this->moveAccepted($vehicle, $acceptedAt);
            }
            if (! $vehicle->yard_id && $vehicle->state === VehicleState::Stored) {
                $vehicle->forceFill(['yard_id' => $this->yard->id])->save();
                $this->setAcceptedYard($vehicle);
                $did[] = 'парковка';
            }
        }
        $this->reportSent($vehicle, 'Акт приёма', $acceptedAt->lt(now()->subDays(3)));

        if ($released) {
            if ($vehicle->state === VehicleState::Released) {
                if (! $vehicle->released_at?->isSameDay($releasedAt)) {
                    $did[] = 'выдача '.($vehicle->released_at?->format('d.m') ?? '—').' → '.$releasedAt->format('d.m');
                    $vehicle->forceFill(['released_at' => $releasedAt])->save();
                    $event = $vehicle->events()->where('type', EventType::Released)->latest('id')->first();
                    $event?->update(['payload' => ['day' => $releasedAt->toDateString()] + ($event->payload ?? [])]);
                }
            } else {
                app(ReleasePast::class)($vehicle, null, $releasedAt, 'по таблице Питера', ['by_fact' => true]);
                $did[] = 'выдана '.$releasedAt->format('d.m');
            }
            $this->reportSent($vehicle, 'Акт выдачи', $releasedAt->lt(now()->startOfDay()));
            if ($releasedAt->lt(Carbon::parse(self::BILLED_BEFORE)) && (! $vehicle->storage_billed_until || $vehicle->storage_billed_until->lt($releasedAt->copy()->startOfDay()))) {
                $vehicle->forceFill(['storage_billed_until' => $releasedAt->toDateString()])->save();
                $did[] = 'выставлено вне системы';
            }
        }
        if ($row['state'] === 'moscow' && (int) $vehicle->city_id !== City::main()) {
            $last = $this->threads->whereIn('id', $p['threads'])->max('last');
            app(Transfer::class)($vehicle, $this->owner(), null, City::main(), Carbon::parse($last ?? $acceptedAt)->max($acceptedAt), 'по таблице Питера «уехала в мск на базу», дата перевода неизвестна');
            $did[] = 'переведена в Москву';
        }

        $linked = $this->letters($vehicle->refresh(), $p['threads']);
        if ($linked) {
            $did[] = "письма: {$linked}";
        }

        return implode('; ', $did) ?: 'без изменений';
    }

    /** Поля таблицы для ТС: марка и модель, если у ТС их нет; документы, цвет, год, полис — таблица главнее. */
    private function fields(array $row, ?string $ref, ?Vehicle $vehicle): array
    {
        $data = ['ref' => $ref, 'vin' => $row['vin'], 'plate' => $row['plate_raw'] ?: null, 'year' => $row['year'], 'color' => $row['color'], 'sts' => $row['sts'], 'pts' => $row['pts'],
            'policy_no' => $row['policy'], 'value' => $row['value']];
        if (! $vehicle?->brand_id && ($found = Names::find($row['title']))) {
            $data['brand_id'] = $found['brand']->id;
            $model = trim((string) ($found['model'] ?? ''));
            $live = $row['state'] !== 'released';
            $data['model_id'] = $model === '' ? null : ($live ? CarModel::resolve($found['brand'], $model)->id : CarModel::known($found['brand'], $model)?->id);
        }
        if (! $vehicle?->category) {
            $data['category'] = (Category::guess($row['title']) ?? Category::Passenger)->value;
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    /** Что править у найденной ТС: тождество (убыток, VIN, госномер) — только в пустое, остальное — из таблицы. */
    private function changes(Vehicle $v, array $data): array
    {
        $out = [];
        // Мусор вместо значения («/» в цвете и документах) — снять, если таблица ничего взамен не дала.
        foreach (['color', 'sts', 'pts'] as $k) {
            if ($v->{$k} !== null && ! preg_match('/[\pL\d]/u', (string) $v->{$k}) && ! isset($data[$k])) {
                $out[$k] = null;
            }
        }
        foreach ($data as $k => $value) {
            $current = $v->{$k} instanceof \BackedEnum ? $v->{$k}->value : $v->{$k};
            $norm = match ($k) {
                'ref' => Vehicle::keyFor((string) $value) === $v->ref_key ? $current : $value,
                'vin' => Vin::normalize((string) $value),
                'plate' => Patterns::plateKey((string) $value),
                default => $value,
            };
            if ((string) $norm === (string) $current) {
                continue;
            }
            if (in_array($k, ['ref', 'vin', 'plate'], true) && $current) {
                $this->report['Расхождения'][] = [$v->id, $v->titleWithYear(), "{$k}: в системе {$current}, в таблице {$value} — оставлено как в системе"];

                continue;
            }
            $out[$k] = $value;
        }

        return $out;
    }

    /** Ожидаемая ТС принята задним числом: без осмотра, заявки на приём закрыты, событие приёма с днём таблицы. */
    private function acceptPast(Vehicle $v, Carbon $at): void
    {
        $v->forceFill(['state' => VehicleState::Stored, 'accepted_at' => $at, 'yard_id' => $v->yard_id ?? $this->yard->id,
            'idle_noticed_at' => $at->lt(now()->subDays(Idle::warn())) ? now() : null])->save();
        $v->log(EventType::Accepted, null, ['yard' => $v->yard?->name, 'yard_id' => $v->yard_id, 'day' => $at->toDateString(), 'by_fact' => true]);
        Request::where('vehicle_id', $v->id)->whereIn('state', RequestState::open())->update(['state' => RequestState::Done, 'done_at' => now(), 'note' => 'принята по таблице Питера']);
    }

    /** Дата приёма — из таблицы, вместе с днём в событии приёма (по нему лента площадок и хранение). */
    private function moveAccepted(Vehicle $v, Carbon $at): void
    {
        $v->forceFill(['accepted_at' => $at])->save();
        $event = $v->events()->where('type', EventType::Accepted)->orderBy('id')->first();
        $event ? $event->update(['payload' => ['day' => $at->toDateString()] + ($event->payload ?? [])])
            : $v->log(EventType::Accepted, null, ['yard' => $v->yard?->name, 'yard_id' => $v->yard_id, 'day' => $at->toDateString(), 'by_fact' => true]);
    }

    private function setAcceptedYard(Vehicle $v): void
    {
        $event = $v->events()->where('type', EventType::Accepted)->orderBy('id')->first();
        $event?->update(['payload' => ['yard' => $this->yard->name, 'yard_id' => $this->yard->id] + ($event->payload ?? [])]);
    }

    /** Отчёт вендору — сделан вне приложения: шаг «Нужно отправить отчёт» у истории не висит. Свежие — нет. */
    private function reportSent(Vehicle $v, string $what, bool $old): void
    {
        if ($old && ! $v->events()->where('type', EventType::ReportSent)->whereRaw("payload->>'what' = ?", [$what])->exists()) {
            $v->log(EventType::ReportSent, null, ['what' => $what, 'by_fact' => true]);
        }
    }

    /** Бумаги, открытые при заведении, по старой ТС давно отправлены. */
    private function settleDocs(Vehicle $v, Carbon $accepted): void
    {
        if ($accepted->lt(now()->subDays(3))) {
            $v->docs()->where('state', DocState::Pending)->update(['state' => DocState::Sent]);
        }
    }

    /**
     * Письма строки — к ТС. Стоит: файлы из писем к ней (разморозка, импорт очередью), цепочка заведена. Выдана: ветки
     * только привязаны и заморожены (файлы в ящике, на диске их нет), цепочка закрыта.
     */
    private function letters(Vehicle $v, array $ids): int
    {
        $n = 0;
        $stored = $v->state === VehicleState::Stored;
        foreach (Thread::withoutGlobalScopes()->whereIn('id', $ids)->get() as $thread) {
            if ($thread->vehicle_id && $thread->vehicle_id !== $v->id) {
                $this->report['Расхождения'][] = [$v->id, $v->titleWithYear(), "ветка {$thread->id} уже у ТС {$thread->vehicle_id} — не тронута"];

                continue;
            }
            $messages = Message::where('thread_id', $thread->id)->pluck('id')->all();
            if ($stored) {
                app(FreezeMessages::class)->thaw($messages);
            }
            if ($thread->vehicle_id !== $v->id) {
                app(LinkThread::class)($thread, $v, $stored);
                $n++;
            } elseif ($stored) {
                ImportThreadFiles::dispatch($thread->id);
            }
            if (! $stored) {
                app(FreezeMessages::class)->freeze($messages);
            }
            if ($thread->candidate_id && ($c = Candidate::withoutGlobalScopes()->find($thread->candidate_id))) {
                if ($stored && $c->state !== CandidateState::Promoted) {
                    $c->update(['state' => CandidateState::Promoted, 'vehicle_id' => $v->id, 'closed_at' => null]);
                } elseif (! $stored && in_array($c->state, [CandidateState::New, CandidateState::Rejected], true)) {
                    $c->update(['vehicle_id' => $v->id]);
                    app(CloseChain::class)($c, 'fact');
                }
            }
        }

        return $n;
    }

    private function owner(): User
    {
        return User::withRole(Role::ParkAdmin)->orderBy('id')->firstOrFail();
    }

    // Аудит

    /** Сверка базы с таблицей: стоящие поштучно, приём и выбытие день в день, письма у 2026, файлы. */
    private function audit(array $rows): bool
    {
        $ok = true;
        $fail = function (string $what, ...$cells) use (&$ok) {
            $ok = false;
            $this->report['Аудит'][] = [$what, ...$cells];
        };
        $all = Vehicle::withoutGlobalScopes()->where('vendor_id', $this->vendor->id)->with([])->withCount(['threads' => fn ($t) => $t->withoutGlobalScopes()])->get();
        $find = fn (array $r) => $all->first(fn (Vehicle $v) => ($r['claim'] && $v->ref_key === Vehicle::keyFor($r['claim']) || ($r['vin'] && $v->vin === $r['vin']))
            && abs($v->accepted_at?->copy()->startOfDay()->diffInDays($r['arrival']) ?? 999) <= 1);
        $seen = [];
        foreach ($rows as $r) {
            $v = $find($r);
            if (! $v) {
                $fail('нет ТС', $r['line'], $r['title'], $r['claim'], $r['arrival']->format('d.m.Y'));

                continue;
            }
            $seen[$v->id] = true;
            if (! $v->accepted_at->isSameDay($r['arrival'])) {
                $fail('приём', $r['line'], $r['title'], $v->accepted_at->format('d.m.Y'), $r['arrival']->format('d.m.Y'));
            }
            $want = ['released' => VehicleState::Released, 'stored' => VehicleState::Stored, 'moscow' => VehicleState::Stored][$r['state']];
            if ($v->state !== $want) {
                $fail('состояние', $r['line'], $r['title'], $v->state->label(), $want->label());
            }
            // Выбытие раньше приёма — опечатка таблицы: выдача не раньше приёма (`ReleasePast`), это не расхождение.
            $ship = $r['ship']?->max($r['arrival']);
            if ($ship && ! $v->released_at?->isSameDay($ship)) {
                $fail('выбытие', $r['line'], $r['title'], $v->released_at?->format('d.m.Y'), $r['ship']->format('d.m.Y'));
            } elseif ($r['ship'] && $r['ship']->lt($r['arrival'])) {
                $this->report['Аудит'][] = ['в таблице выбытие раньше приёма — выдана днём приёма', $r['line'], $r['title'], $r['ship']->format('d.m.Y'), $r['arrival']->format('d.m.Y')];
            }
            if ($r['state'] === 'stored' && (int) $v->yard_id !== $this->yard->id) {
                $fail('парковка', $r['line'], $r['title'], $v->yard_id);
            }
            if ($r['state'] === 'moscow' && (int) $v->city_id !== City::main()) {
                $fail('перевод в Москву', $r['line'], $r['title']);
            }
            if ($r['arrival']->year === 2026 && ! $v->threads_count) {
                $fail('нет писем', $r['line'], $r['title'], $r['claim']);
            }
            $mail = $v->media()->where('custom_properties->source', 'mail')->count();
            if ($v->state === VehicleState::Released && $mail) {
                $fail('файлы из писем у выданной', $r['line'], $r['title'], $mail);
            }
            if ($v->state === VehicleState::Stored && $r['state'] === 'stored') {
                $photos = $v->media()->where('collection_name', 'photos')->count();
                $papers = $v->media()->where('collection_name', 'papers')->count();
                if (! $photos || ! $papers) {
                    $this->report['Аудит'][] = ['мало файлов (очередь ещё тянет?)', $r['line'], $r['title'], "фото {$photos}, документов {$papers}"];
                }
            }
        }
        $stored = $all->where('state', VehicleState::Stored)->where('yard_id', $this->yard->id);
        foreach ($stored as $v) {
            if (! isset($seen[$v->id])) {
                $fail('стоит, а в таблице нет', $v->id, $v->titleWithYear(), $v->ref, $v->accepted_at?->format('d.m.Y'));
            }
        }
        $table = collect($rows);
        $count = fn (Collection $c, string $field, int $m) => $c->filter(fn ($x) => $x[$field] && $x[$field]->year === 2026 && $x[$field]->month === $m)->count();
        $db = fn (string $field, int $m) => $all->filter(fn ($v) => $v->{$field} && $v->{$field}->year === 2026 && $v->{$field}->month === $m && isset($seen[$v->id]))->count();
        foreach ([9 => 'сентябрь', 10 => 'октябрь'] as $m => $name) {
            foreach (['arrival' => 'accepted_at', 'ship' => 'released_at'] as $tf => $vf) {
                [$a, $b] = [$count($table, $tf, $m), $db($vf, $m)];
                $this->report['Аудит'][] = [($tf === 'arrival' ? 'приём ' : 'выбытие ').$name, 'таблица '.$a, 'система '.$b, $a === $b ? 'сходится' : 'НЕ СХОДИТСЯ'];
                $ok = $ok && $a === $b;
            }
        }
        $standing = $table->where('state', 'stored')->count();
        $this->report['Аудит'][] = ['стоят на Краснопутиловской', 'таблица '.$standing, 'система '.$stored->count(), $standing === $stored->count() ? 'сходится' : 'НЕ СХОДИТСЯ'];
        $ok = $ok && $standing === $stored->count();
        foreach ($this->report['Аудит'] as $line) {
            $this->line(implode(' | ', array_map('strval', $line)));
        }
        $this->{$ok ? 'info' : 'error'}($ok ? 'Аудит: всё сходится' : 'Аудит: есть расхождения');

        return $ok;
    }

    // Отчёт

    private function summary(array $plans, bool $applied): void
    {
        foreach ($plans as $p) {
            $r = $p['row'];
            $this->report['Строки'][] = [$r['line'], $r['title'], $r['claim'], $p['ref'] !== $r['claim'] ? $p['ref'] : '', $r['vin'], $r['plate_raw'], $r['arrival']->format('d.m.Y'),
                $r['ship']?->format('d.m.Y') ?? $r['state'], $p['vehicle']?->id ?? 'новая', $p['vehicle']?->state->label() ?? '', count($p['threads']), implode(' ', $p['threads']),
                implode('; ', $p['notes']), $applied ? ($p['result'] ?? '') : ''];
        }
        $plansC = collect($plans);
        $this->table(['', ''], [
            ['строк', count($plans)], ['найдено ТС', $plansC->whereNotNull('vehicle')->count()], ['новых ТС', $plansC->whereNull('vehicle')->count()],
            ['стоят по таблице', $plansC->where('row.state', 'stored')->count()], ['тревоги 2026', count($this->report['Тревоги 2026'])],
            ['писем без строк', count($this->report['Письма без строк'])], ['ТС вне таблицы', count($this->report['ТС вне таблицы'])],
            ['ошибки записи', $plansC->filter(fn ($p) => str_starts_with((string) ($p['result'] ?? ''), 'ошибка') || str_starts_with((string) ($p['result'] ?? ''), 'не записана'))->count()],
        ]);
    }

    private function writeReport(string $file): void
    {
        $heads = [
            'Строки' => ['Строка', 'Марка/модель', 'Убыток', 'Убыток по письмам', 'VIN', 'Госномер', 'Приём', 'Выбытие', 'ТС', 'Было', 'Писем', 'Ветки', 'Заметки', 'Сделано'],
            'Тревоги 2026' => ['Строка', 'Марка/модель', 'Убыток', 'VIN', 'Госномер', 'Приём', 'Что'],
            'Письма без строк' => ['Ветка', 'Первое', 'Последнее', 'Номера', 'Тема', 'ТС'],
            'Расхождения' => ['ТС / строка', 'Марка/модель', 'Что'],
            'ТС вне таблицы' => ['ТС', 'Марка/модель', 'Убыток', 'VIN', 'Госномер', 'Состояние', 'Заведена', 'Принята'],
            'Аудит' => ['Что', '', '', '', ''],
        ];
        $path = $this->option('report') ?: preg_replace('/\.xlsx$/i', '', $file).($this->option('audit') ? ' аудит' : ' сопоставление').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;
        foreach ($this->report as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $first = false;
            $sheet->setName($name);
            $writer->addRow(Row::fromValues($heads[$name]));
            foreach ($rows as $r) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => $v === null ? '' : (is_scalar($v) ? $v : (string) $v), $r)));
            }
        }
        $writer->close();
        $this->info("Отчёт: {$path}");
    }
}
