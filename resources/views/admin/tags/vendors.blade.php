{{-- «Ставится вендорам» в шторке метки (11.10.2026, начальник): черновик такого вендора получает метку сам
     (`Offers\VendorTags`), снять у одного предложения можно. Та же связь — «Метки по умолчанию» в шторке «Продажа» вендора.
     Вендоры строками с галкой, как выбор менеджеров. --}}
<div>
    <p class="list-cap !px-1">Ставится вендорам</p>
    <div class="list">
        @foreach ($vendors as $v)
            <label class="row row-check">
                <x-vendor.logo :vendor="$v" class="size-7"/>
                <span class="min-w-0 flex-1 truncate">{{ $v->name }}</span>
                <span class="check"><input type="checkbox" name="vendors[]" value="{{ $v->id }}" @checked(in_array($v->id, $picked, true))></span>
            </label>
        @endforeach
    </div>
</div>
