<?php

namespace App\Services\Security;

use App\Models\AdminLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes the append-only audit trail. Call this from every sensitive
 * admin action and from system transitions (withdrawals, settings changes).
 */
final class AuditLogger
{
    public function log(
        ?User $admin,
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?string $description = null,
    ): AdminLog {
        $request = app()->runningInConsole() ? null : request();

        return AdminLog::create([
            'admin_id' => $admin?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);
    }

    /** System-triggered entry (no admin session, e.g. queue workers). */
    public function system(string $action, ?Model $subject = null, ?array $payload = null, ?string $description = null): AdminLog
    {
        return $this->log(null, $action, $subject, null, $payload, $description);
    }
}
