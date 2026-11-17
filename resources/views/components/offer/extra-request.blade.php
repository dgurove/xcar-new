{{-- Доп. запрос поставщику над письмами машины (17.11.2026, владелец по CTTM Cargoline 5491/046/00861/26: «в CRM над блоком
     писем удобный блок с кнопкой „Сделать доп. запрос“»): последний запрос — что спросили и когда, справа серым «ждём ответа 2д»
     (ждём не нас) или «ответили 16.11» — входящее письмо по машине после запроса в любой ветке; ниже кнопка своей строкой —
     окно писем с галками запросов (`MailController::compose` с `extra`, `ExtraRequest`). Окно машины и страница `/offers/N`. --}}
@props(['offer', 'messages' => []])
@php $last = \App\Offers\ExtraRequest::last(collect($messages)); @endphp
<div {{ $attributes->class('extra-request') }}>
    @if ($last)
        <div class="extra-request-last">
            <div class="extra-request-head">
                <span class="extra-request-title">Доп. запрос {!! \App\Support\Ago::html($last['at'], true) !!}</span>
                @if ($last['answered'])
                    <span class="extra-request-state">ответили {!! \App\Support\Ago::html($last['answered'], true) !!}</span>
                @else
                    <span class="extra-request-state" title="Письмо ушло {{ \App\Support\Ago::at($last['at']) }}">ждём ответа {{ \App\Support\Ago::since($last['at'], hours: true) }}</span>
                @endif
            </div>
            <p class="extra-request-text">{{ \Illuminate\Support\Str::ucfirst($last['text']) }}</p>
        </div>
    @endif
    <x-mail.window-button :url="'/work/mail/new?offer='.$offer->number.'&extra=1'" label="Сделать доп. запрос" icon="mail" class="btn-s self-start"/>
</div>
