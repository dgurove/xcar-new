{{-- Страница ТС для тех, кто с ней работает (гараж, вывоз, сделка): слева кадры (лента, свайп, просмотр во весь
     экран), справа липко деньги, характеристики и документы; под кадрами — шаг, путь, расходы и описание.
     Телефон: кадры, деньги, работа, характеристики, документы. Документы — строками, открываются шторкой. --}}
@props(['offer', 'photos', 'docs' => collect(), 'full' => true])
<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-8">
    {{-- Пустой блок не рисуется: без кадров нет и заглушки «Фотографий нет» на пол-экрана. --}}
    @if ($photos->isNotEmpty())
        <div class="order-1 min-w-0 lg:col-start-1 lg:row-start-1">
            <x-offer.gallery :photos="$photos" :alt="$offer->titleWithYear()" :thumbs="false"/>
        </div>
    @endif

    <aside class="contents lg:sticky lg:top-24 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:flex lg:flex-col lg:gap-6 lg:self-start">
        @isset($aside)<div class="order-2 flex flex-col gap-4">{{ $aside }}</div>@endisset
        <x-offer.facts :offer="$offer" :full="$full" class="order-4"/>
        @if ($docs->isNotEmpty())
            <section class="order-5">
                <h2 class="list-head lg:pt-0">Документы</h2>
                <div class="flex flex-col">
                    @foreach ($docs as $media)
                        <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}"/>
                    @endforeach
                </div>
            </section>
        @endif
    </aside>

    <section @class(["order-3 flex min-w-0 flex-col gap-6 lg:col-start-1", $photos->isNotEmpty() ? "lg:row-start-2" : "lg:row-start-1"])>
        {{ $slot }}
        @if ($offer->description)
            <div>
                <h2 class="list-head">Описание</h2>
                <p class="whitespace-pre-line text-ink-muted">{{ $offer->description }}</p>
            </div>
        @endif
    </section>
</div>
