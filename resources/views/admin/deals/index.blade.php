<x-ui.shell title="Сделки" :heading="false">
    <x-admin.work-titles current="deals" :count="$deals->total()"/>
    <x-ui.toolbar class="mt-5" :sorts="\App\Http\Admin\DealController::SORTS" :sort="$sort" :pills="\App\Http\Admin\DealController::PRESETS" :pill="$preset" pill-param="preset" name="deals"/>

    @if ($deals->isEmpty())
        <x-ui.empty class="mt-6">Сделок нет</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2">
            @foreach ($deals as $deal)
                @include('admin.deals.row', ['deal' => $deal])
            @endforeach
        </div>
        <div class="mt-8">{{ $deals->links() }}</div>
    @endif
</x-ui.shell>
