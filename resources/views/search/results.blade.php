{{-- Выдача поиска в шапке (`Search\Search::for`): группа — мелкая подпись с «Все ›» справа и строки в два этажа, как
     строка таблицы CRM, без значков (что это — говорит подпись группы). Совпадение с запросом горит в названии и номере
     (`Term::mark`). У машины во второй строке — номер убытка с логотипом вендора (CRM и парковка) и фраза «где» кусками;
     у незаведённого номера из таблицы Кати — «Завести» справа. Строки и «Все ›» с `data-hit` листаются ↑/↓ и
     открываются Enter (global_search_controller). --}}
@php $t = \App\Search\Term::of($q); @endphp
@if ($groups)
    <div class="search-results">
        @foreach ($groups as $group)
            <section class="search-group">
                <div class="search-group-head">
                    <span>{{ $group['title'] }}</span>
                    @isset ($group['more'])<a href="{{ $group['more']['href'] }}" class="search-all" data-hit data-turbo-frame="_top">{{ $group['more']['label'] }}<x-ui.chevron class="size-3.5"/></a>@endisset
                </div>
                <div class="search-rows">
                    @foreach ($group['rows'] as $hit)
                        <div class="search-hit">
                            <a href="{{ $hit->href }}" class="search-link" data-hit data-turbo-frame="_top" @if ($hit->external) data-turbo="false" @endif>
                                <span class="search-title">{{ $t->mark($hit->title) }}</span>
                                @if (($hit->ref && $hit->ref !== $hit->title) || $hit->line)
                                    <span class="search-line">
                                        @if ($hit->ref && $hit->ref !== $hit->title)<span class="search-ref"><x-vendor.ref :vendor="$hit->vendorId ? \App\Vendors\Vendor::badges()->get($hit->vendorId) : null" :ref="$t->mark($hit->ref)"/></span>@endif
                                        @foreach ($hit->line as $part)<span>{{ $part }}</span>@endforeach
                                    </span>
                                @endif
                            </a>
                            @if ($hit->action)
                                <form method="post" action="{{ $hit->action['url'] }}" data-turbo-frame="_top" class="search-do">@csrf<button class="pill pill-accent">{{ $hit->action['label'] }}</button></form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@elseif (mb_strlen($q) >= 2)
    <p class="search-none">Ничего не нашлось</p>
@endif
