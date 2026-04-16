<div class="py-6">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Payouts</h1>
                <p class="mt-2 text-sm text-gray-700">
                    Monthly payout batches built automatically on the 1st of each month.
                    Review queued batches, approve them, then mark paid once the Stripe
                    transfer completes.
                </p>
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

        <!-- Summary cards -->
        <dl class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-3">
            <div class="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                <dt class="truncate text-sm font-medium text-gray-500">Queued (awaiting approval)</dt>
                <dd class="mt-1 text-3xl font-semibold tracking-tight text-gray-900">
                    $<?= number_format((float)($summary['queued_amount'] ?? 0), 2) ?>
                </dd>
                <p class="mt-1 text-sm text-gray-500"><?= (int)($summary['queued_count'] ?? 0) ?> batches</p>
            </div>
            <div class="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                <dt class="truncate text-sm font-medium text-gray-500">Approved (pending transfer)</dt>
                <dd class="mt-1 text-3xl font-semibold tracking-tight text-gray-900">
                    $<?= number_format((float)($summary['approved_amount'] ?? 0), 2) ?>
                </dd>
                <p class="mt-1 text-sm text-gray-500"><?= (int)($summary['approved_count'] ?? 0) ?> batches</p>
            </div>
            <div class="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                <dt class="truncate text-sm font-medium text-gray-500">Paid (last 30 days)</dt>
                <dd class="mt-1 text-3xl font-semibold tracking-tight text-gray-900">
                    $<?= number_format((float)($summary['paid_30d_amount'] ?? 0), 2) ?>
                </dd>
                <p class="mt-1 text-sm text-gray-500"><?= (int)($summary['paid_30d_count'] ?? 0) ?> batches</p>
            </div>
        </dl>

        <!-- Status filter -->
        <form method="GET" action="/admin/payouts" class="mt-8 flex items-center gap-4">
            <div class="flex-1 max-w-xs">
                <select name="status" class="block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6">
                    <?php
                        $current = $filters['status'] ?? 'all';
                        $opts = ['all' => 'All statuses', 'queued' => 'Queued', 'approved' => 'Approved', 'paid' => 'Paid', 'held' => 'Held', 'cancelled' => 'Cancelled'];
                        foreach ($opts as $val => $label):
                    ?>
                        <option value="<?= $val ?>" <?= $current === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">
                Filter
            </button>
        </form>

        <!-- Batches table -->
        <div class="mt-8 flow-root">
            <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                    <?php if (empty($batches)): ?>
                    <div class="text-center rounded-lg border-2 border-dashed border-gray-300 p-12">
                        <h3 class="text-sm font-medium text-gray-900">No payout batches yet</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            Batches appear on the 1st of each month once the build-payout-batches cron runs.
                        </p>
                    </div>
                    <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-300">
                        <thead>
                            <tr>
                                <th class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-0">Partner</th>
                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Period</th>
                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Scheduled</th>
                                <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900">Amount</th>
                                <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900">Conversions</th>
                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Status</th>
                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Partner Status</th>
                                <th class="relative py-3.5 pl-3 pr-4 sm:pr-0"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <?php foreach ($batches as $b): ?>
                            <?php
                                $statusColors = [
                                    'queued' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
                                    'approved' => 'bg-blue-50 text-blue-800 ring-blue-600/20',
                                    'paid' => 'bg-green-50 text-green-800 ring-green-600/20',
                                    'held' => 'bg-orange-50 text-orange-800 ring-orange-600/20',
                                    'cancelled' => 'bg-gray-100 text-gray-800 ring-gray-500/20',
                                ];
                                $cls = $statusColors[$b['status']] ?? 'bg-gray-100 text-gray-800 ring-gray-500/20';
                                $partnerCls = $b['partner_status'] === 'active'
                                    ? 'bg-green-50 text-green-700 ring-green-600/20'
                                    : 'bg-red-50 text-red-700 ring-red-600/20';
                            ?>
                            <tr>
                                <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-0">
                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($b['company_name']) ?></div>
                                    <div class="text-gray-500"><?= htmlspecialchars($b['payment_email'] ?? '') ?></div>
                                </td>
                                <td class="px-3 py-4 text-sm text-gray-500">
                                    <?= htmlspecialchars($b['period_start']) ?>
                                    to <?= htmlspecialchars($b['period_end']) ?>
                                </td>
                                <td class="px-3 py-4 text-sm text-gray-500">
                                    <?= htmlspecialchars($b['scheduled_for']) ?>
                                </td>
                                <td class="px-3 py-4 text-sm text-right text-gray-900 font-medium">
                                    <?= htmlspecialchars($b['payout_currency'] ?: 'USD') ?>
                                    <?= number_format((float)$b['total_amount'], 2) ?>
                                </td>
                                <td class="px-3 py-4 text-sm text-right text-gray-500">
                                    <?= (int)$b['conversion_count'] ?>
                                </td>
                                <td class="px-3 py-4 text-sm">
                                    <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset <?= $cls ?>">
                                        <?= ucfirst(htmlspecialchars($b['status'])) ?>
                                    </span>
                                </td>
                                <td class="px-3 py-4 text-sm">
                                    <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset <?= $partnerCls ?>">
                                        <?= ucfirst(htmlspecialchars($b['partner_status'])) ?>
                                    </span>
                                </td>
                                <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-0">
                                    <a href="/admin/payouts/<?= (int)$b['id'] ?>" class="text-indigo-600 hover:text-indigo-900">
                                        Review<span class="sr-only">, batch <?= (int)$b['id'] ?></span>
                                    </a>
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
