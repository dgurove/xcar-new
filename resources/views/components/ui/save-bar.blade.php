{{-- «Сохранить изменения» (save_bar_controller): спрятана, пока в форме ничего не тронули. Внутри формы — целью bar;
     form — кнопка вне формы, привязана к ней атрибутом form= и находится по data-save-bar. page — на странице: липнет к
     низу экрана над таб-баром, пока форма на экране; без page — к низу карточки строки. action — сохранить эту часть
     формы другим адресом (formaction), без подтверждения формы. --}}
@props(['form' => null, 'page' => false, 'action' => null, 'label' => 'Сохранить изменения'])
<div {{ $attributes->class(['save-bar', 'save-bar--page' => $page]) }} @if ($form) data-save-bar="{{ $form }}" @else data-save-bar-target="bar" @endif hidden>
    <button type="submit" @if ($form) form="{{ $form }}" @endif @if ($action) formaction="{{ $action }}" formmethod="post" data-turbo-confirm="" @endif class="btn btn-accent w-full" data-save-bar-button>{{ $label }}</button>
</div>
