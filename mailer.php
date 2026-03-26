<?php
// ── GMAIL SETTINGS — palitan ng sarili mong Gmail ─────────────
define('MAIL_FROM',     'mendozakiaro@gmail.com');
define('MAIL_FROM_NAME','PawnHub System');
define('MAIL_USERNAME', 'mendozakiaro@gmail.com');
define('MAIL_PASSWORD', 'rsze dtfm yygd nklm');
define('APP_URL', 'https://pawnhub-bjesb8gqh5d3eqfy.southeastasia-01.azurewebsites.net');

// ── Load PHPMailer (with helpful error kung hindi pa naka-composer install) ──
$_autoload = __DIR__ . '/vendor/autoload.php';

if (!file_exists($_autoload)) {
    die('
    <div style="font-family:\'Segoe UI\',sans-serif;max-width:560px;margin:60px auto;background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;padding:28px 32px;color:#991b1b;">
        <h2 style="margin:0 0 10px;font-size:1.1rem;">⚠️ Missing Composer Dependencies</h2>
        <p style="margin:0 0 14px;font-size:.9rem;line-height:1.7;color:#7f1d1d;">
            The <code style="background:#fee2e2;padding:2px 6px;border-radius:4px;">vendor/</code> folder is missing.
            PHPMailer has not been installed yet.
        </p>
        <p style="margin:0 0 8px;font-size:.85rem;font-weight:600;">Fix: Run this command in your project folder:</p>
        <pre style="background:#1e293b;color:#e2e8f0;padding:14px 18px;border-radius:8px;font-size:.85rem;overflow-x:auto;">cd C:\xampp\htdocs\pawnshop_ab
composer install</pre>
        <p style="margin:14px 0 0;font-size:.8rem;color:#92400e;">
            💡 If Composer is not installed, download it at
            <a href="https://getcomposer.org/download/" style="color:#1d4ed8;">getcomposer.org</a>
        </p>
    </div>
    ');
}

require $_autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send email using PHPMailer + Gmail SMTP
 */
function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        die('<pre style="background:#fef2f2;padding:20px;color:red;font-size:14px;">PHPMailer Error: ' . $mail->ErrorInfo . '</pre>');
        return false;
    }
}

/**
 * Send tenant invitation email with registration link
 */
function sendTenantInvitation(string $toEmail, string $toName, string $businessName, string $token, string $slug = ''): bool
{
    $link = $slug ? APP_URL . '/' . urlencode($slug) . '?token=' . urlencode($token) : APP_URL . '/tenant_register.php?token=' . urlencode($token);

    $html = '
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"></head>
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
          <h2 style="font-size:1.25rem;font-weight:800;color:#0f172a;margin:0 0 8px;">You\'re invited! 🎉</h2>
          <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
            Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
            You have been invited to join <strong>PawnHub</strong> as the owner of
            <strong>' . htmlspecialchars($businessName) . '</strong>.
            Click the button below to set up your account and access the system.
          </p>

          <!-- CTA Button -->
          <div style="text-align:center;margin:28px 0;">
            <a href="' . $link . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:.95rem;font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.3);">
              Set Up My Account →
            </a>
          </div>

          <!-- Info box -->
          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
            <p style="color:#1d4ed8;font-size:.82rem;margin:0;line-height:1.6;">
              🔒 This link will expire in <strong>24 hours</strong>.<br>
              If you did not expect this email, you can safely ignore it.
            </p>
          </div>

          <!-- Link fallback -->
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
      </div>
    </body>
    </html>';

    return sendMail($toEmail, $toName, 'PawnHub — You\'re Invited to ' . $businessName, $html);
}

/**
 * Send approval notification email to tenant who registered via signup.php
 * No token needed — they log in using their own credentials at the site URL.
 */
function sendApprovalNotification(string $toEmail, string $toName, string $businessName, string $loginUrl): bool
{
    $html = '
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"></head>
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

          <!-- Badge -->
          <div style="display:inline-block;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:5px 12px;font-size:.78rem;font-weight:700;color:#15803d;margin-bottom:16px;">
            ✅ Application Approved
          </div>

          <h2 style="font-size:1.25rem;font-weight:800;color:#0f172a;margin:0 0 8px;">Your account is now active! 🎉</h2>
          <p style="color:#475569;font-size:.9rem;line-height:1.7;margin:0 0 20px;">
            Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
            Great news! Your PawnHub application for
            <strong>' . htmlspecialchars($businessName) . '</strong>
            has been reviewed and <strong>approved</strong> by our team.
            You can now log in using the username and password you set during registration.
          </p>

          <!-- Login URL box -->
          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:18px 20px;margin-bottom:24px;">
            <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#3b82f6;margin:0 0 8px;">Your Login URL</p>
            <p style="font-size:.92rem;font-weight:700;color:#1d4ed8;word-break:break-all;margin:0 0 16px;">
              <a href="' . $loginUrl . '" style="color:#1d4ed8;">' . $loginUrl . '</a>
            </p>
            <a href="' . $loginUrl . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;padding:13px 32px;border-radius:10px;font-size:.92rem;font-weight:700;box-shadow:0 4px 14px rgba(37,99,235,.3);">
              Log In to PawnHub →
            </a>
          </div>

          <!-- Tip box -->
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:13px 16px;margin-bottom:20px;">
            <p style="color:#15803d;font-size:.82rem;margin:0;line-height:1.7;">
              🔖 <strong>Tip:</strong> Bookmark the login URL above for quick access every time.<br>
              Use the <strong>username and password</strong> you created during sign-up — no extra steps needed.
            </p>
          </div>

          <p style="color:#94a3b8;font-size:.76rem;word-break:break-all;">
            Or copy this link: <a href="' . $loginUrl . '" style="color:#2563eb;">' . $loginUrl . '</a>
          </p>
        </div>

        <!-- Footer -->
        <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:.74rem;margin:0;">
            © ' . date('Y') . ' PawnHub · All rights reserved<br>
            This is an automated message, please do not reply.
          </p>
        </div>
      </div>
    </body>
    </html>';

    return sendMail($toEmail, $toName, 'PawnHub — Your Account for ' . $businessName . ' is Approved!', $html);
}

/**
 * EMAIL 1 — Staff/Cashier Invitation (sent by Tenant Admin)
 */
function sendStaffInvitation(string $toEmail, string $toName, string $businessName, string $role, string $token): bool
{
    $registerLink = APP_URL . '/staff_register.php?token=' . urlencode($token);
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

/**
 * EMAIL 2 — Staff/Cashier Welcome (sent after they complete registration)
 */
function sendStaffWelcome(string $toEmail, string $toName, string $businessName, string $role, string $slug): bool
{
    $loginLink = APP_URL . '/' . urlencode($slug);
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
          Your <strong>' . $roleLabel . '</strong> account for <strong>' . htmlspecialchars($businessName) . '</strong> is ready. Use the link below to sign in anytime.
        </p>
        <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:24px;">
          <p style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin:0 0 8px;">Your Branch Login Page</p>
          <p style="font-size:.9rem;font-weight:700;color:#0f172a;margin:0 0 4px;">' . htmlspecialchars($businessName) . '</p>
          <p style="font-size:.8rem;color:' . $roleColor . ';word-break:break-all;margin:0 0 14px;"><a href="' . $loginLink . '" style="color:' . $roleColor . ';">' . $loginLink . '</a></p>
          <a href="' . $loginLink . '" style="display:inline-block;background:' . $roleBg . ';color:#fff;text-decoration:none;padding:12px 28px;border-radius:9px;font-size:.88rem;font-weight:700;">Go to My Login Page →</a>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
          <p style="color:#15803d;font-size:.82rem;margin:0;line-height:1.7;">🔖 <strong>Tip:</strong> Bookmark this login link for quick access next time.</p>
        </div>
        <p style="color:#94a3b8;font-size:.76rem;word-break:break-all;">Or copy this link: <a href="' . $loginLink . '" style="color:#2563eb;">' . $loginLink . '</a></p>
      </div>
      <div style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="color:#94a3b8;font-size:.74rem;margin:0;">© ' . date('Y') . ' PawnHub · All rights reserved<br>This is an automated message, please do not reply.</p>
      </div>
    </div></body></html>';

    return sendMail($toEmail, $toName, 'PawnHub — Your ' . $roleLabel . ' Account is Ready at ' . $businessName, $html);
}