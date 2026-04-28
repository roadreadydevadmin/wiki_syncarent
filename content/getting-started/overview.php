<?php
if (!defined('WIKI_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageMeta = [
    'title' => 'Admin Portal Getting Started and Initial Setup',
    'description' => 'First-login setup checklist for new admins in required order.',
];
?>
<section>

    <h3>Scope</h3>
    <p>
        This guide is for a new admin&rsquo;s first login and the full initial settings/setup of the Admin Portal.
    </p>

    <h3>Step 1: Complete Getting Started (Required, in order)</h3>
    <ol>
        <li>
            <strong>Set Your New Password</strong><br>
            On Getting Started, complete &ldquo;1. Set Your New Password&rdquo;.
            <br><br>
            Requirements:
            <ul>
                <li>Password must be at least 8 characters.</li>
                <li>New password and confirm password must match.</li>
            </ul>
            Click <strong>Update Password</strong>.
        </li>
        <li>
            <strong>Upload Your Logo</strong><br>
            Complete &ldquo;2. Upload Your Logo&rdquo;.
            <ul>
                <li>Upload a valid image file.</li>
            </ul>
            Click <strong>Upload Logo</strong>.<br>
            This logo is applied across portals (frontend/admin/consignment/driver logo settings).
        </li>
        <li>
            <strong>Connect Stripe</strong><br>
            Complete &ldquo;3. Connect Your Stripe Account&rdquo;.
            <ul>
                <li>Click <strong>Setup payouts on Stripe</strong>.</li>
                <li>Finish Stripe onboarding in Stripe&rsquo;s flow.</li>
                <li>You return to <code>admin/admin-settings.php</code> with success/failure status.</li>
            </ul>
            <strong>Important:</strong><br>
            Until a Stripe account is connected, core nav links are hidden in the admin header, so Stripe is a hard blocker for normal portal use.
        </li>
    </ol>
</section>
