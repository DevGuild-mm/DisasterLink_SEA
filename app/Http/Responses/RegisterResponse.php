<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * After registration: the page the person was trying to reach, otherwise the Welcome page. A new account is never
 * an administrator, so an intended `/admin` page is ignored (see ChoosesAfterAuthPage).
 */
class RegisterResponse implements RegisterResponseContract
{
    use ChoosesAfterAuthPage;

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response|JsonResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        return $this->redirectAfterAuth($request->user(), '/');
    }
}
