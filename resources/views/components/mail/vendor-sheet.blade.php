{{-- «Выбрать вендора» у письма, где вендор не узнан (16.10.2026, владелец): одна шторка на страницу, открывает её
     `x-mail.vendor-pick` событием `vendor-pick:open` с адресом формы, отправителем, догадкой названия и доменом
     (`App\Mail\VendorPick`). «Выбрать» — из своих вендоров стороны (CRM или парковки) строками с поиском, «Новый» —
     название и тип. Кнопка называет исход. Ответ — `MailController::vendor` → `BindSender`: письма с адреса теперь его. --}}
@props(['park' => false])
@php
    use App\Vendors\Kind;
    use App\Vendors\Vendor;
    $vendors = ($park ? Vendor::onPark() : Vendor::query()->whereNull('crm_vendor_id'))->where('is_active', true)->orderBy('name')->get(['id', 'name']);
@endphp
<div class="contents" data-controller="sheet vendor-pick" data-action="vendor-pick:open@window->vendor-pick#fill vendor-pick:open@window->sheet#open">
    <x-ui.sheet id="vendor-pick" title="Вендор письма" class="sheet-steady-phone">
        <form method="post" action="{{ old('_action') }}" class="flex flex-col gap-3" data-vendor-pick-target="form" data-controller="reveal"
              data-action="change->vendor-pick#label input->vendor-pick#label">
            @csrf
            <input type="hidden" name="_action" value="{{ old('_action') }}" data-vendor-pick-target="action">
            <input type="hidden" name="sender" value="{{ old('sender') }}" data-vendor-pick-target="senderInput">
            <div class="min-w-0 truncate text-sm text-ink-dim">От <span class="text-ink" data-vendor-pick-target="sender">{{ old('sender') }}</span></div>
            <x-ui.segment name="mode" :options="['pick' => 'Выбрать', 'new' => 'Новый']" :value="old('mode', 'pick')" pick/>
            <div class="contents" data-reveal-target="pane" data-reveal-key="pick" @if (old('mode') === 'new') hidden @endif>
                @if ($vendors->count() > 8)
                    <input type="search" class="field-input field-s" placeholder="Найти" autocomplete="off" enterkeyhint="search" data-action="input->vendor-pick#filter keydown.enter->vendor-pick#stop">
                @endif
                <div class="list">
                    @foreach ($vendors as $v)
                        <label class="row row-check" data-vendor-pick-target="item" data-name="{{ mb_strtolower($v->name) }}">
                            <x-vendor.logo :vendor="$v" class="size-7 shrink-0"/>
                            <span class="min-w-0 flex-1 truncate">{{ $v->name }}</span>
                            <span class="check"><input type="radio" name="vendor_id" value="{{ $v->id }}" data-label="{{ $v->name }}" @checked((string) old('vendor_id') === (string) $v->id)></span>
                        </label>
                    @endforeach
                </div>
                @error('vendor_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="contents" data-reveal-target="pane" data-reveal-key="new" @if (old('mode') !== 'new') hidden @endif>
                <div class="fields">
                    <x-ui.field name="name" label="Название" :value="old('name')" id="vendor-pick-name" data-vendor-pick-target="name"/>
                    <x-ui.field name="kind" label="Тип" :options="Kind::options()" :value="old('kind', Kind::Insurer->value)" id="vendor-pick-kind"/>
                </div>
            </div>
            <label class="row row-check" data-vendor-pick-target="domainRow" @if (! old('domain')) hidden @endif>
                <span class="min-w-0 flex-1" data-vendor-pick-target="domainLabel">Все адреса домена</span>
                <span class="check"><input type="checkbox" name="domain" value="1" data-vendor-pick-target="domainBox" @checked(old('domain'))></span>
            </label>
            <x-ui.sheet-foot type="submit" id="vendor-pick-submit" data-vendor-pick-target="submit">Отнести к вендору</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
