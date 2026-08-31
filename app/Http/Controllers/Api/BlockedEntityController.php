<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * A flat per-user list of blocked books/authors/series/tags, used by the client to hide
 * matching books from browse/search/discovery/library listings. `{block}` route params bind
 * on `BlockedEntity.string_id` (the client-generated uuid) - see
 * BlockedEntity::getRouteKeyName().
 */
class BlockedEntityController extends Controller
{
    /** GET /blocks — all blocked entities for the user */
    public function index(): JsonResponse
    {
        $blocks = BlockedEntity::where('user_id', Auth::id())
            ->orderBy('entity_type')
            ->orderBy('entity_label')
            ->get()
            ->map(fn ($block) => $this->formatBlock($block));

        return response()->json(['blocks' => $blocks]);
    }

    /** POST /blocks — block an entity, or refresh it if string_id already exists (idempotent retry) */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'string_id'     => 'required|uuid',
            'entity_type'   => ['required', 'string', Rule::in(BlockedEntity::TYPES)],
            'entity_ref_id' => 'nullable|integer',
            'entity_value'  => 'required|string|max:255',
            'entity_label'  => 'required|string|max:255',
            'created_at'    => 'required|integer',
        ]);

        $block = BlockedEntity::where('user_id', Auth::id())->where('string_id', $validated['string_id'])->first();

        if (!$block) {
            // A block on the same (entity_type, entity_value) already exists under a
            // different string_id (e.g. blocked, unblocked, re-blocked offline before ever
            // syncing) - treat the new request as a rename of that existing row rather than
            // failing the unique(user_id, entity_type, entity_value) constraint.
            $block = BlockedEntity::where('user_id', Auth::id())
                ->where('entity_type', $validated['entity_type'])
                ->where('entity_value', $validated['entity_value'])
                ->first();
        }

        if ($block) {
            $block->update([
                'string_id'     => $validated['string_id'],
                'entity_ref_id' => $validated['entity_ref_id'] ?? null,
                'entity_label'  => $validated['entity_label'],
            ]);
        } else {
            $block = BlockedEntity::create([
                'user_id'       => Auth::id(),
                'string_id'     => $validated['string_id'],
                'entity_type'   => $validated['entity_type'],
                'entity_ref_id' => $validated['entity_ref_id'] ?? null,
                'entity_value'  => $validated['entity_value'],
                'entity_label'  => $validated['entity_label'],
                'created_at'    => $validated['created_at'],
            ]);
        }

        return response()->json(['block' => $this->formatBlock($block)], 201);
    }

    /** DELETE /blocks/{block} */
    public function destroy(BlockedEntity $block): JsonResponse
    {
        abort_if($block->user_id !== Auth::id(), 403, 'Forbidden');
        $block->delete();

        return response()->json(['message' => 'Block removed']);
    }

    private function formatBlock(BlockedEntity $block): array
    {
        return [
            'id'            => $block->string_id,
            'entity_type'   => $block->entity_type,
            'entity_ref_id' => $block->entity_ref_id,
            'entity_value'  => $block->entity_value,
            'entity_label'  => $block->entity_label,
            'created_at'    => $block->created_at,
        ];
    }
}
