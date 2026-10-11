<?php

namespace App\Http\Controllers;

use App\Mail\OrderMail;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Consultar pedido" para quem comprou sem conta: o link chega por e-mail.
 * A resposta é sempre a mesma, exista ou não o pedido, para ninguém descobrir
 * códigos ou e-mails por tentativa e erro.
 */
class OrderLookupController extends Controller
{
    public function create(): View
    {
        return view('site.order-lookup');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $order = Order::where('public_code', mb_strtoupper(trim($data['code'])))
            ->where('buyer_email', mb_strtolower(trim($data['email'])))
            ->first();

        $order?->sendMailLater(OrderMail::LINK);

        return back()->with('status', 'Se o código e o e-mail estiverem corretos, você vai receber em instantes um e-mail com o link do pedido. Confira também a caixa de spam.');
    }
}
