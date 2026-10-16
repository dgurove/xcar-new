<?php

namespace App\Offers\Actions;

use App\Cars\Brand;
use App\Cars\Vin\Vin;
use App\Mail\Actions\PromoteCandidate;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\QuotationStripper;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Mail\Scan\AutoScan;
use App\Mail\Scope;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Support\Nav;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
 * машинах), номер в документах письма другой, машины нет (ни VIN, ни марки из справочника), письмо старше недели
 * (история ящика), давняя пересылка с номером не из таблицы Кати.
 */
final class AutoOffer
{
    /** Письмо старше — не новое предложение, а догнавшая история: решит человек. */
    private const FRESH_DAYS = 7;

    /** Пересланное письмо старше — давняя история: заводим, только если номер в таблице Кати (их Андрей и пересылает). */
    private const FORWARD_DAYS = 30;

    /** Сколько отставших цепочек часы заводят за тик: тик держит слоты и волны, а строки входят по одной, не пачкой. */
    private const CATCH_UP = 5;

    public function __construct(private PromoteCandidate $promote) {}

    /** Почему цепочка не заводится сама; null — заводится. `$old` — прогон руками: возраст письма не в счёт. */
    public function blocker(Candidate $candidate, bool $old = false): ?string
    {
        if ($candidate->scope !== Scope::Offers || $candidate->state !== CandidateState::New) {
            return 'не ждёт в «Из писем»';
        }
        if (Cache::has(self::declinedKey($candidate->id))) {
            return 'черновик по ней удалили руками';
        }
        $letter = $this->letter($candidate);
        if (! $letter) {
            return 'письма вендора нет';
        }
        if (! $old && $letter->date_at && $letter->date_at->lt(now()->subDays(self::FRESH_DAYS))) {
            return 'письмо старше недели';
        }
        $body = $letter->text_body ?: strip_tags((string) $letter->html_body);
        if ($why = self::notAnOffer((string) $letter->subject, $body, $letter->attachments->pluck('filename')->all())) {
            return $why;
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
        // 12.10.2026, 511791/2026: Совкомбанк в июньском письме «Вывоз ГОТС по делу 511791» приложил договор и акт по делу
        // 114725-26 — номер встал из темы, машина и VIN из чужих документов. Номер в документах другой — решает человек;
        // разница только в годе («-25» в теме, «-26» в договоре) — то же дело, его год берёт `__invoke` по таблице Кати.
        $docs = self::docClaims($letter);
        $number = self::number($candidate->code);
        // Номер темы есть в таблице Кати — ему верим: в договоре бывает опечатка (564694 вместо 564964).
        $listed = LegacyClaim::where('claim_ref_key', Code::key($candidate->code))->exists();
        if ($docs && $number && ! $listed && ! in_array($number, array_map(self::number(...), $docs), true)) {
            return 'номер убытка в теме и в документах разный';
        }
        // 12.10.2026: Катя переслала июньское письмо, машина продана давно. Давнюю пересылку заводим, только если номер в
        // таблице Кати: её Андрей и пересылает пачкой.
        $sent = self::forwardedAt($letter->text_body ?: strip_tags((string) $letter->html_body));
        if (! $old && $sent && $sent->lt(now()->subDays(self::FORWARD_DAYS)) && ! $listed) {
            return 'давнее пересланное письмо, номера нет в таблице Кати';
        }
        if (! Vin::full((string) $candidate->value('vin')) && ! ($candidate->value('brand') && Brand::known((string) $candidate->value('brand')))) {
            return 'машины нет: ни VIN, ни марки';
        }

        return null;
    }

    /**
     * Письмо похоже на предложение полями, но это переписка (12.10.2026, 154671/2026: «RE: Вывоз ГОТС…», «ПП прикладываю,
     * банк поторопил» — платёжные поручения Совкома Кате; черновик встал в «Без продажной»). Не предложение:
     * - ответ «RE:» / «Ответ:» — своей темой или темой пересланного: по таким и раньше решал человек (из шести «RE:» в
     *   «Из писем» сотрудники завели пять, и это были разные дела);
     * - платёжка: все вложения, кроме картинок подписи, — «ПП …» / «платёжное…». «ПП» в словах письма не признак: у
     *   Совкома это ещё и «акт ПП» — приёма-передачи.
     * Пересылку старого письма не отсекаем: Андрей пересылает пачкой давние предложения по сделкам из таблицы Кати.
     *
     * @param  list<string>  $files
     */
    public static function notAnOffer(string $subject, string $body, array $files): ?string
    {
        // «Fwd: RE: …» — Андрей переслал ответ в переписке: тоже ответ.
        $reply = '/^\s*(?:(?:fwd?|пересл\w*)\s*(?:\[\d+\])?\s*:\s*)*(?:re|ответ|отв)\s*(?:\[\d+\])?\s*:/iu';
        $inner = QuotationStripper::forwardedBody($body);
        $innerSubject = $inner !== null && preg_match('/^\s*(?:Тема|Subject)\s*:\s*(.+)$/umi', $inner, $m) ? $m[1] : '';
        if (preg_match($reply, $subject) || preg_match($reply, $innerSubject)) {
            return 'ответ в переписке';
        }
        $docs = array_values(array_filter($files, fn ($f) => ! preg_match('/^image\d*\.(png|jpe?g|gif)$/i', $f)));
        $payment = '/^\s*(?:ПП|п\/п)(?![\p{L}])|платёжн|платежн/iu';
        if ($docs && count(array_filter($docs, fn ($f) => preg_match($payment, $f))) === count($docs)) {
            return 'письмо о платеже';
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
        try {
            $offer = ($this->promote)($candidate, null);
        } catch (Throwable $e) {
            Log::info('Предложение из письма не завелось само', ['candidate' => $candidate->id, 'error' => $e->getMessage()]);

            return null;
        }
        // Номер или VIN уже у другого (и `IdentityTaken` внутри «Завести») — письма легли к нему, новой строки нет.
        if (! $offer->wasRecentlyCreated) {
            return null;
        }
        // Номер в теме и в договоре разнится только годом, и в таблице Кати — вариант из договора: берём его (12.10.2026,
        // 678776-25 в теме, 678776-26 в договоре и в таблице — черновик встал мимо таблицы, призрак остался).
        if (! LegacyClaim::where('claim_ref_key', $offer->claim_ref_key)->exists()) {
            $fit = collect(self::docClaims($this->letter($candidate)))->first(fn ($c) => LegacyClaim::where('claim_ref_key', Code::key($c))->exists());
            if ($fit) {
                $offer->forceFill(['claim_ref' => $fit])->save();
            }
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
     * (письмо пришло посреди выкладки). Свежие две минуты не трогаем — их заводит само письмо. Не больше `CATCH_UP` за
     * тик: 12.10.2026 после выкладки часы завели разом 44 цепочки, которые Андрей переслал пачкой за час до неё, — тик
     * держался полторы минуты, строки вошли кучей. Старее часа — хвост: его заводит `offers:auto-drafts`.
     */
    public function catchUp(): int
    {
        $done = 0;
        foreach ($this->waiting(fn ($q) => $q->whereBetween('created_at', [now()->subHour(), now()->subMinutes(2)])->limit(self::CATCH_UP)) as $candidate) {
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

    /**
     * Черновик из письма удалили руками («Отменить», корзина): цепочка снова ждёт в «Из писем», и сама больше не
     * заводится — иначе часы завели бы её снова через минуту.
     */
    public static function declined(int $candidateId): void
    {
        Cache::forever(self::declinedKey($candidateId), true);
    }

    private static function declinedKey(int $candidateId): string
    {
        return "auto-offer:declined:{$candidateId}";
    }

    /**
     * Номера дел в именах документов письма: «Договор комиссии 114725-26.docx», «Акт приема-передачи 476135-26.docx».
     *
     * @return list<string> нормальной записью («114725/2026»)
     */
    public static function docClaims(?Message $letter): array
    {
        if (! $letter) {
            return [];
        }

        return $letter->attachments->pluck('filename')
            ->map(fn ($f) => preg_match('/(?:договор|акт)\D*?(\d{4,7}[-\/]\d{2,4})(?!\d)/iu', (string) $f, $m) ? Code::normalize($m[1]) : null)
            ->filter()->unique()->values()->all();
    }

    /** Номер дела без года: «114725/2026» → «114725». */
    private static function number(?string $claim): ?string
    {
        return preg_match('/^(\d{4,7})(?:\/\d{2,4})?$/', (string) Code::normalize($claim), $m) ? $m[1] : null;
    }

    /** Дата пересланного письма из шапки пересылки («Дата: Четверг, 18 июня 2026, 12:56 +03:00»); не пересылка — null. */
    public static function forwardedAt(string $body): ?Carbon
    {
        $months = ['января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5, 'июня' => 6, 'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12];
        if (! preg_match('/(?:Дата|Date|Отправлено|Sent)\s*:[^\n]*?(\d{1,2})\s+('.implode('|', array_keys($months)).')\s+(\d{4})/iu', $body, $m)) {
            return null;
        }

        return Carbon::create((int) $m[3], $months[mb_strtolower($m[2])], (int) $m[1]);
    }

    /** Письмо-предложение: то, с которого цепочка началась, иначе первое входящее. */
    private function letter(Candidate $candidate): ?Message
    {
        return $candidate->message ?? $candidate->messages->first(fn (Message $m) => ! $m->isOurs());
    }
}
