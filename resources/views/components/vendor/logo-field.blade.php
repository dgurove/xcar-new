{{-- Логотип в шторке вендора: квадрат слева от названия, нажатие открывает выбор файла, выбранный сразу встаёт в
     квадрат (avatar_controller). Логотип общий для CRM и парковки, как имя. Форма — multipart. --}}
@props(['vendor'])
<div class="flex items-end gap-3" data-controller="avatar">
    <button type="button" class="shrink-0" data-action="avatar#pick" aria-label="Логотип">
        <span class="vendor-logo-pick" data-avatar-target="circle">@if ($url = $vendor->logoUrl())<img src="{{ $url }}" alt="">@else<x-ui.icon name="camera" class="size-5"/>@endif</span>
    </button>
    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="sr-only" tabindex="-1" data-avatar-target="input" data-action="change->avatar#preview">
    <div class="min-w-0 flex-1">{{ $slot }}</div>
</div>
@if ($vendor->logoUrl())<label class="choice -mt-1"><input type="checkbox" switch name="remove_logo" value="1"><span>Убрать логотип</span></label>@endif
@error('logo')<p class="field-error">{{ $message }}</p>@enderror
