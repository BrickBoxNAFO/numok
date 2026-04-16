<div class="py-6">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="lg:flex lg:items-center lg:justify-between">
            <div class="min-w-0 flex-1">
                <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:truncate sm:text-3xl">
                    Payout Batch #<?= (int)$batch['id'] ?>
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    <?= htmlspecialchars($batch['company_name']) ?>
                    &middot; Scheduled <?= htmlspecialchars($batch['scheduled_for']) ?>
                </p>
            </div>
            <div class="mt-5 flex lg:ml-4 lg:mt-0">
                <a href="/admin/payouts" class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                    Back to payouts
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
        <div class="rounded-md bg-green-50 p-4 mt-6">
            <p class="text-sm font-medium text-green-800"><?= htmlspecialchars($_SESSION['success']) ?></p>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="rounded-md bg-red-50 p-4 mt-6">
            <p class="text-sm font-medium text-red-800"><?= htmlspecialchars($_SESSION['error']) ?></p>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- Partner warning banner when suspended -->
        <?php if (($batch['partner_status'] ?? 'active') !== 'active'): ?>
        <div class="rounded-md bg-red-50 p-4 mt-6 ring-1 ring-red-200">
            <div class="text-sm font-semibold text-red-800">
                WARNING: Partner is <?= htmlspecialchars($batch['partner_status']) ?>
            </div>
            <?php if (!empty($batch['suspended_reason'])): ?>
            <div class="mt-1 text-sm text-red-700">
                Reason: <?= htmlspecialchars($batch['suspended_reason']) ?>
            </div>
            <?php endif; ?>
            <div class="mt-2 text-sm text-red-700">
                Do not approve this batch unless you intend to pay a suspended partner.
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary -->
        <div class="mt-6 bg-white shadow sm:rounded-lg">
            <div class="px-4 py-5 sm:p-6">
                <dl class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Total amount</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900">
                            <?= htmlspecialchars($batch['payout_currency'] ?: 'USD') ?>
                            <?= number_format((float)$batch['total_amount'], 2) ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Conversions</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900"><?= (int)$batch['conversion_count'] ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Status</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900"><?= ucfirst(htmlspecialchars($batch['status'])) ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Payment email</dt>
                        <dd class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($batch['payment_email'] ?? '') ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Stripe Connect</dt>
                        <dd class="mt-1 text-sm text-gray-900 font-mono">
                            <?= htmlspecialchars($batch['stripe_connect_id'] ?? 'not connected') ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Stripe transfer ID</dt>
                        <dd class="mt-1 text-sm text-gray-900 font-mono">
                            <?= htmlspecialchars($batch['stripe_transfer_id'] ?? '-') ?>
                        </dd>
                    </div>
                </dl>

                <?php if (!empty($batch['note'])): ?>
                <div class="mt-4 rounded-md bg-gray-50 p-3 text-sm text-gray-700">
                    Note: <?= nl2br(htmlspecialchars($batch['note'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions -->
        <?php if ($batch['status'] === 'queued'): ?>
        <div class="mt-6 bg-white shadow sm:rounded-lg">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-base font-semibold text-gray-900">Approve this batch</h3>
                <p class="mt-1 text-sm text-gray-500">
                    Approving confirms the batch is ready for payout. After approving, send the Stripe
                    transfer and return here to record it.
                </p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <form action="/admin/payouts/<?= (int)$batch['id'] ?>/approve" method="POST">
                        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                onclick="return confirm('Approve payout of <?= htmlspecialchars($batch['payout_currency'] ?: 'USD') ?> <?= number_format((float)$batch['total_amount'], 2) ?> to <?= htmlspecialchars(addslashes($batch['company_name'])) ?>?')">
                            Approve batch
                        </button>
                    </form>
                    <form action="/admin/payouts/<?= (int)$batch['id'] ?>/hold" method="POST" class="flex gap-2">
                        <input type="text" name="reason" placeholder="Reason (optional)"
                               class="rounded-md border-0 py-1.5 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 px-3">
                        <button type="submit" class="rounded-md bg-orange-500 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-400">
                            Hold for review
                        </button>
                    </form>
                    <form action="/admin/payouts/<?= (int)$batch['id'] ?>/cancel" method="POST" class="flex gap-2">
                        <input type="text" name="reason" placeholder="Reason (optional)"
                               class="rounded-md border-0 py-1.5 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 px-3">
                        <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                                onclick="return confirm('Cancel this batch? Conversions will be returned to the payable queue for next month.')">
                            Cancel batch
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($batch['status'] === 'approved'): ?>
        <div class="mt-6 bg-white shadow sm:rounded-lg">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-base font-semibold text-gray-900">Mark as paid</h3>
                <p class="mt-1 text-sm text-gray-500">
                    Once the Stripe transfer is sent, record it here. Conversions will
                    automatically be flipped to paid.
                </p>
                <form action="/admin/payouts/<?= (int)$batch['id'] ?>/mark-paid" method="POST" class="mt-4 space-y-3">
                    <div>
                        <label for="stripe_transfer_id" class="block text-sm font-medium text-gray-700">Stripe transfer ID</label>
                        <input type="text" name="stripe_transfer_id" id="stripe_transfer_id" placeholder="tr_..."
                               class="mt-1 block w-full rounded-md border-0 py-1.5 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 px-3">
                    </div>
                    <div>
                        <label for="note" class="block text-sm font-medium text-gray-700">Note (optional)</label>
                        <input type="text" name="note" id="note"
                               class="mt-1 block w-full rounded-md border-0 py-1.5 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 px-3">
                    </div>
                    <button type="submit" class="rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500"
                            onclick="return confirm('Mark this batch as paid? This will flip all attached conversions to paid status.')">
                        Mark as paid
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Conversions in this batch -->
        <div class="mt-8">
            <h3 class="text-base font-semibold text-gray-900">Conversions in this batch</h3>
            <div class="mt-4 flow-root">
                <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                    <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                        <?php if (empty($conversions)): ?>
                        <p class="text-sm text-gray-500">No conversions attached to this batch.</p>
                        <?php else: ?>
                        <table class="min-w-full divide-y divide-gray-300">
                            <thead>
                                <tr>
                                    <th class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Date</th>
                                    <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Program</th>
                                    <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Customer</th>
                                    <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900">Sale</th>
                                    <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900">Commission</th>
                                    <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <?php foreach ($conversions as $c): ?>
                                <tr>
                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm text-gray-500">
                                        <?= htmlspecialchars(date('Y-m-d', strtotime($c['created_at']))) ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm text-gray-900">
                                        <?= htmlspecialchars($c['program_name']) ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm text-gray-500">
                                        <?= htmlspecialchars($c['customer_email'] ?? '-') ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm text-right text-gray-900">
                                        $<?= number_format((float)$c['amount'], 2) ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm text-right text-gray-900 font-medium">
                                        $<?= number_format((float)$c['commission_amount'], 2) ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm">
                                        <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium <?= $c['status'] === 'paid' ? 'bg-green-50 text-green-700' : ($c['status'] === 'refunded' ? 'bg-red-50 text-red-700' : 'bg-yellow-50 text-yellow-700') ?>">
                                            <?= ucfirst(htmlspecialchars($c['status'])) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
