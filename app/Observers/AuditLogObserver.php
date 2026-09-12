<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Architecture §3.10: "built as a Laravel model observer attached to every
 * financial entity from the start." One reusable observer, attached per
 * model via #[ObservedBy(AuditLogObserver::class)] - see DummyRecord for
 * the pattern every real financial entity (PO, Invoice, Payment,
 * JournalEntry, VariationOrder, RetentionRelease, ...) reuses.
 */
class AuditLogObserver
{
    public function created(Model $model): void
    {
        $this->record($model, 'created', null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $this->record($model, 'updated', $model->getOriginal(), $model->getChanges());
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', $model->getAttributes(), null);
    }

    public function restored(Model $model): void
    {
        $this->record($model, 'restored', null, $model->getAttributes());
    }

    private function record(Model $model, string $action, ?array $oldValues, ?array $newValues): void
    {
        $user = Auth::guard('sanctum')->user();

        AuditLog::create([
            'tenant_id' => $model->getAttribute('tenant_id'),
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $model::class,
            'entity_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
        ]);
    }
}
