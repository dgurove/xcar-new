{{-- Договор комиссии и акт приёма-передачи ГОТС — страницами на печать по образцу Совкомбанка:
     комиссионер — мы, комитент — страхователь; деньги за ТС — на счёт вендора в N рабочих дней после акта;
     вознаграждение — разница между продажей и назначенной ценой. kind: contract | handover. --}}
@php
    use App\Support\Money;
    $v = $vehicle; $owner = $v->ownerParty; $vendor = $v->vendor; $vp = $vendor?->party; $insp = $v->lastInspection(\App\Park\InspectionKind::Intake);
    $days = $vendor?->payment_days ?? 3;
    $no = $v->contract_no ?: ($v->ref ?: '____');
    $at = ($v->contract_at ?? $v->accepted_at ?? now())->format('d.m.Y');
    $price = $v->assigned_price ?: $v->value;
    $purpose = $vendor?->payment_purpose ?: 'Оплата за поврежденное ТС '.$v->titleWithYear().($v->plate ? ', р/н '.$v->plate : '').' по договору комиссии № '.$no.'. НДС не облагается';
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $kind === 'contract' ? 'Договор комиссии' : 'Акт приёма-передачи' }} {{ $no }}</title>
    <style>
        body { font-family: "Times New Roman", serif; font-size: 12px; color: #000; margin: 0; padding: 24px; background: #f4f4f4; line-height: 1.4; }
        .doc { max-width: 720px; margin: 0 auto; background: #fff; padding: 36px 40px; }
        h1 { font-size: 15px; text-align: center; margin: 0 0 4px; text-transform: uppercase; }
        .head { display: flex; justify-content: space-between; margin: 0 0 14px; }
        p { margin: 6px 0; text-align: justify; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        td, th { border: 1px solid #000; padding: 4px 6px; vertical-align: top; text-align: left; font-weight: normal; }
        th { width: 42%; }
        .blank { border-bottom: 1px solid #000; min-height: 16px; }
        .parties { display: flex; gap: 24px; margin-top: 24px; }
        .parties > div { flex: 1; font-size: 11px; }
        .sig { margin-top: 28px; }
        .print { position: fixed; top: 12px; right: 12px; }
        @media print { body { padding: 0; background: #fff; } .doc { max-width: none; padding: 0; } .print { display: none; } }
    </style>
</head>
<body>
    <div class="print"><button type="button" onclick="window.print()">Печать</button></div>
    <article class="doc">
    @if ($kind === 'contract')
        <h1>Договор комиссии № {{ $no }}</h1>
        <div class="head"><span>г. Москва</span><span>«___» ___________ {{ ($v->contract_at ?? now())->format('Y') }} г.</span></div>
        <p><b>{{ $self->name }}</b>, в лице {{ $self->director ?? '________' }}, действующего на основании {{ $self->director_basis ?? 'Устава' }}, именуемое в дальнейшем КОМИССИОНЕР, с одной стороны, и <b>{{ $owner?->name ?? $v->contact_name ?? '______________________' }}</b>, именуемый(ая) в дальнейшем КОМИТЕНТ, с другой стороны, заключили настоящий Договор о нижеследующем.</p>
        <p>1.1. КОМИССИОНЕР обязуется по поручению КОМИТЕНТА за вознаграждение совершить от своего имени сделку — реализовать повреждённое транспортное средство (далее — ТС):</p>
        @include('billing.docs.commission-car')
        <p>1.2. Сумму за повреждённое ТС перечислить на расчётный счёт {{ $vp?->name ?? $vendor?->legal_name ?? $vendor?->name ?? '__________' }} по реквизитам: {{ $vp?->details() }}{{ $vp?->bankDetails() ? '; '.$vp->bankDetails() : '' }}; в назначении платежа указывать: «{{ $purpose }}», в течение {{ $days }} ({{ \App\Support\Plural::of($days, ['рабочего дня', 'рабочих дней', 'рабочих дней']) }}) с момента принятия ТС на реализацию и подписания Акта приёма-передачи повреждённого транспортного средства.</p>
        <p>2.1. КОМИТЕНТ обязан одновременно с передачей ТС КОМИССИОНЕРУ по Акту приёма-передачи передать все относящиеся к нему документы. 2.2. КОМИТЕНТ гарантирует, что ТС не заложено, не находится в розыске, не является предметом спора третьих лиц. 2.3. КОМИТЕНТ гарантирует, что маркировочные номера агрегатов выполнены на заводе и не подвергались изменениям.</p>
        <p>2.4. КОМИТЕНТ назначает цену за повреждённое ТС <b>{{ $price ? Money::nums($price).' ('.\App\Support\Words::rub($price).')' : '________________' }} рублей 00 копеек</b>.</p>
        <p>3.1. КОМИССИОНЕР обязуется принять от КОМИТЕНТА ТС по Акту приёма-передачи. 3.2. КОМИССИОНЕР отвечает перед КОМИТЕНТОМ за утрату, недостачу или повреждение находящегося у него ТС. 3.3. КОМИССИОНЕР обязан исполнить поручение в соответствии с указанием КОМИТЕНТА. 3.4. Обязанности КОМИССИОНЕРА считаются исполненными с момента зачисления денежных средств по реквизитам п. 1.2 в сумме п. 2.4.</p>
        <p>4.1. Вознаграждение КОМИССИОНЕРА составляет разницу между суммой, полученной за реализацию ТС, и ценой, назначенной КОМИТЕНТОМ (п. 2.4). 4.2. При расторжении Договора КОМИССИОНЕР теряет право на вознаграждение, кроме случаев расторжения по вине КОМИТЕНТА.</p>
        <p>5. Договор вступает в силу с момента подписания и действует до полного исполнения обязательств. 6. Договор составлен в трёх экземплярах: КОМИССИОНЕРУ, КОМИТЕНТУ и {{ $vendor?->legal_name ?? $vendor?->name ?? 'страховой компании' }}. 7. В остальном стороны руководствуются законодательством РФ.</p>
        <div class="parties">
            <div><b>КОМИССИОНЕР</b><br>{{ $self->name }}<br>{{ $self->details() }}<br>{{ $self->bankDetails() }}<div class="sig">_________________ / {{ $self->director ?? '' }} /<br>М.П.</div></div>
            <div><b>КОМИТЕНТ</b><br>{{ $owner?->name ?? $v->contact_name ?? '' }}@if ($owner?->birth_at)<br>Дата рождения {{ $owner->birth_at->format('d.m.Y') }}@endif @if ($owner?->passport)<br>Паспорт {{ $owner->passport }}@endif @if ($owner?->passport_issued)<br>{{ $owner->passport_issued }}@endif @if ($owner?->reg_address)<br>Адрес регистрации: {{ $owner->reg_address }}@endif<div class="sig">_________________ / ________ /</div></div>
        </div>
    @else
        <h1>Акт приёма-передачи повреждённого транспортного средства</h1>
        <div class="head"><span>г. Москва</span><span>{{ $at }}</span></div>
        <p><b>{{ $self->name }}</b>, в лице {{ $self->director ?? '________' }}, действующего на основании {{ $self->director_basis ?? 'Устава' }}, и <b>{{ $owner?->name ?? $v->contact_name ?? '______________________' }}</b> произвели совместный осмотр повреждённого транспортного средства и составили настоящий акт о том, что {{ $owner?->name ?? $v->contact_name ?? 'КОМИТЕНТ' }} передаёт, а {{ $self->name }} принимает повреждённое ТС:</p>
        @include('billing.docs.commission-car')
        @php $r = $insp?->repairMap() ?? []; $yn = fn ($k) => isset($r[$k]) && $r[$k] !== null ? ($r[$k] ? 'да' : 'нет') : 'да / нет'; @endphp
        <p>Автомобиль аварийный, требует ремонта: кузов — {{ $yn('body') }}; двигатель — {{ $yn('engine') }}; ходовая часть — {{ $yn('chassis') }}.</p>
        <p>1. Повреждения, не соответствующие «Акту осмотра повреждённого транспортного средства», полученные при транспортировке или хранении между аварией и передачей ТС в комиссионный магазин:</p>
        <p class="blank">{{ $insp?->transit_damage }}</p>
        <p>2. На автомобиле отсутствуют следующие детали и/или агрегаты:</p>
        <p class="blank">{{ $insp?->missing_parts }}</p>
        <p>3. Узлы и агрегаты, имеющие следы замены:</p>
        <p class="blank">{{ $insp?->replaced_units }}</p>
        @if ($insp)<p>Пробег {{ $insp->mileage !== null ? Money::nums($insp->mileage).' км' : '______' }}, топливо {{ $insp->fuelLabel() ?? '____' }}, ключей {{ $insp->keys_count ?? '__' }}, документы: {{ $insp->docs ? implode(', ', array_map(fn ($d) => \App\Park\Inspection::DOCS[$d] ?? $d, $insp->docs)) : '________' }}.</p>@endif
        <div class="parties">
            <div><b>Передал</b><br>{{ $owner?->name ?? $v->contact_name ?? '' }}<div class="sig">_________________</div></div>
            <div><b>Принял</b><br>{{ $self->name }}, {{ $self->director ?? '' }}<div class="sig">_________________<br>М.П.</div></div>
        </div>
    @endif
    </article>
</body>
</html>
