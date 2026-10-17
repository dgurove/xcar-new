{{-- «Оплаты» логистики (19.01.2027): логисту — реквизиты строкой (нажатие — шторка, те же поля, что у менеджера) и долг по
     вывозам: «Ждут выплаты» по сроку, «Выплачено» — свежие сверху, у заголовка — сколько и на какую сумму; строка — машина, город и км, справа сумма и срок. Админу —
     то же по каждому логисту, имя — заголовком группы. Выплачивают в CRM «Оплаты → Мы должны». --}}
@php use App\Support\Money; @endphp
<x-ui.shell title="Оплаты">
    @forelse ($rows as $row)
        @php $party = $row->party; $u = $row->user; @endphp
        <section @class(['mt-8' => ! $loop->first])>
            @if ($me->isAdmin())<div class="list-head">{{ $u->name }}</div>@endif
            @include('logistics.money.xcar')
        </section>
    @empty
        <x-ui.empty>Логистов нет: позовите ссылкой в CRM «Пользователи»</x-ui.empty>
    @endforelse
</x-ui.shell>
