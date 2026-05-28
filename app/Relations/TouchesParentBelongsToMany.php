<?php

namespace App\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, Pivot>
 */
class TouchesParentBelongsToMany extends BelongsToMany
{
    public function attach($id, array $attributes = [], $touch = true)
    {
        parent::attach($id, $attributes, $touch);
        $this->touchParent();
    }

    public function detach($ids = null, $touch = true)
    {
        $result = parent::detach($ids, $touch);
        $this->touchParent();

        return $result;
    }

    public function sync($ids, $detaching = true)
    {
        $result = parent::sync($ids, $detaching);
        $this->touchParent();

        return $result;
    }

    public function syncWithoutDetaching($ids)
    {
        $result = parent::syncWithoutDetaching($ids);
        $this->touchParent();

        return $result;
    }

    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        $result = parent::updateExistingPivot($id, $attributes, $touch);
        $this->touchParent();

        return $result;
    }

    private function touchParent(): void
    {
        $this->parent->touch();
    }
}
