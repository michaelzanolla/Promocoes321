<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

/**
 * "Esqueci minha senha": a resposta é sempre a mesma e sai antes de qualquer trabalho.
 * Busca da conta, geração do token e envio do e-mail acontecem depois da resposta,
 * para nem o texto nem o tempo de resposta revelarem se o e-mail tem conta.
 */
class PasswordResetLinkController extends FortifyPasswordResetLinkController
{
    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        defer(fn () => rescue(fn () => parent::store($request)));

        return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT]);
    }
}
