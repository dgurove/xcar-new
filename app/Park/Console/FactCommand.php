<?php

namespace App\Park\Console;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Category;
use App\Cars\Names;
use App\Mail\Actions\CloseChain;
use App\Mail\Actions\LinkThread;
use App\Mail\Candidate;
use App\Mail\CandidateStage;
use App\Mail\CandidateState;
use App\Mail\Direction;
use App\Mail\Extraction\Code;
use App\Mail\Scope;
use App\Park\Actions\PromoteCandidate;
use App\Park\Actions\PurgeLetters;
use App\Park\Actions\RegisterFromLetters;
use App\Park\Vehicle;
use App\Park\Yard;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Разовое приведение стоянки к факту по таблице владельца («стоянка выгрузка 21.09.2026.xlsx»: стоящие ТС и
 * выданные в сентябре). Каждая строка → ТС задним числом (`RegisterFromLetters`, источник `fact`): дата приёма и
 * поля — из таблицы, цепочка писем — по номеру убытка, VIN, госномеру (опечатки таблицы поправлены списком
 * `CODE_FIXES`), выданная по письмам до сентября без даты выбытия в таблице — не заводится (владелец верит письмам).
 * Цепочки Москвы, принятые после таблицы (`LATER`), заводятся по письмам; остальные цепочки стоянки закрываются,
 * кроме СПб (`SPB_SENDERS`) и свежих заявок без приёма. Без `--apply` — только сопоставление и отчёт xlsx рядом с файлом.
 */
class FactCommand extends Command
{
    protected $signature = 'park:fact {file : xlsx владельца} {--apply : завести ТС и закрыть цепочки} {--report= : куда положить отчёт xlsx}';

    protected $description = 'Привести стоянку к факту по таблице владельца: ТС из строк, цепочки писем к ним, остальное закрыть';

    private const COLUMNS = [
        'марка/модель тс' => 'name', 'vin' => 'vin', 'год выпуска' => 'year', 'цвет' => 'color', 'стс' => 'sts', '(э)птс' => 'pts',
        'рег.номер' => 'plate', 'дата приема' => 'accepted_at', 'дата отгрузки тс' => 'released_at', 'страховая' => 'vendor',
        'номер убытка/полиса' => 'code', '№ полиса' => 'policy_no', 'заявленная стоимость тс' => 'value', 'комментарий1' => 'yard',
    ];

    /** Опечатки таблицы: VIN строки → номер убытка, как он в письмах. */
    private const CODE_FIXES = [
        'LVTDB21B5ND305919' => '0760/046/18841/25', 'LS4ASE2E6RA923631' => '0760/046/10203/26', 'EDEGD34B6SE054383' => '0760/046/12104/26',
        'X4XVM99420VZ96590' => '0802/046/01541/26', 'EC3DLUFD1TC0112832' => '0760/046/13705/26', 'Z8TXTGF2WKM008517' => '0790/046/07959/26',
        'XTAFS025LS1496455' => '0323/046/00832/26', 'LGWFF9A64PM604595' => '7814/046/01294/26',
        'LVVDB21B1PD741502' => '6892/046/01695/26', 'XW8ZZZ5NZKG224768' => '0790/046/13597/25', 'X6D234900M0731819' => '784R/046/00092/25', 'LB3FX1S15RB136181' => '0760/046/13327/26',
    ];

    /** Опечатки VIN. */
    private const VIN_FIXES = ['LB3FX1S115RB136181' => 'LB3FX1S15RB136181'];

    /** Цепочки без номера в таблице: VIN строки → ключ цепочки. */
    private const KEY_FIXES = ['LB3F31038RG041648' => 'vin:XD2374252P3000037'];

    /** Приняты после таблицы (наш фотоотчёт в письмах): ключи цепочек. */
    private const LATER = [
        'code:0383/046/00105/26', 'code:0804/046/01065/26', 'code:0790/046/06547/26', 'code:6807/046/02086/26', 'code:0760/046/12967/26',
        'code:0730/046/03132/26', 'code:Z691/046/08191/26', 'code:0790/046/09528/26', 'code:Z691/046/01928/26', 'code:0325/046/00567/26',
        'code:6392/046/00581/26', 'code:11575256', 'code:11561857', 'code:11629426', 'code:11613998', 'code:C2600441', 'code:0020790823',
        'plate:Т367ВН790',
    ];

    /** Письма СПб: цепочки не трогаем. */
    private const SPB_SENDERS = ['kolbasinamp@alfastrah.ru', 'filippovaiaiu@alfastrah.ru', 'neshicheva.spb@vsk.ru'];

    private const KEEP_INTAKE_FROM = '2026-08-22';

    private const MONTH_START = '2026-09-01';

    private const YARDS = ['измайловский лес' => 'измайловский лес', '1 мая' => '1 мая', 'шоссе энтузиастов' => 'шоссе энтузиастов'];

    private const VENDORS = ['альфастрахование' => 'альфастрахование', 'согаз' => 'согаз', 'интери' => 'интери', 'абсолют' => 'абсолют страхование'];

    /** @var array<string, list<int>> ключ → цепочки */
    private array $byKey = [];

    /** @var array<int, Candidate> */
    private array $candidates = [];

    /** @var array<int, list<string>> цепочка → её ключи */
    private array $keysOf = [];

    /** @var array<int, int> цепочка → строка таблицы, которая её взяла */
    private array $taken = [];

    private bool $apply = false;

    private ?User $owner = null;

    /** @var array<string, list<array>> листы отчёта */
    private array $report = ['Строки' => [], 'Выданы по письмам' => [], 'Приняты после таблицы' => [], 'Закрыты' => [], 'СПб оставлены' => [], 'Ждут заявки' => []];

    public function handle(RegisterFromLetters $register, PromoteCandidate $promote, LinkThread $link, CloseChain $close, PurgeLetters $purge): int
    {
        $this->apply = (bool) $this->option('apply');
        ini_set('memory_limit', '1G');
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("Файла нет: {$file}");

            return self::FAILURE;
        }
        $this->owner = User::where('role', Role::Admin)->orderBy('id')->first();
        if ($this->apply && ! $this->owner) {
            $this->error('Нет пользователя-владельца');

            return self::FAILURE;
        }
        $rows = $this->read($file);
        $this->line('Строк: '.count($rows).($this->apply ? ' — заводим' : ' — только сопоставление'));
        $this->index();

        $seenVins = [];
        $stats = ['stored' => 0, 'sold' => 0, 'released' => 0, 'skipped' => 0, 'exists' => 0, 'later' => 0, 'closed' => 0, 'spb' => 0, 'waiting' => 0];
        foreach ($rows as $row) {
            $draft = $this->draft($row);
            [$candidate, $how] = $this->match($draft);
            $flags = $draft['flags'];
            // Та же ТС второй строкой (Lada Granta О262РН198: выдача записана приёмом) — дубль, не вторая ТС.
            if ($draft['vin'] && isset($seenVins[$draft['vin']])) {
                $stats['skipped']++;
                $this->row($row, $draft, $candidate, $how, null, null, "дубль строки {$seenVins[$draft['vin']]}", null, $flags);

                continue;
            }
            $seenVins[$draft['vin']] = $row['line'];
            if ($candidate && isset($this->taken[$candidate->id])) {
                $flags[] = "цепочка c{$candidate->id} уже у строки {$this->taken[$candidate->id]}";
                $candidate = null;
            }
            $stage = $candidate?->stage?->value;
            $releasedStage = $candidate?->stageOf(CandidateStage::Released);
            $soldStage = $candidate?->stageOf(CandidateStage::Sold);
            $letterRelease = ($releasedStage['at'] ?? null) ? Carbon::parse($releasedStage['at']) : null;
            $merged = $candidate && count(array_filter($this->keysOf[$candidate->id] ?? [], fn ($k) => str_starts_with($k, 'code:'))) > 1;
            if ($merged) {
                $flags[] = 'слитая цепочка: ветки к ТС только по её номеру';
            }

            // Решение по строке.
            $releasedAt = $draft['released_at'];
            if (! $releasedAt && $letterRelease && $letterRelease->lt(Carbon::parse(self::MONTH_START))) {
                $action = 'не заводить: выдана по письму '.$letterRelease->format('d.m.Y');
                $this->report['Выданы по письмам'][] = [$row['line'], $draft['name'], $draft['vin'], $draft['code'], $draft['accepted_at']?->format('d.m.Y'), $letterRelease->format('d.m.Y'), "c{$candidate->id}", $this->lastSubject($candidate)];
                if ($candidate) {
                    $this->taken[$candidate->id] = $row['line'];
                    $this->apply && $close($candidate, 'fact');
                }
                $stats['skipped']++;
                $this->row($row, $draft, $candidate, $how, $stage, $letterRelease, $action, null, $flags);

                continue;
            }
            if (! $releasedAt && $letterRelease) {
                $releasedAt = $letterRelease->startOfDay();
                $flags[] = 'дата выдачи из письма';
            }
            $sold = ! $releasedAt && $soldStage;
            $exists = $this->existing($draft);
            if ($exists) {
                $action = "уже есть ТС #{$exists->id}";
                $stats['exists']++;
                $this->row($row, $draft, $candidate, $how, $stage, $letterRelease, $action, $exists, $flags);

                continue;
            }
            $action = $releasedAt ? 'выдана '.$releasedAt->format('d.m.Y') : ($sold ? 'стоит, продана' : 'стоит');
            $stats[$releasedAt ? 'released' : ($sold ? 'sold' : 'stored')]++;
            if ($candidate) {
                $this->taken[$candidate->id] = $row['line'];
            }
            $vehicle = null;
            if ($this->apply) {
                $data = $this->data($draft, $candidate);
                $stages = array_filter([
                    'accepted_at' => $draft['accepted_at']?->toDateTimeString(), 'yard_id' => $draft['yard_id'], 'source' => 'fact',
                    'sold' => $sold, 'sold_at' => $sold ? $soldStage['at'] : null, 'pickup_name' => $sold ? $soldStage['name'] ?? null : null, 'pickup_phone' => $sold ? $soldStage['phone'] ?? null : null,
                    'released_at' => $releasedAt?->toDateTimeString(),
                ]);
                $vehicle = $register($this->owner, $merged ? null : $candidate, $data, $stages);
                if ($candidate && $merged) {
                    $link->forVehicle($vehicle, files: ! $releasedAt);
                    $close($candidate, 'fact');
                } elseif ($candidate) {
                    $promote->attach($candidate, $vehicle);
                }
                if ($releasedAt) {
                    $purge($vehicle);
                }
            }
            $this->row($row, $draft, $candidate, $how, $stage, $letterRelease, $action, $vehicle, $flags);
        }

        // Приняты после таблицы — по письмам, без парковки.
        foreach (self::LATER as $key) {
            $candidate = $this->candidateByKey($key);
            if (! $candidate) {
                $this->warn("Цепочка {$key} не найдена");

                continue;
            }
            if (isset($this->taken[$candidate->id]) || $candidate->state === CandidateState::Promoted) {
                continue;
            }
            $this->taken[$candidate->id] = 0;
            $stored = $candidate->stageOf(CandidateStage::Stored);
            $first = $candidate->messages()->orderBy('mail_messages.date_at')->first();
            $acceptedAt = ($stored['at'] ?? null) ? Carbon::parse($stored['at'])->startOfDay() : $first?->date_at?->startOfDay();
            $soldStage = $candidate->stageOf(CandidateStage::Sold);
            $draft = $this->draftFromCandidate($candidate);
            $flag = implode('; ', array_filter([($stored['at'] ?? null) ? null : 'дата приёма = первое письмо, уточнить', $draft['brand'] ? null : 'марки в письмах нет']));
            $exists = $this->existing($draft);
            $vehicle = null;
            if (! $exists && $this->apply) {
                $stages = array_filter(['accepted_at' => $acceptedAt?->toDateTimeString(), 'source' => 'fact', 'sold' => (bool) $soldStage,
                    'sold_at' => $soldStage['at'] ?? null, 'pickup_name' => $soldStage['name'] ?? null, 'pickup_phone' => $soldStage['phone'] ?? null]);
                $vehicle = $register($this->owner, $candidate, $this->data($draft, $candidate), $stages);
                $promote->attach($candidate, $vehicle);
            }
            $stats['later']++;
            $this->report['Приняты после таблицы'][] = ["c{$candidate->id}", $candidate->code, $candidate->title(), $draft['vin'], $draft['plate'], $candidate->vendor?->name, $acceptedAt?->format('d.m.Y'), $soldStage ? 'продана' : '', $exists ? "уже есть ТС #{$exists->id}" : ($vehicle ? "ТС #{$vehicle->id}" : 'завести'), $flag];
        }

        // Остальные цепочки стоянки — закрыть; СПб и свежие заявки без приёма — оставить.
        $rest = Candidate::where('scope', Scope::Park)->whereIn('state', [CandidateState::New, CandidateState::Rejected])->whereNotIn('id', array_keys($this->taken))->orderBy('id')->get();
        foreach ($rest as $candidate) {
            $sender = $this->firstSender($candidate);
            $line = ["c{$candidate->id}", $candidate->code, $candidate->title(), $candidate->stage->value, $candidate->state->label(), $candidate->last_message_at?->format('d.m.Y'), $sender];
            // СПб не трогаем; выданные по письмам и уже в архиве — закрываются (по новому правилу свёртки они и так закрытые).
            if (in_array(mb_strtolower((string) $sender), self::SPB_SENDERS, true) && ! ($candidate->state === CandidateState::Rejected && $candidate->stage === CandidateStage::Released)) {
                $this->report['СПб оставлены'][] = $line;
                $stats['spb']++;

                continue;
            }
            if ($candidate->stage === CandidateStage::Intake && $candidate->state === CandidateState::New && $candidate->last_message_at?->gte(Carbon::parse(self::KEEP_INTAKE_FROM))) {
                $this->report['Ждут заявки'][] = $line;
                $stats['waiting']++;

                continue;
            }
            $this->report['Закрыты'][] = $line;
            $stats['closed']++;
            $this->apply && $close($candidate, 'fact');
        }

        $reportPath = $this->option('report') ?: preg_replace('/\.xlsx$/iu', '', $file).' сопоставление.xlsx';
        $this->write($reportPath);
        $this->line(sprintf('Стоят: %d, проданы: %d, выданы: %d, не заведены (выданы по письмам): %d, уже есть: %d, приняты после таблицы: %d; закрыто цепочек: %d, СПб оставлено: %d, ждут заявки: %d',
            $stats['stored'], $stats['sold'], $stats['released'], $stats['skipped'], $stats['exists'], $stats['later'], $stats['closed'], $stats['spb'], $stats['waiting']));
        $this->line("Отчёт: {$reportPath}");

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function read(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $map = null;
                foreach ($sheet->getRowIterator() as $line => $row) {
                    $cells = array_map(fn ($v) => $v instanceof \DateTimeInterface ? Carbon::instance($v) : trim(is_scalar($v) ? (string) $v : ''), $row->toArray());
                    if ($map === null) {
                        $map = [];
                        foreach ($cells as $i => $title) {
                            $key = mb_strtolower(preg_replace('/\s+/u', ' ', (string) $title));
                            if (isset(self::COLUMNS[$key])) {
                                $map[self::COLUMNS[$key]] = $i;
                            }
                        }
                        if (! isset($map['vin'])) {
                            $map = null;
                        }

                        continue;
                    }
                    if (implode('', array_map(fn ($c) => $c instanceof CarbonInterface ? 'd' : $c, $cells)) === '') {
                        continue;
                    }
                    $get = fn (string $k) => isset($map[$k], $cells[$map[$k]]) && $cells[$map[$k]] !== '' ? $cells[$map[$k]] : null;
                    $rows[] = ['line' => $line, 'cells' => $cells] + array_combine(array_values(self::COLUMNS), array_map($get, array_values(self::COLUMNS)));
                }
                if ($map !== null) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /** Строка таблицы → поля ТС: марка/модель по словарю, номер и VIN с поправками, вендор и парковка по имени. */
    private function draft(array $row): array
    {
        $flags = [];
        $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $row['vin']));
        $vin = self::VIN_FIXES[$vin] ?? $vin;
        $code = self::CODE_FIXES[$vin] ?? $row['code'];
        $notes = null;
        if (strlen($vin) > 17) {
            // В колонку VIN не влезет — в заметку, VIN пустой, поправят руками.
            $notes = 'VIN из таблицы: '.$vin;
            $flags[] = 'VIN длиннее 17 знаков, в заметке';
            $vin = null;
        } elseif (strlen($vin) !== 17) {
            $flags[] = 'VIN не 17 знаков';
        }
        if ($code !== $row['code']) {
            $flags[] = "номер по письмам {$code}";
        }
        $name = trim((string) $row['name']);
        $found = Names::find($name);
        $brand = $found['brand'] ?? null;
        $model = $found['model'] ?? null;
        if (! $brand) {
            // «3009Z5», «Полуприцеп Изотермический»: первое слово маркой, остальное моделью.
            $words = preg_split('/\s+/u', $name) ?: [$name];
            $brandName = preg_match('/^3009/', $words[0]) ? 'ГАЗ' : $words[0];
            $brand = Brand::resolve($brandName);
            $model = $brandName === 'ГАЗ' ? $name : (implode(' ', array_slice($words, 1)) ?: null);
            $flags[] = 'марка по первому слову';
        }
        $vendorKey = mb_strtolower(trim((string) $row['vendor']));
        $vendor = Vendor::whereRaw('lower(name) = ?', [self::VENDORS[$vendorKey] ?? $vendorKey])->first();
        if (! $vendor) {
            $flags[] = 'вендор не найден: '.$row['vendor'];
        }
        $yardName = mb_strtolower(trim((string) $row['yard']));
        $yard = $yardName !== '' ? Yard::whereRaw('lower(name) = ?', [self::YARDS[$yardName] ?? $yardName])->first() : null;
        if ($yardName !== '' && ! $yard) {
            $flags[] = 'парковка не найдена: '.$row['yard'];
        }
        $date = fn ($v) => $v instanceof CarbonInterface ? Carbon::instance($v)->startOfDay() : ($v ? Carbon::parse((string) $v)->startOfDay() : null);
        $color = $row['color'] ? mb_ucfirst(mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $row['color'])))) : null;

        return [
            'line' => $row['line'], 'name' => $name, 'brand' => $brand, 'model' => $model, 'vin' => $vin, 'code' => $code,
            'year' => $row['year'] ? (int) $row['year'] : null, 'color' => $color, 'sts' => $row['sts'] ? (string) $row['sts'] : null, 'pts' => $row['pts'] ? (string) $row['pts'] : null,
            'plate' => $row['plate'] ? mb_strtoupper(preg_replace('/\s+/u', '', (string) $row['plate'])) : null,
            'accepted_at' => $date($row['accepted_at']), 'released_at' => $date($row['released_at']),
            'vendor_id' => $vendor?->id, 'policy_no' => $row['policy_no'] ? (string) $row['policy_no'] : null,
            'value' => $row['value'] ? (int) round((float) str_replace([' ', ','], ['', '.'], (string) $row['value'])) : null,
            'yard_id' => $yard?->id, 'yard' => $yard, 'flags' => $flags, 'notes' => $notes,
        ];
    }

    /** Цепочка, принятая после таблицы: поля из свёртки писем. */
    private function draftFromCandidate(Candidate $candidate): array
    {
        // Марка из свёртки, иначе из темы первого письма («0020790823 ТС 2024 ВАЗ/Lada 2190/Granta (С778КТ977)»).
        $found = Names::find(trim(($candidate->value('brand') ?? '').' '.($candidate->value('model') ?? ''))) ?? Names::find(str_replace('/', ' ', (string) $candidate->subject));
        $brand = $found['brand'] ?? ($candidate->value('brand') ? Brand::resolve($candidate->value('brand')) : null);
        $vin = $candidate->value('vin') ? strtoupper((string) $candidate->value('vin')) : null;

        return [
            'line' => 0, 'name' => $candidate->title(), 'brand' => $brand, 'model' => $found['model'] ?? $candidate->value('model'), 'vin' => $vin, 'code' => $candidate->code,
            'year' => $candidate->value('year'), 'color' => $candidate->value('color') ? mb_ucfirst((string) $candidate->value('color')) : null, 'sts' => null, 'pts' => null,
            'plate' => Candidate::plateKey($candidate->value('plate')), 'accepted_at' => null, 'released_at' => null,
            'vendor_id' => $candidate->vendor_id, 'policy_no' => null, 'value' => $candidate->value('value'), 'yard_id' => null, 'yard' => null, 'flags' => [], 'notes' => null,
        ];
    }

    /** Поля для `RegisterFromLetters`: таблица главнее, из цепочки — чего в таблице нет. */
    private function data(array $draft, ?Candidate $candidate): array
    {
        $brand = $draft['brand'];
        $model = $brand && $draft['model'] ? CarModel::resolve($brand, $draft['model']) : null;
        $v = fn (string $f) => $candidate?->value($f);
        $category = Category::guess($draft['name']) ?? Category::tryFrom((string) $v('category')) ?? Category::Passenger;

        return [
            'ref' => $draft['code'], 'vin' => $draft['vin'] ?: $v('vin'), 'plate' => $draft['plate'] ?: Candidate::plateKey($v('plate')), 'year' => $draft['year'] ?: $v('year'),
            'brand_id' => $brand?->id, 'model_id' => $model?->id, 'vendor_id' => $draft['vendor_id'] ?: $v('vendor_id'), 'category' => $category->value,
            'color' => $draft['color'] ?: ($v('color') ? mb_ucfirst((string) $v('color')) : null), 'policy_no' => $draft['policy_no'], 'pts' => $draft['pts'], 'sts' => $draft['sts'],
            'contact_name' => $v('insured_name'), 'contact_phone' => $v('insured_phone') ?? ((array) $v('phones'))[0] ?? null,
            'flags' => $v('flags') ?: [], 'docs_required' => $v('docs_required') ?: [], 'value' => $draft['value'] ?: $v('value'), 'notes' => $draft['notes'],
        ];
    }

    /** Индекс: ключ → цепочки стоянки (через письма), цепочка → её ключи. */
    private function index(): void
    {
        $this->candidates = Candidate::where('scope', Scope::Park)->with('vendor')->get()->keyBy('id')->all();
        $pairs = DB::table('mail_candidate_messages as cm')->join('mail_message_keys as mk', 'mk.message_id', '=', 'cm.message_id')
            ->whereIn('cm.candidate_id', array_keys($this->candidates))->select('cm.candidate_id', 'mk.key')->distinct()->get();
        foreach ($pairs as $p) {
            $this->byKey[$p->key][] = (int) $p->candidate_id;
            $this->keysOf[(int) $p->candidate_id][] = $p->key;
        }
        foreach ($this->candidates as $c) {
            if ($c->key && ! in_array($c->id, $this->byKey[$c->key] ?? [], true)) {
                $this->byKey[$c->key][] = $c->id;
                $this->keysOf[$c->id][] = $c->key;
            }
        }
    }

    /** @return array{0: ?Candidate, 1: string} цепочка и как нашлась */
    private function match(array $draft): array
    {
        $keys = array_filter([
            'code' => $draft['code'] ? 'code:'.Code::key($draft['code']) : null,
            'vin' => $draft['vin'] ? 'vin:'.$draft['vin'] : null,
            'plate' => $draft['plate'] ? 'plate:'.$draft['plate'] : null,
            'key' => self::KEY_FIXES[$draft['vin']] ?? null,
        ]);
        $hits = [];
        foreach ($keys as $how => $key) {
            foreach ($this->byKey[$key] ?? [] as $id) {
                $hits[$id][] = $how;
            }
        }
        if ($hits) {
            // Несколько цепочек: та, что по номеру; иначе первая по id.
            $ids = array_keys($hits);
            usort($ids, fn ($a, $b) => (in_array('code', $hits[$b], true) <=> in_array('code', $hits[$a], true)) ?: $a <=> $b);
            $how = implode('+', $hits[$ids[0]]).(count($ids) > 1 ? ' (ещё '.(count($ids) - 1).')' : '');

            return [$this->candidates[$ids[0]], $how];
        }
        // Опечатка в номере: расстояние ≤ 2 по ключу и та же марка.
        if ($draft['code'] && $draft['brand']) {
            $key = Code::key($draft['code']);
            $best = null;
            foreach ($this->candidates as $c) {
                if (! $c->code || $c->state === CandidateState::Promoted) {
                    continue;
                }
                $d = levenshtein($key, (string) Code::key($c->code));
                if ($d <= 2 && ($found = Names::find((string) $c->value('brand'))) && $found['brand']->id === $draft['brand']->id && ($best === null || $d < $best[1])) {
                    $best = [$c, $d];
                }
            }
            if ($best) {
                return [$best[0], "похожий номер {$best[0]->code}"];
            }
        }

        return [null, ''];
    }

    private function candidateByKey(string $key): ?Candidate
    {
        [$kind, $value] = explode(':', $key, 2);
        $normalized = $kind === 'code' ? 'code:'.Code::key($value) : $key;
        foreach ($this->byKey[$normalized] ?? [] as $id) {
            if ($this->candidates[$id]->state !== CandidateState::Promoted) {
                return $this->candidates[$id];
            }
        }

        return null;
    }

    private function existing(array $draft): ?Vehicle
    {
        $key = $draft['code'] ? Vehicle::keyFor($draft['code']) : null;

        return Vehicle::query()->where(fn ($q) => $q->when($key, fn ($q) => $q->where('ref_key', $key))->when($draft['vin'], fn ($q) => $q->orWhere('vin', $draft['vin'])))
            ->when(! $key && ! $draft['vin'], fn ($q) => $q->whereRaw('false'))->orderByDesc('id')->first();
    }

    private function firstSender(Candidate $candidate): ?string
    {
        return $candidate->messages()->where('mail_messages.direction', Direction::In)->orderBy('mail_messages.date_at')->value('from_email');
    }

    private function lastSubject(Candidate $candidate): string
    {
        $m = $candidate->messages()->orderByDesc('mail_messages.date_at')->first();

        return $m ? $m->date_at?->format('d.m.Y').' '.$m->subject : '';
    }

    private function row(array $row, array $draft, ?Candidate $candidate, string $how, ?string $stage, ?Carbon $letterRelease, string $action, ?Vehicle $vehicle, array $flags): void
    {
        $cells = array_map(fn ($c) => $c instanceof CarbonInterface ? $c->format('d.m.Y') : $c, $row['cells']);
        $this->report['Строки'][] = [...$cells, $candidate ? "c{$candidate->id}" : '', $how, $stage ? CandidateStage::from($stage)->label() : '', $letterRelease?->format('d.m.Y') ?? '',
            $action, $vehicle ? "#{$vehicle->id}" : '', $draft['brand']?->name.' '.$draft['model'], implode('; ', $flags)];
    }

    private function write(string $path): void
    {
        $heads = [
            'Строки' => ['Марка/модель ТС', 'VIN', 'Год выпуска', 'Цвет', 'СТС', '(Э)ПТС', 'Рег.номер', 'Дата приема', 'Дата отгрузки ТС', 'Страховая', 'Номер убытка/полиса', '№ Полиса', 'Заявленная стоимость ТС', 'Комментарий1',
                'Цепочка', 'Как нашлась', 'Этап по письмам', 'Выдана по письму', 'Действие', 'ТС', 'Марка, модель в системе', 'Флаги'],
            'Выданы по письмам' => ['Строка', 'Марка/модель', 'VIN', 'Номер', 'Приём по таблице', 'Выдана по письму', 'Цепочка', 'Последнее письмо'],
            'Приняты после таблицы' => ['Цепочка', 'Номер', 'Марка, модель', 'VIN', 'Госномер', 'Вендор', 'Дата приёма', 'Продана', 'Действие', 'Флаг'],
            'Закрыты' => ['Цепочка', 'Номер', 'Марка, модель', 'Этап', 'Было', 'Последнее письмо', 'Отправитель'],
            'СПб оставлены' => ['Цепочка', 'Номер', 'Марка, модель', 'Этап', 'Состояние', 'Последнее письмо', 'Отправитель'],
            'Ждут заявки' => ['Цепочка', 'Номер', 'Марка, модель', 'Этап', 'Состояние', 'Последнее письмо', 'Отправитель'],
        ];
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
    }
}
