<!doctype html>
<html lang="ru"><body style="font-family:system-ui,sans-serif;color:#1d1d1b;font-size:16px;line-height:1.5">
<p>{{ $user->name }}, непрочитанное за сутки:</p>
<ul style="padding-left:20px">
@foreach ($items as $item)
    <li style="margin-bottom:8px"><a href="{{ url($item->data['href']) }}" style="color:#669709">{{ $item->data['title'] }}</a>
    @if (!empty($item->data['text']))<br><span style="color:#808080">{{ $item->data['text'] }}</span>@endif</li>
@endforeach
</ul>
<p><a href="{{ url('/lk/uvedomleniya') }}" style="color:#669709">Все уведомления</a></p>
<p style="font-size:13px"><a href="{{ $user->unsubscribeUrl() }}" style="color:#808080">Не присылать на почту</a></p>
</body></html>
