{{-- «Отозвать» у отправленного менеджеру счёта и договора (19.11.2026, владелец: «ОЧЕНЬ не хватает возможности отозвать счёт и
     договор, чтобы что-то поправить и заново отправить»): серой пилюлей справа в строке документа, на месте «Отправить»; с вопросом.
     Менеджер документ больше не видит, номер остаётся (`ShowToManager::recall*`). --}}
@props(['action', 'what', 'deal'])
@if ($deal->buyer_id && auth()->user()?->canEditDeals())
    <form method="post" action="{{ $action }}" class="contents" data-turbo-confirm="Отозвать {{ $what }}? Менеджер перестанет его видеть">
        @csrf
        <button type="submit" class="btn btn-quiet btn-xs ml-1">Отозвать</button>
    </form>
@endif
