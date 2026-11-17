{{-- «Запросить счёт…» под оплатой комиссии (18.11.2026, владелец: «счёт мы больше вообще почти никогда не формируем… под блоком с
     оплатой за подбор кнопку текстовую зелёную справа мелким шрифтом»; в CRM — «Запросили счёт…»). Предупреждение — своей шторкой
     `confirmSheet`: договор и акт, «Да, запросить счёт» / «Нет, оплатим обычным способом». Ответ — `RequestFeeInvoice`. --}}
@props(['action', 'staff' => false])
<form method="post" action="{{ $action }}" {{ $attributes->class('flex justify-end') }}
    data-turbo-confirm="Комиссию можно оплатить по счёту, но тогда придётся оформлять договор и акт"
    data-turbo-confirm-text="Вы уверены?"
    data-turbo-confirm-label="{{ $staff ? 'Да, выставить счёт' : 'Да, запросить счёт' }}"
    data-turbo-confirm-cancel="{{ $staff ? 'Нет, оплатят обычным способом' : 'Нет, оплатим обычным способом' }}">
    @csrf
    <button type="submit" class="py-1 text-sm text-accent-text">{{ $staff ? 'Запросили счёт…' : 'Запросить счёт…' }}</button>
</form>
