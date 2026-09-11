<turbo-stream action="replace" target="gallery"><template>@include('park.vehicles.gallery', ['vehicle' => $vehicle])</template></turbo-stream>
<turbo-stream action="replace" target="papers"><template>@include('park.vehicles.papers', ['vehicle' => $vehicle])</template></turbo-stream>
