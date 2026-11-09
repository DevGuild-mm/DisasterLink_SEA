<?php

namespace App\Policies;

use App\Models\DisasterReport;
use App\Models\User;

class DisasterReportPolicy
{
    /**
     * Determine whether the user can view any reports.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the report.
     */
    public function view(User $user, DisasterReport $report): bool
    {
        return $user->isAdmin() || $user->id === $report->user_id;
    }

    /**
     * Determine whether the user can create reports.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the report.
     * Only pending reports can be updated by the author.
     */
    public function update(User $user, DisasterReport $report): bool
    {
        return $user->id === $report->user_id && $report->status === DisasterReport::STATUS_PENDING;
    }

    /**
     * Determine whether the user can delete/withdraw the report.
     * Only pending reports can be withdrawn by the author.
     */
    public function delete(User $user, DisasterReport $report): bool
    {
        return $user->id === $report->user_id && $report->status === DisasterReport::STATUS_PENDING;
    }

    /**
     * Determine whether the user can review/moderate reports.
     */
    public function review(User $user): bool
    {
        return $user->isAdmin();
    }
}
