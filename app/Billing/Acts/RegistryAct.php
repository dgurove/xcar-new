<?php

namespace App\Billing\Acts;

use App\Billing\ActForm;
use App\Billing\Cadence;
use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Support\Money;
use App\Support\Plural;
use App\Support\Words;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Акт к счёту закрытия месяца — прежний файл страховой (`ActForm`, шаблоны `resources/templates/acts`), заполненный
 * строками счёта: стороны и договор из реквизитов («Реквизиты», вендор), машины — из строк хранения и эвакуации. Файлы
 * лежат в коллекции `act` счёта (у РГС — два: акт и приложение); письмо вендору берёт их вместе со счётом.
 */
final class RegistryAct
{
    public static function form(Invoice $invoice): ActForm
    {
        return self::vendor($invoice)?->act_form ?? ActForm::Report;
    }

    /** Положить файлы акта к счёту (прежние — заменить). */
    public function attach(Invoice $invoice): void
    {
        $invoice->clearMediaCollection('act');
        foreach ($this->files($invoice) as $name => $path) {
            // Имя как у прежних файлов, с пробелами: «Отчет-акт 20261105-01.xlsx» (медиатека меняет пробелы на дефисы).
            $invoice->addMedia($path)->usingFileName($name)->sanitizingFileName(fn ($n) => str_replace(['/', '\\'], '-', $n))->toMediaCollection('act');
        }
    }

    /** @return array<string, string> имя файла → путь к заполненному */
    public function files(Invoice $invoice): array
    {
        $invoice->loadMissing(['party', 'charges.vehicle.vendor', 'charges.vehicle.yard', 'charges.vehicle.requests']);
        $tow = $invoice->kind === ChargeKind::Tow;
        $vars = $this->vars($invoice, $tow);
        $out = [];
        foreach (self::form($invoice)->files($tow) as $template => $title) {
            [$rows, $keys] = $this->rows($invoice, $template);
            $out[$title.' '.$invoice->fileNo().'.'.pathinfo($template, PATHINFO_EXTENSION)] = Template::fill($template, $vars, $rows, $keys);
        }

        return $out;
    }

    private static function vendor(Invoice $invoice)
    {
        $invoice->loadMissing('charges.vehicle.vendor');

        return $invoice->charges->first(fn ($c) => $c->vehicle?->vendor)?->vehicle->vendor;
    }

    /** @return array<string, mixed> */
    private function vars(Invoice $invoice, bool $tow): array
    {
        $p = $invoice->party;
        $self = $invoice->seller->party();
        $vendor = self::vendor($invoice);
        $from = $invoice->month->copy()->startOfMonth();
        $to = $invoice->month->copy()->endOfMonth();
        // «01» октября 2025 г. — месяц в родительном падеже (его даёт только формат с днём).
        $quoted = fn (Carbon $d) => '«'.$d->format('d').'» '.Str::after($d->locale('ru')->isoFormat('D MMMM YYYY'), ' ').' г.';
        $number = $tow ? ($vendor?->tow_agreement_number ?: $vendor?->agreement_number) : $vendor?->agreement_number;
        $date = $tow && $vendor?->tow_agreement_number ? $vendor->tow_agreement_date : $vendor?->agreement_date;
        $contract = $number ? $number.($date ? ' от '.$date->format('d.m.Y').' г.' : '') : '____________';
        $total = (float) $invoice->total;
        $rub = (int) floor($total);
        $kop = str_pad((string) (int) round(($total - $rub) * 100), 2, '0', STR_PAD_LEFT);
        $words = Words::rub($rub);
        $cars = $invoice->charges->whereNotNull('vehicle_id')->pluck('vehicle_id')->unique()->count();
        $ip = $self->kind === PartyKind::Entrepreneur;
        $released = $invoice->charges->every(fn ($c) => $c->vehicle?->released_at !== null);
        $selfFull = $self->full_name ?: $self->name;
        $short = fn (Party $x) => $x->kind === PartyKind::Entrepreneur ? 'ИП '.str_replace('. ', '.', (string) $x->director) : $x->name;

        return [
            'number' => $invoice->docNo(),
            'date' => $invoice->issued_at->format('d.m.Y'),
            'date_words' => $invoice->issued_at->locale('ru')->isoFormat('D MMMM YYYY').' г.',
            'period' => $quoted($from).' по '.$quoted($to),
            'from' => $from->format('d.m.Y'),
            'to' => $to->format('d.m.Y'),
            'contract' => $contract,
            'preamble' => $this->preamble($invoice, $p, $self, $contract, $tow),
            'ended' => $released || $vendor?->billing_cadence === Cadence::Release
                ? 'Хранитель окончил оказание услуг Поклажедателю, сведения о которых указаны в нижеприведенной таблице.'
                : 'Сведения об оказанных услугах указаны в нижеприведенной таблице.',
            'total' => Money::plain($total, 0, false),
            'total_text' => Money::plain($rub, 0, false).' ('.mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1).') '.Plural::of($rub, ['рубль', 'рубля', 'рублей']).' '.$kop.' копеек',
            'total_rub' => Money::plain($rub, 0, false).' руб. '.$kop.' коп.',
            'total_words' => Words::money($total),
            'count_text' => $cars.' '.(($cars % 10 === 1 && $cars % 100 !== 11) ? 'заявке' : 'заявкам'),
            'customer_name' => $p->name,
            'customer_short' => $p->name,
            'customer_full' => $p->full_name ?: $p->name,
            'customer_line' => $this->line($p),
            'customer_address' => $p->legal_address,
            'customer_inn' => $p->inn,
            'customer_kpp' => $p->kpp,
            'customer_account' => $p->account,
            'customer_bank' => $p->bank_name,
            'customer_bik' => $p->bik,
            'customer_corr' => $p->corr_account,
            'customer_signer' => $p->director ?: '____________',
            'self_name' => $self->name,
            'self_full' => $selfFull,
            'self_short' => $short($self),
            'self_line' => $this->line($self),
            'self_signer' => $self->director ?: '____________',
            'self_title' => $ip ? 'Индивидуальный предприниматель '.str_replace('. ', '.', (string) $self->director) : $self->signerTitle(),
            'self_phone' => $self->phone,
            'yard_address' => $invoice->charges->first(fn ($c) => $c->vehicle?->yard)?->vehicle->yard->fullAddress() ?? $self->legal_address,
        ];
    }

    /** Вступление акта: стороны с подписантами, как в прежнем файле этой формы. */
    private function preamble(Invoice $invoice, Party $p, Party $self, string $contract, bool $tow): string
    {
        $ip = $self->kind === PartyKind::Entrepreneur;
        $customer = $p->full_name ?: $p->name;
        if (self::form($invoice) === ActForm::Sogaz) {
            $selfPart = $ip ? 'индивидуальный предприниматель '.($self->director_full ?: $self->name).', именуемый в дальнейшем «Исполнитель»'
                : ($self->full_name ?: $self->name).', именуемое в дальнейшем «Исполнитель», в лице '.($self->signed_by ?: '____________').', действующего на основании Устава,';

            return $customer.', именуемое в дальнейшем «Заказчик» , в  лице '.($p->signed_by ?: '______________________________').', действующего на основании '
                .($p->director_basis ?: '______________________________').', с одной стороны и '.$selfPart.' с другой стороны, совместно именуемые «Стороны», заключили настоящий Акт о нижеследующем:'
                ."\nИсполнитель оказал Заказчику услуги по ".($tow ? 'эвакуации' : 'хранению').' следующих транспортных средств';
        }
        $selfPart = $ip ? ($self->full_name ?: $self->name).', именуемый в дальнейшем Хранитель,'
            : ($self->name).', именуемое в дальнейшем Хранитель в лице '.($self->signed_by ?: '____________').', действующего на основании Устава';

        return $p->name.', именуемое в дальнейшем Поклажедатель в лице представителя Поклажедателя ФИО, должность и доверенность которого указаны в разделе "Подписи сторон", с одной стороны и '
            .$selfPart.' составили настоящий Отчет-Акт к договору № '.$contract;
    }

    /** Реквизиты строкой, как в актах СОГАЗа: «АО "СОГАЗ", ИНН …, адрес, р/с …, в банке …, БИК …, к/с …». */
    private function line(Party $x): string
    {
        return implode(', ', array_filter([
            $x->kind === PartyKind::Entrepreneur ? 'ИП '.$x->director : $x->name,
            $x->inn ? 'ИНН '.$x->inn : null,
            $x->kpp ? 'КПП '.$x->kpp : null,
            $x->legal_address,
            $x->account ? 'р/с '.$x->account : null,
            $x->bank_name ? 'в банке '.$x->bank_name : null,
            $x->bik ? 'БИК '.$x->bik : null,
            $x->corr_account ? 'к/с '.$x->corr_account : null,
        ]));
    }

    /**
     * Строки шаблона и метки строк.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function rows(Invoice $invoice, string $template): array
    {
        $charges = $invoice->charges->whereNotNull('vehicle_id')->sortBy([fn ($a, $b) => $a->vehicle->accepted_at <=> $b->vehicle->accepted_at, fn ($a, $b) => $a->id <=> $b->id])->values();
        $vendor = self::vendor($invoice);
        $service = mb_strtoupper($vendor?->storageServiceTitle() ?? 'Хранение ТС');
        $car = fn (Vehicle $v) => $v->carRecord()->title();
        $day = fn (Charge $c) => Carbon::parse($c->period_from ?? $c->created_at)->startOfDay();
        $asked = fn (Vehicle $v) => $v->requests->where('type', RequestType::Intake)->min('created_at')?->copy()->startOfDay() ?? $v->accepted_at?->copy()->startOfDay();
        $storage = $charges->where('kind', ChargeKind::Storage);
        $money = fn ($v) => (float) $v;

        $rows = match ($template) {
            'report.xlsx' => $charges->map(fn (Charge $c) => [
                'vin' => $c->vehicle->vin, 'accepted' => $c->vehicle->accepted_at?->copy()->startOfDay(), 'released' => $c->vehicle->released_at?->copy()->startOfDay(),
                'ref' => $c->vehicle->ref, 'policy' => $c->vehicle->policy_no, 'plate' => $c->vehicle->plate, 'car' => $car($c->vehicle),
                'service' => $c->kind === ChargeKind::Storage ? $service.$this->part($c) : mb_strtoupper($c->title), 'days' => $money($c->qty), 'price' => $money($c->price),
            ]),
            'report-tow.xlsx' => $charges->map(fn (Charge $c) => [
                'vin' => $c->vehicle->vin, 'date' => $day($c), 'ref' => $c->vehicle->ref, 'policy' => $c->vehicle->policy_no, 'plate' => $c->vehicle->plate,
                'car' => $car($c->vehicle), 'service' => mb_strtoupper($c->title), 'amount' => $money($c->amount),
            ]),
            'sogaz.xlsx' => $charges->map(fn (Charge $c) => [
                'ref' => $c->vehicle->ref, 'asked' => $asked($c->vehicle), 'insured' => $c->vehicle->contact_name, 'car_vin' => trim($car($c->vehicle).' '.$c->vehicle->vin),
                'from' => ($c->period_from ?? $day($c))->copy()->startOfDay(), 'to' => ($c->period_to ?? $day($c))->copy()->startOfDay(), 'price' => (float) $c->price,
            ]),
            'sogaz-tow.xlsx' => $charges->map(fn (Charge $c) => [
                'ref' => $c->vehicle->ref, 'asked' => $asked($c->vehicle), 'customer_short' => $invoice->party->name, 'insured' => $c->vehicle->contact_name, 'policy' => $c->vehicle->policy_no,
                'car_vin' => trim($car($c->vehicle).' '.$c->vehicle->vin), 'date' => $day($c), 'service' => $c->title, 'amount' => $money($c->amount),
            ]),
            'rgs.docx' => $storage->groupBy(fn ($c) => (string) (float) $c->price)->map(fn (Collection $g) => [
                'days' => (int) $g->sum('qty'), 'price' => Money::plain($g->first()->price, 0, false), 'amount' => Money::plain($g->sum('amount'), 0, false),
            ])->values()->concat($charges->where('kind', '!=', ChargeKind::Storage)->map(fn ($c) => [
                'days' => (int) $c->qty, 'price' => Money::plain($c->price, 0, false), 'amount' => Money::plain($c->amount, 0, false),
            ])),
            'rgs-appendix.xlsx' => $storage->map(fn (Charge $c) => [
                'ref' => $c->vehicle->ref, 'car' => $car($c->vehicle), 'vin' => $c->vehicle->vin, 'accepted' => $c->vehicle->accepted_at?->copy()->startOfDay(),
                'prev' => $c->period_from && $c->vehicle->accepted_at && $c->period_from->gt($c->vehicle->accepted_at->copy()->startOfDay()) ? $c->period_from->copy()->subDay() : null,
                'to' => $c->period_to?->copy(), 'released' => $c->vehicle->released_at?->copy()->startOfDay(),
                'stored' => $c->vehicle->accepted_at ? (int) $c->vehicle->accepted_at->copy()->startOfDay()->diffInDays(($c->vehicle->released_at ?? $c->period_to ?? now())->copy()->startOfDay()) + 1 : null,
                'price' => $money($c->price), 'days' => $money($c->qty),
            ]),
            'works.docx' => $storage->groupBy(fn ($c) => $c->vehicle_id.':'.(float) $c->price)->map(fn (Collection $g) => [
                'car' => $car($g->first()->vehicle), 'vin' => $g->first()->vehicle->vin, 'days' => (int) $g->sum('qty'),
                'price' => Money::plain($g->first()->price, 0, false), 'amount' => Money::plain($g->sum('amount'), 0, false),
            ])->values()->concat($charges->where('kind', '!=', ChargeKind::Storage)->map(fn ($c) => [
                'car' => $c->title.', '.$car($c->vehicle), 'vin' => $c->vehicle->vin, 'days' => (int) $c->qty, 'price' => Money::plain($c->price, 0, false), 'amount' => Money::plain($c->amount, 0, false),
            ])),
        };
        $rows = $rows->values()->all();

        return [$rows, $rows ? array_keys($rows[0]) : ['vin', 'ref', 'days', 'amount']];
    }

    /** Часть срока машины в этом акте (помесячный, своя ставка части): « С 01.10.2025 ПО 31.10.2025»; весь срок — пусто. */
    private function part(Charge $c): string
    {
        $v = $c->vehicle;
        $whole = $v->accepted_at && $v->released_at && $c->period_from?->isSameDay($v->accepted_at) && $c->period_to?->isSameDay($v->released_at);

        return $whole || ! $c->period_from ? '' : ' С '.$c->period_from->format('d.m.Y').' ПО '.$c->period_to?->format('d.m.Y');
    }
}
