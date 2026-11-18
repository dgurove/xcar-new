{{-- Подвал шторки «Принять» / «Отдать» / «В гараж» (18.11.2026, владелец: «должен явно указывать — отправить письмо или не
     отправлять, пока явно не нажмёт, дальше нельзя»): маршрут после принятия попросит письмо вендору (`Offer::dealAsksLetter`)
     — две кнопки, выбор уходит полем `letter` (`GiveController::done`): «и написать письмо» — редактор над той же машиной,
     «без письма» — шаг закрыт как «сообщили сами» (поставщик уже знает). Письма не будет — одна кнопка, как раньше. --}}
@props(['offer', 'label', 'letter' => true])
@if ($letter && $offer->dealAsksLetter())
    <div class="sheet-foot flex flex-col gap-2">
        <x-ui.button block type="submit" variant="primary" name="letter" value="write"><x-ui.icon name="send" class="size-4"/>{{ $label }} и написать письмо</x-ui.button>
        <x-ui.button block type="submit" variant="quiet" name="letter" value="skip">{{ $label }} без письма</x-ui.button>
    </div>
@else
    <x-ui.sheet-foot type="submit" variant="primary">{{ $label }}</x-ui.sheet-foot>
@endif
