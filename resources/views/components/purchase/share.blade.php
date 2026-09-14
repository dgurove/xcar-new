@props(['car', 'variant' => 'secondary', 'size' => null, 'icon' => false])
<x-ui.share :subject="\App\Offers\Share\Subject::car($car)" :variant="$variant" :size="$size" :icon="$icon" {{ $attributes }}/>
