<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmergencyResourceRequest;
use App\Http\Requests\StoreResourceDistributionRequest;
use App\Http\Requests\StoreShelterRequest;
use App\Http\Requests\UpdateShelterRequest;
use App\Models\EmergencyResource;
use App\Models\RescueRequest;
use App\Models\ResourceDistribution;
use App\Models\Shelter;
use App\Models\Volunteer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmergencyCoordinationAdminController extends Controller
{
    /**
     * Register a new shelter or relief facility.
     */
    public function storeShelter(StoreShelterRequest $request): RedirectResponse|JsonResponse
    {
        $shelter = Shelter::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Emergency shelter registered successfully.',
                'shelter' => $shelter,
            ], 201);
        }

        return redirect()->back()->with('success', 'Emergency shelter registered successfully.');
    }

    /**
     * Update shelter capacity, occupancy, and operational status.
     */
    public function updateShelter(UpdateShelterRequest $request, Shelter $shelter): RedirectResponse|JsonResponse
    {
        $shelter->update($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Shelter information updated successfully.',
                'shelter' => $shelter->fresh(),
            ]);
        }

        return redirect()->back()->with('success', 'Shelter information updated successfully.');
    }

    /**
     * Add emergency resource inventory item.
     */
    public function storeResource(StoreEmergencyResourceRequest $request): RedirectResponse|JsonResponse
    {
        $resource = EmergencyResource::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Emergency resource stock record created.',
                'resource' => $resource,
            ], 201);
        }

        return redirect()->back()->with('success', 'Emergency resource stock record created.');
    }

    /**
     * Record resource distribution and decrement active stock.
     */
    public function distributeResource(StoreResourceDistributionRequest $request, EmergencyResource $resource): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();
        $distQty = (int) $validated['quantity'];

        if ($distQty > $resource->quantity) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => "Insufficient stock: cannot distribute {$distQty} {$resource->unit} (available: {$resource->quantity}).",
                ], 422);
            }

            return redirect()->back()->withErrors([
                'quantity' => "Insufficient stock: only {$resource->quantity} {$resource->unit} remaining.",
            ]);
        }

        // Deduct from inventory
        $resource->quantity -= $distQty;
        $resource->status = EmergencyResource::computeStatus($resource->quantity, $resource->min_threshold);
        $resource->save();

        // Log distribution
        $distribution = ResourceDistribution::create([
            'resource_id' => $resource->id,
            'shelter_id' => $resource->shelter_id,
            'recipient' => $validated['recipient'],
            'quantity' => $distQty,
            'distributed_by' => $validated['distributed_by'],
            'distributed_at' => $validated['distributed_at'] ?? now(),
            'notes' => $validated['notes'] ?? null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Resource distribution recorded and inventory stock updated.',
                'distribution' => $distribution,
                'remaining_stock' => $resource->quantity,
            ], 201);
        }

        return redirect()->back()->with('success', 'Resource distribution logged successfully.');
    }

    /**
     * Dispatch or update rescue request status and volunteer assignment.
     */
    public function updateRescueStatus(Request $request, RescueRequest $rescueRequest): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                RescueRequest::STATUS_PENDING,
                RescueRequest::STATUS_DISPATCHED,
                RescueRequest::STATUS_IN_PROGRESS,
                RescueRequest::STATUS_COMPLETED,
                RescueRequest::STATUS_CANCELLED,
            ])],
            'assigned_volunteer_id' => ['nullable', 'string', 'exists:volunteers,id'],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $rescueRequest->update($validated);

        // If volunteer assigned, update their status to deployed if dispatched or in progress
        if (!empty($validated['assigned_volunteer_id'])) {
            $volunteer = Volunteer::find($validated['assigned_volunteer_id']);
            if ($volunteer && in_array($validated['status'], [RescueRequest::STATUS_DISPATCHED, RescueRequest::STATUS_IN_PROGRESS], true)) {
                $volunteer->update(['availability_status' => Volunteer::STATUS_DEPLOYED]);
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Rescue assistance request status updated.',
                'rescue_request' => $rescueRequest->fresh(['assignedVolunteer']),
            ]);
        }

        return redirect()->back()->with('success', 'Rescue request status updated.');
    }

    /**
     * Update volunteer deployment status.
     */
    public function updateVolunteerStatus(Request $request, Volunteer $volunteer): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'availability_status' => ['required', 'string', Rule::in([
                Volunteer::STATUS_AVAILABLE,
                Volunteer::STATUS_DEPLOYED,
                Volunteer::STATUS_STANDBY,
            ])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $volunteer->update($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Volunteer availability status updated.',
                'volunteer' => $volunteer->fresh(),
            ]);
        }

        return redirect()->back()->with('success', 'Volunteer availability status updated.');
    }
}
