{{-- Группы человека одного вида (менеджеров или модераторов): в карточке — чипами, можно несколько
     (`{name}_groups[]`); в ссылке-приглашении — одна, пришедший сразу в ней (`{name}_group_id`). Групп нет — поля нет. --}}
@props(['groups', 'user' => null, 'name'])
@if ($groups->isNotEmpty())
    @if ($user)
        @php $mine = $user->userGroups->pluck('id')->all(); @endphp
        <div class="field">
            <span class="field-label">Группы</span>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($groups as $g)
                    <label class="choice"><input type="checkbox" name="{{ $name }}_groups[]" value="{{ $g->id }}" @checked(in_array($g->id, old($name.'_groups', $mine)))><span>{{ $g->name }}</span></label>
                @endforeach
            </div>
        </div>
    @else
        <x-ui.field :name="$name.'_group_id'" label="Группа" :options="$groups->pluck('name', 'id')" placeholder="Без группы"/>
    @endif
@endif
