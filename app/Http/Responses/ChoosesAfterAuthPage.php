<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Where to send a person after login or registration.
 *
 * The page they were trying to reach (the "intended" URL) wins, query string included. The role only picks the
 * fallback. One rule protects non-administrators: an intended URL that points at `/admin` or a path under it is
 * ignored for them, so they land on the fallback instead of a 403 page. Administrators may return to any page.
 */
trait ChoosesAfterAuthPage
{
    protected function redirectAfterAuth(?User $user, string $fallback): RedirectResponse
    {
        $intended = session()->pull('url.intended');

        if (! is_string($intended) || $intended === '' || (! $user?->isAdmin() && $this->pointsAtAdminArea($intended))) {
            return redirect()->to($fallback);
        }

        return redirect()->to($intended);
    }

    /**
     * True for `/admin` and anything under `/admin/`. The path is decoded and normalised first, so `/%61dmin`,
     * `/ADMIN`, `//admin` and `/admin//x` count too. `/administrator` and `/admin-tools` do not.
     */
    private function pointsAtAdminArea(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        $path = strtolower(trim((string) preg_replace('#/+#', '/', rawurldecode($path)), '/'));

        return $path === 'admin' || str_starts_with($path, 'admin/');
    }
}
