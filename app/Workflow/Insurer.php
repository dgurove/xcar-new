<?php

namespace App\Workflow;

use App\Offers\Offer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_active', 'contact_name', 'phone', 'email', 'notes'])]
class Insurer extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'bool'];
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function workflow(Track $track): ?Workflow
    {
        return $this->workflows->firstWhere('track', $track);
    }

    /** Маршрут ветки, заведённый при первом обращении. */
    public function workflowOrNew(Track $track): Workflow
    {
        $workflow = $this->workflows()->firstOrCreate(['track' => $track]);
        $this->unsetRelation('workflows');

        return $workflow;
    }
}
