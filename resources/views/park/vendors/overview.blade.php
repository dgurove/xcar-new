{{-- Обзор вендора: хранение (кто платит, счёт, ставки за сутки по категориям), реквизиты, договор, почта, что
     присылаем после приёма, заметки, ТС на парковке. Строками плашек, пустое не рисуется. --}}
@php
    use App\Support\Money;
    $payer = ['vendor' => 'Вендор', 'owner' => 'Страхователь', 'nobody' => 'Никто'][$vendor->storage_payer] ?? $vendor->storage_payer;
    $row = fn ($label, $value) => $value !== null && $value !== '' ? [$label, $value] : null;
@endphp
<div class="flex flex-col gap-2">
    <div class="list-head">Хранение</div>
    <div class="list">
        <div class="row justify-between"><span class="text-ink-muted">Платит</span><span>{{ $payer }}</span></div>
        <div class="row justify-between"><span class="text-ink-muted">Счёт</span><span class="text-right">{{ $vendor->billing_cadence->label() }}</span></div>
        <div class="row justify-between"><span class="text-ink-muted">НДС</span><span>{{ $vendor->vat_included ? 'Цены с НДС' : 'Без НДС' }}</span></div>
        @if ($vendor->payment_days !== null)<div class="row justify-between"><span class="text-ink-muted">Оплата</span><span class="nums">{{ $vendor->payment_days }} раб. дн</span></div>@endif
        @if ($vendor->buyer_pays_late)<div class="row justify-between"><span class="text-ink-muted">Опоздавший покупатель</span><span class="nums">× {{ rtrim(rtrim(number_format($vendor->buyer_rate_multiplier, 2, '.', ''), '0'), '.') }} прайса</span></div>@endif
        @if ($vendor->release_without_payment)<div class="row justify-between"><span class="text-ink-muted">Выдача</span><span>Без оплаты</span></div>@endif
    </div>
    @if ($vendor->kind->billable())
        <div class="list-head">За сутки</div>
        <x-vendor.rates :vendor="$vendor" :edit="auth()->user()->canManagePark() ? $base.'?pill=tariffs' : null"/>
    @endif

    @php $bank = array_filter([$row('Юрлицо', $vendor->legal_name), $row('ИНН', $vendor->inn), $row('КПП', $vendor->kpp), $row('Банк', $vendor->bank_name), $row('Р/с', $vendor->bank_account), $row('БИК', $vendor->bank_bic)]); @endphp
    <div class="list-head">Реквизиты</div>
    @if ($bank)
        <div class="list">
            @foreach ($bank as [$label, $value])<div class="row justify-between"><span class="shrink-0 text-ink-muted">{{ $label }}</span><x-ui.copy-code :value="$value" class="min-w-0 text-right" done="Скопировано"/></div>@endforeach
        </div>
    @else
        <div class="list"><div class="row justify-between"><span class="text-urgent">Не заполнены</span></div></div>
    @endif

    <div class="list-head">Договор</div>
    <div class="list">
        @if ($vendor->agreement_number || $vendor->agreement_date)
            <div class="row justify-between"><span class="text-ink-muted">Номер</span><span class="nums text-right">{{ $vendor->agreement_number }}@if ($vendor->agreement_date) от {{ $vendor->agreement_date->translatedFormat('j M Y') }}@endif</span></div>
        @endif
        @if ($vendor->agreement_until)<div class="row justify-between"><span class="text-ink-muted">Действует до</span><span class="nums {{ $vendor->agreementExpired() ? 'text-danger' : '' }}">{{ $vendor->agreement_until->translatedFormat('j M Y') }}</span></div>@endif
        @if ($contract)
            <div class="row justify-between">
                <a href="/files/{{ $contract->id }}" class="flex min-w-0 items-center gap-2" data-turbo="false"><x-ui.icon name="file" class="size-5 shrink-0 text-ink-dim"/><span class="truncate">{{ $contract->file_name }}</span></a>
                @if (auth()->user()->canManagePark())<form method="post" action="{{ $base }}/contract" data-turbo-confirm="Убрать файл договора?">@csrf @method('delete')<button class="btn btn-ghost btn-round btn-s text-ink-muted" aria-label="Убрать"><x-ui.icon name="trash" class="size-4"/></button></form>@endif
            </div>
        @endif
        @if (auth()->user()->canManagePark())
            <form method="post" action="{{ $base }}/contract" enctype="multipart/form-data" class="row items-end">
                @csrf
                <x-ui.field name="file" type="file" :label="$contract ? 'Заменить файл договора' : 'Файл договора'" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" span="min-w-0 flex-1"/>
                <x-ui.button variant="secondary" size="sm">Приложить</x-ui.button>
            </form>
        @endif
    </div>

    @if ($vendor->senders || $vendor->mailAccount)
        <div class="list-head">Почта</div>
        <div class="list">
            @if ($vendor->senders)<div class="row justify-between"><span class="shrink-0 text-ink-muted">Письма от</span><span class="min-w-0 text-right">{{ implode(', ', $vendor->senders) }}</span></div>@endif
            @if ($vendor->mailAccount)<div class="row justify-between"><span class="shrink-0 text-ink-muted">Отвечаем с</span><span class="min-w-0 truncate">{{ $vendor->mailAccount->email }}</span></div>@endif
        </div>
    @endif

    @if ($vendor->intake_docs || $vendor->intake_note)
        <div class="list-head">После приёма присылаем</div>
        <div class="list">
            @foreach ($vendor->intake_docs ?? [] as $d)@if ($doc = \App\Vendors\DocRequirement::tryFrom($d))<div class="row justify-between"><span>{{ $doc->label() }}</span></div>@endif @endforeach
            @if ($vendor->intake_note)<div class="row justify-between"><span class="text-ink-muted">{{ $vendor->intake_note }}</span></div>@endif
        </div>
    @endif

    @if ($vendor->notes)
        <div class="list-head">Заметки</div>
        <div class="list"><div class="row justify-between"><span class="whitespace-pre-line">{{ $vendor->notes }}</span></div></div>
    @endif

    @if ($vehicles->isNotEmpty())
        <div class="list-head">На парковке <span class="nums">{{ $vehicles->count() }}</span></div>
        <div class="list">
            @foreach ($vehicles as $v)
                <a href="/cars/{{ $v->id }}" class="row justify-between">
                    <span class="min-w-0"><span class="block truncate">{{ $v->titleWithYear() }}</span><span class="block truncate text-sm text-ink-muted">{{ $v->ref }}@if ($v->yard) {{ $v->ref ? ',' : '' }} {{ $v->yard->name }}@endif</span></span>
                    <span class="flex shrink-0 items-center gap-1"><span class="nums text-sm text-ink-muted">{{ $v->daysStored() }} дн</span><x-ui.icon name="chevron-right" class="size-4 text-ink-dim"/></span>
                </a>
            @endforeach
        </div>
    @endif

    @if (auth()->user()->isStaff())<a href="{{ $crm }}" class="btn btn-ghost btn-s mt-4 self-start" data-turbo="false">Продажа и маршруты в CRM ↗</a>@endif
</div>
