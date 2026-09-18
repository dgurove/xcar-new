<turbo-stream action="replace" target="gallery"><template>@include('park.vehicles.gallery', ['vehicle' => $vehicle])</template></turbo-stream>
<turbo-stream action="replace" target="papers"><template>@include('park.vehicles.papers', ['vehicle' => $vehicle])</template></turbo-stream>
@if ($stage ?? null)
<turbo-stream action="replace" target="photo-slots"><template><div id="photo-slots"><x-park.photo-slots :vehicle="$vehicle" :stage="$stage" :slots="\App\Park\PhotoSlot::cases()"/></div></template></turbo-stream>
@endif
