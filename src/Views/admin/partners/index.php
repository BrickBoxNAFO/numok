<?php
/**
 * Admin view: /admin/partners
 *
 * Full affiliates tracking table. One row per partner. Shows:
 *   - status (active / pending / suspended)
 *   - lifetime revenue + commission
 *   - pending / approved / paid / refunded commission buckets
 *   - 30d clicks + risk flags (vpn, datacenter, refunds)
 *   - last activity
 *   - quick actions (edit, suspend/reinstate)
 *
 * All data comes pre-aggregated from PartnersController::index.
 */

$f          = $filters             ?? ['status' => 'all', 'q' => '', 'sort' => 'recent'];
$partners   = $partners            ?? [];
$totals     = $totals              ?? [];
$windowDays = (int)($approval_delay_days ?? 14);

function p_money($v): string {
    return '$' . number_format((float)$v, 2);
}
function p_num($v): string {
    return number_format((int)$v);
}
?>
<div class="py-6">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

        <div class="sm:flex sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Affiliates</h1>
                <p class="mt-2 text-sm text-gray-700">
                    Every partner, every click, every commission. Pending commissions
                    clear the <?= $windowDays ?>-day refund window before becoming
                    payable. Approve button is locked until they do.
                </p>
            </div>
            <div class="mt-4 sm:mt-0 flex gap-2">
                <a href="/admin/partners/create"
                   class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Add partner
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

        <!-- Totals row -->
        <dl class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-5">
            <div class="rounded-lg bg-white px-4 py-5 shadow">
                <dt class="truncate text-xs font-medium text-gray-500">Partners (<?= htmlspecialchars($f['status']) ?>)</dt>
                <dd class="mt-1 text-2xl font-semibold text-gray-900"><?= p_num($totals['partners'] ?? 0) ?></dd>
                <p class="mt-1 text-xs text-gray-500">
                    <?= (int)($totals['active'] ?? 0) ?> active &middot;
                    <?= (int)($totals['suspended'] ?? 0) ?> suspended
                </p>
            </div>
            <div class="rounded-lg bg-white px-4 py-5 shadow">
                <dt class="truncate text-xs font-medium text-gray-500">Pending (in window)</dt>
                <dd class="mt-1 text-2xl font-semibold text-yellow-700"><?= p_money($totals['pending_commission'] ?? 0) ?></dd>
                <p class="mt-1 text-xs text-gray-500">locked <?= $windowDays ?>d</p>
            </div>
            <div class="rounded-lg bg-white px-4 py-5 shadow">
                <dt class="truncate text-xs font-medium text-gray-500">Approved (payable)</dt>
                <dd class="mt-1 text-2xl font-semibold text-emerald-700"><?= p_money($totals['approved_commission'] ?? 0) ?></dd>
                <p class="mt-1 text-xs text-gray-500">queues on the 1st</p>
            </div>
            <div class="rounded-lg bg-white px-4 py-5 shadow">
                <dt class="truncate text-xs font-medium text-gray-500">Paid (lifetime)</dt>
                <dd class="mt-1 text-2xl font-semibold text-indigo-700"><?= p_money($totals['paid_commission'] ?? 0) ?></dd>
                <p class="mt-1 text-xs text-gray-500"><?= p_num($totals['conversions_lifetime'] ?? 0) ?> conversions</p>
            </div>
            <div class="rounded-lg bg-white px-4 py-5 shadow">
                <dt class="truncate text-xs font-medium text-gray-500">Refunded (lifetime)</dt>
                <dd class="mt-1 text-2xl font-semibold text-rose-700"><?= p_money($totals['refunded_commission'] ?? 0) ?></dd>
                <p class="mt-1 text-xs text-gray-500"><?= p_num($totals['clicks_30d'] ?? 0) ?> clicks /30d</p>
            </div>
        </dl>

        <!-- Filters -->
        <form method="GET" action="/admin/partners" class="mt-8 grid grid-cols-1 gap-3 sm:grid-cols-12">
            <div class="sm:col-span-5">
                <label for="q" class="sr-only">Search</label>
                <input type="text" name="q" id="q" value="<?= htmlspecialchars($f['q']) ?>"
                       placeholder="Search company, contact, or email"
                       class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <select name="status" class="block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm">
                    <?php
                        $statusOpts = [
                            'all'       => 'All statuses',
                            'active'    => 'Active',
                            'pending'   => 'Pending',
                            'suspended' => 'Suspended'
                        ];
                        foreach ($statusOpts as $val => $label):
                    ?>
                        <option value="<?= $val ?>" <?= $f['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sm:col-span-3">
                <select name="sort" class="block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm">
                    <?php
                        $sortOpts = [
                            'recent'     => 'Most recent',
                            'revenue'    => 'Top revenue',
                            'commission' => 'Top commission',
                            'pending'    => 'Most pending',
                            'refunds'    => 'Most refunds',
                            'risk'       => 'Highest risk',
                            'name'       => 'Name A–Z'
                        ];
                        foreach ($sortOpts as $val => $label):
                    ?>
                        <option value="<?= $val ?>" <?= $f['sort'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sm:col-span-1">
                <button type="submit" class="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Apply
                </button>
            </div>
        </form>

        <!-- Table -->
        <div class="mt-6 flow-root">
            <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                    <?php if (empty($partners)): ?>
                    <div class="text-center rounded-lg border-2 border-dashed border-gray-300 p-12">
                        <h3 class="text-sm font-medium text-gray-900">No partners match this filter.</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            Try changing the status or clearing the search.
                        </p>
                    </div>
                    <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-300">
                        <thead>
                            <tr>
                                <th class="py-3 pl-4 pr-3 text-left text-xs font-semibold text-gray-900 uppercase tracking-wide">Partner</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-gray-900 uppercase tracking-wide">Status</th>
                                <th class="px-3 py-3 text-right text-xs font-semibold text-gray-900 uppercase tracking-wide">Clicks (30d)</th>
                                <th class="px-3 py-3 text-right text-xs font-semibold text-gray-900 uppercase tracking-wide">Conversions</th>
                                <th class="px-3 py-3 text-right text-xs font-semibold text-gray-900 uppercase tracking-wide">Pending</th>
                                <th class="px-3 py-3 text-right text-xs font-semibold text-gray-900 uppercase tracking-wide">Approved</th>
                                <th class="px-3 py-3 text-right text-xs font-semibold text-gray-900 uppercase tracking-wide">Paid</th>
                                <th class="px-3 py-3 text-right text-xs font-semibold text-gray-900 uppercase tracking-wide">Refunded</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-gray-900 uppercase tracking-wide">Risk</th>
                                <th class="relative py-3 pl-3 pr-4">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <?php foreach ($partners as $p):
                                $status        = $p['status'] ?? 'pending';
                                $risk          = (int)($p['risk_score'] ?? 0);
                                $vpn           = (int)($p['vpn_clicks_30d'] ?? 0);
                                $dc            = (int)($p['dc_clicks_30d'] ?? 0);
                                $refunds30d    = (int)($p['refunds_30d'] ?? 0);
                                $lastConv      = $p['last_conversion_at'] ?? null;
                                $lastClick     = $p['last_click_at'] ?? null;
                                $lastActivity  = $lastConv && $lastClick
                                    ? (strtotime($lastConv) > strtotime($lastClick) ? $lastConv : $lastClick)
                                    : ($lastConv ?: $lastClick);
                                $badgeClass = [
                                    'active'    => 'bg-emerald-100 text-emerald-800',
                                    'pending'   => 'bg-yellow-100 text-yellow-800',
                                    'suspended' => 'bg-rose-100 text-rose-800'
                                ][$status] ?? 'bg-gray-100 text-gray-800';

                                if ($risk === 0) {
                                    $riskLabel = 'Clean';
                                    $riskColor = 'bg-gray-100 text-gray-600';
                                } elseif ($risk < 10) {
                                    $riskLabel = 'Low';
                                    $riskColor = 'bg-emerald-100 text-emerald-800';
                                } elseif ($risk < 30) {
                                    $riskLabel = 'Watch';
                                    $riskColor = 'bg-yellow-100 text-yellow-800';
                                } else {
                                    $riskLabel = 'High';
                                    $riskColor = 'bg-rose-100 text-rose-800';
                                }
                            ?>
                            <tr class="<?= $status === 'suspended' ? 'bg-rose-50/40' : '' ?>">
                                <td class="py-4 pl-4 pr-3">
                                    <div class="font-medium text-gray-900">
                                        <?= htmlspecialchars($p['company_name'] ?? '—') ?>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        <?= htmlspecialchars($p['contact_name'] ?? '') ?>
                                        <?php if (!empty($p['contact_name']) && !empty($p['email'])): ?>&middot;<?php endif; ?>
                                        <?= htmlspecialchars($p['email'] ?? '') ?>
                                    </div>
                                    <?php if ($status === 'suspended' && !empty($p['suspended_reason'])): ?>
                                    <div class="text-xs text-rose-700 mt-1">
                                        Suspended: <?= htmlspecialchars($p['suspended_reason']) ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-4">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium <?= $badgeClass ?>">
                                        <?= ucfirst($status) ?>
                                    </span>
                                    <?php if (empty($p['stripe_connect_id'])): ?>
                                    <div class="text-[11px] text-amber-700 mt-1">No Stripe Connect</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-4 text-right text-sm text-gray-700">
                                    <?= p_num($p['clicks_30d']) ?>
                                </td>
                                <td class="px-3 py-4 text-right text-sm text-gray-700">
                                    <?= p_num($p['lifetime_conversions']) ?>
                                </td>
                                <td class="px-3 py-4 text-right text-sm font-medium text-yellow-700">
                                    <?= p_money($p['pending_commission']) ?>
                                    <div class="text-[11px] font-normal text-gray-500">
                                        <?= (int)$p['pending_count'] ?> in window
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-right text-sm font-medium text-emerald-700">
                                    <?= p_money($p['approved_commission']) ?>
                                    <div class="text-[11px] font-normal text-gray-500">
                                        <?= (int)$p['approved_count'] ?> payable
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-right text-sm font-medium text-indigo-700">
                                    <?= p_money($p['paid_commission']) ?>
                                    <div class="text-[11px] font-normal text-gray-500">
                                        <?= (int)$p['paid_count'] ?> paid
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-right text-sm font-medium <?= ((float)$p['refunded_commission']) > 0 ? 'text-rose-700' : 'text-gray-500' ?>">
                                    <?= ((float)$p['refunded_commission']) > 0 ? '-' . p_money($p['refunded_commission']) : p_money(0) ?>
                                    <div class="text-[11px] font-normal text-gray-500">
                                        <?= (int)$p['refunded_count'] ?> refunds
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium <?= $riskColor ?>">
                                        <?= $riskLabel ?>
                                    </span>
                                    <?php if ($vpn + $dc + $refunds30d > 0): ?>
                                    <div class="text-[11px] text-gray-500 mt-1">
                                        <?php if ($vpn > 0): ?><?= $vpn ?> vpn<?php endif; ?>
                                        <?php if ($dc > 0): ?> &middot; <?= $dc ?> dc<?php endif; ?>
                                        <?php if ($refunds30d > 0): ?> &middot; <?= $refunds30d ?> refunds<?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 pl-3 pr-4 text-right text-sm font-medium">
                                    <a href="/admin/partners/<?= (int)$p['id'] ?>/edit"
                                       class="text-indigo-600 hover:text-indigo-900">
                                        Manage<span class="sr-only">, <?= htmlspecialchars($p['company_name'] ?? '') ?></span>
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

        <div class="mt-6 text-xs text-gray-500">
            <p>
                Pending commissions are locked for the <?= $windowDays ?>-day approval window.
                They move to Approved automatically by the <code>approve-conversions</code>
                cron, never by an admin. Paid amounts correspond to real Stripe transfers.
            </p>
        </div>

    </div>
</div>
