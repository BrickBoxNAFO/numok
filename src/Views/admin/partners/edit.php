<div class="py-6">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="lg:flex lg:items-center lg:justify-between">
            <div class="min-w-0 flex-1">
                <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:truncate sm:text-3xl sm:tracking-tight">
                    Edit Partner: <?= htmlspecialchars($partner['company_name']) ?>
                </h2>
                <p class="mt-1 text-sm text-gray-500"><?= htmlspecialchars($partner['email']) ?></p>
            </div>
            <div class="mt-5 flex lg:ml-4 lg:mt-0">
                <a href="/admin/partners" class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                    Back
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

        <!-- Suspension banner -->
        <?php if ($partner['status'] === 'suspended'): ?>
        <div class="rounded-md bg-red-50 p-4 mt-6 ring-1 ring-red-200">
            <div class="flex">
                <div class="flex-1">
                    <div class="text-sm font-semibold text-red-800">Partner is suspended</div>
                    <?php if (!empty($partner['suspended_reason'])): ?>
                    <div class="mt-1 text-sm text-red-700">Reason: <?= htmlspecialchars($partner['suspended_reason']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($partner['suspended_at'])): ?>
                    <div class="mt-1 text-xs text-red-600">Since <?= htmlspecialchars($partner['suspended_at']) ?></div>
                    <?php endif; ?>
                </div>
                <form action="/admin/partners/<?= (int)$partner['id'] ?>/reinstate" method="POST" class="ml-4">
                    <button type="submit" class="rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500"
                            onclick="return confirm('Reinstate this partner? Their tracking codes will resume attributing conversions.')">
                        Reinstate partner
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Partner Information -->
            <div class="space-y-6">
                <form action="/admin/partners/<?= $partner['id'] ?>/update" method="POST">
                    <div class="bg-white shadow sm:rounded-lg">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold leading-6 text-gray-900">Partner Information</h3>

                            <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                <div class="sm:col-span-6">
                                    <label for="company_name" class="block text-sm font-medium text-gray-700">Company / Contact Name</label>
                                    <input type="text" name="company_name" id="company_name" required
                                        value="<?= htmlspecialchars($partner['company_name']) ?>"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 px-3">
                                </div>

                                <div class="sm:col-span-6">
                                    <label for="contact_name" class="block text-sm font-medium text-gray-700">Contact Name</label>
                                    <input type="text" name="contact_name" id="contact_name"
                                        value="<?= htmlspecialchars($partner['contact_name'] ?? '') ?>"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 px-3">
                                </div>

                                <div class="sm:col-span-4">
                                    <label for="payment_email" class="block text-sm font-medium text-gray-700">Payment Email</label>
                                    <input type="email" name="payment_email" id="payment_email"
                                        value="<?= htmlspecialchars($partner['payment_email'] ?? '') ?>"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 px-3">
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="payout_currency" class="block text-sm font-medium text-gray-700">Currency</label>
                                    <select id="payout_currency" name="payout_currency"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6">
                                        <?php $cur = strtoupper($partner['payout_currency'] ?? 'USD'); ?>
                                        <option value="USD" <?= $cur === 'USD' ? 'selected' : '' ?>>USD</option>
                                        <option value="GBP" <?= $cur === 'GBP' ? 'selected' : '' ?>>GBP</option>
                                        <option value="EUR" <?= $cur === 'EUR' ? 'selected' : '' ?>>EUR</option>
                                    </select>
                                </div>

                                <div class="sm:col-span-6">
                                    <label for="stripe_connect_id" class="block text-sm font-medium text-gray-700">Stripe Connect ID</label>
                                    <input type="text" name="stripe_connect_id" id="stripe_connect_id"
                                        value="<?= htmlspecialchars($partner['stripe_connect_id'] ?? '') ?>"
                                        placeholder="acct_..."
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 px-3 font-mono">
                                    <p class="mt-1 text-xs text-gray-500">Partner's Stripe Connect account for payouts.</p>
                                </div>

                                <?php if ($partner['status'] !== 'suspended'): ?>
                                <div class="sm:col-span-6">
                                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                                    <select id="status" name="status"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6">
                                        <option value="pending" <?= $partner['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="active" <?= $partner['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">To suspend, use the kill-switch panel below.</p>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="mt-6 flex justify-end">
                                <button type="submit" class="inline-flex justify-center rounded-md bg-indigo-600 py-2 px-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                                    Save changes
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Kill-switch -->
                <?php if ($partner['status'] !== 'suspended'): ?>
                <div class="bg-white shadow sm:rounded-lg ring-1 ring-red-200">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-base font-semibold leading-6 text-red-700">Kill-switch: Suspend partner</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Immediately stops attributing new clicks and blocks future conversions
                            for this partner. Existing payable conversions remain in place; any
                            queued payout can be held or cancelled separately on the payouts page.
                        </p>
                        <form action="/admin/partners/<?= (int)$partner['id'] ?>/suspend" method="POST" class="mt-4 space-y-3">
                            <div>
                                <label for="reason" class="block text-sm font-medium text-gray-700">Suspension reason (required)</label>
                                <textarea name="reason" id="reason" rows="2" required
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-red-600 sm:text-sm sm:leading-6 px-3"
                                    placeholder="e.g. Self-referral fraud detected in logs"></textarea>
                            </div>
                            <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                                onclick="return confirm('Suspend this partner? Their tracking codes will stop attributing conversions immediately.')">
                                Suspend partner
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Programs + Risk signals -->
            <div class="space-y-6">
                <!-- Risk signals -->
                <?php if (!empty($riskSignals)): ?>
                <div class="bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-base font-semibold leading-6 text-gray-900">Risk signals (last 30 days)</h3>
                        <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
                            <div>
                                <dt class="text-xs font-medium text-gray-500">Total clicks</dt>
                                <dd class="mt-1 text-xl font-semibold text-gray-900"><?= number_format((int)$riskSignals['total_clicks']) ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500">VPN clicks</dt>
                                <dd class="mt-1 text-xl font-semibold <?= (int)$riskSignals['vpn_clicks'] > 0 ? 'text-orange-600' : 'text-gray-900' ?>">
                                    <?= number_format((int)$riskSignals['vpn_clicks']) ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500">Datacenter clicks</dt>
                                <dd class="mt-1 text-xl font-semibold <?= (int)$riskSignals['dc_clicks'] > 0 ? 'text-orange-600' : 'text-gray-900' ?>">
                                    <?= number_format((int)$riskSignals['dc_clicks']) ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500">Conversions</dt>
                                <dd class="mt-1 text-xl font-semibold text-gray-900"><?= number_format((int)$riskSignals['total_conversions']) ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500">Refunded</dt>
                                <dd class="mt-1 text-xl font-semibold <?= (int)$riskSignals['refunded_conversions'] > 0 ? 'text-red-600' : 'text-gray-900' ?>">
                                    <?= number_format((int)$riskSignals['refunded_conversions']) ?>
                                </dd>
                            </div>
                        </dl>
                        <?php if ((int)$riskSignals['vpn_clicks'] > 0 || (int)$riskSignals['dc_clicks'] > 0): ?>
                        <p class="mt-3 text-xs text-gray-500">
                            VPN / datacenter clicks are flagged for your review but never blocked automatically.
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Programs -->
                <div class="bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-base font-semibold leading-6 text-gray-900">Program Assignments</h3>

                        <?php if ($partner['status'] === 'active'): ?>
                        <form action="/admin/partners/<?= $partner['id'] ?>/assign-program" method="POST" class="mt-4">
                            <div class="flex gap-3">
                                <div class="flex-1">
                                    <select name="program_id" required
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6">
                                        <option value="">Select a program...</option>
                                        <?php foreach ($availablePrograms as $program): ?>
                                        <option value="<?= $program['id'] ?>"><?= htmlspecialchars($program['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                                    Assign Program
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <div class="mt-6">
                            <?php if (empty($programs)): ?>
                            <div class="text-center rounded-lg border-2 border-dashed border-gray-300 p-8">
                                <p class="text-sm text-gray-500">No programs assigned yet.</p>
                            </div>
                            <?php else: ?>
                            <table class="min-w-full divide-y divide-gray-300">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="py-3 pl-3 pr-2 text-left text-xs font-semibold text-gray-900">Program</th>
                                        <th class="px-2 py-3 text-left text-xs font-semibold text-gray-900">Tracking code</th>
                                        <th class="px-2 py-3 text-left text-xs font-semibold text-gray-900">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <?php foreach ($programs as $program): ?>
                                    <tr>
                                        <td class="py-3 pl-3 pr-2 text-sm text-gray-900">
                                            <div class="font-medium"><?= htmlspecialchars($program['program_name']) ?></div>
                                            <div class="text-xs text-gray-500">
                                                <?= $program['commission_type'] === 'percentage'
                                                    ? number_format($program['commission_value'], 1) . '%'
                                                    : '$' . number_format($program['commission_value'], 2) ?>
                                            </div>
                                        </td>
                                        <td class="px-2 py-3 text-sm text-gray-500 font-mono">
                                            <?= htmlspecialchars($program['tracking_code']) ?>
                                        </td>
                                        <td class="px-2 py-3 text-sm">
                                            <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium <?= $program['status'] === 'active' ? 'bg-green-50 text-green-700' : 'bg-yellow-50 text-yellow-700' ?>">
                                                <?= ucfirst(htmlspecialchars($program['status'])) ?>
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
</div>
