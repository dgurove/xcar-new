{{-- «Отправили сами» — документ ушёл не письмом отсюда (бумагой, мессенджером, раньше — по-старому): свой номер (по
     умолчанию — какой получит; у прошлых месяцев — из архива: «20240904-01», «202503/04») и день отправки, от него срок
     оплаты. У исправленного номер прежний — только день. --}}
<form method="post" action="/money/draft/fix" class="flex flex-col gap-4">
    @csrf
    <input type="hidden" name="month" value="{{ $doc->month->format('Y-m') }}"><input type="hidden" name="group" value="{{ $doc->group }}">
    @if ($replaces)<input type="hidden" name="replaces" value="{{ $replaces->id }}">@endif
    <div class="fields">
        @unless ($replaces)<x-ui.field name="code" label="Номер" :id="'code-'.$doc->key" :value="old('code', $draft?->code)"/>@endunless
        <x-ui.field name="sent_on" label="Когда отправили" type="date" :id="'sent-on-'.$doc->key" :value="old('sent_on', now()->toDateString())" :max="now()->toDateString()" required/>
    </div>
    <x-ui.sheet-foot variant="secondary">Отметить отправленным</x-ui.sheet-foot>
</form>
