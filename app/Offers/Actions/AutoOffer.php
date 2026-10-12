<?php

namespace App\Offers\Actions;

use App\Cars\Brand;
use App\Cars\Vin\Vin;
use App\Mail\Actions\PromoteCandidate;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\Code;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Mail\Scan\AutoScan;
use App\Mail\Scope;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Support\Nav;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Письмо-предложение на deal@ / offer@ → черновик сам (12.10.2026, владелец: «80 штук прокликивать „Завести“ неудобно,
 * пусть превращаются в черновики, но ТОЛЬКО если система уверена, что это предложение, а не левое письмо»). Дверь та же,
 * что «Завести» (`PromoteCandidate`): поля из писем, ветки к черновику, файлы — `ImportThreadFiles` (вложения, архивы,
 * облако), документы читаются в фоне (`AutoScan`, предмет `o:{id}`), пустые поля встают сами.
 *
 * Порядок — как просил владелец: сначала номер убытка и закупочная (по ним ясно, в какую вкладку встанет строка:
 * «Из таблицы Кати», «Без продажной цены» или «Без закупочной»), черновик с ними сразу — строка появляется, — а файлы и
 * распознанное догружаются на глазах.
 *
 * Не заводит — цепочка ждёт человека в «Из писем»: вендор не узнан, номера убытка нет или их несколько (письмо о двух
 * машинах), машины нет (ни VIN, ни марки из справочника), письмо старше недели (история ящика).
 */
final class AutoOffer
{
    /** Письмо старше — не новое предложение, а догнавшая история: решит человек. */
    private const FRESH_DAYS = 7;

    public function __construct(private PromoteCandidate $promote) {}

    /** Почему цепочка не заводится сама; null — заводится. `$old` — прогон руками: возраст письма не в счёт. */
    public function blocker(Candidate $candidate, bool $old = false): ?string
    {
        if ($candidate->scope !== Scope::Offers || $candidate->state !== CandidateState::New) {
            return 'не ждёт в «Из писем»';
        }
        $letter = $this->letter($candidate);
        if (! $letter) {
            return 'письма вендора нет';
        }
        if (! $old && $letter->date_at && $letter->date_at->lt(now()->subDays(self::FRESH_DAYS))) {
            return 'письмо старше недели';
        }
        if (! $candidate->vendor_id && ! $candidate->value('vendor_id')) {
            return 'вендор не узнан';
        }
        $codes = $candidate->messages->flatMap(fn (Message $m) => array_filter($m->keys(), fn ($k) => str_starts_with($k, 'code:')))->unique();
        if ($codes->count() > 1) {
            return 'в письмах несколько номеров убытка';
        }
        if (! $candidate->code) {
            return 'номера убытка нет';
        }
        if (! Vin::full((string) $candidate->value('vin')) && ! ($candidate->value('brand') && Brand::known((string) $candidate->value('brand')))) {
            return 'машины нет: ни VIN, ни марки';
        }

        return null;
    }

    /** Вкладка, куда встанет строка: номер в таблице Кати — туда, иначе по закупочной из письма. */
    public function tab(Candidate $candidate): string
    {
        return match (true) {
            LegacyClaim::where('claim_ref_key', Code::key($candidate->code))->exists() => 'legacy',
            (int) $candidate->value('floor_price') >= PromoteCandidate::MIN_FLOOR => 'unpriced',
            default => 'nofloor',
        };
    }

    /** Завести; null — не уверены или письма легли к уже заведённому. */
    public function __invoke(Candidate $candidate, bool $old = false): ?Offer
    {
        $candidate->refresh()->load('messages');
        if ($this->blocker($candidate, $old)) {
            return null;
        }
        // Уже заведено (номер, VIN) — письма к нему, новой строки нет.
        $fresh = ! $this->promote->existing($candidate);
        try {
            $offer = ($this->promote)($candidate, null);
        } catch (Throwable $e) {
            Log::info('Предложение из письма не завелось само', ['candidate' => $candidate->id, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $fresh) {
            return null;
        }
        AutoScan::await($offer);
        // Файлы письма уже на месте (нечего тянуть из ящика) — читать сразу.
        if (! ImportThreadFiles::progressOf($offer)) {
            AutoScan::filesArrived($offer);
        }
        Nav::forgetStaffCounts();

        return $offer;
    }

    /**
     * Подстраховка часов (`offers:tick`): цепочка последнего часа, что должна была завестись при письме, но не завелась
     * (письмо пришло посреди выкладки). Свежие две минуты не трогаем — их заводит само письмо. Старее часа — хвост
     * до автозаведения: его заводит `offers:auto-drafts` по одной, чтобы строки входили вереницей, а не разом.
     */
    public function catchUp(): int
    {
        $done = 0;
        // Окно — запросом к базе: `whereBetween` коллекции с датами пропускал всё (12.10.2026 часы завели хвост разом).
        foreach ($this->waiting(fn ($q) => $q->whereBetween('created_at', [now()->subHour(), now()->subMinutes(2)])) as $candidate) {
            try {
                $done += (int) (bool) $this($candidate);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $done;
    }

    /** @return Collection<int, Candidate> ждущие цепочки предложений, старые первыми */
    public function waiting(?callable $scope = null): Collection
    {
        return Candidate::where('scope', Scope::Offers)->where('state', CandidateState::New)->when($scope, $scope)->orderBy('id')->get();
    }

    /** Письмо-предложение: то, с которого цепочка началась, иначе первое входящее. */
    private function letter(Candidate $candidate): ?Message
    {
        return $candidate->message ?? $candidate->messages->first(fn (Message $m) => ! $m->isOurs());
    }
}
