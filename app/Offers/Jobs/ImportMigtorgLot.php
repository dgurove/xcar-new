<?php

namespace App\Offers\Jobs;

use App\Cars\Brand;
use App\Cars\IdentityTaken;
use App\Live\Publisher;
use App\Live\Topics;
use App\Media\Actions\UnmarkPhoto;
use App\Media\PhotoIngest;
use App\Offers\Actions\ApplyCarFields;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Console\MigtorgSync;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Migtorg;
use App\Offers\MigtorgFields;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Лот Мигторга в предложение — по номеру дела, совпавшему с номером убытка. Поля (`MigtorgFields`, только в пустые)
 * ложатся сразу в `start()` из индекса, задача дочитывает карточку со входом ради кадров. Кадры сами — в пустой ряд
 * или в ряд из кадров этого же лота (скачанных с их сайта руками: `{uuid}_watermark` — такой кадр меняется на
 * оригинал на том же месте); к чужим кадрам — только кнопкой (`manual`). Оригиналы без их знака, пачками по четыре;
 * уже взятые (по uuid) пропускаются, поэтому повтор дочитывает с места обрыва. Ход — кольцом в поле номера убытка
 * (`progress`), по ходу редактор перечитывается сам.
 */
final class ImportMigtorgLot implements ShouldQueue
{
    use Queueable;

    public const STATES = [OfferState::Draft, OfferState::Gallery, OfferState::Open];

    public int $timeout = 1500;

    public int $tries = 3;

    public array $backoff = [300, 1800];

    public function __construct(public int $offerId, public int $lotId, public bool $manual = false)
    {
        // Своя очередь первой у воркера: нажатие не ждёт за спиной архивов Carcade и писем.
        $this->onConnection('database-long')->onQueue('migtorg')->afterCommit();
    }

    /** Ход — своей пилюлей: ключ разбора архива и писем общий, чужая задача стёрла бы его. */
    public static function progress(int $offerId): ?array
    {
        $p = Cache::get("migtorg:offer:{$offerId}");

        // Пять минут без движения — задачу убили (выкладка) или она ждёт повтора: не крутить кольцо вечно.
        return $p && time() - ($p['at'] ?? 0) < 300 ? $p : null;
    }

    /** Ещё не скачанные кадры — заглушками в ряду фото, не больше восьми (как у кадров письма): остальное скажет строка хода. */
    public static function pending(int $offerId): int
    {
        $p = self::progress($offerId);

        return ($p['n'] ?? null) ? max(0, min(8, $p['n'] - $p['i'])) : 0;
    }

    /** Свежий лот по номеру убытка предложения, если он есть в индексе и за фото можно ходить. */
    public static function lotFor(Offer $offer): ?object
    {
        if (! $offer->claim_ref_key) {
            return null;
        }
        $lot = DB::table('migtorg_lots')->where('claim_ref_key', $offer->claim_ref_key)->orderByDesc('id')->first();

        return $lot && Migtorg::ready() ? $lot : null;
    }

    /**
     * Лот о той же машине. VIN с обеих сторон — должен совпасть (у Мигторга VIN скрыт, так бывает редко). Иначе марка и
     * год: вписанные в предложение не должны спорить с лотом. Сам (`$strict`) берётся номер с буквами или длинный
     * («0760/046/14575/26» — 14 знаков, у разных машин не совпадает); короткий из цифр («7805/2024» бывает у разных
     * страховых) — только при совпавших и марке, и годе. Кнопкой — и при споре: в поле видно название лота.
     */
    public static function sameCar(Offer $offer, object $lot, bool $strict): bool
    {
        $vin = strtoupper(trim((string) $offer->vin));
        if ($vin && $lot->vin) {
            return $vin === $lot->vin;
        }
        $lotBrand = $offer->brand_id ? self::brandOf($lot) : null;
        $brand = $lotBrand ? $lotBrand->id === $offer->brand_id : null;
        $lotYear = self::yearOf($lot);
        $year = $offer->year && $lotYear ? (int) $offer->year === $lotYear : null;
        if ($brand === false || $year === false) {
            return ! $strict;
        }
        $long = preg_match('/\p{L}/u', $offer->claim_ref_key) === 1 || strlen($offer->claim_ref_key) >= 12;

        return ! $strict || $long || ($brand && $year);
    }

    /** Поля машины лота из индекса (`data`) — без карточки и входа; у строки без `data` — пусто. */
    public static function fieldsOf(object $lot): array
    {
        $data = $lot->data ? json_decode($lot->data, true) : null;

        return $data ? MigtorgFields::of($data) : [];
    }

    /** Марка лота по справочнику: из `data`, у старых строк — по началу названия («ВАЗ (Lada) Vesta 2025»). */
    private static function brandOf(object $lot): ?Brand
    {
        $title = json_decode((string) $lot->data, true)['lot']['brand']['title'] ?? null;
        if ($title) {
            return Brand::known($title);
        }
        $words = preg_split('/\s+/u', trim(preg_replace('/\s\d{4}$/', '', (string) $lot->title)));
        for ($n = min(3, count($words)); $n > 0; $n--) {
            if ($brand = Brand::known(implode(' ', array_slice($words, 0, $n)))) {
                return $brand;
            }
        }

        return null;
    }

    private static function yearOf(object $lot): ?int
    {
        $year = json_decode((string) $lot->data, true)['lot']['year'] ?? null;

        return $year ? (int) $year : (preg_match('/\s(\d{4})$/', (string) $lot->title, $m) ? (int) $m[1] : null);
    }

    /** Лот для чипа «Мигторг» и кнопки шторки: есть в индексе и не о другой машине. */
    public static function available(Offer $offer): ?object
    {
        $lot = self::lotFor($offer);

        return $lot && self::sameCar($offer, $lot, strict: false) ? $lot : null;
    }

    /**
     * Взять лот: поля — сразу из индекса (марка и пробег в форме уже в ответе на нажатие), кадры — задачей. Лоты с тем
     * же номером помечаются предложением сразу, чтобы синхронизация не ставила задачу второй раз.
     */
    public static function start(Offer $offer, object $lot, bool $manual = false): void
    {
        DB::table('migtorg_lots')->where('claim_ref_key', $offer->claim_ref_key)->update(['offer_id' => $offer->id]);
        if ($fields = self::fieldsOf($lot)) {
            self::glow($offer, app(ApplyCarFields::class)($offer, $fields, null, onlyEmpty: true, log: ['source' => 'migtorg', 'lot' => $lot->id]));
        }
        // Ход — сразу, а не когда задачу возьмёт воркер: чип крутится с нажатия, второй раз кнопку не нажать.
        Cache::put("migtorg:offer:{$offer->id}", ['i' => 0, 'n' => null, 'at' => time()], 1800);
        self::dispatch($offer->id, $lot->id, $manual);
    }

    /**
     * Поля, которые Мигторг только что вписал: редактор, открывшись или перерисовавшись, проигрывает на них вспышку
     * заполнения (`glow_controller`), как у текста про машину и читалки документов. Две минуты — дальше уже не новость.
     */
    public static function glow(Offer $offer, array $fields): void
    {
        if ($fields) {
            Cache::put("migtorg:glow:{$offer->id}", array_values(array_unique([...Cache::get("migtorg:glow:{$offer->id}", []), ...$fields])), 120);
        }
    }

    /** Вписанное Мигторгом с прошлого показа редактора — один раз. @return list<string> */
    public static function glowed(Offer $offer): array
    {
        // Turbo подгружает редактор заранее, при наведении на ссылку: такой запрос список не забирает.
        $prefetch = str_contains((string) request()->header('Sec-Purpose', request()->header('X-Sec-Purpose', '')), 'prefetch');

        return $prefetch ? [] : Cache::pull("migtorg:glow:{$offer->id}", []);
    }

    /** Совпавший лот берётся сам один раз: поля нужны и предложению с фото, кадры задача сама кладёт только в пустой ряд. */
    public static function auto(Offer $offer): bool
    {
        if (! in_array($offer->state, self::STATES, true)) {
            return false;
        }
        $lot = self::lotFor($offer);
        // Не взялся трижды — сутки сам не берётся: иначе синхронизация ставила бы задачу раз в полчаса без конца.
        if (! $lot || $lot->offer_id || ($lot->failed_at && now()->subDay()->lt($lot->failed_at)) || ! self::sameCar($offer, $lot, strict: true)) {
            return false;
        }
        self::start($offer, $lot);

        return true;
    }

    /**
     * Кадр, скачанный с сайта Мигторга и брошенный в предложение, сам называет лот (`migtorg_media`): номер убытка
     * вписывается из лота, поля и остальные кадры — как по «Это она» (человек сам принёс кадр этой машины). Номер уже
     * другой — лот не наш, ничего не трогаем. Кадры летят пачкой: замок, чтобы задача встала один раз.
     */
    public static function byPhoto(Offer $offer, string $uuid, ?User $by): void
    {
        $lot = DB::table('migtorg_lots')->whereIn('id', DB::table('migtorg_media')->where('uuid', $uuid)->select('lot_id'))->whereNotNull('claim_ref_key')->first();
        if (! $lot || ! Migtorg::ready() || ! in_array($offer->state, self::STATES, true) || ($offer->claim_ref_key && $offer->claim_ref_key !== $lot->claim_ref_key)) {
            return;
        }
        Cache::lock("migtorg:take:{$offer->id}", 30)->get(function () use ($offer, $lot, $by) {
            $offer->refresh();
            if (! $offer->claim_ref) {
                // Номер ставит и сам берёт лот (`auto`), если правило «та же машина» пропускает. Номер уже у другого
                // предложения — это его машина: номер не встаёт, под полями строка с «Это она» (`OfferController::twins`).
                try {
                    app(UpdateOffer::class)($offer, ['claim_ref' => $lot->claim_ref], $by);
                } catch (IdentityTaken $e) {
                    Cache::put("offer:twin:{$offer->id}", $e->holder->getKey(), 86400);

                    return;
                }
                $offer->refresh();
            }
            $lot = self::lotFor($offer);
            if ($lot && ! $lot->offer_id && ! self::progress($offer->id)) {
                $offer->log(OfferEventType::Updated, $by, ['source' => 'migtorg', 'lot' => $lot->id]);
                self::start($offer, $lot, manual: true);
            }
        });
    }

    /** «Это она» — человек сверил машину лота: поля и кадры, запись в историю от него. Ответ — тост. */
    public static function take(Offer $offer, ?User $by): string
    {
        $lot = self::available($offer);
        if (! $lot) {
            return 'На Мигторге пока нет';
        }
        if (! self::progress($offer->id)) {
            $offer->log(OfferEventType::Updated, $by, ['source' => 'migtorg', 'lot' => $lot->id]);
            self::start($offer, $lot, manual: true);
        }

        return 'Берём с Мигторга';
    }

    public function handle(Migtorg $migtorg, PhotoIngest $ingest, Publisher $publish, ApplyCarFields $apply, UnmarkPhoto $unmark): void
    {
        $offer = Offer::find($this->offerId);
        if (! $offer) {
            return;
        }
        // Номер убытка сменили, пока задача ждала очереди (или повтора после сбоя), — лот уже о другой машине: не брать.
        // 04.10.2026 так в черновик 1148 с Tenet T7 легли поля и кадры Kia Rio по прежнему номеру.
        $lotKey = DB::table('migtorg_lots')->where('id', $this->lotId)->value('claim_ref_key');
        if (! $lotKey || $lotKey !== $offer->claim_ref_key) {
            Log::info("Мигторг: лот {$this->lotId} не взят в предложение {$offer->id} — номер убытка уже другой");

            return;
        }
        $key = "migtorg:offer:{$offer->id}";
        $added = 0;
        $failed = 0;
        $filled = [];
        try {
            Cache::put($key, ['i' => 0, 'n' => null, 'at' => time()], 1800);
            $card = $migtorg->card($this->lotId);
            self::noteShape($card);
            $row = Migtorg::row($card);
            $files = $row['photos'];
            $lotPhotos = array_values($files);
            // Карточка полнее строки списка: поля и все кадры — в индекс, им пользуются поле номера и загрузка кадров.
            DB::table('migtorg_lots')->where('id', $this->lotId)->update(['photos' => count($lotPhotos), 'data' => json_encode($row['data'], JSON_UNESCAPED_UNICODE)]);
            MigtorgSync::media([$this->lotId => $files]);
            // Поля из индекса легли в start(); карточка дописывает то, чего в списке не было. Правит система, не человек.
            $filled = $apply($offer, MigtorgFields::of($card), null, onlyEmpty: true, log: ['source' => 'migtorg', 'lot' => $this->lotId]);
            self::glow($offer, $filled);
            $offer->refresh();
            $this->refresh($publish, $offer);
            $photos = $offer->media()->where('collection_name', 'photos')->get();
            $have = $photos->map(fn (Media $m) => $m->getCustomProperty('migtorg'))->filter()->flip();
            // Скачанные с их сайта руками — со знаком (снятым сетью), по имени файла: меняются на оригинал на том же месте.
            $named = $photos->reject(fn (Media $m) => $m->getCustomProperty('migtorg'))
                ->keyBy(fn (Media $m) => $files[Migtorg::fileOf($m->file_name) ?? ''] ?? 'own-'.$m->id);
            // Сами кадры — если в ряду нет чужих (из писем, с телефона): к своим менеджер добавит лот кнопкой, без дублей.
            $own = $named->keys()->diff($lotPhotos)->isNotEmpty();
            $todo = $this->manual || ! $own ? array_values(array_filter($lotPhotos, fn ($u) => ! $have->has($u))) : [];
            $lotPhotos || ! $this->manual || Log::warning("Мигторг: в карточке лота {$this->lotId} нет фото");
            foreach (array_chunk($todo, Migtorg::BATCH) as $b => $batch) {
                // Две пачки — восемь кадров: редактор морфом меняет заглушки на настоящие, кольцо считает «12 из 54».
                if ($b && $b % 2 === 0) {
                    $this->refresh($publish, $offer);
                }
                foreach ($migtorg->downloadMany($batch) as $uuid => $temp) {
                    Cache::put($key, ['i' => $added + $failed, 'n' => count($todo), 'at' => time()], 1800);
                    try {
                        // Не пришедший пачкой — ещё раз по одному, с паузой.
                        $temp ??= tap(tempnam(sys_get_temp_dir(), 'kadr-'), fn ($t) => $migtorg->download($uuid, $t));
                        if ($old = $named->get($uuid)) {
                            $this->original($old, $uuid, $temp, $ingest, $unmark);
                        } elseif ($offer->media()->where('collection_name', 'photos')->where('custom_properties->migtorg', $uuid)->exists()) {
                            // Тот же кадр принесли руками, пока шла задача (`PhotoIngest` взял оригинал) — второй не нужен.
                            continue;
                        } else {
                            $ingest->add($offer, 'photos', $temp, 'migtorg-'.(array_search($uuid, $lotPhotos, true) + 1).'.jpg', ['migtorg' => $uuid]);
                        }
                        $added++;
                    } catch (Throwable $e) {
                        $failed++;
                        Log::warning("Мигторг: кадр {$uuid} лота {$this->lotId} не забран: ".$e->getMessage());
                    } finally {
                        $temp && @unlink($temp);
                    }
                }
            }
        } finally {
            Cache::forget($key);
        }
        Log::info("Мигторг: лот {$this->lotId} → предложение {$offer->id}: полей ".count($filled).", кадров {$added}".($failed ? ", не забрано {$failed}" : ''));
        if ($added) {
            $offer->log(OfferEventType::Updated, null, ['source' => 'migtorg', 'lot' => $this->lotId, 'photos' => $added]);
        }
        OfferStateChanged::dispatch($offer->fresh());
        $this->refresh($publish, $offer);
        // Тост — только когда нажали кнопку: сами задачи синхронизации всем сотрудникам не звонят.
        if ($this->manual) {
            $publish->toast(Topics::STAFF, match (true) {
                ! $added => 'С Мигторга нового нет',
                ! $failed => "Фото с Мигторга: {$added}",
                default => "Фото с Мигторга: {$added}, не забрано {$failed}",
            }, "/offers/{$offer->number}", 'photo');
        }
    }

    public function failed(?Throwable $e): void
    {
        // Лоты номера свободны, как их пометил start(): кнопка «С Мигторга» вернётся, сам — через сутки.
        DB::table('migtorg_lots')->where('offer_id', $this->offerId)->update(['offer_id' => null, 'failed_at' => now()]);
        Log::warning("Мигторг: лот {$this->lotId} в предложение {$this->offerId} не взят: ".$e?->getMessage());
    }

    /**
     * Оригинал вместо кадра, скачанного с их сайта со знаком: тот же media — порядок, «скрыт», главный кадр, наш знак
     * (`UnmarkPhoto::replace`). Знак снимать было не с чего — копия со знаком не нужна.
     */
    private function original(Media $media, string $uuid, string $file, PhotoIngest $ingest, UnmarkPhoto $unmark): void
    {
        $unmark->replace($media, $file, $ingest);
        @unlink(UnmarkPhoto::markedPath($media));
        $media->forgetCustomProperty('unmarked');
        $media->setCustomProperty('migtorg', $uuid);
        $media->save();
    }

    /** Открытый редактор предложения перечитывается морфом (без Mercure локально — молча ничего). */
    private function refresh(Publisher $publish, Offer $offer): void
    {
        $publish->refresh(Topics::STAFF, ['/offers/'.$offer->number]);
    }

    /** Что отдаёт карточка со входом сверх строки списка — ключами, без значений, один раз: по ним дописать поля. */
    private static function noteShape(array $card): void
    {
        if (Cache::add('migtorg:card-shape', true, now()->addMonth())) {
            Log::info('Мигторг: карточка лота, ключи '.implode(',', array_keys($card)).'; lot: '.implode(',', array_keys($card['lot'] ?? [])).'; media: '.implode(',', array_keys($card['media'] ?? [])));
        }
    }
}
