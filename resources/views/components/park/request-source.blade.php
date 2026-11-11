{{-- Откуда заявка — строкой, как отправитель в почте (владелец 07.10.2026: «чтобы было понятно откуда это появилось»):
     слева кто — адрес написавшего (не имя, копируется) или сотрудник, который завёл; справа время с днём
     (`Request::source`). В краткой таблице и в строках или плитках — третьим этажом, у силуэта тоже; на ПК в таблице —
     столбцами «Пришла» и «От кого» (`x-park.request-row`). Стоянка (`place`) — слева, кнопкой со шторкой (`x-park.request-place`): там нет столбца
     «Парковка» (11.11.2026, Сергей с парковки: «видеть, на какой стоянке стоит, без открытия заявки»); время — справа.
     На телефоне отправителя нет: «слева парковка, справа дата и время» (владелец 11.11.2026). --}}
@props(['req', 'place' => false])
@php $s = $req->source(); @endphp
<span {{ $attributes->class('req-source') }}>
    @if ($place)<x-park.request-place :req="$req" class="req-source-yard"/>@endif
    @if ($s['email'])<x-mail.address :email="$s['email']" class="req-source-who"/>@else<span class="req-source-who">{{ $s['name'] }}</span>@endif
    @if ($s['when'])<span class="req-source-when nums">{{ $s['when'] }}</span>@endif
</span>
