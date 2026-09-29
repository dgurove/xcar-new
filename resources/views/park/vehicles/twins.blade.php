{{-- Такая ТС уже есть (VehicleController::twins, twins_controller): строки ТС под полями. На разборе письма —
     «Это она»: форма открывается на эту ТС, письма привяжутся к ней, второй ТС не будет. --}}
@foreach ($vehicles as $v)
    <div class="box-nested box-urgent flex items-center gap-2 !p-1.5">
        <div class="min-w-0 flex-1"><x-park.vehicle-row :vehicle="$v"><x-park.state :vehicle="$v"/></x-park.vehicle-row></div>
        @if ($candidate)<a href="/requests/new?candidate={{ $candidate }}&car={{ $v->id }}" class="btn btn-s btn-secondary shrink-0">Это она</a>@endif
    </div>
@endforeach
