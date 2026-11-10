<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * After login: the page the person was trying to reach, otherwise the Welcome page (administrators: `/admin`).
 * A non-administrator is never sent back to an `/admin` page (see ChoosesAfterAuthPage).
 */
class LoginResponse implements LoginResponseContract
{
    use ChoosesAfterAuthPage;

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response|JsonResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        return $this->redirectAfterAuth($request->user(), $request->user()?->isAdmin() ? '/admin' : '/');
    }
}
