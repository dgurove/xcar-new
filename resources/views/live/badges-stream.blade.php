@foreach (\App\Support\Nav::badgePaths(auth()->user(), $surface) as $href)
<turbo-stream action="update" targets="[data-badge='{{ $href }}']"><template>@if (!empty($badges[$href]))<span class="badge">{{ $badges[$href] > 99 ? '99+' : $badges[$href] }}</span>@endif</template></turbo-stream>
@endforeach
