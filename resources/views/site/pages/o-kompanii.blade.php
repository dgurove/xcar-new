{{-- О компании: продавец и владелец сайта — реквизиты по карточке предприятия, связь и как забрать ТС. --}}
@php $c = config('xcar.company'); @endphp
<x-ui.shell title="О компании" :back="false" :trail="[['Главная', '/'], ['О компании']]" narrow>
    <h2 class="list-head">Реквизиты</h2>
    <div class="list">
        <div class="row justify-between gap-4"><span class="text-ink-muted">Наименование</span><span class="text-right">{{ $c['full_name'] }}</span></div>
        <div class="row justify-between gap-4"><span class="text-ink-muted">ИНН / КПП</span><span class="nums text-right">{{ $c['inn'] }} / {{ $c['kpp'] }}</span></div>
        <div class="row justify-between gap-4"><span class="text-ink-muted">ОГРН</span><span class="nums text-right">{{ $c['ogrn'] }}</span></div>
        <div class="row justify-between gap-4"><span class="text-ink-muted">Юридический адрес</span><span class="text-right">{{ $c['address'] }}</span></div>
        <div class="row justify-between gap-4"><span class="text-ink-muted">Расчётный счёт</span><span class="nums text-right">{{ $c['account'] }}</span></div>
        <div class="row justify-between gap-4"><span class="text-ink-muted">Банк</span><span class="text-right">{{ $c['bank'] }}</span></div>
        <div class="row justify-between gap-4"><span class="text-ink-muted">БИК</span><span class="nums text-right">{{ $c['bik'] }}</span></div>
        <div class="row justify-between gap-4"><span class="text-ink-muted">Корр. счёт</span><span class="nums text-right">{{ $c['corr_account'] }}</span></div>
        <div class="row justify-between gap-4"><span class="text-ink-muted">Генеральный директор</span><span class="text-right">{{ $c['director_full'] }}</span></div>
    </div>

    <h2 class="list-head">Связь</h2>
    <div class="list">
        <a href="tel:{{ preg_replace('/[^\d+]/', '', $c['phone']) }}" class="row justify-between gap-4"><span class="text-ink-muted">Телефон</span><span class="nums text-accent-text">{{ $c['phone'] }}</span></a>
        <a href="mailto:{{ $c['email'] }}" class="row justify-between gap-4"><span class="text-ink-muted">Почта</span><span class="text-accent-text">{{ $c['email'] }}</span></a>
        <div class="row justify-between gap-4"><span class="text-ink-muted">Почтовый адрес</span><span class="text-right">{{ $c['address'] }}</span></div>
    </div>

    <h2 class="list-head">Оплата и получение</h2>
    <div class="list">
        <div class="row justify-between gap-4"><span class="text-ink-muted">Оплата</span><span class="text-right">переводом по счёту, картой, через СБП или SberPay по ссылке</span></div>
        <div class="row justify-between gap-4"><span class="text-ink-muted">Получение</span><span class="text-right">самовывоз с парковки, указанной в предложении, по пропуску после оплаты</span></div>
        <a href="/terms" class="row justify-between"><span>Пользовательское соглашение</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
    </div>
</x-ui.shell>
