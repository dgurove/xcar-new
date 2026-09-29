{{-- Круг менеджеров: по умолчанию все; сняли «Все» — выбирайте. $form — id формы, когда поля стоят вне её (редактор). --}}
<div data-controller="managers">
    <input type="hidden" name="managers_limited" value="{{ old('managers_limited', $offer->managers_limited ? 1 : 0) }}" @if ($form ?? null) form="{{ $form }}" @endif data-managers-target="limited">
    <div class="flex flex-wrap gap-1.5">
        <label class="choice"><input type="checkbox" @checked(! old('managers_limited', $offer->managers_limited)) data-managers-target="all" data-action="managers#toggleAll"><span>Все</span></label>
        @foreach ($managers as $m)
            <label class="choice"><input type="checkbox" name="managers[]" value="{{ $m->id }}" @if ($form ?? null) form="{{ $form }}" @endif @checked(in_array($m->id, old('managers', $offerManagers))) @disabled(! old('managers_limited', $offer->managers_limited)) data-managers-target="chip" data-action="managers#pick"><span class="gap-1.5"><x-ui.avatar :user="$m" :size="20"/>{{ $m->shortName() }}</span></label>
        @endforeach
    </div>
</div>
