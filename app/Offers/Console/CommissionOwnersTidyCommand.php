<?php

namespace App\Offers\Console;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Offers\DealContract;
use App\Vendors\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разово (25.11.2026, собственник по договору комиссии — физлицо, ИП, организация): навести порядок в собственниках, что
 * распознал `FillCommissionOwner` до того.
 * - Сам поставщик: реквизиты вендора по комиссии (Совкомбанк) — из распознанных собственников с его ИНН, если их у вендора нет;
 *   сделки с копиями поставщика — на одну его сторону.
 * - ИП, записанные организацией («ИП Токарев Иван Николаевич»), — видом ИП, ФИО без «ИП».
 * - «в лице … действующего на основании …» в названии организации — подписантом (`signed_by`, `director_basis`).
 * Руками организацию и ИП собственником до того вписать было нельзя — всё это распознанное. Без `--apply` — только список.
 */
class CommissionOwnersTidyCommand extends Command
{
    protected $signature = 'deals:commission-owners-tidy {--apply}';

    protected $description = 'Собственники по договору комиссии: сторона поставщика, ИП, подписант не в названии';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $contracts = DealContract::with(['seller', 'deal.offer.vendor.party'])->whereNotNull('seller_party_id')->get()
            ->filter(fn (DealContract $c) => $c->deal && $c->deal->isLive() && $c->deal->isCommission() && $c->seller && $c->seller->kind !== PartyKind::Person);

        DB::transaction(function () use ($contracts, $apply) {
            // 1. Сторона поставщика.
            foreach ($contracts->groupBy(fn ($c) => $c->deal->offer->vendor_id) as $list) {
                $vendor = $list->first()->deal->offer->vendor;
                $own = $list->map->seller->filter(fn (Party $p) => $p->kind === PartyKind::Company && $vendor->party?->inn
                    ? $p->inn === $vendor->party->inn
                    : self::looksLike($p, $vendor));
                if ($own->isEmpty()) {
                    continue;
                }
                $party = $vendor->party;
                if (! $party || ! filled($party->inn)) {
                    $src = $own->sortByDesc(fn (Party $p) => (int) filled($p->legal_address) + (int) filled($p->kpp) + (int) filled($p->ogrn))->first();
                    // Название — самое частое среди распознанных («АО «Совкомбанк страхование»», а не «… (АО)»).
                    $name = $own->map(fn (Party $p) => self::split($p)['name'])->countBy()->sortDesc()->keys()->first();
                    $fields = ['name' => $name] + self::split($src) + ['kind' => PartyKind::Company, 'inn' => $src->inn, 'kpp' => $src->kpp, 'ogrn' => $src->ogrn, 'legal_address' => $src->legal_address];
                    $fields['full_name'] = $fields['name'];
                    $this->line("{$vendor->name}: реквизиты поставщика — {$fields['name']}, ИНН {$src->inn}".($party ? ' (дописать к его стороне)' : ''));
                    if ($apply) {
                        $party ??= new Party;
                        $party->fill(array_filter($fields, fn ($v) => $v !== null))->save();
                        $vendor->party_id === $party->id || $vendor->update(['party_id' => $party->id]);
                    }
                }
                $moved = $list->filter(fn ($c) => $own->contains('id', $c->seller_party_id) && $c->seller_party_id !== $party?->id);
                $this->line("{$vendor->name}: сделок на сторону поставщика — {$moved->count()}");
                if ($apply && $party?->id) {
                    $moved->each(fn ($c) => $c->forceFill(['seller_party_id' => $party->id])->save());
                }
            }
            // 2–3. ИП и подписант.
            foreach ($contracts as $c) {
                $p = $c->fresh('seller')->seller;
                if (! $p || $p->id === $c->deal->offer->vendor?->fresh()->party_id) {
                    continue;
                }
                $fix = [];
                if ($p->kind === PartyKind::Company && preg_match('/^(?:ИП|Индивидуальн\S*\s+предпринимател\S*)\s+(.+)$/u', $p->name, $m)) {
                    $fix = ['kind' => PartyKind::Entrepreneur, 'name' => trim($m[1]), 'full_name' => null];
                } elseif (preg_match('/\sв\s+лице\s/u', $p->name)) {
                    $fix = self::split($p);
                    $fix['full_name'] = $fix['name'];
                }
                if ($fix) {
                    $this->line("сделка {$c->deal_id}: «{$p->name}» → «{$fix['name']}»".(isset($fix['kind']) ? ', ИП' : '').(isset($fix['signed_by']) ? ", в лице {$fix['signed_by']}" : ''));
                    $apply && $p->forceFill($fix)->save();
                }
            }
        });
        $this->info($apply ? 'Записано' : 'Только список, запись — с --apply');

        return self::SUCCESS;
    }

    /** Название без «в лице … действующего на основании …» и подписант отдельно. @return array<string, ?string> */
    private static function split(Party $p): array
    {
        if (preg_match('/^(.+?)\s*,?\s+в\s+лице\s+(.+?)(?:\s*,?\s+действующ\S*\s+на\s+основании\s+(.+?))?\s*\.?$/u', (string) $p->name, $m)) {
            return ['name' => trim($m[1], ' ,'), 'signed_by' => trim($m[2], ' ,'), 'director_basis' => isset($m[3]) ? trim($m[3], ' ,') : null];
        }

        return ['name' => $p->name];
    }

    /** Вендор без реквизитов: собственник — он сам, если в названии стороны есть первое слово имени вендора («Совкомбанк»). */
    private static function looksLike(Party $p, Vendor $vendor): bool
    {
        $word = mb_strtolower((string) strtok((string) $vendor->name, ' '));

        return $p->kind === PartyKind::Company && $word !== '' && str_contains(mb_strtolower((string) $p->name), $word);
    }
}
