<span id="signed-mark" @class(['task-step-mark', 'is-done' => $done])>@if ($done)<x-ui.icon name="check" class="size-3.5"/>@else{{ $n ?? 5 }}@endif</span>
