@foreach (\App\Support\Nav::badgePaths(auth()->user()) as $href)
<turbo-stream action="update" targets="[data-badge='{{ $href }}']"><template>@if (!empty($badges[$href]))<span class="badge">{{ $badges[$href] > 99 ? '99+' : $badges[$href] }}</span>@endif</template></turbo-stream>
@endforeach
@if (\App\Offers\Binding::alarmsFor(auth()->user()))
{{-- Тревога «нет покупателя» — только кнопка: открытая шторка со списком не закрывается. --}}
<turbo-stream action="replace" targets="[data-alarm-button='phone']"><template><x-alarm.button/></template></turbo-stream>
<turbo-stream action="replace" targets="[data-alarm-button='wide']"><template><x-alarm.button wide/></template></turbo-stream>
@endif
@auth
{{-- Число для значка приложения — одна дверь `User::badgeCount` (pwa_controller читает meta). --}}
<turbo-stream action="replace" targets="meta[name='badge-count']"><template><meta name="badge-count" content="{{ auth()->user()->badgeCount() }}"></template></turbo-stream>
@endauth
