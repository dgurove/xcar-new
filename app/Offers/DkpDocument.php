<?php

namespace App\Offers;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\Vat;
use App\Cars\Car;
use App\Cars\Category;
use App\Support\Money;
use App\Support\Plural;
use App\Support\Words;

/**
 * Что подставляется в ДКП (19.10.2026, по шаблонам владельца): виды `deals/docs/dkp-owner` (физлицо физлицу,
 * Т-Страхование) и `deals/docs/dkp-sale` (ДКП и акт от ПРАЙМ / Р.О.С.) берут готовые строки отсюда и сами ничего не
 * считают. Пустое — `null`, вид рисует линию для ручки. Род («гражданка», «именуемая») — по отчеству (`Party::isFemale`).
 */
final class DkpDocument
{
    public readonly Deal $deal;

    public readonly Offer $offer;

    public readonly ?Car $car;

    public readonly ?Party $seller;

    public readonly ?Party $buyer;

    public function __construct(public readonly DealContract $contract)
    {
        $this->deal = $contract->deal;
        $this->offer = $this->deal->offer;
        $this->car = $this->offer->car;
        $this->seller = $contract->sellerParty();
        $this->buyer = $contract->buyerParty();
    }

    public function form(): string
    {
        return $this->contract->form();
    }

    /** «ПР-15/2026» — только номер нынешнего продавца: сменили ПРАЙМ на Р.О.С., а договор не пересобран — номера нет. */
    public function number(): ?string
    {
        return $this->form() !== 'owner' && $this->contract->issuer === $this->deal->seller() ? $this->contract->numberLabel() : null;
    }

    /** Дата договора «19.10.2026». */
    public function date(): ?string
    {
        return $this->contract->signed_at?->format('d.m.Y');
    }

    /** Город договора: вписанный, у юрлица — Москва (так в шаблонах), у собственника — город машины. */
    public function city(): ?string
    {
        return $this->contract->city ?: ($this->form() === 'owner' ? $this->offer->settlement?->name : 'Москва');
    }

    public function title(): string
    {
        return trim('ДКП '.($this->number() ?? '').' '.$this->offer->titleWithYear());
    }

    /** Имя файла: «ДКП ПР-15-2026 Chery Tiggo 7 Pro, 2024.pdf». */
    public function fileName(): string
    {
        return str_replace(['/', '\\'], '-', $this->title()).'.pdf';
    }

    // ——— Стороны ———

    /** «гражданин РФ» / «гражданка РФ» по отчеству, не понять — «гражданин(ка) РФ». */
    public function citizen(?Party $p): string
    {
        return match ($p?->isFemale()) {
            true => 'гражданка РФ', false => 'гражданин РФ', default => 'гражданин(ка) РФ',
        };
    }

    /** Слово по роду: («именуем», «ый», «ая») → «именуемая»; рода не понять — «именуемый(ая)», «Зарегистрирован(а)». */
    public function ending(?Party $p, string $stem, string $male, string $female): string
    {
        return $stem.match ($p?->isFemale()) {
            true => $female, false => $male, default => $male.'('.$female.')',
        };
    }

    public function isCompany(?Party $p): bool
    {
        return $p && in_array($p->kind, [PartyKind::Company, PartyKind::Entrepreneur], true);
    }

    /**
     * Организация в преамбуле: «Общество … «ПРАЙМ», именуемое в дальнейшем «Продавец», в лице Генерального директора …,
     * действующего на основании Устава». «В лице» — готовым родительным падежом (`signed_by`); его нет — без падежа:
     * «от имени которого на основании Устава действует Иванов Иван Иванович».
     */
    public function companyIntro(Party $p, string $role): string
    {
        $name = $p->full_name ?: $p->name;
        if ($p->kind === PartyKind::Entrepreneur) {
            return $name.', '.'именуемый в дальнейшем «'.$role.'»';
        }
        $basis = $p->director_basis ?: 'Устава';
        $who = $p->signed_by
            ? 'в лице '.$p->signed_by.', действующего на основании '.$basis
            : 'от имени которого на основании '.$basis.' действует '.($p->director_full ?: $p->director);

        return $name.', именуемое в дальнейшем «'.$role.'», '.$who;
    }

    /** ФИО подписанта организации полностью — под линией подписи. */
    public function signer(?Party $p): ?string
    {
        if (! $p) {
            return null;
        }

        return $this->isCompany($p) ? ($p->director_full ?: $p->director ?: ($p->kind === PartyKind::Entrepreneur ? $p->name : null)) : $p->name;
    }

    /** Паспорт по частям для договора. @return array{series: ?string, number: ?string, issued_by: ?string, issued_at: ?string, code: ?string, birth: ?string, address: ?string} */
    public function passport(?Party $p): array
    {
        [$series, $number] = $p?->passportParts() ?? [null, null];

        return [
            'series' => $series, 'number' => $number, 'issued_by' => $p?->passport_issued,
            'issued_at' => $p?->passport_issued_at?->format('d.m.Y'), 'code' => $p?->passport_code,
            'birth' => $p?->birth_at?->format('d.m.Y'), 'address' => $p?->reg_address,
        ];
    }

    /** Реквизиты организации строками для подвала: адрес, ОГРН, ИНН/КПП, банк. @return list<string> */
    public function requisites(Party $p): array
    {
        $ip = $p->kind === PartyKind::Entrepreneur;

        return array_values(array_filter([
            $p->legal_address ? ($ip ? 'Адрес: ' : 'Юридический/почтовый адрес: ').$p->legal_address : null,
            $p->ogrn ? ($ip ? 'ОГРНИП ' : 'ОГРН ').$p->ogrn : null,
            $p->inn ? ($p->kpp ? 'ИНН/КПП '.$p->inn.'/'.$p->kpp : 'ИНН '.$p->inn) : null,
            $p->account ? 'р/с '.$p->account.($p->bank_name ? ' в '.$p->bank_name : '') : null,
            $p->corr_account ? 'к/с '.$p->corr_account : null,
            $p->bik ? 'БИК '.$p->bik : null,
        ]));
    }

    // ——— Машина ———

    /** Марка и модель заглавными, как в ПТС: «CHERY TIGGO 7 PRO». */
    public function carName(): ?string
    {
        return mb_strtoupper(trim(($this->offer->brand?->name ?? '').' '.($this->offer->model?->name ?? ''))) ?: null;
    }

    public function color(): ?string
    {
        return $this->offer->color ? mb_strtoupper($this->offer->color) : null;
    }

    /** Наименование (тип ТС) в ДКП собственника — словами ПТС. */
    public function type(): ?string
    {
        return match ($this->offer->category()) {
            Category::Passenger => 'Легковой',
            Category::Light, Category::Truck, Category::Long => 'Грузовой',
            Category::Trailer => 'Прицеп',
            Category::Special => 'Специальное ТС',
            Category::Moto => 'Мотоцикл',
        };
    }

    /**
     * (Э)ПТС: 15 цифр — электронный («ЭПТС № 164302075126838»), иначе бумажный «ПТС серии 77 ТТ № 123456»; не
     * разобралось — как вписано. @return array{kind: string, text: string}|null
     */
    public function pts(): ?array
    {
        $raw = trim((string) $this->car?->pts);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{15}$/', preg_replace('/\s+/', '', $raw))) {
            return ['kind' => 'ЭПТС', 'text' => '№ '.preg_replace('/\s+/', '', $raw)];
        }
        if (preg_match('/^(\d{2})\s*([А-ЯЁA-Z]{2})\s*№?\s*(\d{6})$/u', mb_strtoupper($raw), $m)) {
            return ['kind' => 'ПТС', 'text' => 'серии '.$m[1].' '.$m[2].' № '.$m[3]];
        }

        return ['kind' => 'ПТС', 'text' => $raw];
    }

    /** (Э)ПТС одной строкой: «ЭПТС № 1643…», «ПТС серии 77 ТТ № 123456». */
    public function ptsLine(): ?string
    {
        $pts = $this->pts();

        return $pts ? $pts['kind'].' '.$pts['text'] : null;
    }

    /** Чем подтверждается собственность (п. 2 ДКП собственника): (электронный) паспорт ТС и СТС с номерами. */
    public function ownership(): ?string
    {
        $pts = $this->pts();
        $sts = trim((string) $this->car?->sts);
        $parts = array_filter([
            $pts ? ($pts['kind'] === 'ЭПТС' ? 'электронный паспорт транспортного средства ' : 'паспорт транспортного средства ').$pts['text'] : null,
            $sts !== '' ? 'свидетельство о регистрации транспортного средства '.$sts : null,
        ]);

        return $parts ? implode(' и ', $parts) : null;
    }

    public function bodyNo(): ?string
    {
        return $this->car?->body_no ?: $this->offer->vin;
    }

    public function chassisNo(): string
    {
        return $this->car?->chassis_no ?: 'отсутствует';
    }

    /** Сколько ключей передаём (акт): записано у машины при вывозе или приёме; не знаем — null (линия для ручки). */
    public function keys(): ?int
    {
        return $this->car?->keys_count;
    }

    // ——— Деньги ———

    public function price(): ?int
    {
        return $this->contract->price;
    }

    /** «1 250 000 (один миллион двести пятьдесят тысяч) рублей 00 копеек». */
    public function money(int|float|null $amount): ?string
    {
        if ($amount === null) {
            return null;
        }
        $rub = (int) floor($amount + 0.0001);
        $kop = (int) round(($amount - $rub) * 100);

        return Money::nums($rub).' ('.Words::rub($rub).') '.Plural::of($rub, ['рубль', 'рубля', 'рублей']).' '
            .str_pad((string) $kop, 2, '0', STR_PAD_LEFT).' '.Plural::of($kop, ['копейка', 'копейки', 'копеек']);
    }

    /** Ставка НДС продавца-юрлица (как в его счёте): ПРАЙМ 5 %, Р.О.С. 22 %; 0 — без НДС. */
    public function vatRate(): int
    {
        return $this->form() === 'owner' ? 0 : $this->deal->seller()->vatRate();
    }

    /** НДС внутри цены — той же дверью, что у счёта. */
    public function vat(): ?float
    {
        return $this->price() && $this->vatRate() ? Vat::inside((float) $this->price(), $this->vatRate()) : null;
    }
}
