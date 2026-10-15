{{-- Одно письмо страховой подложкой: дата и урезанный текст (`Offers\ReplyText`, 15.10.2026, владелец: без подписи
     сотрудника, названия вендора, сумм, почты и ссылок; файлов писем менеджеру нет). Номера убытка и ДЛ вырезаны. --}}
<div class="reply">
    <p class="nums text-sm text-ink-dim">{{ $reply->date_at?->translatedFormat('j M, H:i') }}</p>
    <p class="mt-1 whitespace-pre-line break-words">{!! \App\Support\Linkify::html((string) \App\Offers\ReplyText::for($reply, $deal->offer)) !!}</p>
</div>
