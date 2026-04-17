<?php
// File: src/Views/partner/dashboard/index.php
?>
<div class="py-6">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="md:flex md:items-center md:justify-between">
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-bold leading-7 text-gray-900 sm:truncate sm:text-3xl sm:tracking-tight">
                    Partner Dashboard
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    Every click and every commission, updated in real time.
                </p>
            </div>
            <div class="mt-4 flex md:ml-4 md:mt-0">
                <a href="/earnings"
                   class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                    View Earnings
                </a>
                <a href="/programs"
                   class="ml-3 inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Browse Programs
                </a>
            </div>
        </div>

        <!-- =============================================================
             WELCOME GUIDE DOWNLOAD — highly visible
             ============================================================= -->
        <div class="mt-8 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 shadow-lg">
            <div class="px-6 py-6 sm:px-8 sm:py-8 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="flex-shrink-0 w-14 h-14 rounded-xl bg-white/20 flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-white">Your Welcome Guide &amp; Product Training</h3>
                        <p class="text-white/80 text-sm mt-0.5">
                            Everything you need: commission details, package breakdowns, recommendation scripts, and tips. Read this first!
                        </p>
                    </div>
                </div>
                <a href="https://homesafeeducation.com/welcome-guide"
                   target="_blank"
                   class="flex-shrink-0 inline-flex items-center gap-2 bg-white text-emerald-700 font-bold text-sm px-6 py-3 rounded-lg shadow-md hover:bg-emerald-50 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                    Read the Welcome Guide
                </a>
            </div>
        </div>

        <!-- =============================================================
             TRAFFIC ROW — clicks + conversion rate
             ============================================================= -->
        <div class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">

            <!-- Total clicks -->
            <div class="relative overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:px-6">
                <dt>
                    <div class="absolute rounded-md bg-sky-500 p-3">
                        <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24"
                             stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"/>
                        </svg>
                    </div>
                    <p class="ml-16 truncate text-sm font-medium text-gray-500">Total Clicks</p>
                </dt>
                <dd class="ml-16 flex items-baseline">
                    <p class="text-2xl font-semibold text-gray-900">
                        <?= number_format($click_stats['total_clicks']) ?>
                    </p>
                    <?php if ($click_stats['clicks_change'] != 0): ?>
                        <p class="ml-2 flex items-baseline text-sm font-semibold
                            <?= $click_stats['clicks_change'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                            <?= $click_stats['clicks_change'] >= 0 ? '+' : '' ?><?= $click_stats['clicks_change'] ?>%
                        </p>
                    <?php endif; ?>
                </dd>
                <dd class="ml-16 text-sm text-gray-500">
                    <?= number_format($click_stats['monthly_clicks']) ?> this month ·
                    <?= number_format($click_stats['today_clicks']) ?> today
                </dd>
            </div>

            <!-- Conversions -->
            <div class="relative overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:px-6">
                <dt>
                    <div class="absolute rounded-md bg-emerald-500 p-3">
                        <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24"
                             stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <p class="ml-16 truncate text-sm font-medium text-gray-500">Conversions</p>
                </dt>
                <dd class="ml-16 flex items-baseline">
                    <p class="text-2xl font-semibold text-gray-900">
                        <?= number_format($stats['total_conversions']) ?>
                    </p>
                </dd>
                <dd class="ml-16 text-sm text-gray-500">
                    <?= number_format($stats['monthly_conversions']) ?> this month
                </dd>
            </div>

            <!-- Conversion rate -->
            <div class="relative overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:px-6">
                <dt>
                    <div class="absolute rounded-md bg-violet-500 p-3">
                        <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24"
                             stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
                        </svg>
                    </div>
                    <p class="ml-16 truncate text-sm font-medium text-gray-500">Conversion Rate</p>
                </dt>
                <dd class="ml-16 flex items-baseline">
                    <p class="text-2xl font-semibold text-gray-900"><?= $click_stats['conversion_rate'] ?>%</p>
                </dd>
                <dd class="ml-16 text-sm text-gray-500">of clicks that purchased</dd>
            </div>

            <!-- Lifetime earnings -->
            <div class="relative overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:px-6">
                <dt>
                    <div class="absolute rounded-md bg-indigo-500 p-3">
                        <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24"
                             stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.467-.22-2.121-.659C8.737 10.46 8.737 9.04 9.879 8.182c1.171-.879 3.07-.879 4.242 0L15 9"/>
                        </svg>
                    </div>
                    <p class="ml-16 truncate text-sm font-medium text-gray-500">Lifetime Earnings</p>
                </dt>
                <dd class="ml-16 flex items-baseline">
                    <p class="text-2xl font-semibold text-gray-900">
                        $<?= number_format($stats['total_commission'], 2) ?>
                    </p>
                </dd>
                <dd class="ml-16 text-sm text-gray-500">
                    $<?= number_format($stats['monthly_commission'], 2) ?> this month
                </dd>
            </div>

        </div>

        <!-- =============================================================
             COMMISSION BREAKDOWN — pending / approved / paid / refunded
             ============================================================= -->
        <div class="mt-8">
            <div class="flex items-end justify-between mb-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Commission status</h2>
                    <p class="text-sm text-gray-500">Every sale you refer, tracked through its full lifecycle.</p>
                </div>
                <p class="text-sm text-gray-500">
                    Next payout:
                    <span class="font-semibold text-gray-900">
                        <?= date('M j, Y', strtotime($commission_breakdown['next_payout_date'])) ?>
                    </span>
                </p>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">

                <!-- Pending -->
                <div class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-5 sm:px-6">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold uppercase tracking-wide text-yellow-700">Pending</p>
                        <span class="inline-flex items-center rounded-full bg-yellow-200/80 px-2 py-0.5 text-xs font-medium text-yellow-900">
                            <?= number_format($commission_breakdown['pending']['count']) ?>
                        </span>
                    </div>
                    <p class="mt-2 text-2xl font-semibold text-gray-900">
                        $<?= number_format($commission_breakdown['pending']['total'], 2) ?>
                    </p>
                    <p class="mt-1 text-xs text-gray-600">
                        Inside refund window. Approved 14 days after purchase if no refund.
                    </p>
                </div>

                <!-- Approved (payable) -->
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-5 sm:px-6">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Approved</p>
                        <span class="inline-flex items-center rounded-full bg-emerald-200/80 px-2 py-0.5 text-xs font-medium text-emerald-900">
                            <?= number_format($commission_breakdown['approved']['count']) ?>
                        </span>
                    </div>
                    <p class="mt-2 text-2xl font-semibold text-gray-900">
                        $<?= number_format($commission_breakdown['approved']['total'], 2) ?>
                    </p>
                    <p class="mt-1 text-xs text-gray-600">
                        Queued for payout on the 1st of next month (if total reaches $25).
                    </p>
                </div>

                <!-- Paid -->
                <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-5 sm:px-6">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Paid</p>
                        <span class="inline-flex items-center rounded-full bg-indigo-200/80 px-2 py-0.5 text-xs font-medium text-indigo-900">
                            <?= number_format($commission_breakdown['paid']['count']) ?>
                        </span>
                    </div>
                    <p class="mt-2 text-2xl font-semibold text-gray-900">
                        $<?= number_format($commission_breakdown['paid']['total'], 2) ?>
                    </p>
                    <p class="mt-1 text-xs text-gray-600">
                        Already paid to your Stripe account.
                    </p>
                </div>

                <!-- Refunded -->
                <div class="rounded-lg border <?= $commission_breakdown['refunded']['count'] > 0 ? 'border-rose-200 bg-rose-50' : 'border-gray-200 bg-gray-50' ?> px-4 py-5 sm:px-6">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold uppercase tracking-wide <?= $commission_breakdown['refunded']['count'] > 0 ? 'text-rose-700' : 'text-gray-500' ?>">
                            Refunded
                        </p>
                        <span class="inline-flex items-center rounded-full <?= $commission_breakdown['refunded']['count'] > 0 ? 'bg-rose-200/80 text-rose-900' : 'bg-gray-200/80 text-gray-700' ?> px-2 py-0.5 text-xs font-medium">
                            <?= number_format($commission_breakdown['refunded']['count']) ?>
                        </span>
                    </div>
                    <p class="mt-2 text-2xl font-semibold text-gray-900">
                        $<?= number_format($commission_breakdown['refunded']['total'], 2) ?>
                    </p>
                    <p class="mt-1 text-xs text-gray-600">
                        Customer refunds — commission clawed back automatically.
                    </p>
                </div>

            </div>
        </div>

        <!-- =============================================================
             MAIN GRID — earnings chart / recent conversions / top programs
             ============================================================= -->
        <div class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-3">

            <!-- Left column -->
            <div class="lg:col-span-2 space-y-8">

                <!-- Earnings trends -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-base font-semibold leading-6 text-gray-900">Earnings Trends</h3>
                        <p class="mt-2 text-sm text-gray-700">Your commission earnings over the last 6 months.</p>

                        <?php if (!empty($earnings_trends)): ?>
                            <div class="mt-6">
                                <div class="relative h-64">
                                    <canvas id="earningsChart" class="w-full h-full"></canvas>
                                </div>
                            </div>
                            <script>
                                document.addEventListener('DOMContentLoaded', function () {
                                    const ctx = document.getElementById('earningsChart').getContext('2d');
                                    const data = <?= json_encode($earnings_trends) ?>;
                                    new Chart(ctx, {
                                        type: 'line',
                                        data: {
                                            labels: data.map(item => {
                                                const date = new Date(item.month + '-01');
                                                return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                                            }),
                                            datasets: [{
                                                label: 'Earnings',
                                                data: data.map(item => parseFloat(item.earnings)),
                                                borderColor: 'rgb(34, 197, 94)',
                                                backgroundColor: 'rgba(34, 197, 94, 0.1)',
                                                tension: 0.4,
                                                fill: true
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            plugins: { legend: { display: false } },
                                            scales: {
                                                y: {
                                                    beginAtZero: true,
                                                    ticks: { callback: v => '$' + v.toLocaleString() }
                                                }
                                            }
                                        }
                                    });
                                });
                            </script>
                        <?php else: ?>
                            <div class="mt-6 text-center py-12">
                                <h3 class="mt-2 text-sm font-medium text-gray-900">No earnings data yet</h3>
                                <p class="mt-1 text-sm text-gray-500">Start promoting programs to see your earnings trends.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent conversions -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <div class="sm:flex sm:items-center">
                            <div class="sm:flex-auto">
                                <h3 class="text-base font-semibold leading-6 text-gray-900">Recent Conversions</h3>
                                <p class="mt-2 text-sm text-gray-700">Every recent sale with its current status.</p>
                            </div>
                            <div class="mt-4 sm:ml-16 sm:mt-0 sm:flex-none">
                                <a href="/earnings" class="block rounded-md bg-indigo-600 px-3 py-2 text-center text-sm font-semibold text-white hover:bg-indigo-500">
                                    View all
                                </a>
                            </div>
                        </div>

                        <?php if (!empty($conversions)): ?>
                            <div class="mt-6 flow-root">
                                <ul role="list" class="-my-5 divide-y divide-gray-200">
                                    <?php foreach ($conversions as $conversion):
                                        $status = $conversion['status'];
                                        $label = $status === 'payable' ? 'Approved' : ucfirst($status);
                                        $classes = [
                                            'pending'  => 'bg-yellow-100 text-yellow-800',
                                            'payable'  => 'bg-emerald-100 text-emerald-800',
                                            'paid'     => 'bg-indigo-100 text-indigo-800',
                                            'refunded' => 'bg-rose-100 text-rose-800',
                                            'rejected' => 'bg-gray-200 text-gray-700',
                                        ][$status] ?? 'bg-gray-100 text-gray-700';
                                    ?>
                                        <li class="py-4">
                                            <div class="flex items-center space-x-4">
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($conversion['program_name']) ?>
                                                    </p>
                                                    <p class="truncate text-sm text-gray-500">
                                                        <?= date('M j, Y g:i A', strtotime($conversion['created_at'])) ?>
                                                    </p>
                                                </div>
                                                <div class="flex-shrink-0 text-right">
                                                    <p class="text-sm font-medium text-gray-900">
                                                        $<?= number_format($conversion['amount'], 2) ?>
                                                    </p>
                                                    <p class="text-sm <?= $status === 'refunded' ? 'text-rose-600' : 'text-green-600' ?> font-medium">
                                                        <?= $status === 'refunded' ? '−' : '+' ?>$<?= number_format($conversion['commission_amount'], 2) ?>
                                                    </p>
                                                </div>
                                                <div class="flex-shrink-0">
                                                    <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium <?= $classes ?>">
                                                        <?= htmlspecialchars($label) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <div class="text-center mt-6 py-12">
                                <h3 class="mt-2 text-sm font-medium text-gray-900">No conversions yet</h3>
                                <p class="mt-1 text-sm text-gray-500">Start promoting your programs to earn commissions.</p>
                                <div class="mt-6">
                                    <a href="/programs" class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                                        Browse Programs
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right column -->
            <div class="space-y-8">

                <!-- Quick actions -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-base font-semibold leading-6 text-gray-900">Quick Actions</h3>
                        <div class="mt-6 grid grid-cols-1 gap-4">
                            <a href="/programs"
                               class="relative rounded-lg border border-gray-300 bg-white px-6 py-5 shadow-sm flex items-center space-x-3 hover:border-gray-400">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900">Browse Programs</p>
                                    <p class="text-sm text-gray-500">Find new programs to promote</p>
                                </div>
                            </a>
                            <a href="/earnings"
                               class="relative rounded-lg border border-gray-300 bg-white px-6 py-5 shadow-sm flex items-center space-x-3 hover:border-gray-400">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900">View Earnings</p>
                                    <p class="text-sm text-gray-500">Full earnings and payout history</p>
                                </div>
                            </a>
                            <a href="/settings"
                               class="relative rounded-lg border border-gray-300 bg-white px-6 py-5 shadow-sm flex items-center space-x-3 hover:border-gray-400">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900">Account Settings</p>
                                    <p class="text-sm text-gray-500">Payout currency, Stripe, profile</p>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Top programs -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-base font-semibold leading-6 text-gray-900">Top Programs</h3>
                        <p class="mt-2 text-sm text-gray-700">Your best performing programs.</p>

                        <?php if (!empty($program_performance)): ?>
                            <div class="mt-6">
                                <ul role="list" class="space-y-3">
                                    <?php foreach (array_slice($program_performance, 0, 5) as $i => $program): ?>
                                        <li class="flex items-center justify-between">
                                            <div class="flex items-center">
                                                <span class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-indigo-100 text-indigo-800 text-xs font-medium">
                                                    <?= $i + 1 ?>
                                                </span>
                                                <div class="ml-3">
                                                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($program['program_name']) ?></p>
                                                    <p class="text-sm text-gray-500"><?= number_format($program['total_conversions']) ?> conversions</p>
                                                </div>
                                            </div>
                                            <p class="text-sm font-medium text-gray-900">
                                                $<?= number_format($program['total_commission'], 2) ?>
                                            </p>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <div class="text-center mt-6 py-8">
                                <p class="text-sm text-gray-500">No programs joined yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
