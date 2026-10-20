{{-- Шаг «Нужно ответить»: письмо вендора, которое ждёт ответа, его текст, «Ответить» в окне писем и «Сделано». --}}
@php use App\Mail\Extraction\Intent; $m = $step->ask; @endphp
<div class="mt-2 whitespace-pre-line text-sm">{{ Intent::excerpt($m->text_body ?: strip_tags((string) $m->html_body)) }}</div>
<div class="mt-2 flex items-center gap-2">
    <x-mail.window-button :url="$step->plate['url']" label="Ответить" class="btn-s btn-accent min-w-0 flex-1"/>
    <button type="submit" form="reply-form-{{ $m->id }}" class="btn btn-s btn-quiet shrink-0">Сделано</button>
</div>
