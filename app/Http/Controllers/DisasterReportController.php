<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDisasterReportRequest;
use App\Models\DisasterReport;
use App\Services\Disaster\DisasterDashboardService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DisasterReportController extends Controller
{
    /**
     * Show the disaster report submission form.
     */
    public function create(): Response
    {
        return Inertia::render('reports/Create', [
            'countries' => DisasterDashboardService::ASEAN_COUNTRIES,
            'disasterTypes' => DisasterReport::DISASTER_TYPES,
        ]);
    }

    /**
     * Store a newly created disaster report.
     */
    public function store(StoreDisasterReportRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $photoPaths = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $file) {
                if ($file->isValid()) {
                    $path = $file->store('reports', 'public');
                    $photoPaths[] = $path;
                }
            }
        }

        $report = DisasterReport::create([
            'user_id' => $request->user()->id,
            'disaster_type' => $validated['disaster_type'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'country' => $validated['country'],
            'location' => $validated['location'],
            'latitude' => isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            'longitude' => isset($validated['longitude']) ? (float) $validated['longitude'] : null,
            'incident_time' => Carbon::parse($validated['incident_time']),
            'photos' => $photoPaths,
            'status' => DisasterReport::STATUS_PENDING,
        ]);

        return redirect()->route('reports.my')->with('success', "Disaster report submitted successfully! Reference ID: {$report->id}. It will be reviewed by our monitoring team.");
    }

    /**
     * Display a listing of reports submitted by the authenticated user.
     */
    public function myReports(Request $request): Response
    {
        $reports = DisasterReport::where('user_id', $request->user()->id)
            ->latest('created_at')
            ->get()
            ->map(function (DisasterReport $report) {
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
                ];
            });

        return Inertia::render('reports/MyReports', [
            'reports' => $reports,
            'countries' => DisasterDashboardService::ASEAN_COUNTRIES,
        ]);
    }

    /**
     * Withdraw a pending disaster report submitted by the user.
     */
    public function withdraw(Request $request, DisasterReport $report): RedirectResponse
    {
        if ($report->user_id !== $request->user()->id) {
            abort(403, 'You are not authorized to withdraw this report.');
        }

        if ($report->status !== DisasterReport::STATUS_PENDING) {
            return back()->with('error', 'Only pending reports can be withdrawn.');
        }

        if (!empty($report->photos)) {
            foreach ($report->photos as $photo) {
                Storage::disk('public')->delete($photo);
            }
        }

        $report->delete();

        return redirect()->route('reports.my')->with('success', 'Report was withdrawn successfully.');
    }
}
