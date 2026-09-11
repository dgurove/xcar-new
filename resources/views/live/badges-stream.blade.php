@foreach (\App\Support\Nav::tabs(auth()->user()) as $tab)
<turbo-stream action="replace" target="{{ \App\Support\Nav::badgeId($tab['href']) }}"><template><x-ui.badge :href="$tab['href']" :badges="$badges"/></template></turbo-stream>
@endforeach
