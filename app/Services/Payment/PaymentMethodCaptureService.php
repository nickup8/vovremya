<?php

namespace App\Services\Payment;

use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Services\Payment\DTOs\ProviderStatusUpdate;

class PaymentMethodCaptureService
{
    /**
     * Persist the T-Bank recurring card (RebillId) and link it to the attempt.
     *
     * Must be called only after a successful transition.
     */
    public function capture(ProviderStatusUpdate $update): ?PaymentMethod
    {
        if ($update->provider !== 'tbank') {
            return null;
        }

        $raw = $update->raw;

        $status = $raw['Status'] ?? null;
        if (! in_array($status, ['AUTHORIZED', 'CONFIRMED'], true)) {
            return null;
        }

        $rebillId = $this->nonEmptyString($raw['RebillId'] ?? null);
        if ($rebillId === null) {
            return null;
        }

        $providerPaymentId = $update->providerPaymentId;
        if ($providerPaymentId === null || $providerPaymentId === '') {
            return null;
        }

        $attempt = PaymentAttempt::where('provider_payment_id', $providerPaymentId)->first();
        if ($attempt === null) {
            return null;
        }

        $workspaceId = $attempt->billingCycle?->workspace_id;
        if ($workspaceId === null) {
            return null;
        }

        $method = PaymentMethod::where('workspace_id', $workspaceId)
            ->where('provider', 'tbank')
            ->where('type', 'card')
            ->where('provider_reference', $rebillId)
            ->first();

        $metadata = $method?->metadata ?? [];
        $customerKey = $this->nonEmptyString($raw['CustomerKey'] ?? null);
        if ($customerKey !== null) {
            $metadata['customer_key'] = $customerKey;
        }
        $cardId = $this->nonEmptyString($raw['CardId'] ?? null);
        if ($cardId !== null) {
            $metadata['card_id'] = $cardId;
        }

        if ($method === null) {
            $method = new PaymentMethod([
                'workspace_id' => $workspaceId,
                'provider' => 'tbank',
                'type' => 'card',
                'provider_reference' => $rebillId,
            ]);
        }

        $method->status = 'active';
        $method->is_default = true;
        $method->metadata = $metadata;

        // Demote other active tbank card methods before making this one default
        PaymentMethod::where('workspace_id', $workspaceId)
            ->where('provider', 'tbank')
            ->where('type', 'card')
            ->where('status', 'active')
            ->when($method->exists, fn ($query) => $query->where('id', '!=', $method->id))
            ->update(['is_default' => false]);

        $method->save();

        if ($attempt->payment_method_id !== $method->id) {
            $attempt->update(['payment_method_id' => $method->id]);
        }

        return $method;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }
}
