{{-- Шаг «Нужно ответить»: письмо вендора, которое ждёт ответа, его текст, «Ответить» в окне писем и «Сделано». --}}
@php use App\Mail\Extraction\Intent; $m = $ask; @endphp
<div class="mt-2 whitespace-pre-line text-sm">{{ Intent::excerpt($m->text_body ?: strip_tags((string) $m->html_body)) }}</div>
<div class="mt-3 flex flex-wrap items-center gap-2">
    <x-mail.window-button :url="$step->plate['url']" label="Ответить" class="btn-accent"/>
    <button type="submit" form="reply-form" class="btn btn-quiet">Сделано</button>
</div>
