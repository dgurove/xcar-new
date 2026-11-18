{{-- Страница ТС для тех, кто с ней работает (гараж): слева работа (шаг, путь, расходы, описание), справа липко деньги,
     характеристики, документы и кадры плиткой внизу — как у сделки (18.11.2026, владелец: «в гараже и сделке фотки всегда вбок/
     вниз, они не должны быть в центре внимания»; прежде лента кадров стояла первой на пол-экрана). Телефон: деньги, работа,
     характеристики, документы, фото. Документы — строками, открываются шторкой. --}}
@props(['offer', 'photos', 'docs' => collect(), 'full' => true])
<div class="grid grid-cols-[minmax(0,1fr)] gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-6">
    <aside class="contents lg:sticky lg:top-[calc(var(--stick-top)+1rem)] lg:col-start-2 lg:row-start-1 lg:flex lg:flex-col lg:gap-4 lg:self-start">
        @isset($aside)<div class="order-2 flex flex-col gap-4">{{ $aside }}</div>@endisset
        <x-offer.facts :offer="$offer" :full="$full" class="order-4"/>
        @if ($docs->isNotEmpty())
            <section class="order-5">
                <h2 class="list-head lg:pt-0">Документы</h2>
                <div class="flex flex-col">
                    @foreach ($docs as $media)
                        <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}" :refs="[$offer->claim_ref]"/>
                    @endforeach
                </div>
            </section>
        @endif
        {{-- Кадры — плиткой в самом низу колонки, как у сделки (`cabinet.deals.show`); пустого блока нет. --}}
        @if ($photos->isNotEmpty())
            <section class="order-9 min-w-0">
                <h2 class="mb-2 px-1 text-sm font-medium text-ink-dim">Фото <span class="nums">{{ $photos->count() }}</span></h2>
                <x-offer.gallery :photos="$photos" :alt="$offer->titleWithYear()" tiles :offer="$offer"/>
            </section>
        @endif
    </aside>

    <section class="order-3 flex min-w-0 flex-col gap-4 lg:col-start-1 lg:row-start-1">
        {{ $slot }}
        @if ($offer->publicDescription())
            <div>
                <h2 class="list-head">Описание</h2>
                <p class="whitespace-pre-line text-ink-muted">{{ $offer->publicDescription() }}</p>
            </div>
        @endif
    </section>
</div>
