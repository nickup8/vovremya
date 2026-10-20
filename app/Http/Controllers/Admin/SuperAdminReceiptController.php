<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentReceiptStatus;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\Payment\PaymentReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Superadmin section «Чеки» — manual NPD receipt queue.
 *
 * View-only list (receipts.view); attaching/changing a link and marking a
 * refund as reviewed require receipts.update. ROOT bypass is handled by
 * the platform_permission middleware.
 */
class SuperAdminReceiptController extends Controller
{
    public function __construct(
        private PaymentReceiptService $receiptService,
    ) {}

    public function index(Request $request): Response
    {
        $tab = (string) $request->query('tab', 'awaiting');
        if (! in_array($tab, ['awaiting', 'attached', 'refund_review'], true)) {
            $tab = 'awaiting';
        }

        $query = PaymentReceipt::query()
            ->with([
                'paymentAttempt.billingCycle.workspace.owner',
                'paymentAttempt.billingCycle.tariffPlan',
                'attachedByUser',
            ])
            ->orderByDesc('created_at');

        match ($tab) {
            'attached' => $query->where('status', PaymentReceiptStatus::Attached),
            'refund_review' => $query->where('refund_review_required', true),
            default => $query->where('status', PaymentReceiptStatus::AwaitingManual)
                ->where('refund_review_required', false),
        };

        $receipts = $query->paginate(20)->withQueryString();
        $receipts->getCollection()->transform(function (PaymentReceipt $receipt) {
            $attempt = $receipt->paymentAttempt;
            $cycle = $attempt?->billingCycle;

            return array_merge($receipt->only([
                'id', 'status', 'receipt_url', 'attached_at', 'refund_review_required', 'refund_reviewed_at', 'created_at',
            ]), [
                'attached_by_user' => $receipt->attachedByUser
                    ? ['id' => $receipt->attachedByUser->id, 'name' => $receipt->attachedByUser->name]
                    : null,
                'attempt' => [
                    'id' => $attempt?->id,
                    'amount' => $attempt?->amount,
                    'currency' => $attempt?->currency,
                    'confirmed_at' => $attempt?->confirmed_at?->toIso8601String(),
                    'provider' => $attempt?->provider,
                    'payment_env' => $attempt?->payment_env?->value,
                    'origin' => $cycle?->origin?->value,
                    'period_start' => $cycle?->period_start?->toIso8601String(),
                    'period_end' => $cycle?->period_end?->toIso8601String(),
                    'tariff_name' => $cycle?->tariffPlan?->name,
                    'workspace_id' => $cycle?->workspace_id,
                    'owner_name' => $cycle?->workspace?->owner?->name,
                    'owner_email' => $cycle?->workspace?->owner?->email,
                ],
            ]);
        });

        $canUpdate = $this->canUpdate($request);

        return Inertia::render('SuperAdmin/Receipts', [
            'receipts' => $receipts,
            'tab' => $tab,
            'canUpdate' => $canUpdate,
            'filters' => $request->only(['tab']),
        ]);
    }

    public function attach(Request $request, PaymentReceipt $receipt): RedirectResponse
    {
        $validated = $request->validate([
            'receipt_url' => 'required|string|max:2048',
        ]);

        $admin = $request->user();

        if (! $admin instanceof User) {
            abort(403);
        }

        $this->receiptService->attachUrl(
            $receipt,
            $validated['receipt_url'],
            $admin,
        );

        return back()->with('success', 'Ссылка на чек сохранена и доступна покупателю в кабинете.');
    }

    public function markRefundReviewed(Request $request, PaymentReceipt $receipt): RedirectResponse
    {
        $admin = $request->user();

        if (! $admin instanceof User) {
            abort(403);
        }

        $this->receiptService->markRefundReviewed($receipt, $admin);

        return back()->with('success', 'Возврат отмечен как проверенный.');
    }

    private function canUpdate(Request $request): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        $access = $user->platformAdminAccess;

        return $access !== null
            && $access->is_active
            && $access->hasPermission(PlatformPermission::ReceiptsUpdate);
    }
}
