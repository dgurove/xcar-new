{{-- Новый покупатель по виду (13.11.2026, владелец: «"внесём сами" не катит, они сами должны поля заполнять; паспорт — ладно,
     скан нужен»; прежде — «Реквизиты фотографией», 10.11.2026): плитки «Физлицо», «ИП», «Организация» — нажатие заводит
     покупателя этого вида и сразу открывает «Данные для договора» с полями и сканом паспорта (`Cabinet\DealController::buyerNew`).
     Ниже — выбор из своих (`cabinet.deals.contract`). --}}
@props(['deal'])
<div class="buyer-kinds">
    @foreach (['person' => ['Физлицо', 'паспорт', 'user'], 'ip' => ['ИП', 'паспорт и реквизиты', 'bag'], 'company' => ['Организация', 'реквизиты', 'bank']] as $kind => [$title, $sub, $icon])
        <form method="post" action="/deals/{{ $deal->id }}/buyer-new" class="contents">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            <button type="submit" class="buyer-kind">
                <x-ui.row-icon :name="$icon" size="s"/>
                <span class="min-w-0"><span class="block font-medium">{{ $title }}</span><span class="block text-sm text-ink-muted">{{ $sub }}</span></span>
            </button>
        </form>
    @endforeach
</div>
