<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SafetyGuideRequest;
use App\Models\SafetyGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class SafetyGuideAdminController extends Controller
{
    public function store(SafetyGuideRequest $request): RedirectResponse
    {
        $data = $this->payload($request);

        SafetyGuide::create($data + [
            'slug' => $this->uniqueSlug($data['title']),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Safety guide created.');
    }

    public function update(SafetyGuideRequest $request, SafetyGuide $guide): RedirectResponse
    {
        $guide->update($this->payload($request) + ['updated_by' => $request->user()->id]);

        return back()->with('success', 'Safety guide updated.');
    }

    public function destroy(SafetyGuide $guide): RedirectResponse
    {
        $guide->delete();

        return back()->with('success', 'Safety guide deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SafetyGuideRequest $request): array
    {
        $data = $request->validated();
        $data['is_published'] = (bool) ($data['is_published'] ?? false);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'guide';
        $slug = $base;
        $n = 2;

        while (SafetyGuide::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
