<?php

namespace App\Payments;

use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;

/**
 * Cobrança como o provedor informou, já traduzida para os estados do sistema.
 */
final readonly class Charge
{
    public function __construct(
        public string $id,
        public PaymentStatus $status,
        public string $providerStatus,
        public int $amountCents,
        public ?string $externalReference,
        public bool $inRiskAnalysis = false,
        public ?string $pixPayload = null,
        public ?string $pixQrBase64 = null,
        public ?string $invoiceUrl = null,
        public ?CarbonImmutable $dueAt = null,
        public ?CarbonImmutable $paidAt = null,
        public ?string $failureReason = null,
    ) {}

    public function withPix(?string $payload, ?string $qrBase64): self
    {
        return new self(...[...get_object_vars($this), 'pixPayload' => $payload, 'pixQrBase64' => $qrBase64]);
    }
}
