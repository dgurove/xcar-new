{{-- Письма в окне: кнопка «Письма N» (x-mail.window-button, событие letters:open) открывает шторку — на телефоне почти
     во весь экран, от 640 окно 48rem по центру; письма целиком с вложениями прокручиваются внутри. Ни блока сбоку, ни отдельной страницы. --}}
@props(['messages', 'base' => '/mail'])
<div data-controller="sheet" data-action="letters:open@window->sheet#open" class="contents">
    <x-ui.sheet id="letters" :title="'Письма '.count($messages)" tall {{ $attributes }}>
        <x-mail.panel :messages="$messages" :base="$base"/>
    </x-ui.sheet>
</div>
