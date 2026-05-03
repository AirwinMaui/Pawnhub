<?php
// ── GMAIL SETTINGS — palitan ng sarili mong Gmail ─────────────
define('MAIL_FROM',     'kiaromendoza70@gmail.com');
define('MAIL_FROM_NAME','PawnHub System');
define('MAIL_USERNAME', 'kiaromendoza70@gmail.com');
define('MAIL_PASSWORD', 'pyfh zipv bqyn kyxd');
define('APP_URL', 'https://pawnhub-bjesb8gqh5d3eqfy.southeastasia-01.azurewebsites.net');

// ── Load PHPMailer ─────────────────────────────────────────────
$_autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($_autoload)) {
    error_log('[PawnHub] vendor/autoload.php missing — run composer install');
    if (!function_exists('sendMail')) {
        function sendMail(): bool { return false; }
        function sendTenantInvitation(): bool { return false; }
        function sendTenantWelcome(): bool { return false; }
        function sendTenantApproved(): bool { return false; }
        function sendStaffInvitation(): bool { return false; }
        function sendStaffWelcome(): bool { return false; }
        function sendManagerInvitation(): bool { return false; }
        function sendManagerWelcome(): bool { return false; }
        function sendSubscriptionExpiring(): bool         { return false; }
        function sendSubscriptionExpired(): bool          { return false; }
        function sendSubscriptionRenewed(): bool          { return false; }
        function sendRenewalRequestReceived(): bool       { return false; }
        function sendSubscriptionAutoDeactivated(string $toEmail='', string $toName='', string $businessName='', string $plan='', string $slug='', int $tenantId=0): bool  { return false; }
        function sendSuperAdminAccountReady(): bool       { return false; }
        function sendPermitApproved(string $a='', string $b='', string $c='', string $d=''): bool { return false; }
        function sendPermitRejected(string $a='', string $b='', string $c='', string $d=''): bool { return false; }
        function sendSuperAdminPaymentNotif(string $a='', string $b='', string $c='', string $d='', string $e='', string $f='', float $g=0, string $h='', string $i='', string $j='', int $k=0): bool { return false; }
    }
    return;
}
require $_autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ─────────────────────────────────────────────────────────────────────────────
// CORE SEND FUNCTION
// ─────────────────────────────────────────────────────────────────────────────

function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FLOW 1-A  ─  Super Admin manually adds a tenant
// ─────────────────────────────────────────────────────────────────────────────

function sendTenantInvitation(string $toEmail, string $toName, string $businessName, string $token, string $slug = ''): bool
{
    $link = $slug
        ? APP_URL . '/' . urlencode($slug) . '?token=' . urlencode($token) . '&role=admin'
        : APP_URL . '/tenant_register.php?token=' . urlencode($token);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Multi-Tenant Pawnshop Management</p>
      </div>
      <div style="padding:36px;">
        <h2 style="font-size:1.25rem;font-weight:800;color:#0f172a;margin:0 0 8px;">You\'re invited! 🎉</h2>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          You have been invited to join <strong>PawnHub</strong> as the owner of
          <strong>' . htmlspecialchars($businessName) . '</strong>.
          Click the button below to set up your account credentials and access the system.
        </p>
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $link . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.3);">
            Set Up My Account →
          </a>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#1d4ed8;font-size:.82rem;margin:0;line-height:1.6;">
            🔒 This link will expire in <strong>24 hours</strong>.<br>
            If you did not expect this email, you can safely ignore it.
          </p>
        </div>
        <p style="color:#94a3b8;font-size:.76rem;word-break:break-all;">
          Or copy this link: <a href="' . $link . '" style="color:#2563eb;">' . $link . '</a>
        </p>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, 'PawnHub — You\'re Invited to ' . $businessName, $html);
}

function sendTenantWelcome(string $toEmail, string $toName, string $businessName, string $slug): bool
{
    $homeLink  = APP_URL . '/' . urlencode($slug);              // → public home page
    $loginLink = APP_URL . '/' . urlencode($slug) . '?login=1'; // → login page (for bookmark tip)

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Multi-Tenant Pawnshop Management</p>
      </div>
      <div style="padding:36px;">
        <h2 style="font-size:1.25rem;font-weight:800;color:#0f172a;margin:0 0 8px;">Your account is ready! 🚀</h2>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Your <strong>' . htmlspecialchars($businessName) . '</strong> account on PawnHub is fully set up.
          Click the button below to visit your shop home page.
        </p>
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $homeLink . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.3);">
            Visit My Shop →
          </a>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#15803d;font-size:.82rem;margin:0;line-height:1.6;">
            🔖 <strong>Tip:</strong> I-bookmark ang login link mo para madaling ma-access next time:<br>
            <a href="' . $loginLink . '" style="color:#2563eb;">' . $loginLink . '</a>
          </p>
        </div>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, 'PawnHub — Your ' . $businessName . ' Account is Ready', $html);
}

// ─────────────────────────────────────────────────────────────────────────────
// FLOW 1-B  ─  Self-registration via signup.php
// ─────────────────────────────────────────────────────────────────────────────

function sendTenantApproved(string $toEmail, string $toName, string $businessName, string $slug): bool
{
    $shopLink  = APP_URL . '/' . urlencode($slug);              // → home/shop page
    $loginLink = APP_URL . '/' . urlencode($slug) . '?login=1'; // → login page

    // ── EMAIL 1: Approved — visit your shop ──────────────────
    $html1 = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Multi-Tenant Pawnshop Management</p>
      </div>
      <div style="padding:36px;">
        <h2 style="font-size:1.25rem;font-weight:800;color:#0f172a;margin:0 0 8px;">Your application has been approved! ✅</h2>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Great news! Your pawnshop registration for <strong>' . htmlspecialchars($businessName) . '</strong>
          has been <strong style="color:#15803d;">approved</strong> by our Super Admin.<br><br>
          Your shop is now live! Click the button below to visit your pawnshop page.
        </p>
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $shopLink . '" style="display:inline-block;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(22,163,74,.3);">
            Visit My Shop →
          </a>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#15803d;font-size:.82rem;margin:0;line-height:1.6;">
            🔖 <strong>I-bookmark ang link na ito</strong> — ito na ang iyong personal na shop page:<br>
            <a href="' . $shopLink . '" style="color:#2563eb;">' . $shopLink . '</a>
          </p>
        </div>
        <p style="color:#94a3b8;font-size:.76rem;word-break:break-all;">
          Or copy this link: <a href="' . $shopLink . '" style="color:#2563eb;">' . $shopLink . '</a>
        </p>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    $sent1 = sendMail($toEmail, $toName, '🎉 PawnHub — Your Shop is Now Live! ' . $businessName, $html1);

    // ── EMAIL 2: Login to your account ───────────────────────
    $html2 = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Multi-Tenant Pawnshop Management</p>
      </div>
      <div style="padding:36px;">
        <h2 style="font-size:1.25rem;font-weight:800;color:#0f172a;margin:0 0 8px;">Access your account 🔑</h2>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          You can now sign in to your <strong>' . htmlspecialchars($businessName) . '</strong> account
          using the <strong>username and password</strong> you set during registration.
        </p>
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $loginLink . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.3);">
            Sign In to Login Page →
          </a>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#1d4ed8;font-size:.82rem;margin:0;line-height:1.6;">
            🔖 <strong>I-bookmark ang link na ito</strong> — ito na ang iyong personal na login page:<br>
            <a href="' . $loginLink . '" style="color:#2563eb;">' . $loginLink . '</a>
          </p>
        </div>
        <p style="color:#94a3b8;font-size:.76rem;word-break:break-all;">
          Or copy this link: <a href="' . $loginLink . '" style="color:#2563eb;">' . $loginLink . '</a>
        </p>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    $sent2 = sendMail($toEmail, $toName, 'PawnHub — Sign In to Your ' . $businessName . ' Dashboard', $html2);

    return $sent1 && $sent2;
}

// ─────────────────────────────────────────────────────────────────────────────
// STAFF / CASHIER EMAILS
// ─────────────────────────────────────────────────────────────────────────────

function sendStaffInvitation(string $toEmail, string $toName, string $businessName, string $role, string $token, string $slug = ''): bool
{
    $registerLink = $slug
        ? APP_URL . '/' . urlencode($slug) . '?token=' . urlencode($token) . '&role=' . urlencode($role)
        : APP_URL . '/staff_register.php?token=' . urlencode($token);
    $roleLabel    = ucfirst($role);
    $roleColor    = $role === 'cashier' ? '#7c3aed' : '#2563eb';
    $roleBg       = $role === 'cashier' ? 'linear-gradient(135deg,#4c1d95,#7c3aed)' : 'linear-gradient(135deg,#1e3a8a,#2563eb)';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:' . $roleBg . ';padding:32px 36px;text-align:center;">
        <span style="font-size:1.4rem;font-weight:800;color:#fff;">' . htmlspecialchars($businessName) . '</span>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:6px 0 0;">PawnHub — ' . $roleLabel . ' Invitation</p>
      </div>
      <div style="padding:36px;">
        <div style="display:inline-block;background:' . ($role === 'cashier' ? '#f3e8ff' : '#dbeafe') . ';border:1px solid ' . ($role === 'cashier' ? '#d8b4fe' : '#bfdbfe') . ';border-radius:8px;padding:5px 12px;font-size:.78rem;font-weight:700;color:' . $roleColor . ';margin-bottom:16px;">' . $roleLabel . ' Account Invitation</div>
        <h2 style="font-size:1.25rem;font-weight:800;color:#0f172a;margin:0 0 8px;">You\'re invited to join the team! 👋</h2>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          You have been invited to join <strong>' . htmlspecialchars($businessName) . '</strong> as a <strong>' . $roleLabel . '</strong>.
          Click the button below to set up your username and password.
        </p>
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $registerLink . '" style="display:inline-block;background:' . $roleBg . ';color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(0,0,0,.2);">Set Up My Account →</a>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#1d4ed8;font-size:.82rem;margin:0;line-height:1.6;">🔒 This link will expire in <strong>24 hours</strong>.<br>If you did not expect this email, you can safely ignore it.</p>
        </div>
        <p style="color:#94a3b8;font-size:.76rem;word-break:break-all;">Or copy this link: <a href="' . $registerLink . '" style="color:#2563eb;">' . $registerLink . '</a></p>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">© ' . date('Y') . ' PawnHub · All rights reserved<br>This is an automated message, please do not reply.</p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, 'PawnHub — ' . $roleLabel . ' Invitation from ' . $businessName, $html);
}

function sendStaffWelcome(string $toEmail, string $toName, string $businessName, string $role, string $slug): bool
{
    $homeLink  = APP_URL . '/' . urlencode($slug);              // → public home page
    $loginLink = APP_URL . '/' . urlencode($slug) . '?login=1'; // → login page (bookmark tip)
    $roleLabel = ucfirst($role);
    $roleColor = $role === 'cashier' ? '#7c3aed' : '#2563eb';
    $roleBg    = $role === 'cashier' ? 'linear-gradient(135deg,#4c1d95,#7c3aed)' : 'linear-gradient(135deg,#1e3a8a,#2563eb)';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:' . $roleBg . ';padding:32px 36px;text-align:center;">
        <span style="font-size:1.4rem;font-weight:800;color:#fff;">' . htmlspecialchars($businessName) . '</span>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:6px 0 0;">PawnHub — Your Account is Ready</p>
      </div>
      <div style="padding:36px;">
        <h2 style="font-size:1.25rem;font-weight:800;color:#0f172a;margin:0 0 8px;">Welcome to the team! 🚀</h2>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Your <strong>' . $roleLabel . '</strong> account for <strong>' . htmlspecialchars($businessName) . '</strong> is ready. Click the button below to visit the shop.
        </p>
        <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:24px;">
          <p style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin:0 0 8px;">Your Branch</p>
          <p style="font-size:.9rem;font-weight:700;color:#0f172a;margin:0 0 14px;">' . htmlspecialchars($businessName) . '</p>
          <a href="' . $homeLink . '" style="display:inline-block;background:' . $roleBg . ';color:#fff;text-decoration:none;padding:12px 28px;border-radius:9px;font-size:.88rem;font-weight:700;">Visit My Shop →</a>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#15803d;font-size:.82rem;margin:0;line-height:1.7;">🔖 <strong>Tip:</strong> Bookmark your login link for quick access next time:<br><a href="' . $loginLink . '" style="color:#2563eb;">' . $loginLink . '</a></p>
        </div>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">© ' . date('Y') . ' PawnHub · All rights reserved<br>This is an automated message, please do not reply.</p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, 'PawnHub — Your ' . $roleLabel . ' Account is Ready at ' . $businessName, $html);
}

// ─────────────────────────────────────────────────────────────────────────────
// MANAGER EMAILS
// ─────────────────────────────────────────────────────────────────────────────

function sendManagerInvitation(string $toEmail, string $toName, string $businessName, string $token, string $slug = ''): bool
{
    $registerLink = $slug
        ? APP_URL . '/' . urlencode($slug) . '?token=' . urlencode($token) . '&role=manager'
        : APP_URL . '/manager_register.php?token=' . urlencode($token);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#064e3b,#059669);padding:32px 36px;text-align:center;">
        <span style="font-size:1.4rem;font-weight:800;color:#fff;">' . htmlspecialchars($businessName) . '</span>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:6px 0 0;">PawnHub — Branch Manager Invitation</p>
      </div>
      <div style="padding:36px;">
        <div style="display:inline-block;background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;padding:5px 12px;font-size:.78rem;font-weight:700;color:#065f46;margin-bottom:16px;">Manager Account Invitation</div>
        <h2 style="font-size:1.25rem;font-weight:800;color:#0f172a;margin:0 0 8px;">You\'re invited as Branch Manager! 🧑‍💼</h2>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          You have been invited to join <strong>' . htmlspecialchars($businessName) . '</strong> as a <strong>Branch Manager</strong>.
          As Manager, you will be able to manage staff, cashiers, and branch operations.
          Click the button below to set up your username and password.
        </p>
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $registerLink . '" style="display:inline-block;background:linear-gradient(135deg,#064e3b,#059669);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(5,150,105,.3);">Set Up My Manager Account →</a>
        </div>
        <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#065f46;font-size:.82rem;margin:0;line-height:1.6;">🔒 This link will expire in <strong>24 hours</strong>.<br>If you did not expect this email, you can safely ignore it.</p>
        </div>
        <p style="color:#94a3b8;font-size:.76rem;word-break:break-all;">Or copy this link: <a href="' . $registerLink . '" style="color:#059669;">' . $registerLink . '</a></p>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">© ' . date('Y') . ' PawnHub · All rights reserved<br>This is an automated message, please do not reply.</p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, 'PawnHub — Manager Invitation from ' . $businessName, $html);
}

function sendManagerWelcome(string $toEmail, string $toName, string $businessName, string $slug): bool
{
    $homeLink  = APP_URL . '/' . urlencode($slug);              // → public home page
    $loginLink = APP_URL . '/' . urlencode($slug) . '?login=1'; // → login page (bookmark tip)

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#064e3b,#059669);padding:32px 36px;text-align:center;">
        <span style="font-size:1.4rem;font-weight:800;color:#fff;">' . htmlspecialchars($businessName) . '</span>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:6px 0 0;">PawnHub — Your Manager Account is Ready</p>
      </div>
      <div style="padding:36px;">
        <h2 style="font-size:1.25rem;font-weight:800;color:#0f172a;margin:0 0 8px;">Welcome, Branch Manager! 🚀</h2>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Your <strong>Manager</strong> account for <strong>' . htmlspecialchars($businessName) . '</strong> is ready. Click the button below to visit the shop.
        </p>
        <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:24px;">
          <p style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin:0 0 8px;">Your Branch</p>
          <p style="font-size:.9rem;font-weight:700;color:#0f172a;margin:0 0 14px;">' . htmlspecialchars($businessName) . '</p>
          <a href="' . $homeLink . '" style="display:inline-block;background:linear-gradient(135deg,#064e3b,#059669);color:#fff;text-decoration:none;padding:12px 28px;border-radius:9px;font-size:.88rem;font-weight:700;">Visit My Shop →</a>
        </div>
        <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#065f46;font-size:.82rem;margin:0;line-height:1.7;">🔖 <strong>Tip:</strong> Bookmark your login link for quick access next time:<br><a href="' . $loginLink . '" style="color:#059669;">' . $loginLink . '</a></p>
        </div>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">© ' . date('Y') . ' PawnHub · All rights reserved<br>This is an automated message, please do not reply.</p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, 'PawnHub — Your Manager Account is Ready at ' . $businessName, $html);
}

// ─────────────────────────────────────────────────────────────────────────────
// SUBSCRIPTION EMAILS
// ─────────────────────────────────────────────────────────────────────────────

function sendSubscriptionExpiring(
    string $toEmail,
    string $toName,
    string $businessName,
    string $plan,
    string $expiryDate,
    int    $daysLeft,
    string $slug
): bool {
    $loginLink     = APP_URL . '/' . urlencode($slug) . '?login=1';
    $formatted     = date('F d, Y', strtotime($expiryDate));
    $dayWord       = $daysLeft === 1 ? '1 day' : "{$daysLeft} days";
    $urgencyColor  = $daysLeft <= 1 ? '#dc2626' : ($daysLeft <= 3 ? '#ea580c' : '#d97706');
    $urgencyBg     = $daysLeft <= 1 ? '#fef2f2' : ($daysLeft <= 3 ? '#fff7ed' : '#fffbeb');
    $urgencyBorder = $daysLeft <= 1 ? '#fecaca' : ($daysLeft <= 3 ? '#fed7aa' : '#fde68a');
    $subject       = $daysLeft <= 1
        ? "⚠️ URGENT: Your PawnHub subscription expires TOMORROW!"
        : "⏰ Your PawnHub subscription expires in {$dayWord} — {$businessName}";

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Multi-Tenant Pawnshop Management</p>
      </div>
      <div style="padding:36px;">
        <div style="background:' . $urgencyBg . ';border:1px solid ' . $urgencyBorder . ';border-radius:12px;padding:18px 20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2rem;margin:0 0 6px;">⏰</p>
          <p style="color:' . $urgencyColor . ';font-size:1.1rem;font-weight:800;margin:0 0 4px;">Subscription Expiring in ' . $dayWord . '</p>
          <p style="color:' . $urgencyColor . ';font-size:.84rem;margin:0;">Expires on <strong>' . $formatted . '</strong></p>
        </div>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          This is a reminder that your <strong>' . htmlspecialchars($businessName) . '</strong> subscription
          (<strong>' . htmlspecialchars($plan) . ' Plan</strong>) will expire in
          <strong style="color:' . $urgencyColor . ';">' . $dayWord . '</strong>.<br><br>
          To avoid any interruption to your service, please renew your subscription as soon as possible.
        </p>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
          <p style="color:#334155;font-size:.85rem;font-weight:700;margin:0 0 10px;">📋 How to Renew:</p>
          <ol style="color:#475569;font-size:.84rem;line-height:1.9;margin:0;padding-left:18px;">
            <li>Sign in to your dashboard</li>
            <li>Click <strong>Subscription</strong> in the sidebar</li>
            <li>Click <strong>Request Renewal</strong></li>
            <li>Fill in your payment details and submit</li>
            <li>Wait for Super Admin confirmation (usually within 24 hours)</li>
          </ol>
        </div>
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $loginLink . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.3);">
            Go to My Dashboard →
          </a>
        </div>
        <p style="color:#94a3b8;font-size:.76rem;text-align:center;">
          Or visit: <a href="' . $loginLink . '" style="color:#2563eb;">' . $loginLink . '</a>
        </p>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, $subject, $html);
}

function sendSubscriptionExpired(
    string $toEmail,
    string $toName,
    string $businessName,
    string $plan,
    string $slug
): bool {
    $loginLink = APP_URL . '/' . urlencode($slug) . '?login=1';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#7f1d1d,#dc2626);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:rgba(255,255,255,.2);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.7);font-size:.85rem;margin:0;">Subscription Notice</p>
      </div>
      <div style="padding:36px;">
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:18px 20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2rem;margin:0 0 6px;">🔴</p>
          <p style="color:#dc2626;font-size:1.1rem;font-weight:800;margin:0;">Your Subscription Has Expired</p>
        </div>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Your <strong>' . htmlspecialchars($businessName) . '</strong> subscription
          (<strong>' . htmlspecialchars($plan) . ' Plan</strong>) on PawnHub has <strong style="color:#dc2626;">expired</strong>.<br><br>
          Your account is now in limited access mode. You have a short grace period to renew before your account is fully locked.
          Please renew your subscription to restore full access.
        </p>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
          <p style="color:#334155;font-size:.85rem;font-weight:700;margin:0 0 10px;">📋 How to Renew:</p>
          <ol style="color:#475569;font-size:.84rem;line-height:1.9;margin:0;padding-left:18px;">
            <li>Sign in to your dashboard</li>
            <li>Click <strong>Subscription</strong> in the sidebar</li>
            <li>Click <strong>Request Renewal</strong></li>
            <li>Fill in your payment details and submit</li>
            <li>Wait for Super Admin confirmation (usually within 24 hours)</li>
          </ol>
        </div>
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $loginLink . '" style="display:inline-block;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(220,38,38,.3);">
            Renew My Subscription →
          </a>
        </div>
        <p style="color:#94a3b8;font-size:.76rem;text-align:center;">
          Need help? Contact us at <a href="mailto:' . MAIL_FROM . '" style="color:#2563eb;">' . MAIL_FROM . '</a>
        </p>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, '🔴 Your PawnHub Subscription for ' . $businessName . ' Has Expired', $html);
}

function sendSubscriptionRenewed(
    string $toEmail,
    string $toName,
    string $businessName,
    string $plan,
    string $newExpiryDate,
    string $slug
): bool {
    $loginLink = APP_URL . '/' . urlencode($slug) . '?login=1';
    $formatted = date('F d, Y', strtotime($newExpiryDate));

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Multi-Tenant Pawnshop Management</p>
      </div>
      <div style="padding:36px;">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:18px 20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2rem;margin:0 0 6px;">✅</p>
          <p style="color:#15803d;font-size:1.1rem;font-weight:800;margin:0 0 4px;">Subscription Renewed!</p>
          <p style="color:#15803d;font-size:.84rem;margin:0;">Valid until <strong>' . $formatted . '</strong></p>
        </div>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Great news! Your <strong>' . htmlspecialchars($businessName) . '</strong> subscription
          (<strong>' . htmlspecialchars($plan) . ' Plan</strong>) has been successfully renewed.<br><br>
          Your account is now fully active and valid until <strong style="color:#15803d;">' . $formatted . '</strong>.
          Thank you for continuing with PawnHub!
        </p>
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $loginLink . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.3);">
            Go to My Dashboard →
          </a>
        </div>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, '✅ PawnHub Subscription Renewed — ' . $businessName, $html);
}

function sendRenewalRequestReceived(
    string $toEmail,
    string $toName,
    string $businessName,
    string $plan,
    string $slug
): bool {
    $loginLink = APP_URL . '/' . urlencode($slug) . '?login=1';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Multi-Tenant Pawnshop Management</p>
      </div>
      <div style="padding:36px;">
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:18px 20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2rem;margin:0 0 6px;">📋</p>
          <p style="color:#1d4ed8;font-size:1.1rem;font-weight:800;margin:0 0 4px;">Renewal Request Received</p>
          <p style="color:#1d4ed8;font-size:.84rem;margin:0;">We are reviewing your request</p>
        </div>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          We have received your renewal request for <strong>' . htmlspecialchars($businessName) . '</strong>
          (<strong>' . htmlspecialchars($plan) . ' Plan</strong>).<br><br>
          Our admin will review your payment and activate your subscription within <strong>24 hours</strong>.
          We will send you a confirmation email once it is approved.
        </p>
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $loginLink . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.3);">
            Go to My Dashboard →
          </a>
        </div>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, 'PawnHub — Renewal Request Received for ' . $businessName, $html);
}
// ─────────────────────────────────────────────────────────────────────────────
// Walk-in Applicant Approved — notifies applicant they can now log in
// ─────────────────────────────────────────────────────────────────────────────

function sendApplicantApproved(
    string $toEmail,
    string $toName,
    string $businessName,
    string $role,
    string $slug
): bool {
    $loginLink = APP_URL . '/tenant_login.php?slug=' . urlencode($slug);
    $roleLabel = ucfirst($role);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">

      <!-- Header -->
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Multi-Tenant Pawnshop Management</p>
      </div>

      <!-- Body -->
      <div style="padding:36px;">

        <!-- Green approved badge -->
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2.2rem;margin:0 0 6px;">🎉</p>
          <p style="color:#15803d;font-size:1.15rem;font-weight:800;margin:0 0 4px;">Application Approved!</p>
          <p style="color:#16a34a;font-size:.84rem;margin:0;">You can now log in to <strong>' . htmlspecialchars($businessName) . '</strong></p>
        </div>

        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Great news! Your walk-in application to join
          <strong>' . htmlspecialchars($businessName) . '</strong> as
          <strong>' . htmlspecialchars($roleLabel) . '</strong> has been
          <strong style="color:#15803d;">approved</strong>.<br><br>
          Your account is now active. Use the username and password you registered with to log in.
        </p>

        <!-- Info box -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
          <p style="color:#334155;font-size:.84rem;margin:0;line-height:1.8;">
            🏪 <strong>Branch:</strong> ' . htmlspecialchars($businessName) . '<br>
            👤 <strong>Your Role:</strong> ' . htmlspecialchars($roleLabel) . '<br>
            📧 <strong>Email:</strong> ' . htmlspecialchars($toEmail) . '
          </p>
        </div>

        <!-- Login button -->
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $loginLink . '"
             style="display:inline-block;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;text-decoration:none;padding:14px 40px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(22,163,74,.35);">
            Go to Login Page →
          </a>
        </div>

        <p style="color:#94a3b8;font-size:.76rem;text-align:center;word-break:break-all;">
          Or copy this link: <a href="' . $loginLink . '" style="color:#2563eb;">' . $loginLink . '</a>
        </p>
      </div>

      <!-- Footer -->
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>

    </div></body></html>';

    return sendMail($toEmail, $toName, '🎉 Your Application to ' . $businessName . ' Has Been Approved!', $html);
}
// ─────────────────────────────────────────────────────────────────────────────
// SUPER ADMIN INVITATION — sent when original SA adds a new Super Admin
// The new SA must click the link to set up their own password.
// ─────────────────────────────────────────────────────────────────────────────

function sendSuperAdminInvitation(string $toEmail, string $toName, string $username, string $token): bool
{
    $link = APP_URL . '/sa_setup_password.php?token=' . urlencode($token);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">

      <!-- Header -->
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Super Admin Invitation</p>
      </div>

      <!-- Body -->
      <div style="padding:36px;">
        <div style="background:#f3e8ff;border:1px solid #d8b4fe;border-radius:12px;padding:18px 20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2rem;margin:0 0 6px;">🛡️</p>
          <p style="color:#6d28d9;font-size:1.1rem;font-weight:800;margin:0 0 4px;">You\'ve Been Invited as Super Admin</p>
          <p style="color:#7c3aed;font-size:.84rem;margin:0;">Full system access · Trusted account</p>
        </div>

        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          You have been invited to join <strong>PawnHub</strong> as a <strong>Super Admin</strong>.<br><br>
          Click the button below to <strong>set up your username and password</strong> and activate your account.
          This link expires in <strong>24 hours</strong>.
        </p>

        <div style="text-align:center;margin:28px 0;">
          <a href="' . $link . '" style="display:inline-block;background:linear-gradient(135deg,#4338ca,#7c3aed);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(109,40,217,.35);">
            Set Up My Password →
          </a>
        </div>

        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#92400e;font-size:.82rem;margin:0;line-height:1.6;">
            ⚠️ <strong>Important:</strong> Do not share this link with anyone.<br>
            If you did not expect this invitation, please ignore this email.<br>
            This link will expire on <strong>' . date('F j, Y \a\t g:i A', strtotime('+24 hours')) . '</strong>.
          </p>
        </div>

        <p style="color:#94a3b8;font-size:.76rem;word-break:break-all;">
          Or copy this link: <a href="' . $link . '" style="color:#2563eb;">' . $link . '</a>
        </p>
      </div>

      <!-- Footer -->
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>

    </div></body></html>';

    return sendMail($toEmail, $toName, '🛡️ PawnHub — You\'re Invited as Super Admin', $html);
}


// ─────────────────────────────────────────────────────────────────────────────
// SUPER ADMIN ACCOUNT READY — sent after new SA sets their password
// ─────────────────────────────────────────────────────────────────────────────

function sendSuperAdminAccountReady(string $toEmail, string $toName, string $username): bool
{
    $loginLink = APP_URL . '/login.php';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">

      <!-- Header -->
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Super Admin Portal</p>
      </div>

      <!-- Body -->
      <div style="padding:36px;">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:18px 20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2rem;margin:0 0 6px;">🛡️✅</p>
          <p style="color:#15803d;font-size:1.1rem;font-weight:800;margin:0 0 4px;">Your Account is Ready!</p>
          <p style="color:#16a34a;font-size:.84rem;margin:0;">Super Admin access activated</p>
        </div>

        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Your <strong>PawnHub Super Admin</strong> account has been successfully activated.<br>
          Your username is: <strong style="font-family:monospace;color:#1e3a8a;">' . htmlspecialchars($username) . '</strong><br><br>
          You can now log in to the Super Admin portal anytime using the button below.
        </p>

        <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:24px;">
          <p style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin:0 0 8px;">Your Login Details</p>
          <p style="font-size:.88rem;color:#0f172a;margin:0 0 4px;">👤 Username: <strong style="font-family:monospace;">' . htmlspecialchars($username) . '</strong></p>
          <p style="font-size:.88rem;color:#0f172a;margin:0;">🌐 Portal: <a href="' . $loginLink . '" style="color:#2563eb;">' . $loginLink . '</a></p>
        </div>

        <div style="text-align:center;margin:28px 0;">
          <a href="' . $loginLink . '" style="display:inline-block;background:linear-gradient(135deg,#4338ca,#7c3aed);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(109,40,217,.35);">
            Go to Super Admin Login →
          </a>
        </div>

        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#92400e;font-size:.82rem;margin:0;line-height:1.6;">
            🔒 <strong>Keep your credentials safe.</strong><br>
            Never share your password with anyone. If you suspect unauthorized access, contact the system administrator immediately.
          </p>
        </div>
      </div>

      <!-- Footer -->
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>

    </div></body></html>';

    return sendMail($toEmail, $toName, '🛡️ PawnHub — Your Super Admin Account is Ready', $html);
}
// ─────────────────────────────────────────────────────────────────────────────
// SUPER ADMIN PASSWORD RESET — sent when SA requests forgot password
// ─────────────────────────────────────────────────────────────────────────────

function sendSuperAdminPasswordReset(string $toEmail, string $toName, string $username, string $token): bool
{
    $link = APP_URL . '/sa_setup_password.php?token=' . urlencode($token);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">

      <!-- Header -->
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Super Admin Password Reset</p>
      </div>

      <!-- Body -->
      <div style="padding:36px;">
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:18px 20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2rem;margin:0 0 6px;">🔑</p>
          <p style="color:#1d4ed8;font-size:1.1rem;font-weight:800;margin:0 0 4px;">Password Reset Request</p>
          <p style="color:#2563eb;font-size:.84rem;margin:0;">Super Admin Portal</p>
        </div>

        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          We received a request to reset the password for your Super Admin account.<br>
          Your username is: <strong style="font-family:monospace;color:#1e3a8a;">' . htmlspecialchars($username) . '</strong><br><br>
          Click the button below to set a new password. This link expires in <strong>1 hour</strong>.
        </p>

        <div style="text-align:center;margin:28px 0;">
          <a href="' . $link . '" style="display:inline-block;background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(30,58,138,.35);">
            Reset My Password →
          </a>
        </div>

        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#92400e;font-size:.82rem;margin:0;line-height:1.6;">
            ⚠️ <strong>If you did not request this reset, ignore this email.</strong><br>
            Your password will remain unchanged. This link expires in <strong>1 hour</strong>.<br>
            If you are concerned, contact your system administrator immediately.
          </p>
        </div>

        <p style="color:#94a3b8;font-size:.76rem;word-break:break-all;">
          Or copy this link: <a href="' . $link . '" style="color:#2563eb;">' . $link . '</a>
        </p>
      </div>

      <!-- Footer -->
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>

    </div></body></html>';

    return sendMail($toEmail, $toName, '🔑 PawnHub — Super Admin Password Reset', $html);
}

// ─────────────────────────────────────────────────────────────────────────────
// sendSubscriptionAutoDeactivated — sent when tenant is auto-deactivated after
// 7 days of expired subscription
// ─────────────────────────────────────────────────────────────────────────────
function sendSubscriptionAutoDeactivated(
    string $toEmail,
    string $toName,
    string $businessName,
    string $plan,
    string $slug,
    int    $tenantId = 0
): bool {
    $loginLink       = APP_URL . '/' . urlencode($slug) . '?login=1';
    $reactivateBase  = APP_URL . '/paymongo_reactivate.php?tenant=' . $tenantId;

    // ── Plan cards with direct PayMongo reactivation links ───────────────────
    $plans = [
        'Starter'    => ['price' => 'Free',      'centavos' => 0,      'color' => '#0f172a', 'bg' => '#f8fafc', 'border' => '#e2e8f0', 'badge_bg' => '#e2e8f0',    'badge_color' => '#334155', 'features' => 'Basic features · Up to 1 branch'],
        'Pro'        => ['price' => '₱999/mo',   'centavos' => 99900,  'color' => '#1d4ed8', 'bg' => '#eff6ff', 'border' => '#bfdbfe', 'badge_bg' => '#dbeafe',    'badge_color' => '#1e40af', 'features' => 'Full features · Up to 3 branches'],
        'Enterprise' => ['price' => '₱2,499/mo', 'centavos' => 249900, 'color' => '#6d28d9', 'bg' => '#f5f3ff', 'border' => '#ddd6fe', 'badge_bg' => '#ede9fe',    'badge_color' => '#5b21b6', 'features' => 'Unlimited branches · Priority support'],
    ];

    $plan_cards = '';
    foreach ($plans as $plan_name => $p) {
        $is_current  = ($plan_name === $plan);
        $current_tag = $is_current ? '<span style="display:inline-block;background:#fef9c3;border:1px solid #fde047;border-radius:6px;padding:2px 8px;font-size:.72rem;font-weight:700;color:#713f12;margin-left:6px;">Current Plan</span>' : '';
        $link_url    = $plan_name === 'Starter'
            ? $reactivateBase . '&plan=Starter'
            : $reactivateBase . '&plan=' . urlencode($plan_name);

        $btn_style = $plan_name === 'Starter'
            ? 'background:#334155;color:#fff;'
            : ($plan_name === 'Enterprise'
                ? 'background:linear-gradient(135deg,#4c1d95,#6d28d9);color:#fff;'
                : 'background:linear-gradient(135deg,#1e40af,#2563eb);color:#fff;');

        $plan_cards .= '
        <div style="background:' . $p['bg'] . ';border:2px solid ' . ($is_current ? $p['color'] : $p['border']) . ';border-radius:12px;padding:16px 18px;margin-bottom:12px;">
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
            <div>
              <span style="font-size:.95rem;font-weight:800;color:' . $p['color'] . ';">' . $plan_name . ' Plan</span>' . $current_tag . '
              <p style="color:#64748b;font-size:.78rem;margin:3px 0 0;">' . $p['features'] . '</p>
            </div>
            <span style="background:' . $p['badge_bg'] . ';color:' . $p['badge_color'] . ';border-radius:8px;padding:4px 12px;font-size:.85rem;font-weight:800;">' . $p['price'] . '</span>
          </div>
          <a href="' . $link_url . '"
             style="display:block;text-align:center;' . $btn_style . 'text-decoration:none;padding:10px;border-radius:8px;font-size:.84rem;font-weight:700;">
            ' . ($plan_name === 'Starter' ? 'Reactivate Free →' : 'Pay &amp; Reactivate →') . '
          </a>
        </div>';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:580px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">

      <!-- Header -->
      <div style="background:linear-gradient(135deg,#450a0a,#7f1d1d);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Account Notice</p>
      </div>

      <!-- Body -->
      <div style="padding:36px;">
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:18px 20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2rem;margin:0 0 6px;">🔒</p>
          <p style="color:#dc2626;font-size:1.1rem;font-weight:800;margin:0 0 4px;">Account Auto-Deactivated</p>
          <p style="color:#b91c1c;font-size:.84rem;margin:0;">Subscription expired — 7-day grace period has passed</p>
        </div>

        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Your <strong>' . htmlspecialchars($businessName) . '</strong> account
          (<strong>' . htmlspecialchars($plan) . ' Plan</strong>) has been
          <strong style="color:#dc2626;">automatically deactivated</strong> because your subscription
          expired more than 7 days ago and was not renewed.<br><br>
          All staff and cashier accounts under your branch have also been suspended.
          Choose a plan below to reactivate your account immediately.
        </p>

        <!-- Plan Selection -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:24px;">
          <p style="color:#334155;font-size:.85rem;font-weight:700;margin:0 0 14px;">🔄 Choose a Plan to Reactivate:</p>
          ' . $plan_cards . '
          <p style="color:#94a3b8;font-size:.76rem;margin:8px 0 0;text-align:center;">
            Not sure? <a href="' . $reactivateBase . '" style="color:#2563eb;">View all plan details →</a>
          </p>
        </div>

        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px 18px;margin-bottom:24px;">
          <p style="color:#991b1b;font-size:.82rem;margin:0;line-height:1.6;">
            ⚠️ <strong>Your data is safe.</strong> All your records and transaction history are preserved.
            Reactivating your account will immediately restore access for you and your staff.
          </p>
        </div>

        <p style="color:#94a3b8;font-size:.76rem;text-align:center;">
          Need help? Contact us at <a href="mailto:' . MAIL_FROM . '" style="color:#2563eb;">' . MAIL_FROM . '</a>
        </p>
      </div>

      <!-- Footer -->
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>

    </div></body></html>';

    return sendMail($toEmail, $toName, '🔒 PawnHub — ' . $businessName . ' Account Has Been Deactivated', $html);
}

// ─────────────────────────────────────────────────────────────────────────────
// sendPermitApproved — SA manually approved the business permit
// ─────────────────────────────────────────────────────────────────────────────
function sendPermitApproved(string $toEmail, string $toName, string $businessName, string $slug): bool
{
    $loginLink = $slug ? APP_URL . '/' . urlencode($slug) . '?login=1' : APP_URL . '/login.php';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Multi-Tenant Pawnshop Management</p>
      </div>
      <div style="padding:36px;">
        <h2 style="font-size:1.25rem;font-weight:800;color:#0f172a;margin:0 0 8px;">Business Permit Approved ✅</h2>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Great news! Your Business Permit for <strong>' . htmlspecialchars($businessName) . '</strong>
          has been reviewed and <strong style="color:#15803d;">approved</strong> by our Super Admin.<br><br>
          Your account remains fully active. Thank you for providing a valid permit!
        </p>
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $loginLink . '" style="display:inline-block;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(22,163,74,.3);">
            Go to My Dashboard →
          </a>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#15803d;font-size:.82rem;margin:0;line-height:1.6;">
            ✅ Your Business Permit is now verified and on file. No further action is needed from your end.
          </p>
        </div>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, '✅ PawnHub — Business Permit Approved for ' . $businessName, $html);
}

// ─────────────────────────────────────────────────────────────────────────────
// sendPermitRejected — SA rejected the permit → account deactivated
// ─────────────────────────────────────────────────────────────────────────────
function sendPermitRejected(string $toEmail, string $toName, string $businessName, string $reason): bool
{
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <div style="background:linear-gradient(135deg,#7f1d1d,#991b1b);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#ef4444,#dc2626);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Business Permit Review</p>
      </div>
      <div style="padding:36px;">
        <h2 style="font-size:1.25rem;font-weight:800;color:#991b1b;margin:0 0 8px;">Business Permit Rejected ❌</h2>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          We regret to inform you that your Business Permit submitted for
          <strong>' . htmlspecialchars($businessName) . '</strong> has been
          <strong style="color:#dc2626;">rejected</strong> upon review by our Super Admin,
          and your account has been <strong style="color:#dc2626;">deactivated</strong>.
        </p>
        <div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;padding:16px 18px;margin-bottom:20px;">
          <p style="color:#991b1b;font-size:.83rem;font-weight:700;margin:0 0 6px;">Reason for Rejection:</p>
          <p style="color:#7f1d1d;font-size:.85rem;margin:0;line-height:1.6;">' . htmlspecialchars($reason) . '</p>
        </div>
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#92400e;font-size:.82rem;margin:0;line-height:1.6;">
            ⚠️ <strong>Per our Terms &amp; Conditions</strong>, submitting a fake or expired Business Permit
            results in immediate account deactivation <strong>without refund</strong>.<br><br>
            If you believe this is an error, please contact our support team at
            <a href="mailto:' . MAIL_FROM . '" style="color:#2563eb;">' . MAIL_FROM . '</a>.
          </p>
        </div>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, '❌ PawnHub — Business Permit Rejected for ' . $businessName, $html);
}


// ────────────────────────────────────────────────────────────────────────────
// sendPaymentLink — SA-initiated: sends PayMongo checkout link + QR to tenant
// ────────────────────────────────────────────────────────────────────────────
function sendPaymentLink(
    string  $toEmail,
    string  $toName,
    string  $businessName,
    string  $plan,
    string  $checkoutUrl,
    ?string $qrDataUri,
    float   $amountPesos
): bool {
    $amount_fmt = '₱' . number_format($amountPesos, 2);
    $plan_label = htmlspecialchars($plan);

    // QR code block — embedded inline if we have the data URI, else show link only
    $qr_block = $qrDataUri
        ? '<div style="text-align:center;margin:24px 0 8px;">
             <img src="' . $qrDataUri . '" alt="Payment QR Code"
                  style="width:220px;height:220px;border:4px solid #e2e8f0;border-radius:12px;"/>
             <p style="color:#64748b;font-size:.78rem;margin:6px 0 0;">
               Scan this QR code with GCash, Maya, or your banking app to pay
             </p>
           </div>'
        : '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin:20px 0;text-align:center;">
             <p style="color:#1d4ed8;font-size:.85rem;margin:0;">
               ⚠️ QR code unavailable — please use the button below to pay online.
             </p>
           </div>';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:580px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">

      <!-- Header -->
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Multi-Tenant Pawnshop Management</p>
      </div>

      <!-- Body -->
      <div style="padding:36px;">
        <h2 style="font-size:1.2rem;font-weight:800;color:#0f172a;margin:0 0 8px;">
          Complete Your Subscription Payment 💳
        </h2>
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Your PawnHub account for <strong>' . htmlspecialchars($businessName) . '</strong>
          is ready! Please complete your <strong>' . $plan_label . ' Plan</strong> subscription payment
          to activate your account.
        </p>

        <!-- Amount card -->
        <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px 22px;margin-bottom:20px;text-align:center;">
          <p style="color:#64748b;font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin:0 0 4px;">Amount Due</p>
          <p style="color:#0f172a;font-size:2rem;font-weight:900;margin:0;">' . $amount_fmt . '</p>
          <p style="color:#94a3b8;font-size:.78rem;margin:4px 0 0;">' . $plan_label . ' Plan — Monthly Subscription</p>
        </div>

        <!-- QR code -->
        ' . $qr_block . '

        <!-- Payment button -->
        <div style="text-align:center;margin:24px 0;">
          <a href="' . htmlspecialchars($checkoutUrl) . '"
             style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;
                    text-decoration:none;padding:16px 40px;border-radius:10px;font-size:1rem;
                    font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.35);">
            Pay Now via PayMongo →
          </a>
        </div>

        <!-- Accepted methods -->
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#166534;font-size:.82rem;margin:0;line-height:1.8;">
            ✅ <strong>Accepted payment methods:</strong><br>
            GCash &nbsp;·&nbsp; Maya (PayMaya) &nbsp;·&nbsp; Credit / Debit Card &nbsp;·&nbsp; Online Banking (BPI &amp; more) &nbsp;·&nbsp; Billease
          </p>
        </div>

        <!-- Steps -->
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#1d4ed8;font-size:.82rem;margin:0;line-height:1.9;">
            <strong>What happens after payment?</strong><br>
            1️⃣ &nbsp;Payment is confirmed automatically<br>
            2️⃣ &nbsp;Our admin reviews and approves your account (within 24 hours)<br>
            3️⃣ &nbsp;You receive your login credentials by email ✅
          </p>
        </div>

        <!-- Link fallback -->
        <p style="color:#94a3b8;font-size:.75rem;word-break:break-all;">
          Or copy this payment link:<br>
          <a href="' . htmlspecialchars($checkoutUrl) . '" style="color:#2563eb;">'
          . htmlspecialchars($checkoutUrl) . '</a>
        </p>
      </div>

      <!-- Footer -->
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, "PawnHub — Complete Your {$plan} Plan Payment", $html);
}

// ─────────────────────────────────────────────────────────────────────────────
// FREE TRIAL FUNCTIONS (Starter Plan)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * sendFreeTrialExpiring()
 * Sent at 7, 3, and 1 day(s) before the Starter free trial ends.
 * Reminds the tenant to upgrade to Pro or Enterprise.
 */
function sendFreeTrialExpiring(
    string $toEmail,
    string $toName,
    string $businessName,
    string $expiryDate,
    int    $daysLeft,
    string $slug
): bool {
    $loginLink    = APP_URL . '/' . urlencode($slug) . '?login=1';
    $upgradeLink  = APP_URL . '/' . urlencode($slug) . '?login=1';
    $formatted    = date('F d, Y', strtotime($expiryDate));
    $dayWord      = $daysLeft === 1 ? '1 day' : "{$daysLeft} days";

    if ($daysLeft <= 1) {
        $urgencyColor  = '#dc2626';
        $urgencyBg     = '#fef2f2';
        $urgencyBorder = '#fecaca';
        $urgencyIcon   = '🚨';
        $urgencyLabel  = 'Your Free Trial Ends TODAY!';
        $subject       = "🚨 URGENT: Your PawnHub free trial ends TODAY — {$businessName}";
    } elseif ($daysLeft <= 3) {
        $urgencyColor  = '#ea580c';
        $urgencyBg     = '#fff7ed';
        $urgencyBorder = '#fed7aa';
        $urgencyIcon   = '⚠️';
        $urgencyLabel  = "Free Trial Ends in {$dayWord}!";
        $subject       = "⚠️ Your PawnHub free trial ends in {$dayWord} — {$businessName}";
    } else {
        $urgencyColor  = '#d97706';
        $urgencyBg     = '#fffbeb';
        $urgencyBorder = '#fde68a';
        $urgencyIcon   = '⏳';
        $urgencyLabel  = "Free Trial Ends in {$dayWord}";
        $subject       = "⏳ Your PawnHub free trial ends in {$dayWord} — {$businessName}";
    }

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">

      <!-- Header -->
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.85rem;margin:0;">Free Trial Notice</p>
      </div>

      <div style="padding:36px;">

        <!-- Urgency box -->
        <div style="background:' . $urgencyBg . ';border:1px solid ' . $urgencyBorder . ';border-radius:12px;padding:18px 20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2rem;margin:0 0 6px;">' . $urgencyIcon . '</p>
          <p style="color:' . $urgencyColor . ';font-size:1.1rem;font-weight:800;margin:0 0 4px;">' . $urgencyLabel . '</p>
          <p style="color:' . $urgencyColor . ';font-size:.84rem;margin:0;">Trial expires on <strong>' . $formatted . '</strong></p>
        </div>

        <!-- Body copy -->
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Your <strong>1-month free trial</strong> for <strong>' . htmlspecialchars($businessName) . '</strong>
          on PawnHub will end in <strong style="color:' . $urgencyColor . ';">' . $dayWord . '</strong>.<br><br>
          After your trial ends, your account will be <strong>locked</strong> until you upgrade
          to a paid plan. Upgrade now to keep full access to your dashboard and all your data.
        </p>

        <!-- Plan comparison -->
        <div style="display:flex;gap:12px;margin-bottom:24px;">
          <div style="flex:1;background:linear-gradient(135deg,#eff6ff,#dbeafe);border:2px solid #2563eb;border-radius:14px;padding:18px 14px;text-align:center;position:relative;">
            <div style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:#2563eb;color:#fff;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;padding:2px 10px;border-radius:100px;white-space:nowrap;">⭐ Most Popular</div>
            <div style="font-size:.9rem;font-weight:800;color:#1e40af;margin-bottom:6px;">Pro Plan</div>
            <div style="font-size:1.5rem;font-weight:800;color:#1d4ed8;line-height:1.1;">₱999<span style="font-size:.75rem;font-weight:500;color:#60a5fa;">/mo</span></div>
            <div style="font-size:.72rem;color:#3b82f6;margin-top:8px;line-height:1.5;">Unlimited staff · Advanced reports · Custom branding · Priority support</div>
          </div>
          <div style="flex:1;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:14px;padding:18px 14px;text-align:center;">
            <div style="font-size:.9rem;font-weight:800;color:#334155;margin-bottom:6px;">Enterprise</div>
            <div style="font-size:1.5rem;font-weight:800;color:#1e293b;line-height:1.1;">₱2,499<span style="font-size:.75rem;font-weight:500;color:#94a3b8;">/mo</span></div>
            <div style="font-size:.72rem;color:#64748b;margin-top:8px;line-height:1.5;">Multi-branch · White-label · Data export · Dedicated account manager</div>
          </div>
        </div>

        <!-- How to upgrade -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
          <p style="color:#334155;font-size:.85rem;font-weight:700;margin:0 0 10px;">🚀 How to Upgrade:</p>
          <ol style="color:#475569;font-size:.84rem;line-height:1.9;margin:0;padding-left:18px;">
            <li>Sign in to your dashboard</li>
            <li>Go to <strong>Subscription</strong> in the sidebar</li>
            <li>Choose <strong>Pro</strong> or <strong>Enterprise</strong></li>
            <li>Pay via GCash, Maya, Card, or Online Banking</li>
            <li>Your account is upgraded <strong>instantly</strong> after payment ✅</li>
          </ol>
        </div>

        <!-- CTA button -->
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $loginLink . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.3);">
            🚀 Upgrade My Plan Now →
          </a>
        </div>

        <p style="color:#94a3b8;font-size:.76rem;text-align:center;">
          Or visit: <a href="' . $loginLink . '" style="color:#2563eb;">' . $loginLink . '</a>
        </p>
      </div>

      <!-- Footer -->
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, $subject, $html);
}


/**
 * sendFreeTrialExpired()
 * Sent on the day the Starter free trial ends (subscription_end passed).
 * Informs the tenant their account is now locked and they must upgrade.
 */
function sendFreeTrialExpired(
    string $toEmail,
    string $toName,
    string $businessName,
    string $slug
): bool {
    $loginLink = APP_URL . '/' . urlencode($slug) . '?login=1';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">

      <!-- Header — amber/orange theme for trial ended -->
      <div style="background:linear-gradient(135deg,#78350f,#d97706);padding:32px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:40px;height:40px;background:rgba(255,255,255,.2);border-radius:10px;display:inline-block;"></div>
          <span style="font-size:1.4rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.75);font-size:.85rem;margin:0;">Free Trial Notice</p>
      </div>

      <div style="padding:36px;">

        <!-- Alert box -->
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:18px 20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2rem;margin:0 0 6px;">⏰</p>
          <p style="color:#92400e;font-size:1.1rem;font-weight:800;margin:0 0 4px;">Your Free Trial Has Ended</p>
          <p style="color:#a16207;font-size:.84rem;margin:0;">Your account is now locked until you upgrade.</p>
        </div>

        <!-- Body copy -->
        <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
          Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
          Your <strong>1-month free trial</strong> for <strong>' . htmlspecialchars($businessName) . '</strong>
          on PawnHub has officially ended.<br><br>
          Your account has been <strong style="color:#dc2626;">temporarily locked</strong>.
          All your data is safe — simply upgrade to <strong>Pro</strong> or <strong>Enterprise</strong>
          to instantly restore access for you and your entire team.
        </p>

        <!-- What you\'re missing box -->
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#dc2626;font-size:.82rem;font-weight:700;margin:0 0 8px;">🔒 Currently unavailable until you upgrade:</p>
          <ul style="color:#7f1d1d;font-size:.82rem;line-height:1.9;margin:0;padding-left:18px;">
            <li>Staff &amp; manager dashboard access</li>
            <li>Pawn transactions &amp; inventory management</li>
            <li>Reports &amp; audit logs</li>
            <li>Customer records</li>
          </ul>
        </div>

        <!-- Plan comparison -->
        <div style="display:flex;gap:12px;margin-bottom:24px;">
          <div style="flex:1;background:linear-gradient(135deg,#eff6ff,#dbeafe);border:2px solid #2563eb;border-radius:14px;padding:18px 14px;text-align:center;position:relative;">
            <div style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:#2563eb;color:#fff;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;padding:2px 10px;border-radius:100px;white-space:nowrap;">⭐ Most Popular</div>
            <div style="font-size:.9rem;font-weight:800;color:#1e40af;margin-bottom:6px;">Pro Plan</div>
            <div style="font-size:1.5rem;font-weight:800;color:#1d4ed8;line-height:1.1;">₱999<span style="font-size:.75rem;font-weight:500;color:#60a5fa;">/mo</span></div>
            <div style="font-size:.72rem;color:#3b82f6;margin-top:8px;line-height:1.5;">Unlimited staff · Advanced reports · Custom branding · Priority support</div>
          </div>
          <div style="flex:1;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:14px;padding:18px 14px;text-align:center;">
            <div style="font-size:.9rem;font-weight:800;color:#334155;margin-bottom:6px;">Enterprise</div>
            <div style="font-size:1.5rem;font-weight:800;color:#1e293b;line-height:1.1;">₱2,499<span style="font-size:.75rem;font-weight:500;color:#94a3b8;">/mo</span></div>
            <div style="font-size:.72rem;color:#64748b;margin-top:8px;line-height:1.5;">Multi-branch · White-label · Data export · Dedicated account manager</div>
          </div>
        </div>

        <!-- CTA button -->
        <div style="text-align:center;margin:28px 0;">
          <a href="' . $loginLink . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.3);">
            🚀 Upgrade &amp; Restore Access →
          </a>
        </div>

        <!-- Reassurance -->
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#15803d;font-size:.82rem;margin:0;line-height:1.7;">
            ✅ <strong>Your data is safe.</strong> All your transactions, records, and settings
            are preserved. Upgrading instantly restores your full dashboard — no setup needed.
          </p>
        </div>

        <p style="color:#94a3b8;font-size:.76rem;text-align:center;">
          Need help? Contact us at <a href="mailto:' . MAIL_FROM . '" style="color:#2563eb;">' . MAIL_FROM . '</a>
        </p>
      </div>

      <!-- Footer -->
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · All rights reserved<br>
          This is an automated message, please do not reply.
        </p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, '⏰ Your PawnHub free trial for ' . $businessName . ' has ended — Upgrade to continue', $html);
}

// ─────────────────────────────────────────────────────────────────────────────
// SUPER ADMIN PAYMENT NOTIFICATION
// Sent to ALL Super Admin accounts whenever a payment event fires via webhook.
// Types: 'signup' | 'renewal' | 'upgrade' | 'downgrade' | 'reactivation'
// ─────────────────────────────────────────────────────────────────────────────

function sendSuperAdminPaymentNotif(
    string $saEmail,
    string $saName,
    string $businessName,
    string $ownerName,
    string $plan,
    string $type,
    float  $amount,
    string $billingCycle  = 'monthly',
    string $paymentMethod = '',
    string $newSubEnd     = '',
    int    $tenantId      = 0
): bool {
    $dashboardUrl = APP_URL . '/superadmin.php?page=subscriptions';

    $typeLabels = [
        'signup'       => ['emoji' => '🆕', 'label' => 'New Signup Payment',       'color' => '#2563eb', 'bg' => '#dbeafe', 'border' => '#bfdbfe'],
        'renewal'      => ['emoji' => '🔄', 'label' => 'Subscription Renewed',     'color' => '#15803d', 'bg' => '#dcfce7', 'border' => '#bbf7d0'],
        'upgrade'      => ['emoji' => '⬆️', 'label' => 'Plan Upgraded',            'color' => '#7c3aed', 'bg' => '#f3e8ff', 'border' => '#e9d5ff'],
        'downgrade'    => ['emoji' => '⬇️', 'label' => 'Plan Downgraded',          'color' => '#b45309', 'bg' => '#fef3c7', 'border' => '#fde68a'],
        'reactivation' => ['emoji' => '✅', 'label' => 'Account Reactivated',      'color' => '#0f766e', 'bg' => '#ccfbf1', 'border' => '#99f6e4'],
    ];

    $t         = $typeLabels[$type] ?? ['emoji' => '💳', 'label' => 'Payment Received', 'color' => '#2563eb', 'bg' => '#dbeafe', 'border' => '#bfdbfe'];
    $subject   = "[PawnHub] {$t['emoji']} {$t['label']} — {$businessName}";
    $formatted = $newSubEnd ? date('F d, Y', strtotime($newSubEnd)) : '—';
    $amountFmt = '₱' . number_format($amount, 2);

    $cycleLabels = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly (3 months)', 'annually' => 'Annual (12 months)'];
    $cycleLabel  = $cycleLabels[$billingCycle] ?? ucfirst($billingCycle);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',sans-serif;">
    <div style="max-width:580px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">

      <!-- Header -->
      <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:28px 36px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="width:36px;height:36px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:9px;display:inline-block;"></div>
          <span style="font-size:1.3rem;font-weight:800;color:#fff;">PawnHub</span>
        </div>
        <p style="color:rgba(255,255,255,.55);font-size:.8rem;margin:0;">Super Admin Notification</p>
      </div>

      <div style="padding:32px 36px;">

        <!-- Alert box -->
        <div style="background:' . $t['bg'] . ';border:1px solid ' . $t['border'] . ';border-radius:12px;padding:18px 20px;margin-bottom:24px;text-align:center;">
          <p style="font-size:2rem;margin:0 0 6px;">' . $t['emoji'] . '</p>
          <p style="color:' . $t['color'] . ';font-size:1.05rem;font-weight:800;margin:0 0 4px;">' . $t['label'] . '</p>
          <p style="color:' . $t['color'] . ';font-size:.84rem;margin:0;opacity:.85;">' . htmlspecialchars($businessName) . ' — ' . htmlspecialchars($ownerName) . '</p>
        </div>

        <!-- Payment details table -->
        <table style="width:100%;border-collapse:collapse;margin-bottom:24px;font-size:.85rem;">
          <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:10px 0;color:#64748b;font-weight:600;width:45%;">Business</td>
            <td style="padding:10px 0;color:#0f172a;font-weight:700;">' . htmlspecialchars($businessName) . '</td>
          </tr>
          <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:10px 0;color:#64748b;font-weight:600;">Owner</td>
            <td style="padding:10px 0;color:#0f172a;">' . htmlspecialchars($ownerName) . '</td>
          </tr>
          <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:10px 0;color:#64748b;font-weight:600;">Plan</td>
            <td style="padding:10px 0;"><span style="background:#fef3c7;color:#b45309;font-weight:700;padding:2px 10px;border-radius:100px;font-size:.78rem;">' . htmlspecialchars($plan) . '</span></td>
          </tr>
          <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:10px 0;color:#64748b;font-weight:600;">Billing Cycle</td>
            <td style="padding:10px 0;color:#0f172a;">' . $cycleLabel . '</td>
          </tr>
          <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:10px 0;color:#64748b;font-weight:600;">Amount Paid</td>
            <td style="padding:10px 0;color:#15803d;font-weight:800;font-size:1rem;">' . $amountFmt . '</td>
          </tr>
          ' . ($paymentMethod ? '<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0;color:#64748b;font-weight:600;">Payment Method</td><td style="padding:10px 0;color:#0f172a;">' . htmlspecialchars($paymentMethod) . '</td></tr>' : '') . '
          ' . ($newSubEnd ? '<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0;color:#64748b;font-weight:600;">New Subscription End</td><td style="padding:10px 0;color:#0f172a;font-weight:700;">' . $formatted . '</td></tr>' : '') . '
          <tr>
            <td style="padding:10px 0;color:#64748b;font-weight:600;">Time</td>
            <td style="padding:10px 0;color:#0f172a;">' . date('F d, Y · h:i A') . ' (PHT)</td>
          </tr>
        </table>

        <!-- CTA -->
        <div style="text-align:center;margin:24px 0;">
          <a href="' . $dashboardUrl . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;padding:13px 32px;border-radius:10px;font-size:.9rem;font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.3);">
            View in Dashboard →
          </a>
        </div>

        <p style="color:#94a3b8;font-size:.74rem;text-align:center;margin:0;">
          This is an automated notification from the PawnHub webhook system.
        </p>
      </div>

      <!-- Footer -->
      <div style="background:#f8fafc;padding:16px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">
          © ' . date('Y') . ' PawnHub · Super Admin Portal<br>
          You are receiving this because you are a registered Super Admin.
        </p>
      </div>
    </div></body></html>';

    return sendMail($saEmail, $saName, $subject, $html);
}