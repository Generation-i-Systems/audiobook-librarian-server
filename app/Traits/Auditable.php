<?php

namespace App\Traits;

use App\Models\Audit;
use App\Services\SafeLoggingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    protected static function bootAuditable()
    {
        static::created(function (Model $model) {
            static::auditActivity('created', $model);
        });

        static::updated(function (Model $model) {
            static::auditActivity('updated', $model);
        });

        static::deleted(function (Model $model) {
            static::auditActivity('deleted', $model);
        });
    }

    protected static function auditActivity(string $event, Model $model)
    {
        $userId = Auth::id();
        $auditableType = $model->getMorphClass();
        $auditableId = $model->getKey();

        $oldValues = $event === 'updated' ? $model->getOriginal() : null;
        $newValues = $event === 'updated' ? $model->getChanges() : $model->toArray();

        // Filter out timestamps and other non-relevant fields
        $filteredOldValues = static::filterAuditData($oldValues);
        $filteredNewValues = static::filterAuditData($newValues);

        // Only record if there are actual changes for 'updated' event
        if ($event === 'updated' && empty($filteredNewValues)) {
            return;
        }

        // Record to database
        Audit::create([
            'user_id' => $userId,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'event' => $event,
            'old_values' => $filteredOldValues ? json_encode($filteredOldValues) : null,
            'new_values' => $filteredNewValues ? json_encode($filteredNewValues) : null,
            'url' => request()->fullUrl(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->header('User-Agent'),
            'tags' => null, // You can add custom tags here if needed
        ]);

        // Record to redundant changelog file using safe logging to prevent infinite loops
        SafeLoggingService::safeLog(
            'info',
            "Audit Trail: User [{$userId}] {$event} {$auditableType} [{$auditableId}]",
            [
                'old_values' => $filteredOldValues,
                'new_values' => $filteredNewValues,
                'url' => request()->fullUrl(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->header('User-Agent'),
            ],
            'audit_trail'
        );
    }

    protected static function filterAuditData(?array $data): ?array
    {
        if (is_null($data)) {
            return null;
        }

        $filtered = [];
        $ignored = ['created_at', 'updated_at', 'remember_token', 'password'];

        foreach ($data as $key => $value) {
            if (!in_array($key, $ignored)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
