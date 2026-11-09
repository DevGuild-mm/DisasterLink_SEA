<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DisasterReport;
use App\Services\Disaster\DisasterDashboardService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DisasterReportAdminController extends Controller
{
    /**
     * Display a paginated/filtered listing of all user-submitted disaster reports for administrators.
     */
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'all');
        $country = $request->query('country', 'all');
        $disasterType = $request->query('disaster_type', 'all');
        $search = $request->query('search');

        $query = DisasterReport::with(['user:id,name,email', 'reviewer:id,name,email'])
            ->latest('created_at');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($country && $country !== 'all') {
            $query->where('country', $country);
        }

        if ($disasterType && $disasterType !== 'all') {
            $query->where('disaster_type', $disasterType);
        }

        if ($search && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($q) use ($term) {
                $q->where('id', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%")
                    ->orWhere('location', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhereHas('user', function ($uq) use ($term) {
                        $uq->where('name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
            });
        }

        $reports = $query->paginate(20)->withQueryString()->through(function (DisasterReport $report) {
            return [
                'id' => $report->id,
                'disaster_type' => $report->disaster_type,
                'title' => $report->title,
                'description' => $report->description,
                'country' => $report->country,
                'location' => $report->location,
                'latitude' => $report->latitude,
                'longitude' => $report->longitude,
                'incident_time' => $report->incident_time->toIso8601String(),
                'created_at' => $report->created_at?->toIso8601String(),
                'status' => $report->status,
                'photos' => $report->photo_urls,
                'review_notes' => $report->review_notes,
                'rejection_reason' => $report->rejection_reason,
                'verified_at' => $report->verified_at?->toIso8601String(),
                'user' => $report->user ? [
                    'id' => $report->user->id,
                    'name' => $report->user->name,
                    'email' => $report->user->email,
                ] : null,
                'reviewer' => $report->reviewer ? [
                    'id' => $report->reviewer->id,
                    'name' => $report->reviewer->name,
                ] : null,
            ];
        });

        // Compute summary counts across all reports
        $counts = [
            'total' => DisasterReport::count(),
            'pending' => DisasterReport::where('status', DisasterReport::STATUS_PENDING)->count(),
            'under_review' => DisasterReport::where('status', DisasterReport::STATUS_UNDER_REVIEW)->count(),
            'verified' => DisasterReport::where('status', DisasterReport::STATUS_VERIFIED)->count(),
            'rejected' => DisasterReport::where('status', DisasterReport::STATUS_REJECTED)->count(),
        ];

        return Inertia::render('admin/reports/Index', [
            'reports' => $reports,
            'counts' => $counts,
            'filters' => [
                'status' => $status,
                'country' => $country,
                'disaster_type' => $disasterType,
                'search' => $search,
            ],
            'countries' => DisasterDashboardService::ASEAN_COUNTRIES,
            'disasterTypes' => DisasterReport::DISASTER_TYPES,
        ]);
    }

    /**
     * Update the verification status of a disaster report.
     */
    public function updateStatus(Request $request, DisasterReport $report): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                DisasterReport::STATUS_UNDER_REVIEW,
                DisasterReport::STATUS_VERIFIED,
                DisasterReport::STATUS_REJECTED,
            ])],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'rejection_reason' => [
                Rule::requiredIf($request->input('status') === DisasterReport::STATUS_REJECTED),
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $newStatus = $validated['status'];
        if (isset($validated['review_notes'])) {
            $notes = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', (string) $validated['review_notes']);
            $validated['review_notes'] = trim(strip_tags((string) $notes));
        }
        if (isset($validated['rejection_reason'])) {
            $reason = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', (string) $validated['rejection_reason']);
            $validated['rejection_reason'] = trim(strip_tags((string) $reason));
        }

        // Enforce valid state transitions
        if ($report->status === DisasterReport::STATUS_REJECTED) {
            return back()->with('error', 'Cannot update a report that has already been rejected.');
        }

        if ($newStatus === DisasterReport::STATUS_VERIFIED) {
            $report->status = DisasterReport::STATUS_VERIFIED;
            $report->reviewer_id = $request->user()->id;
            $report->review_notes = $validated['review_notes'] ?? null;
            $report->verified_at = Carbon::now();
            $report->save();

            return back()->with('success', "Report {$report->id} has been verified and published to live feeds.");
        }

        if ($newStatus === DisasterReport::STATUS_REJECTED) {
            $report->status = DisasterReport::STATUS_REJECTED;
            $report->reviewer_id = $request->user()->id;
            $report->rejection_reason = $validated['rejection_reason'];
            $report->review_notes = $validated['review_notes'] ?? null;
            $report->verified_at = null;
            $report->save();

            return back()->with('success', "Report {$report->id} has been marked as rejected.");
        }

        if ($newStatus === DisasterReport::STATUS_UNDER_REVIEW) {
            $report->status = DisasterReport::STATUS_UNDER_REVIEW;
            $report->reviewer_id = $request->user()->id;
            if (!empty($validated['review_notes'])) {
                $report->review_notes = $validated['review_notes'];
            }
            $report->save();

            return back()->with('success', "Report {$report->id} is now under review.");
        }

        return back();
    }
}
