{{-- Дата и время одним видом (`Support\Ago::html`): «сегодня 07:40», «07.10 07:40», время приглушено. words — «сегодня» и
     «вчера» словом (во фразе — false: «с 06.10 12:00»); short — строка чата и ленты: сегодня только время. --}}
@props(['at' => null, 'time' => false, 'words' => true, 'short' => false])
{{ \App\Support\Ago::html($at, $time, $words, $short) }}