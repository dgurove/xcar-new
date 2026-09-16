<x-ui.shell title="Кому что не показывать" narrow>
    <div class="flex flex-col gap-2">
        @foreach ($users as $user)
            @php $hidden = $restrictions[$user->id]->hidden_kinds ?? []; @endphp
            <form method="post" action="/purchases/limits/{{ $user->id }}" class="row flex-wrap items-center" data-controller="autosubmit">
                @csrf
                <span class="min-w-40 flex-1">{{ $user->name }} <span class="text-sm text-ink-muted">{{ $user->phoneFormatted() }}</span></span>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (\App\Purchases\Kind::cases() as $kind)
                        <label class="choice choice-danger"><input type="checkbox" name="hidden[]" value="{{ $kind->value }}" @checked(in_array($kind->value, $hidden, true)) data-action="change->autosubmit#submit"><span>{{ $kind->label() }}</span></label>
                    @endforeach
                </div>
            </form>
        @endforeach
    </div>
</x-ui.shell>
