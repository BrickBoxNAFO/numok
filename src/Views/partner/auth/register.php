<div class="min-h-screen flex flex-col justify-center py-12 sm:px-6 lg:px-8" style="background: linear-gradient(135deg, #0B1F3A 0%, #132d4f 100%);">
    <div class="sm:mx-auto sm:w-full sm:max-w-md text-center">
        <!-- Brand Logo Text -->
        <div style="margin-bottom: 8px;">
            <span style="font-size: 28px; font-weight: 700; color: #2B3480; letter-spacing: -0.5px;">HomeSafe</span><span style="font-size: 28px; font-weight: 700; color: #E8703A; letter-spacing: -0.5px;">Education</span>
        </div>
        <!-- Affiliate Program Badge -->
        <div style="display: inline-block; background: #0EA5A0; color: white; padding: 6px 20px; border-radius: 50px; font-size: 13px; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 12px;">
            Affiliate Program
        </div>
        <h2 class="mt-2 text-center" style="color: rgba(255,255,255,0.7); font-size: 16px;">
            Join our affiliate program and earn 20% commission
        </h2>
    </div>

    <div class="mt-6 sm:mx-auto sm:w-full sm:max-w-md">
        <div class="bg-white py-8 px-4 shadow-lg sm:rounded-lg sm:px-10" style="border-top: 4px solid #0EA5A0;">
            <?php if (isset($_SESSION['register_error'])): ?>
            <div class="rounded-md bg-red-50 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-red-800"><?= htmlspecialchars($_SESSION['register_error']) ?></p>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['register_error']); endif; ?>

            <form class="space-y-5" action="/auth/register" method="POST">
                <div>
                    <label for="company_name" class="block text-sm font-medium text-gray-700">
                        Company / Website Name
                    </label>
                    <div class="mt-1">
                        <input id="company_name" name="company_name" type="text" required autofocus
                               placeholder="e.g. Your Blog or Business Name"
                               class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none sm:text-sm" onfocus="this.style.borderColor='#0EA5A0'; this.style.boxShadow='0 0 0 1px #0EA5A0';" onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none';">
                    </div>
                </div>

                <div>
                    <label for="contact_name" class="block text-sm font-medium text-gray-700">
                        Your Full Name
                    </label>
                    <div class="mt-1">
                        <input id="contact_name" name="contact_name" type="text" required
                               class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none sm:text-sm" onfocus="this.style.borderColor='#0EA5A0'; this.style.boxShadow='0 0 0 1px #0EA5A0';" onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none';">
                    </div>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">
                        Email address
                    </label>
                    <div class="mt-1">
                        <input id="email" name="email" type="email" autocomplete="email" required
                               class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none sm:text-sm" onfocus="this.style.borderColor='#0EA5A0'; this.style.boxShadow='0 0 0 1px #0EA5A0';" onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none';">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">
                        Password
                    </label>
                    <div class="mt-1">
                        <input id="password" name="password" type="password" required
                               class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none sm:text-sm"
                               minlength="8" onfocus="this.style.borderColor='#0EA5A0'; this.style.boxShadow='0 0 0 1px #0EA5A0';" onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none';">
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Minimum 8 characters</p>
                </div>

                <div>
                    <button type="submit"
                            class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-md shadow-sm text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-offset-2" style="background-color: #0EA5A0;" onmouseover="this.style.backgroundColor='#0B8A86'" onmouseout="this.style.backgroundColor='#0EA5A0'">
                        Create Affiliate Account
                    </button>
                </div>
            </form>
        </div>

        <!-- Benefits -->
        <div style="margin-top: 24px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; text-align: center;">
            <div style="background: rgba(255,255,255,0.08); border-radius: 8px; padding: 14px 8px; border: 1px solid rgba(255,255,255,0.1);">
                <div style="font-size: 22px; font-weight: 700; color: #0EA5A0;">20%</div>
                <div style="font-size: 11px; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: 0.5px;">Commission</div>
            </div>
            <div style="background: rgba(255,255,255,0.08); border-radius: 8px; padding: 14px 8px; border: 1px solid rgba(255,255,255,0.1);">
                <div style="font-size: 22px; font-weight: 700; color: #0EA5A0;">30</div>
                <div style="font-size: 11px; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: 0.5px;">Day Cookies</div>
            </div>
            <div style="background: rgba(255,255,255,0.08); border-radius: 8px; padding: 14px 8px; border: 1px solid rgba(255,255,255,0.1);">
                <div style="font-size: 22px; font-weight: 700; color: #0EA5A0;">Monthly</div>
                <div style="font-size: 11px; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: 0.5px;">Payouts</div>
            </div>
        </div>

        <p class="mt-6 text-center text-sm" style="color: rgba(255,255,255,0.6);">
            Already have an affiliate account?
            <a href="/login" style="color: #0EA5A0; font-weight: 600;" onmouseover="this.style.color='#E8703A'" onmouseout="this.style.color='#0EA5A0'">
                Sign in
            </a>
        </p>

        <p class="mt-4 text-center text-xs" style="color: rgba(255,255,255,0.3);">
            <a href="https://homesafeeducation.com" style="color: rgba(255,255,255,0.3);" onmouseover="this.style.color='rgba(255,255,255,0.5)'" onmouseout="this.style.color='rgba(255,255,255,0.3)'">
                &larr; Back to HomeSafeEducation.com
            </a>
        </p>
    </div>
</div>
