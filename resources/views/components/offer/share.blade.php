@props(['offer', 'variant' => 'secondary', 'size' => null, 'icon' => false])
<x-ui.share :subject="\App\Offers\Share\Subject::offer($offer)" :variant="$variant" :size="$size" :icon="$icon" {{ $attributes }}/>
