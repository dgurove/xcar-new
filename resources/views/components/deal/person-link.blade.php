{{-- Ссылка тому, кому отдали машину, — строкой в карточке сделки и гаража CRM (28.10.2026, владелец: прислать позже):
     его сделка или машина в гараже на сайте (`Deal::personUrl`; не вошёл — вход и сюда же). «Скопировать» и
     «Отправить» текстом (`Deal::personText`). Та же ссылка сразу после «Отдать» — шторкой `x-deal.handed`. Только админу. --}}
@props(['deal', 'pill' => false, 'inline' => false])
@php $url = auth()->user()?->canManageCrm() ? $deal->personUrl() : null; @endphp
@if ($url && $deal->buyer && ($pill || $inline))
    {{-- Пилюлей в ряду «Чат · Документы» окошка сделки (03.11.2026, владелец: карточка «Ссылка для Евгений Т.» посреди окошка была
         непонятной): нажатие — ссылка с текстом в буфер. --}}
    {{-- inline — тихой ссылкой в плашке менеджера окна машины (12.11.2026). --}}
    <button type="button" class="{{ $inline ? 'holder-act' : 'pill pill-plain gap-1.5' }}" data-controller="copy" data-action="copy#copy" data-copy-text-value="{{ $url }}" data-copy-title-value="{{ $deal->offer->titleWithYear() }}" data-copy-done-value="Ссылка для менеджера в буфере">
        <textarea hidden data-copy-target="message">{{ $deal->personText() }}</textarea><x-ui.icon name="link" class="size-4"/> {{ $inline ? 'Ссылка' : 'Ссылка менеджеру' }}
    </button>
@elseif ($url && $deal->buyer)
    <div {{ $attributes->class(['list']) }}>
        <x-ui.share-link variant="row" :url="$url" :title="$deal->offer->titleWithYear()" done="Ссылка в буфере" :text="$deal->personText()" :label="'Ссылка для '.$deal->buyer->shortName()"/>
    </div>
@endif
