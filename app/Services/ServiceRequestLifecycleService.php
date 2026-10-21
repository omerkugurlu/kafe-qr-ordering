<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ServiceRequest;
use App\Models\TableSession;
use App\Models\User;

class ServiceRequestLifecycleService
{
    public function resolveForClosedSession(TableSession $session, User $actor, ?string $ipAddress): int
    {
        $requests = $session->serviceRequests()
            ->where('status', ServiceRequest::STATUS_OPEN)
            ->lockForUpdate()
            ->get();

        foreach ($requests as $serviceRequest) {
            $claimedBy = $serviceRequest->claimed_by;
            $serviceRequest->forceFill([
                'status' => ServiceRequest::STATUS_RESOLVED,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'claimed_by' => null,
                'claimed_at' => null,
            ])->save();

            ActivityLog::query()->create([
                'cafe_id' => $session->branch->cafe_id,
                'branch_id' => $session->branch_id,
                'user_id' => $actor->id,
                'action' => 'service_request.closed_with_session',
                'subject_type' => ServiceRequest::class,
                'subject_id' => $serviceRequest->id,
                'metadata' => [
                    'type' => $serviceRequest->type,
                    'claimed_by' => $claimedBy,
                    'reason' => 'table_session_closed',
                ],
                'ip_address' => $ipAddress,
            ]);
        }

        return $requests->count();
    }
}
