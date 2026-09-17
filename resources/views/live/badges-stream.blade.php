@foreach (\App\Support\Nav::badgePaths(auth()->user()) as $href)
<turbo-stream action="update" targets="[data-badge='{{ $href }}']"><template>@if (!empty($badges[$href]))<span class="badge">{{ $badges[$href] > 99 ? '99+' : $badges[$href] }}</span>@endif</template></turbo-stream>
@endforeach
@auth
{{-- Число для значка приложения: уведомления и чаты вместе (pwa_controller читает meta). --}}
<turbo-stream action="replace" targets="meta[name='badge-count']"><template><meta name="badge-count" content="{{ ($badges['/account/notifications'] ?? 0) + ($badges['/account/chats'] ?? 0) }}"></template></turbo-stream>
@endauth
