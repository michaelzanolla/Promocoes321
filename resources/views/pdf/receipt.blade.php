@php
    use App\Support\Format;
    $r = $receipt;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Comprovante {{ $r['receipt_code'] }}</title>
    {{-- dompdf: CSS simples e fonte DejaVu (acentos em português). --}}
    <style>
        @page { margin: 28px; }
        body { font-family: DejaVu Sans, sans-serif; color: #12343b; font-size: 11px; }
        .status { padding: 8px 12px; border-radius: 6px; font-weight: bold; margin-bottom: 14px; }
        .ok { background: #e6f4ec; color: #1d7a4c; }
        .no { background: #fbeceb; color: #b8322a; }
        .ticket { border: 1px solid #d3dcde; border-radius: 10px; width: 100%; border-collapse: separate; }
        .main { padding: 18px; vertical-align: top; }
        .stub { width: 170px; padding: 18px; text-align: center; border-left: 2px dashed #d3dcde; vertical-align: middle; }
        .eyebrow { font-size: 8px; letter-spacing: 3px; text-transform: uppercase; color: #4a6066; }
        h1 { font-size: 18px; margin: 8px 0 2px; }
        .muted { color: #4a6066; }
        .grid td { padding: 4px 16px 4px 0; vertical-align: top; }
        .label { color: #4a6066; font-size: 9px; }
        .value { font-weight: bold; }
        .num { font-family: DejaVu Sans Mono, monospace; }
        .chip { display: inline-block; border: 1px solid #12343b; background: #ffc83d; border-radius: 4px; padding: 2px 6px; margin: 0 3px 4px 0; }
        .chip-void { background: #fff; color: #4a6066; text-decoration: line-through; border-color: #d3dcde; }
        .footer { margin-top: 14px; color: #4a6066; font-size: 9px; }
    </style>
</head>
<body>
    <div class="status {{ $r['valid'] ? 'ok' : 'no' }}">{{ $r['status_text'] }}</div>

    <table class="ticket">
        <tr>
            <td class="main">
                <div class="eyebrow">{{ $r['platform'] }} · Comprovante</div>
                <h1>{{ $r['promotion'] }}</h1>
                @if ($r['organizer'])
                    <div class="muted">Responsável: {{ $r['organizer'] }}@if ($r['organizer_document']) · {{ $r['organizer_document'] }}@endif</div>
                @endif

                <table class="grid" style="margin-top: 12px;">
                    <tr>
                        <td><div class="label">Comprador</div><div class="value">{{ $r['buyer'] }}</div></td>
                        <td><div class="label">Pedido</div><div class="value num">{{ $r['order_code'] }}</div></td>
                    </tr>
                    <tr>
                        <td><div class="label">Quantidade</div><div class="value">{{ $r['quantity'] }}</div></td>
                        <td><div class="label">Valor por número</div><div class="value">{{ Format::brl($r['unit_price']) }}</div></td>
                    </tr>
                    <tr>
                        <td><div class="label">Total</div><div class="value">{{ Format::brl($r['total']) }}</div></td>
                        <td><div class="label">Pagamento</div><div class="value">{{ $r['method'] }}@if ($r['payment_status']) · {{ $r['payment_status'] }}@endif</div></td>
                    </tr>
                    @if ($r['confirmed_at'])
                        <tr><td colspan="2"><div class="label">Confirmado em</div><div class="value">{{ $r['confirmed_at']->format('d/m/Y \à\s H:i') }} (horário de Brasília)</div></td></tr>
                    @endif
                </table>

                <div class="label" style="margin: 12px 0 0 2px;">Números confirmados</div>
                <div style="margin: 8px 0 0 2px;">
                    @foreach ($r['numbers'] as $n)
                        <span class="chip num {{ $r['valid'] ? '' : 'chip-void' }}">{{ $n }}</span>
                    @endforeach
                </div>
            </td>
            <td class="stub">
                <img src="{{ $r['qr'] }}" width="130" height="130" alt="QR Code de verificação">
                <div class="label" style="margin-top: 6px;">Código de verificação</div>
                <div class="value num">{{ $r['receipt_code'] }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Confira a autenticidade em {{ $r['verify_url'] }}. A página de verificação mostra sempre a situação atual registrada no sistema;
        este documento sozinho não comprova o pagamento.
    </div>
</body>
</html>
