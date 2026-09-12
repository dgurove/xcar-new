{{-- Главная стоянки: первый экран с числом машин и «Принять машину», ниже — заявки, которые ждут. --}}
<x-ui.shell :heading="false" over-hero>
    <x-ui.hero :count="$stored" :label="\App\Support\Plural::of($stored, ['машина на стоянке', 'машины на стоянке', 'машин на стоянке'])" href="/zayavki/novaya?tip=intake" button="Принять машину" cue="#catalog-section"/>

    <div id="catalog-section" class="over-hero">
        <div class="container-site pb-10 pt-10 sm:pb-14 sm:pt-14">
            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
                <x-ui.section-title level="h1" :count="$requests">Заявки</x-ui.section-title>
                <x-ui.section-title href="/mashiny" :current="false" :count="$stored">Машины</x-ui.section-title>
                @if ($expected)<x-ui.section-title href="/mashiny?preset=expected" :current="false" :count="$expected">Ожидаются</x-ui.section-title>@endif
            </div>
            <div class="mt-6">
                @if ($fresh->isEmpty())
                    <x-ui.empty href="/zayavki/novaya" link="Новая заявка">Всё сделано.</x-ui.empty>
                @else
                    <div class="flex flex-col gap-2">
                        @foreach ($fresh as $r)
                            <x-park.vehicle-row :vehicle="$r->vehicle" :href="'/zayavki/'.$r->id">
                                <x-ui.pill :tone="$r->isOverdue() ? 'danger' : 'soft'" class="!min-h-0 !py-1 text-xs">{{ $r->type->label() }}{{ $r->type === \App\Park\RequestType::Move && $r->yard ? ' → '.$r->yard->name : '' }}</x-ui.pill>
                                @if ($r->planned_at)<span class="chip {{ $r->isOverdue() ? 'text-danger' : '' }}">{{ $r->planned_at->translatedFormat('j M, H:i') }}</span>@endif
                            </x-park.vehicle-row>
                        @endforeach
                    </div>
                    <a href="/zayavki" class="btn btn-quiet mt-6">Все заявки</a>
                @endif
            </div>
        </div>
    </div>
</x-ui.shell>
