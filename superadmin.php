<?php
require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('super_admin');
require 'db.php';
require 'mailer.php';

if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
$u = $_SESSION['user'];
if ($u['role'] !== 'super_admin') { header('Location: login.php'); exit; }


$active_page = $_GET['page'] ?? 'dashboard';

// ── Fetch recent payment notifications (last 30 days) ─────────
// Includes: signups, renewals, upgrades, reactivations, downgrades
$notif_items = [];
$notif_count = 0;
try {
    $notif_stmt = $pdo->query("
        SELECT
            t.business_name,
            t.owner_name,
            sr.plan,
            sr.reviewed_at   AS paymongo_paid_at,
            t.id             AS tenant_id,
            sr.billing_cycle,
            CASE
                WHEN UPPER(COALESCE(sr.notes,'')) LIKE '%UPGRADE%'      THEN 'upgrade'
                WHEN UPPER(COALESCE(sr.notes,'')) LIKE '%REACTIVATION%' THEN 'reactivation'
                WHEN UPPER(COALESCE(sr.notes,'')) LIKE '%DOWNGRADE%'    THEN 'downgrade'
                WHEN UPPER(COALESCE(sr.notes,'')) LIKE '%RENEWAL%'      THEN 'renewal'
                ELSE 'signup'
            END AS payment_type,
            sr.amount
        FROM subscription_renewals sr
        JOIN tenants t ON sr.tenant_id = t.id
        WHERE sr.status IN ('approved', 'pending')
          AND sr.reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)

        UNION ALL

        SELECT
            t.business_name,
            t.owner_name,
            t.plan,
            t.paymongo_paid_at,
            t.id       AS tenant_id,
            'monthly'  AS billing_cycle,
            'signup'   AS payment_type,
            CASE t.plan WHEN 'Pro' THEN 999 WHEN 'Enterprise' THEN 2499 ELSE 0 END AS amount
        FROM tenants t
        WHERE t.payment_status = 'paid'
          AND t.paymongo_paid_at IS NOT NULL
          AND t.paymongo_paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          AND NOT EXISTS (
              SELECT 1 FROM subscription_renewals sr2
              WHERE sr2.tenant_id = t.id
                AND sr2.reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          )

        ORDER BY paymongo_paid_at DESC
        LIMIT 50
    ");
    $notif_items = $notif_stmt->fetchAll();
    $notif_count = count($notif_items);
} catch (Throwable $e) {}
$success_msg = $error_msg = '';
// Flash messages from redirect (e.g. paymongo_send_link.php)
if (!empty($_SESSION['sa_success'])) { $success_msg = $_SESSION['sa_success']; unset($_SESSION['sa_success']); }
if (!empty($_SESSION['sa_error']))   { $error_msg   = $_SESSION['sa_error'];   unset($_SESSION['sa_error']);   }

// Only the original "System Super Admin" (username = 'superadmin') may add or remove
// other Super Admin accounts. All other super admins are restricted from these actions.
$is_main_sa = ($u['username'] === 'superadmin');

// ── POST ACTIONS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── ADD TENANT + SEND INVITE EMAIL ────────────────────────
    if ($_POST['action'] === 'add_tenant') {
        $bname    = trim($_POST['business_name'] ?? '');
        $oname    = trim($_POST['owner_name']    ?? '');
        $email    = trim($_POST['email']         ?? '');
        $phone    = trim($_POST['phone']         ?? '');
        $address  = trim($_POST['address']       ?? '');
        $plan     = in_array($_POST['plan']??'', ['Starter','Pro','Enterprise']) ? $_POST['plan'] : 'Starter';
        $branches = intval($_POST['branches']    ?? 1);

        if ($bname && $oname && $email) {
            // Check email in BOTH users AND tenants tables
            $chk_u = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
            $chk_u->execute([$email]);
            $chk_t = $pdo->prepare("SELECT id FROM tenants WHERE email=? LIMIT 1");
            $chk_t->execute([$email]);
            // Check duplicate business name (case-insensitive)
            $chk_biz = $pdo->prepare("SELECT id FROM tenants WHERE LOWER(business_name)=LOWER(?) LIMIT 1");
            $chk_biz->execute([$bname]);
            if ($chk_u->fetch() || $chk_t->fetch()) {
                $error_msg = 'This email is already registered. Each tenant must use a unique email address.';
            } elseif ($chk_biz->fetch()) {
                $error_msg = 'A business with that name is already registered. Please use a different business name.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO tenants (business_name,owner_name,email,phone,address,plan,branches,status) VALUES (?,?,?,?,?,?,?,'pending')")
                        ->execute([$bname,$oname,$email,$phone,$address,$plan,$branches]);
                    $new_tid    = $pdo->lastInsertId();

                    $base_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $bname));
                    $slug = $base_slug;
                    $slug_counter = 1;
                    while (true) {
                        $slug_chk = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
                        $slug_chk->execute([$slug, $new_tid]);
                        if (!$slug_chk->fetch()) break;
                        $slug = $base_slug . $slug_counter++;
                    }
                    $pdo->prepare("UPDATE tenants SET slug = ? WHERE id = ?")->execute([$slug, $new_tid]);

                    $token      = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    $pdo->prepare("INSERT INTO tenant_invitations (tenant_id,email,owner_name,token,status,expires_at,created_by) VALUES (?,?,?,?,'pending',?,?)")
                        ->execute([$new_tid,$email,$oname,$token,$expires_at,$u['id']]);
                    $pdo->commit();
                    try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'TENANT_INVITE','tenant',?,?,?,NOW())")->execute([$new_tid,$u['id'],$u['username'],'super_admin',$new_tid,"Super Admin added tenant \"$bname\".",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}

                    // ── Auto-send payment link for Pro/Enterprise, invitation for Starter ──
                    $payment_link_sent = false;
                    $invite_sent       = false;
                    if (in_array($plan, ['Pro', 'Enterprise'])) {
                        // Create PayMongo checkout session and email the link automatically
                        require_once __DIR__ . '/paymongo_config.php';
                        require_once __DIR__ . '/mailer.php';
                        $prices = ['Pro' => 99900, 'Enterprise' => 249900];
                        $pm_amount = $prices[$plan] ?? 99900;
                        $pm_payload = [
                            'data' => ['attributes' => [
                                'billing'              => ['email' => $email, 'name' => $bname],
                                'line_items'           => [['currency' => 'PHP', 'amount' => $pm_amount, 'name' => "PawnHub {$plan} Plan — Monthly Subscription", 'quantity' => 1]],
                                'payment_method_types' => ['card', 'gcash', 'paymaya', 'dob', 'billease'],
                                'success_url'          => PAYMONGO_SUCCESS_URL . '?tenant=' . $new_tid . '&user=0',
                                'cancel_url'           => PAYMONGO_CANCEL_URL,
                                'metadata'             => ['tenant_id' => $new_tid, 'user_id' => 0, 'plan' => $plan, 'type' => 'signup'],
                            ]],
                        ];
                        $pm_ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
                        curl_setopt_array($pm_ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':')],
                            CURLOPT_POSTFIELDS     => json_encode($pm_payload),
                            CURLOPT_TIMEOUT        => 20,
                        ]);
                        $pm_raw  = curl_exec($pm_ch);
                        $pm_http = curl_getinfo($pm_ch, CURLINFO_HTTP_CODE);
                        curl_close($pm_ch);
                        $pm_resp = json_decode($pm_raw, true);
                        if ($pm_http === 200 && !empty($pm_resp['data']['attributes']['checkout_url'])) {
                            $pm_session_id   = $pm_resp['data']['id'];
                            $pm_checkout_url = $pm_resp['data']['attributes']['checkout_url'];
                            $pdo->prepare("UPDATE tenants SET paymongo_session_id=? WHERE id=?")->execute([$pm_session_id, $new_tid]);
                            // Generate QR code
                            $qr_url  = 'https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl=' . urlencode($pm_checkout_url) . '&choe=UTF-8';
                            $qr_data = @file_get_contents($qr_url);
                            $qr_uri  = $qr_data ? 'data:image/png;base64,' . base64_encode($qr_data) : null;
                            $payment_link_sent = sendPaymentLink($email, $oname, $bname, $plan, $pm_checkout_url, $qr_uri, $pm_amount / 100);
                        } else {
                            error_log('[AddTenant] PayMongo session failed: ' . $pm_raw);
                        }
                        $success_msg = $payment_link_sent
                            ? "✅ Tenant \"<strong>$bname</strong>\" added! Payment link auto-sent to <strong>$email</strong>. Account will activate once payment is received."
                            : "✅ Tenant \"<strong>$bname</strong>\" added but payment link email failed. Please send it manually from the tenant list.";
                    } else {
                        // Starter — just send the invitation email right away
                        require_once __DIR__ . '/mailer.php';
                        $invite_sent = sendTenantInvitation($email, $oname, $bname, $token, $slug);
                        $success_msg = $invite_sent
                            ? "✅ Tenant \"<strong>$bname</strong>\" added! Invitation email sent to <strong>$email</strong>. Approve to activate."
                            : "✅ Tenant \"<strong>$bname</strong>\" added but invitation email failed to send.";
                    }
                    $active_page = 'tenants';
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error_msg = 'Database error: ' . $e->getMessage();
                }
            }
        } else {
            $error_msg = 'Fill in all required fields.';
        }
    }

    // ── RESEND INVITATION ─────────────────────────────────────
    if ($_POST['action'] === 'resend_invite') {
        $inv_id = intval($_POST['inv_id']);
        $inv    = $pdo->prepare("SELECT i.*,t.business_name,t.slug FROM tenant_invitations i JOIN tenants t ON i.tenant_id=t.id WHERE i.id=?");
        $inv->execute([$inv_id]); $inv = $inv->fetch();
        if ($inv && in_array($inv['status'], ['pending', 'expired'])) {
            $token      = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $pdo->prepare("UPDATE tenant_invitations SET token=?, expires_at=?, status='pending', used_at=NULL WHERE id=?")
                ->execute([$token, $expires_at, $inv_id]);
            $inv_slug = $inv['slug'] ?? '';
            $sent = sendTenantInvitation($inv['email'],$inv['owner_name'],$inv['business_name'],$token,$inv_slug);
            $success_msg = $sent ? "📧 Invitation resent to {$inv['email']}!" : "⚠️ Failed to send. Check mailer.php settings.";
        } elseif ($inv && $inv['status'] === 'used') {
            $error_msg = "This invitation was already used. The tenant already has an account.";
        } else {
            $error_msg = "Invitation not found.";
        }
        $active_page = 'invitations';
    }

    // ── CHANGE TENANT PLAN ────────────────────────────────────
    if ($_POST['action'] === 'change_plan') {
        $tid_p    = intval($_POST['tenant_id']);
        $new_plan = in_array($_POST['new_plan']??'', ['Starter','Pro','Enterprise']) ? $_POST['new_plan'] : 'Starter';
        $plan_limits = ['Starter'=>3,'Pro'=>999,'Enterprise'=>999];
        $new_limit   = $plan_limits[$new_plan] ?? 3;
        $pdo->prepare("UPDATE tenants SET plan=? WHERE id=?")->execute([$new_plan,$tid_p]);
        if ($new_limit < 999) {
            $sl = $pdo->prepare("SELECT id FROM users WHERE tenant_id=? AND role IN ('staff','cashier') AND is_suspended=0 ORDER BY created_at ASC");
            $sl->execute([$tid_p]); $sl = $sl->fetchAll();
            if (count($sl) > $new_limit) {
                $excess = array_slice($sl, $new_limit);
                foreach ($excess as $ex) {
                    $pdo->prepare("UPDATE users SET is_suspended=1,suspended_at=NOW(),suspension_reason='Plan downgraded — staff limit exceeded.' WHERE id=?")->execute([$ex['id']]);
                }
                $success_msg = "Plan changed to <strong>$new_plan</strong>. " . count($excess) . " staff suspended due to new limit.";
            } else {
                $success_msg = "Plan updated to <strong>$new_plan</strong> successfully!";
            }
        } else {
            $pdo->prepare("UPDATE users SET is_suspended=0,suspended_at=NULL,suspension_reason=NULL WHERE tenant_id=? AND suspension_reason='Plan downgraded — staff limit exceeded.'")->execute([$tid_p]);
            $success_msg = "Plan upgraded to <strong>$new_plan</strong>! Previously suspended staff have been restored.";
        }
        try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'CHANGE_PLAN','tenant',?,?,?,NOW())")->execute([$tid_p,$u['id'],$u['username'],'super_admin',$tid_p,"Plan changed to $new_plan for tenant ID $tid_p.",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
        $active_page = 'tenants';
    }



    if ($_POST['action'] === 'save_system_settings') {
        $sys_name    = trim($_POST['system_name']    ?? 'PawnHub');
        $sys_tagline = trim($_POST['system_tagline'] ?? 'Multi-Tenant Pawnshop Management');
        $plan_starter_staff    = max(1, intval($_POST['starter_staff']    ?? 3));
        $plan_starter_branches = max(1, intval($_POST['starter_branches'] ?? 1));
        $plan_pro_staff        = intval($_POST['pro_staff']        ?? 0);
        $plan_pro_branches     = max(1, intval($_POST['pro_branches']     ?? 3));
        $plan_ent_staff        = intval($_POST['ent_staff']        ?? 0);
        $plan_ent_branches     = max(1, intval($_POST['ent_branches']     ?? 10));
        $plan_starter_price    = trim($_POST['starter_price'] ?? 'Free');
        $plan_pro_price        = trim($_POST['pro_price']     ?? '₱999/mo');
        $plan_ent_price        = trim($_POST['ent_price']     ?? '₱2,499/mo');

        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES
                ('system_name',?),('system_tagline',?),
                ('starter_staff',?),('starter_branches',?),
                ('pro_staff',?),('pro_branches',?),
                ('ent_staff',?),('ent_branches',?),
                ('starter_price',?),('pro_price',?),('ent_price',?)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
                ->execute([$sys_name,$sys_tagline,
                    $plan_starter_staff,$plan_starter_branches,
                    $plan_pro_staff,$plan_pro_branches,
                    $plan_ent_staff,$plan_ent_branches,
                    $plan_starter_price,$plan_pro_price,$plan_ent_price]);
            try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (NULL,?,?,?,'SETTINGS_UPDATE','system','1','Super Admin updated system settings.',?,NOW())")->execute([$u['id'],$u['username'],'super_admin',$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
            $success_msg = 'System settings saved successfully!';
        } catch (PDOException $e) {
            $error_msg = 'Error saving settings: ' . $e->getMessage();
        }
        $active_page = 'settings';
    }

    if ($_POST['action'] === 'approve_tenant') {
        $tid = intval($_POST['tenant_id']);
        $uid = intval($_POST['user_id']);

        $t_stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $t_stmt->execute([$tid]);
        $t_row = $t_stmt->fetch();

        if (!$t_row) {
            $error_msg = 'Tenant not found.';
            $active_page = 'tenants';
            goto end_approve;
        }

        // Permit check removed — Super Admin approves manually.

        // ── Generate / ensure slug ─────────────────────────────
        $slug = $t_row['slug'] ?? '';
        if (empty($slug) && !empty($t_row['business_name'])) {
            $base_slug    = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $t_row['business_name']));
            $slug         = $base_slug;
            $slug_counter = 1;
            while (true) {
                $slug_chk = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
                $slug_chk->execute([$slug, $tid]);
                if (!$slug_chk->fetch()) break;
                $slug = $base_slug . $slug_counter++;
            }
            $pdo->prepare("UPDATE tenants SET slug = ? WHERE id = ?")->execute([$slug, $tid]);
            $t_row['slug'] = $slug;
        }

        // ── Activate tenant & user ─────────────────────────────
        $pdo->prepare("UPDATE tenants SET status='active',
            subscription_start = CURDATE(),
            subscription_end   = DATE_ADD(CURDATE(), INTERVAL 1 MONTH),
            subscription_status = 'active',
            renewal_reminded_7d = 0,
            renewal_reminded_3d = 0,
            renewal_reminded_1d = 0
            WHERE id=?")->execute([$tid]);
        $pdo->prepare("UPDATE users SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$u['id'], $uid]);

        // ── Auto-record subscription payment in sales report ───
        // Maps the tenant's plan to a numeric amount so it shows up
        // in the Sales Report as SA income from subscriptions.
        $plan_amounts = ['Starter' => 0, 'Pro' => 999, 'Enterprise' => 2499];
        $sub_amount   = $plan_amounts[$t_row['plan']] ?? 0;
        if ($sub_amount > 0) {
            try {
                $pdo->prepare("INSERT INTO subscription_renewals
                    (tenant_id, plan, billing_cycle, payment_method, payment_reference, amount, status, requested_at, reviewed_by, reviewed_at, new_subscription_end)
                    VALUES (?, ?, 'monthly', 'Initial Subscription', 'AUTO — Tenant Approved', ?, 'approved', NOW(), ?, NOW(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH))")
                    ->execute([$tid, $t_row['plan'], $sub_amount, $u['id']]);
            } catch (PDOException $e) {
                error_log('[Approve] Could not record subscription_renewals: ' . $e->getMessage());
            }
        }

        // ── Send approval/login email ──────────────────────────
        // For SA-invited tenants: send the invitation/setup email (with token link).
        // For self-signup tenants (no invitation token): send the approved/login email.
        $email_sent = false;
        if (!empty($t_row['email']) && !empty($slug)) {
            try {
                // Check if this tenant was SA-invited (any token)
                $inv_stmt = $pdo->prepare("SELECT id, token, status FROM tenant_invitations WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 1");
                $inv_stmt->execute([$tid]);
                $inv_row = $inv_stmt->fetch();

                if ($inv_row && $inv_row['status'] === 'used') {
                    // Tenant already set up their password — just send approved/login email
                    $email_sent = sendTenantApproved(
                        $t_row['email'],
                        $t_row['owner_name'],
                        $t_row['business_name'],
                        $slug
                    );
                } elseif ($inv_row && $inv_row['status'] !== 'used') {
                    // Token not used yet — renew it and resend the setup/invitation email
                    $new_token   = bin2hex(random_bytes(32));
                    $new_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    $pdo->prepare("UPDATE tenant_invitations SET token=?, expires_at=?, status='pending', used_at=NULL WHERE id=?")
                        ->execute([$new_token, $new_expires, $inv_row['id']]);
                    $email_sent = sendTenantInvitation(
                        $t_row['email'],
                        $t_row['owner_name'],
                        $t_row['business_name'],
                        $new_token,
                        $slug
                    );
                } else {
                    // Self-signup tenant (no invitation): send approved/login email
                    $email_sent = sendTenantApproved(
                        $t_row['email'],
                        $t_row['owner_name'],
                        $t_row['business_name'],
                        $slug
                    );
                }
                if (!$email_sent) {
                    error_log("[Approve] Email returned false for tenant_id={$tid} email={$t_row['email']}");
                }
            } catch (Throwable $mail_err) {
                error_log('[Approve] Approval email exception: ' . $mail_err->getMessage());
            }
        }

        try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'APPROVE_TENANT','tenant',?,?,?,NOW())")->execute([$tid,$u['id'],$u['username'],'super_admin',$tid,"Approved tenant ID $tid ({$t_row['business_name']}). Email sent: " . ($email_sent ? 'yes' : 'no'),$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}

        $success_msg = $email_sent
            ? "✅ Tenant approved! Subscription started today (expires " . date('M d, Y', strtotime('+1 month')) . "). Invitation sent to <strong>{$t_row['email']}</strong>."
            : "✅ Tenant approved! Subscription started today (expires " . date('M d, Y', strtotime('+1 month')) . "). ⚠️ Email could not be sent — check mailer.php settings.";
        $active_page = 'tenants';
        end_approve:;
    }

    if ($_POST['action'] === 'reject_tenant') {
        $tid    = intval($_POST['tenant_id']);
        $uid    = intval($_POST['user_id']);
        $reason = trim($_POST['reject_reason'] ?? 'Application rejected.');
        $pdo->prepare("UPDATE tenants SET status='rejected' WHERE id=?")->execute([$tid]);
        $pdo->prepare("UPDATE users SET status='rejected', rejected_reason=? WHERE id=?")->execute([$reason, $uid]);
        try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'REJECT_TENANT','tenant',?,?,?,NOW())")->execute([$tid,$u['id'],$u['username'],'super_admin',$tid,"Rejected tenant ID $tid. Reason: $reason",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
        $success_msg = 'Tenant application rejected.';
        $active_page = 'tenants';
    }

    // ── APPROVE BUSINESS PERMIT ───────────────────────────────
    if ($_POST['action'] === 'approve_permit') {
        $tid = intval($_POST['tenant_id']);
        $t_stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $t_stmt->execute([$tid]);
        $t_row = $t_stmt->fetch();
        if ($t_row) {
            $pdo->prepare("UPDATE tenants SET business_permit_status = 'sa_approved' WHERE id = ?")->execute([$tid]);
            try {
                require_once __DIR__ . '/mailer.php';
                sendPermitApproved($t_row['email'], $t_row['owner_name'], $t_row['business_name'], $t_row['slug'] ?? '');
            } catch (Throwable $e) { error_log('[ApprovePermit] Email error: ' . $e->getMessage()); }
            try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'PERMIT_APPROVED','tenant',?,?,?,NOW())")->execute([$tid,$u['id'],$u['username'],'super_admin',$tid,"Business permit approved for tenant ID $tid ({$t_row['business_name']}).",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
            $success_msg = "✅ Business permit approved for <strong>{$t_row['business_name']}</strong>. Tenant notified by email.";
        } else { $error_msg = 'Tenant not found.'; }
        $active_page = 'tenants';
    }

    // ── REJECT BUSINESS PERMIT → auto-deactivate tenant ──────
    if ($_POST['action'] === 'reject_permit') {
        $tid    = intval($_POST['tenant_id']);
        $reason = trim($_POST['reject_reason'] ?? 'Your business permit was found to be invalid or expired.');
        $t_stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $t_stmt->execute([$tid]);
        $t_row = $t_stmt->fetch();
        if ($t_row) {
            $pdo->prepare("UPDATE tenants SET business_permit_status = 'sa_rejected', status = 'inactive', rejection_reason = ? WHERE id = ?")->execute([$reason, $tid]);
            $pdo->prepare("UPDATE users SET is_suspended = 1, suspended_at = NOW(), suspension_reason = ? WHERE tenant_id = ?")->execute(["Account deactivated: business permit rejected. Reason: $reason", $tid]);
            try {
                require_once __DIR__ . '/mailer.php';
                sendPermitRejected($t_row['email'], $t_row['owner_name'], $t_row['business_name'], $reason);
            } catch (Throwable $e) { error_log('[RejectPermit] Email error: ' . $e->getMessage()); }
            try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'PERMIT_REJECTED','tenant',?,?,?,NOW())")->execute([$tid,$u['id'],$u['username'],'super_admin',$tid,"Business permit rejected for tenant ID $tid ({$t_row['business_name']}). Reason: $reason. Account deactivated.",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
            $success_msg = "⛔ Permit rejected. <strong>{$t_row['business_name']}</strong> has been deactivated and notified by email.";
        } else { $error_msg = 'Tenant not found.'; }
        $active_page = 'tenants';
    }

    if ($_POST['action'] === 'deactivate_tenant') {
        $target_tid = intval($_POST['tenant_id']);
        $pdo->prepare("UPDATE tenants SET status='inactive' WHERE id=?")->execute([$target_tid]);
        $pdo->prepare("UPDATE users SET is_suspended=1, suspended_at=NOW(), suspension_reason='Tenant deactivated by Super Admin.' WHERE tenant_id=? AND role != 'admin'")->execute([$target_tid]);
        try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'DEACTIVATE_TENANT','tenant',?,?,?,NOW())")->execute([$target_tid,$u['id'],$u['username'],'super_admin',$target_tid,"Deactivated tenant ID $target_tid — all users suspended.",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
        $success_msg = 'Tenant deactivated. All branch users have been suspended.';
        $active_page = 'tenants';
    }

    if ($_POST['action'] === 'activate_tenant') {
        $target_tid = intval($_POST['tenant_id']);
        $pdo->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$target_tid]);
        $pdo->prepare("UPDATE users SET is_suspended=0, suspended_at=NULL, suspension_reason=NULL WHERE tenant_id=? AND suspension_reason='Tenant deactivated by Super Admin.'")->execute([$target_tid]);
        try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'ACTIVATE_TENANT','tenant',?,?,?,NOW())")->execute([$target_tid,$u['id'],$u['username'],'super_admin',$target_tid,"Activated tenant ID $target_tid — all users unsuspended.",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
        $success_msg = 'Tenant activated. All branch users have been unsuspended.';
        $active_page = 'tenants';
    }

    // ── EXTEND SUBSCRIPTION 1 MONTH (SA quick action) ─────────
    // Used when a tenant calls in and SA manually extends their sub by 1 month.
    // Also re-activates the tenant and unsuspends all users if deactivated.
    if ($_POST['action'] === 'extend_1_month') {
        $target_tid = intval($_POST['tenant_id']);

        $t_info = $pdo->prepare("SELECT id, business_name, owner_name, email, plan, slug, status, subscription_end, subscription_status FROM tenants WHERE id=? LIMIT 1");
        $t_info->execute([$target_tid]);
        $t_info = $t_info->fetch();

        if ($t_info) {
            // New end = today + 1 month (not from old expiry — fresh start from today)
            $new_end = date('Y-m-d', strtotime('+1 month'));

            // Update tenant: reactivate + extend subscription
            $pdo->prepare("
                UPDATE tenants SET
                    status = 'active',
                    subscription_start = CURDATE(),
                    subscription_end = ?,
                    subscription_status = 'active',
                    renewal_reminded_7d = 0,
                    renewal_reminded_3d = 0,
                    renewal_reminded_1d = 0
                WHERE id = ?
            ")->execute([$new_end, $target_tid]);

            // Unsuspend all users (covers auto-deactivated + SA deactivated)
            $pdo->prepare("
                UPDATE users SET is_suspended=0, suspended_at=NULL, suspension_reason=NULL
                WHERE tenant_id=? AND is_suspended=1
            ")->execute([$target_tid]);

            // Record in subscription_renewals for sales report
            $plan_amounts = ['Starter' => 0, 'Pro' => 999, 'Enterprise' => 2499];
            $sub_amount   = $plan_amounts[$t_info['plan']] ?? 0;
            try {
                $pdo->prepare("
                    INSERT INTO subscription_renewals
                        (tenant_id, plan, billing_cycle, payment_method, payment_reference, amount, status, requested_at, reviewed_by, reviewed_at, new_subscription_end)
                    VALUES (?, ?, 'monthly', 'SA Manual Extend', 'AUTO — SA Extended 1 Month', ?, ?, NOW(), ?, NOW(), ?)
                ")->execute([$target_tid, $t_info['plan'], $sub_amount, 'approved', $u['id'], $new_end]);
            } catch (PDOException $e) {
                error_log('[Extend1Mo] Could not record subscription_renewals: ' . $e->getMessage());
            }

            // Send renewal confirmation email to tenant
            if (function_exists('sendSubscriptionRenewed')) {
                try {
                    sendSubscriptionRenewed(
                        $t_info['email'],
                        $t_info['owner_name'],
                        $t_info['business_name'],
                        $t_info['plan'],
                        $new_end,
                        $t_info['slug']
                    );
                } catch (Throwable $e) {}
            }

            try {
                $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'SUB_EXTEND_1MO','subscription',?,?,?,NOW())")
                    ->execute([$target_tid,$u['id'],$u['username'],'super_admin',$target_tid,
                        "SA manually extended subscription for \"{$t_info['business_name']}\" by 1 month. New expiry: {$new_end}. Tenant re-activated.",
                        $_SERVER['REMOTE_ADDR']??'::1']);
            } catch (PDOException $e) {}

            $success_msg = "✅ Subscription extended 1 month for <strong>{$t_info['business_name']}</strong>! New expiry: <strong>" . date('M d, Y', strtotime($new_end)) . "</strong>. Tenant re-activated and all users unsuspended.";
        } else {
            $error_msg = 'Tenant not found.';
        }
        $active_page = 'subscriptions';
    }
    if ($_POST['action'] === 'approve_sub_renewal') {
        $rid = intval($_POST['renewal_id']);
        $billing_months = ['monthly' => 1, 'quarterly' => 3, 'annually' => 12];

        // ── Fetch renewal + tenant info ───────────────────────────
        // NOTE: alias t.plan as current_tenant_plan to avoid collision with sr.plan
        $ren = $pdo->prepare("
            SELECT sr.*,
                   t.email, t.owner_name, t.business_name, t.slug,
                   t.plan          AS current_tenant_plan,
                   t.status        AS tenant_status,
                   t.subscription_end AS tenant_sub_end
            FROM subscription_renewals sr
            JOIN tenants t ON sr.tenant_id = t.id
            WHERE sr.id = ? LIMIT 1
        ");
        $ren->execute([$rid]);
        $ren = $ren->fetch();

        if ($ren && $ren['status'] === 'pending') {
            $months = $billing_months[$ren['billing_cycle']] ?? 1;

            // ── Detect request type ───────────────────────────────
            $is_upgrade     = !empty($ren['is_upgrade']);
            $upgrade_to_col = trim($ren['upgrade_to']   ?? '');
            $upgrade_from   = trim($ren['upgrade_from'] ?? $ren['current_tenant_plan']);
            $plan_hierarchy = ['Starter' => 0, 'Pro' => 1, 'Enterprise' => 2];

            // Scheduled = request was submitted while tenant's subscription was still active
            // We detect this from the notes field containing "[SCHEDULED" marker
            $notes_text  = $ren['notes'] ?? '';
            $is_scheduled = (strpos($notes_text, '[SCHEDULED') !== false);

            // Detect downgrade: upgrade_from > upgrade_to in rank, or is_upgrade=0 and upgrade columns differ
            $is_downgrade = !$is_upgrade
                && !empty($upgrade_from)
                && !empty($upgrade_to_col)
                && isset($plan_hierarchy[$upgrade_from])
                && isset($plan_hierarchy[$upgrade_to_col])
                && $plan_hierarchy[$upgrade_to_col] < $plan_hierarchy[$upgrade_from];

            // Validate upgrade
            $valid_upgrade = $is_upgrade
                && isset($plan_hierarchy[$upgrade_to_col])
                && isset($plan_hierarchy[$upgrade_from])
                && $plan_hierarchy[$upgrade_to_col] > $plan_hierarchy[$upgrade_from];

            // ── New expiry calculation ────────────────────────────
            // Scheduled (submitted while still active):
            //   → Start from current subscription_end (kicks in right after current period)
            // Immediate (expired already, or a regular renewal):
            //   → Start from today if expired, or extend from current end if still active
            $tenant_sub_end_ts = !empty($ren['tenant_sub_end']) ? strtotime($ren['tenant_sub_end']) : 0;

            if ($is_scheduled && $tenant_sub_end_ts > 0) {
                // Scheduled: new period starts right after current sub ends
                $base_date = $ren['tenant_sub_end'];
            } elseif ($tenant_sub_end_ts > time()) {
                // Active non-scheduled (e.g. immediate upgrade mid-period): extend from current end
                $base_date = $ren['tenant_sub_end'];
            } else {
                // Expired: fresh start from today
                $base_date = date('Y-m-d');
            }
            $new_end = date('Y-m-d', strtotime($base_date . " +{$months} months"));

            // Final plan to apply
            if ($is_upgrade && $valid_upgrade) {
                $final_plan = $upgrade_to_col;
            } elseif ($is_downgrade) {
                $final_plan = $upgrade_to_col; // downgrade target
            } else {
                $final_plan = $ren['current_tenant_plan']; // renewal — keep same plan
            }

            // ── Mark renewal approved ─────────────────────────────
            $pdo->prepare("
                UPDATE subscription_renewals
                SET status='approved', reviewed_by=?, reviewed_at=NOW(), new_subscription_end=?
                WHERE id=?
            ")->execute([$u['id'], $new_end, $rid]);

            // ── Update tenant ─────────────────────────────────────
            // For scheduled requests: if current sub is still active, DON'T change the plan yet —
            // only pre-set the new subscription_end so it auto-activates. But it's simpler and
            // safer to just set it now (admin approved = confirmed). Access is already paid through
            // the old expiry, so tenant keeps access regardless.
            $pdo->prepare("
                UPDATE tenants SET
                    plan                = ?,
                    status              = 'active',
                    subscription_start  = ?,
                    subscription_end    = ?,
                    subscription_status = 'active',
                    renewal_reminded_7d = 0,
                    renewal_reminded_3d = 0,
                    renewal_reminded_1d = 0
                WHERE id = ?
            ")->execute([
                $final_plan,
                $is_scheduled ? $ren['tenant_sub_end'] : date('Y-m-d'), // start = after current end if scheduled
                $new_end,
                $ren['tenant_id']
            ]);

            // ── Unsuspend all users ───────────────────────────────
            $pdo->prepare("
                UPDATE users
                SET is_suspended=0, suspended_at=NULL, suspension_reason=NULL
                WHERE tenant_id=? AND is_suspended=1
            ")->execute([$ren['tenant_id']]);

            // ── Send confirmation email ───────────────────────────
            if (function_exists('sendSubscriptionRenewed')) {
                sendSubscriptionRenewed(
                    $ren['email'], $ren['owner_name'], $ren['business_name'],
                    $final_plan, $new_end, $ren['slug']
                );
            }

            try { $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, 'renewed')")->execute([$ren['tenant_id']]); } catch(PDOException $e){}

            $scheduled_label = $is_scheduled ? ' (Scheduled)' : '';

            // ── Audit log + success message ───────────────────────
            if ($is_upgrade && $valid_upgrade) {
                $audit_action = 'PLAN_UPGRADE_APPROVED';
                $audit_msg    = "Approved UPGRADE{$scheduled_label} for {$ren['business_name']}: {$upgrade_from} → {$upgrade_to_col}. New expiry: {$new_end}.";
                if ($is_scheduled) {
                    $success_msg = "✅ Scheduled upgrade confirmed! <strong>{$ren['business_name']}</strong> will switch to <strong>{$upgrade_to_col}</strong> after <strong>" . date('M d, Y', strtotime($ren['tenant_sub_end'])) . "</strong>. New expiry: <strong>" . date('M d, Y', strtotime($new_end)) . "</strong>.";
                } else {
                    $success_msg = "✅ Upgrade approved! <strong>{$ren['business_name']}</strong> is now on the <strong>{$upgrade_to_col}</strong> plan. New expiry: <strong>" . date('M d, Y', strtotime($new_end)) . "</strong>. Confirmation email sent.";
                }
            } elseif ($is_downgrade) {
                $audit_action = 'PLAN_DOWNGRADE_APPROVED';
                $audit_msg    = "Approved DOWNGRADE{$scheduled_label} for {$ren['business_name']}: {$upgrade_from} → {$upgrade_to_col}. New expiry: {$new_end}.";
                if ($is_scheduled) {
                    $success_msg = "✅ Scheduled downgrade confirmed! <strong>{$ren['business_name']}</strong> will switch to <strong>{$upgrade_to_col}</strong> after <strong>" . date('M d, Y', strtotime($ren['tenant_sub_end'])) . "</strong>. New expiry: <strong>" . date('M d, Y', strtotime($new_end)) . "</strong>.";
                } else {
                    $success_msg = "✅ Downgrade approved! <strong>{$ren['business_name']}</strong> switched to <strong>{$upgrade_to_col}</strong>. New expiry: <strong>" . date('M d, Y', strtotime($new_end)) . "</strong>. Confirmation email sent.";
                }
            } else {
                $audit_action = 'SUB_RENEWAL_APPROVED';
                $audit_msg    = "Approved renewal for {$ren['business_name']} ({$final_plan}). New expiry: {$new_end}.";
                $success_msg  = "✅ Renewal approved for <strong>{$ren['business_name']}</strong>! New expiry: <strong>" . date('M d, Y', strtotime($new_end)) . "</strong>. Confirmation email sent.";
            }
            try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,?,'subscription',?,?,?,NOW())")->execute([$ren['tenant_id'],$u['id'],$u['username'],'super_admin',$audit_action,$rid,$audit_msg,$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}

        } else {
            $error_msg = 'Renewal request not found or already processed.';
        }
        $active_page = 'subscriptions';
    }

    // ── REJECT SUBSCRIPTION RENEWAL ──────────────────────────
    if ($_POST['action'] === 'reject_sub_renewal') {
        $rid          = intval($_POST['renewal_id']);
        $reject_notes = trim($_POST['reject_notes'] ?? '');

        $ren = $pdo->prepare("SELECT sr.*, t.business_name FROM subscription_renewals sr JOIN tenants t ON sr.tenant_id=t.id WHERE sr.id=? LIMIT 1");
        $ren->execute([$rid]);
        $ren = $ren->fetch();

        if ($ren && $ren['status'] === 'pending') {
            $pdo->prepare("UPDATE subscription_renewals SET status='rejected', reviewed_by=?, reviewed_at=NOW(), notes=CONCAT(IFNULL(notes,''), IF(notes IS NOT NULL AND notes != '', '\n', ''), 'Rejected: ', ?) WHERE id=?")
                ->execute([$u['id'], $reject_notes, $rid]);
            try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'SUB_RENEWAL_REJECTED','subscription',?,?,?,NOW())")->execute([$ren['tenant_id'],$u['id'],$u['username'],'super_admin',$rid,"Rejected renewal for {$ren['business_name']}. Reason: {$reject_notes}",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
            $success_msg = "Renewal request for <strong>{$ren['business_name']}</strong> has been rejected.";
        } else {
            $error_msg = 'Request not found or already processed.';
        }
        $active_page = 'subscriptions';
    }

    // ── MANUALLY SET SUBSCRIPTION DATES ──────────────────────
    if ($_POST['action'] === 'set_subscription') {
        $tid_s     = intval($_POST['tenant_id']);
        $start     = $_POST['sub_start'] ?? '';
        $end_date  = $_POST['sub_end']   ?? '';
        $new_plan  = in_array($_POST['sub_plan'] ?? '', ['Starter','Pro','Enterprise']) ? $_POST['sub_plan'] : null;

        if ($start && $end_date && strtotime($start) && strtotime($end_date)) {
            // Fetch tenant name for a more descriptive audit log
            $t_info_stmt = $pdo->prepare("SELECT business_name, plan FROM tenants WHERE id=? LIMIT 1");
            $t_info_stmt->execute([$tid_s]);
            $t_info_row = $t_info_stmt->fetch();
            $t_biz_name = $t_info_row['business_name'] ?? "Tenant #$tid_s";
            $t_cur_plan = $t_info_row['plan'] ?? '';

            $updates = "subscription_start=?, subscription_end=?, subscription_status='active', renewal_reminded_7d=0, renewal_reminded_3d=0, renewal_reminded_1d=0";
            $params  = [$start, $end_date];
            if ($new_plan) { $updates .= ", plan=?"; $params[] = $new_plan; }
            $params[] = $tid_s;
            $pdo->prepare("UPDATE tenants SET {$updates} WHERE id=?")->execute($params);

            // Record this manual set as a subscription payment in the Sales Report
            // only if it's a paid plan and there's no existing approved renewal for today
            $effective_plan = $new_plan ?: $t_cur_plan;
            $plan_amounts   = ['Starter' => 0, 'Pro' => 999, 'Enterprise' => 2499];
            $sub_amount     = $plan_amounts[$effective_plan] ?? 0;
            if ($sub_amount > 0) {
                try {
                    // Avoid duplicate: check if there's already an approved renewal today for this tenant
                    $dup = $pdo->prepare("SELECT id FROM subscription_renewals WHERE tenant_id=? AND status='approved' AND DATE(reviewed_at)=CURDATE() LIMIT 1");
                    $dup->execute([$tid_s]);
                    if (!$dup->fetch()) {
                        $pdo->prepare("INSERT INTO subscription_renewals
                            (tenant_id, plan, billing_cycle, payment_method, payment_reference, amount, status, requested_at, reviewed_by, reviewed_at, new_subscription_end)
                            VALUES (?, ?, 'monthly', 'Manual Set', 'AUTO — SA Set Dates', ?, 'approved', NOW(), ?, NOW(), ?)")
                            ->execute([$tid_s, $effective_plan, $sub_amount, $u['id'], $end_date]);
                    }
                } catch (PDOException $e) {
                    error_log('[SetSub] Could not record subscription_renewals: ' . $e->getMessage());
                }
            }
            $plan_note = $new_plan ? " Plan set to: {$new_plan}." : " Plan unchanged ({$t_cur_plan}).";
            try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'SUB_MANUAL_SET','subscription',?,?,?,NOW())")->execute([$tid_s,$u['id'],$u['username'],'super_admin',$tid_s,"Subscription set for \"{$t_biz_name}\": Start {$start}, Expiry {$end_date}.{$plan_note}",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
            $success_msg = 'Subscription dates updated successfully.';
        } else {
            $error_msg = 'Invalid dates provided.';
        }
        $active_page = 'subscriptions';
    }

    // ── RUN SUBSCRIPTION CRON MANUALLY ───────────────────────
    if ($_POST['action'] === 'run_sub_cron') {
        $cron_secret = 'pawnhub_cron_2026_secret';
        header('Location: subscription_cron.php?cron_secret=' . urlencode($cron_secret));
        exit;
    }

    // ── ADD ANOTHER SUPER ADMIN (token-based invite, no password here) ──
    if ($_POST['action'] === 'add_super_admin') {
        if (!$is_main_sa) {
            $error_msg = 'Only the System Super Admin can add new Super Admin accounts.';
            $active_page = 'settings';
        } else {
        $sa_fullname = trim($_POST['sa_fullname'] ?? '');
        $sa_username = trim($_POST['sa_username'] ?? '');
        $sa_email    = trim($_POST['sa_email']    ?? '');

        if (!$sa_fullname || !$sa_email) {
            $error_msg = 'Please fill in all required fields.';
        } elseif (!filter_var($sa_email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Invalid email address.';
        } else {
            // Check email in BOTH users AND tenants tables
            $dup_u = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $dup_u->execute([$sa_email]);
            $dup_t = $pdo->prepare("SELECT id FROM tenants WHERE email = ? LIMIT 1");
            $dup_t->execute([$sa_email]);
            if ($dup_u->fetch() || $dup_t->fetch()) {
                $error_msg = 'This email is already registered in the system.';
            } else {
                // DDL (CREATE TABLE) must run OUTSIDE a transaction — MySQL DDL causes
                // an implicit commit, which would silently end any active transaction and
                // make the subsequent rollBack() throw "There is no active transaction".
                $pdo->exec("CREATE TABLE IF NOT EXISTS super_admin_invitations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token VARCHAR(128) NOT NULL UNIQUE,
                    used TINYINT(1) NOT NULL DEFAULT 0,
                    used_at DATETIME DEFAULT NULL,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    created_by INT DEFAULT NULL
                )");

                try {
                    $pdo->beginTransaction();

                    // Insert with status='pending', username temporarily blank
                    // The new SA will set their own username + password via the setup link
                    $temp_username = 'sa_pending_' . bin2hex(random_bytes(4));
                    $pdo->prepare("INSERT INTO users (fullname, username, email, password, role, status, is_suspended, tenant_id, created_at)
                        VALUES (?, ?, ?, '', 'super_admin', 'pending', 0, NULL, NOW())")
                        ->execute([$sa_fullname, $temp_username, $sa_email]);
                    $new_sa_id = $pdo->lastInsertId();

                    // Generate setup token (24 hours)
                    $sa_token     = bin2hex(random_bytes(32));
                    $sa_expires   = date('Y-m-d H:i:s', strtotime('+24 hours'));

                    $pdo->prepare("INSERT INTO super_admin_invitations (user_id, token, expires_at, created_by) VALUES (?, ?, ?, ?)")
                        ->execute([$new_sa_id, $sa_token, $sa_expires, $u['id']]);

                    $pdo->commit();

                    // Audit log
                    try {
                        $pdo->prepare("INSERT INTO audit_logs (tenant_id, actor_user_id, actor_username, actor_role, action, entity_type, entity_id, message, ip_address, created_at)
                            VALUES (NULL, ?, ?, 'super_admin', 'ADD_SUPER_ADMIN', 'user', ?, ?, ?, NOW())")
                            ->execute([$u['id'], $u['username'], $new_sa_id,
                                "Super Admin \"{$u['username']}\" invited new Super Admin \"{$sa_fullname}\" ({$sa_email}). Invitation email sent.",
                                $_SERVER['REMOTE_ADDR'] ?? '::1']);
                    } catch (PDOException $e) {}

                    // Send invitation email with setup link (no username yet — they set it themselves)
                    require_once __DIR__ . '/mailer.php';
                    $sent = sendSuperAdminInvitation($sa_email, $sa_fullname, '', $sa_token);

                    if ($sent) {
                        $success_msg = "✅ Invitation sent to <strong>{$sa_email}</strong>! They will receive an email to set up their own username and password.";
                    } else {
                        $success_msg = "⚠️ Super Admin account created but email failed to send. Setup link: <code>" . APP_URL . "/sa_setup_password.php?token={$sa_token}</code>";
                    }

                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error_msg = 'Database error: ' . $e->getMessage();
                }
            }
        }
        } // end else ($is_main_sa)
        $active_page = 'settings';
    }

    // ── REMOVE SUPER ADMIN ────────────────────────────────────
    if ($_POST['action'] === 'remove_super_admin') {
        if (!$is_main_sa) {
            $error_msg = 'Only the System Super Admin can remove Super Admin accounts.';
            $active_page = 'settings';
        } else {
        $target_id = intval($_POST['target_id'] ?? 0);
        if ($target_id === (int)$u['id']) {
            $error_msg = 'You cannot remove your own Super Admin account.';
        } else {
            $check = $pdo->prepare("SELECT id, username, fullname FROM users WHERE id = ? AND role = 'super_admin' LIMIT 1");
            $check->execute([$target_id]);
            $target = $check->fetch();
            if ($target) {
                $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'super_admin'")->execute([$target_id]);
                try {
                    $pdo->prepare("INSERT INTO audit_logs (tenant_id, actor_user_id, actor_username, actor_role, action, entity_type, entity_id, message, ip_address, created_at)
                        VALUES (NULL, ?, ?, 'super_admin', 'REMOVE_SUPER_ADMIN', 'user', ?, ?, ?, NOW())")
                        ->execute([$u['id'], $u['username'], $target_id,
                            "Super Admin \"{$u['username']}\" removed Super Admin \"{$target['username']}\".",
                            $_SERVER['REMOTE_ADDR'] ?? '::1']);
                } catch (PDOException $e) {}
                $success_msg = "Super Admin \"<strong>{$target['fullname']}</strong>\" has been removed.";
            } else {
                $error_msg = 'Super Admin account not found.';
            }
        }
        } // end else ($is_main_sa)
        $active_page = 'settings';
    }
}

// ── FETCH CORE DATA ──────────────────────────────────────────
try {
    $tenants = $pdo->query("
        SELECT t.*,
            (SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id AND u.role != 'super_admin') AS user_count,
            (SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id AND u.status='pending') AS pending_users,
            (SELECT u.fullname FROM users u WHERE u.tenant_id=t.id AND u.role='admin' AND u.status='approved' LIMIT 1) AS admin_name,
            (SELECT u.id FROM users u WHERE u.tenant_id=t.id AND u.role='admin' LIMIT 1) AS admin_uid,
            (SELECT status FROM tenant_invitations ti WHERE ti.tenant_id=t.id ORDER BY ti.created_at DESC LIMIT 1) AS invite_status,
            DATEDIFF(t.subscription_end, CURDATE()) AS days_left
        FROM tenants t ORDER BY t.business_name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $tenants = [];
    $error_msg = 'Error loading tenants: ' . $e->getMessage();
}

try {
    $invitations = $pdo->query("SELECT i.*,t.business_name,t.plan FROM tenant_invitations i JOIN tenants t ON i.tenant_id=t.id ORDER BY i.created_at DESC")->fetchAll();
} catch (PDOException $e) { $invitations = []; }

$pending_inv = count(array_filter($invitations, fn($i) => $i['status'] === 'pending'));

$total_tenants    = count($tenants);
$active_tenants   = count(array_filter($tenants, fn($t) => $t['status'] === 'active'));
$inactive_tenants = count(array_filter($tenants, fn($t) => $t['status'] === 'inactive'));
$pending_tenants  = count(array_filter($tenants, fn($t) => $t['status'] === 'pending'));

try {
    $total_users    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role != 'super_admin'")->fetchColumn();
    $active_users   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='approved' AND is_suspended=0 AND role != 'super_admin'")->fetchColumn();
    $inactive_users = $total_users - $active_users;
} catch (PDOException $e) { $total_users = $active_users = $inactive_users = 0; }

try {
    $monthly_regs = $pdo->query("
        SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
               DATE_FORMAT(created_at,'%Y-%m') AS month_key, COUNT(*) AS count
        FROM users WHERE role != 'super_admin'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_key, month_label ORDER BY month_key ASC
    ")->fetchAll();
} catch (PDOException $e) { $monthly_regs = []; }

try {
    $monthly_tenants = $pdo->query("
        SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
               DATE_FORMAT(created_at,'%Y-%m') AS month_key, COUNT(*) AS count
        FROM tenants WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_key, month_label ORDER BY month_key ASC
    ")->fetchAll();
} catch (PDOException $e) { $monthly_tenants = []; }

$plan_dist = ['Starter' => 0, 'Pro' => 0, 'Enterprise' => 0];
foreach ($tenants as $t) { if (isset($plan_dist[$t['plan']])) $plan_dist[$t['plan']]++; }

// ── FETCH SYSTEM SETTINGS ─────────────────────────────────────
$sys_settings = [];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
    foreach ($rows as $row) { $sys_settings[$row['setting_key']] = $row['setting_value']; }
} catch (PDOException $e) {}

$ss = array_merge([
    'system_name'       => 'PawnHub',
    'system_tagline'    => 'Multi-Tenant Pawnshop Management',
    'starter_staff'     => 3,
    'starter_branches'  => 1,
    'pro_staff'         => 0,
    'pro_branches'      => 3,
    'ent_staff'         => 0,
    'ent_branches'      => 10,
    'starter_price'     => 'Free',
    'pro_price'         => '₱999/mo',
    'ent_price'         => '₱2,499/mo',
], $sys_settings);

// ── SUBSCRIPTION DATA ─────────────────────────────────────────
try {
    $sub_renewals = $pdo->query("
        SELECT sr.*, t.business_name, t.email, t.owner_name, t.slug, t.subscription_end, t.subscription_status,
               u.fullname AS reviewed_by_name
        FROM subscription_renewals sr
        JOIN tenants t ON sr.tenant_id = t.id
        LEFT JOIN users u ON sr.reviewed_by = u.id
        ORDER BY FIELD(sr.status,'pending','approved','rejected'), sr.requested_at DESC
        LIMIT 100
    ")->fetchAll();
} catch (PDOException $e) { $sub_renewals = []; }

$pending_sub_renewals = array_filter($sub_renewals, fn($r) => $r['status'] === 'pending');
$pending_sub_count    = count($pending_sub_renewals);


try {
    $all_tenants_sub = $pdo->query("
        SELECT id, business_name, email, owner_name, plan, status, slug,
               subscription_start, subscription_end, subscription_status,
               DATEDIFF(subscription_end, CURDATE()) AS days_left
        FROM tenants
        ORDER BY subscription_end ASC
    ")->fetchAll();
} catch (PDOException $e) { $all_tenants_sub = []; }

// ── FILTERS ──────────────────────────────────────────────────
$report_type      = $_GET['report_type']    ?? 'tenant_activity';
$filter_date_from = $_GET['date_from']      ?? date('Y-m-01');
$filter_date_to   = $_GET['date_to']        ?? date('Y-m-d');
$filter_tenant    = intval($_GET['filter_tenant'] ?? 0);
$filter_status    = $_GET['filter_status']  ?? '';

$sales_period     = $_GET['sales_period']   ?? 'monthly';
$sales_date_from  = $_GET['sales_from']     ?? date('Y-m-01');
$sales_date_to    = $_GET['sales_to']       ?? date('Y-m-d');
$sales_tenant     = intval($_GET['sales_tenant'] ?? 0);

$audit_date_from  = $_GET['audit_from']     ?? date('Y-m-01');
$audit_date_to    = $_GET['audit_to']       ?? date('Y-m-d');
$audit_action     = $_GET['audit_action']   ?? '';
$audit_actor      = trim($_GET['audit_actor'] ?? '');
$audit_page       = max(1, intval($_GET['audit_page'] ?? 1));
$audit_per_page   = 50;

// ── REPORT DATA ───────────────────────────────────────────────
$report_data = [];
if ($active_page === 'reports') {
    try {
        if ($report_type === 'tenant_activity') {
            $q = "SELECT t.id, t.business_name, t.owner_name, t.email, t.phone,
                    t.plan, t.status, t.branches, t.created_at,
                    COUNT(DISTINCT u.id) AS user_count,
                    COUNT(DISTINCT CASE WHEN u.role='admin'   THEN u.id END) AS admin_count,
                    COUNT(DISTINCT CASE WHEN u.role='staff'   THEN u.id END) AS staff_count,
                    COUNT(DISTINCT CASE WHEN u.role='cashier' THEN u.id END) AS cashier_count
                  FROM tenants t LEFT JOIN users u ON u.tenant_id=t.id AND u.role != 'super_admin'
                  WHERE DATE(t.created_at) BETWEEN ? AND ?";
            $params = [$filter_date_from, $filter_date_to];
            if ($filter_status) { $q .= " AND t.status=?"; $params[] = $filter_status; }
            if ($filter_tenant) { $q .= " AND t.id=?";     $params[] = $filter_tenant; }
            $q .= " GROUP BY t.id ORDER BY t.created_at DESC";
            $s = $pdo->prepare($q); $s->execute($params); $report_data = $s->fetchAll();

        } elseif ($report_type === 'user_registration') {
            $q = "SELECT u.id, u.fullname, u.username, u.email, u.role,
                    u.status, u.is_suspended, u.created_at, t.business_name
                  FROM users u LEFT JOIN tenants t ON u.tenant_id=t.id
                  WHERE u.role != 'super_admin' AND DATE(u.created_at) BETWEEN ? AND ?";
            $params = [$filter_date_from, $filter_date_to];
            if ($filter_status) { $q .= " AND u.status=?";    $params[] = $filter_status; }
            if ($filter_tenant) { $q .= " AND u.tenant_id=?"; $params[] = $filter_tenant; }
            $q .= " ORDER BY u.created_at DESC";
            $s = $pdo->prepare($q); $s->execute($params); $report_data = $s->fetchAll();

        } elseif ($report_type === 'usage_statistics') {
            $q = "SELECT t.id, t.business_name, t.plan, t.status, t.branches,
                    COUNT(DISTINCT u.id) AS total_users,
                    COUNT(DISTINCT CASE WHEN u.role='admin'   THEN u.id END) AS admin_count,
                    COUNT(DISTINCT CASE WHEN u.role='staff'   THEN u.id END) AS staff_count,
                    COUNT(DISTINCT CASE WHEN u.role='cashier' THEN u.id END) AS cashier_count,
                    COUNT(DISTINCT CASE WHEN u.status='approved' AND u.is_suspended=0 THEN u.id END) AS active_users,
                    COUNT(DISTINCT CASE WHEN u.is_suspended=1 THEN u.id END) AS suspended_users
                  FROM tenants t LEFT JOIN users u ON u.tenant_id=t.id AND u.role != 'super_admin'
                  WHERE 1=1";
            $params = [];
            if ($filter_tenant) { $q .= " AND t.id=?"; $params[] = $filter_tenant; }
            $q .= " GROUP BY t.id ORDER BY total_users DESC";
            $s = $pdo->prepare($q); $s->execute($params); $report_data = $s->fetchAll();
        }
    } catch (PDOException $e) { $error_msg = 'Report error: ' . $e->getMessage(); }
}

// ── SALES REPORT DATA ─────────────────────────────────────────
// ── SUPER ADMIN SUBSCRIPTION REVENUE REPORT ──────────────────
// Shows the Super Admin's OWN income from approved tenant subscription
// renewals — NOT the tenants' pawn transaction revenue.
$sales_data = $sales_summary = $sales_per_tenant = $top_tenants = $tx_history = [];
$sales_chart_labels = $sales_chart_data = [];

if ($active_page === 'sales_report') {
    try {
        $sq = "SELECT COUNT(*) AS total_transactions,
                      COUNT(DISTINCT sr.tenant_id) AS active_tenants,
                      COALESCE(SUM(sr.amount), 0)  AS total_revenue,
                      COALESCE(AVG(sr.amount), 0)  AS avg_transaction
               FROM subscription_renewals sr
               WHERE sr.status = 'approved'
                 AND DATE(sr.reviewed_at) BETWEEN ? AND ?";
        $sp = [$sales_date_from, $sales_date_to];
        if ($sales_tenant) { $sq .= " AND sr.tenant_id = ?"; $sp[] = $sales_tenant; }
        $s = $pdo->prepare($sq); $s->execute($sp); $sales_summary = $s->fetch();

        if ($sales_period === 'daily') {
            $tq = "SELECT DATE(sr.reviewed_at) AS period_label,
                          COUNT(*) AS tx_count,
                          COALESCE(SUM(sr.amount), 0) AS revenue
                   FROM subscription_renewals sr
                   WHERE sr.status = 'approved'
                     AND DATE(sr.reviewed_at) BETWEEN ? AND ?";
            $group = " GROUP BY DATE(sr.reviewed_at) ORDER BY DATE(sr.reviewed_at) ASC";
        } elseif ($sales_period === 'weekly') {
            $tq = "SELECT CONCAT(YEAR(sr.reviewed_at),'-W',LPAD(WEEK(sr.reviewed_at),2,'0')) AS period_label,
                          COUNT(*) AS tx_count,
                          COALESCE(SUM(sr.amount), 0) AS revenue
                   FROM subscription_renewals sr
                   WHERE sr.status = 'approved'
                     AND DATE(sr.reviewed_at) BETWEEN ? AND ?";
            $group = " GROUP BY period_label ORDER BY period_label ASC";
        } else {
            $tq = "SELECT DATE_FORMAT(sr.reviewed_at,'%b %Y') AS period_label,
                          DATE_FORMAT(sr.reviewed_at,'%Y-%m') AS sort_key,
                          COUNT(*) AS tx_count,
                          COALESCE(SUM(sr.amount), 0) AS revenue
                   FROM subscription_renewals sr
                   WHERE sr.status = 'approved'
                     AND DATE(sr.reviewed_at) BETWEEN ? AND ?";
            $group = " GROUP BY sort_key, period_label ORDER BY sort_key ASC";
        }
        $tp = [$sales_date_from, $sales_date_to];
        if ($sales_tenant) { $tq .= " AND sr.tenant_id = ?"; $tp[] = $sales_tenant; }
        $tq .= $group;
        $ts = $pdo->prepare($tq); $ts->execute($tp); $sales_data = $ts->fetchAll();
        $sales_chart_labels = array_column($sales_data, 'period_label');
        $sales_chart_data   = array_column($sales_data, 'revenue');

        $ptq = "SELECT t.business_name, t.plan,
                       COUNT(sr.id)                AS tx_count,
                       COALESCE(SUM(sr.amount), 0) AS revenue,
                       COALESCE(AVG(sr.amount), 0) AS avg_tx,
                       MAX(sr.reviewed_at)         AS last_tx
                FROM tenants t
                LEFT JOIN subscription_renewals sr
                  ON sr.tenant_id = t.id
                  AND sr.status = 'approved'
                  AND DATE(sr.reviewed_at) BETWEEN ? AND ?
                WHERE t.status = 'active'";
        $ptp = [$sales_date_from, $sales_date_to];
        if ($sales_tenant) { $ptq .= " AND t.id = ?"; $ptp[] = $sales_tenant; }
        $ptq .= " GROUP BY t.id ORDER BY revenue DESC";
        $pts = $pdo->prepare($ptq); $pts->execute($ptp);
        $sales_per_tenant = $pts->fetchAll();
        $top_tenants      = array_slice(array_filter($sales_per_tenant, fn($r) => $r['revenue'] > 0), 0, 5);

        $thq = "SELECT sr.id, sr.amount, sr.billing_cycle, sr.reviewed_at AS created_at,
                       sr.status, sr.payment_method, sr.payment_reference, t.business_name, t.plan
                FROM subscription_renewals sr
                LEFT JOIN tenants t ON t.id = sr.tenant_id
                WHERE sr.status = 'approved'
                  AND DATE(sr.reviewed_at) BETWEEN ? AND ?";
        $thp = [$sales_date_from, $sales_date_to];
        if ($sales_tenant) { $thq .= " AND sr.tenant_id = ?"; $thp[] = $sales_tenant; }
        $thq .= " ORDER BY sr.reviewed_at DESC LIMIT 100";
        $ths = $pdo->prepare($thq); $ths->execute($thp); $tx_history = $ths->fetchAll();

    } catch (PDOException $e) {
        $error_msg = 'Sales report error: ' . $e->getMessage();
        $sales_summary = ['total_transactions'=>0,'active_tenants'=>0,'total_revenue'=>0,'avg_transaction'=>0];
    }
}

// ── AUDIT LOG DATA ────────────────────────────────────────────
$audit_logs = []; $audit_total = 0; $audit_total_pages = 1;

if ($active_page === 'audit_logs') {
    try {
        $aq = "SELECT * FROM audit_logs WHERE DATE(created_at) BETWEEN ? AND ?";
        $ap = [$audit_date_from, $audit_date_to];
        if ($audit_action) { $aq .= " AND action=?";              $ap[] = $audit_action; }
        if ($audit_actor)  { $aq .= " AND actor_username LIKE ?"; $ap[] = "%$audit_actor%"; }
        $count_q = str_replace("SELECT *", "SELECT COUNT(*)", $aq);
        $cs = $pdo->prepare($count_q); $cs->execute($ap);
        $audit_total       = (int)$cs->fetchColumn();
        $audit_total_pages = max(1, ceil($audit_total / $audit_per_page));
        $audit_page        = min($audit_page, $audit_total_pages);
        $offset            = ($audit_page - 1) * $audit_per_page;
        $aq .= " ORDER BY created_at DESC LIMIT $audit_per_page OFFSET $offset";
        $as = $pdo->prepare($aq); $as->execute($ap); $audit_logs = $as->fetchAll();
    } catch (PDOException $e) { $error_msg = 'Audit log error: ' . $e->getMessage(); }
}

$audit_actions_list = [];
try { $audit_actions_list = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN); } catch(PDOException $e){}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
<link rel="manifest" href="site.webmanifest">



<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>PawnHub — Super Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--sw:252px;--navy:#0f172a;--blue-acc:#2563eb;--bg:#f1f5f9;--card:#fff;--border:#e2e8f0;--text:#1e293b;--text-m:#475569;--text-dim:#94a3b8;--success:#16a34a;--danger:#dc2626;--warning:#d97706;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden;width:100%;max-width:100vw;}
.sidebar{width:var(--sw);min-height:100vh;background:var(--navy);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;}
.sb-brand{padding:20px 18px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px;}
.sb-logo{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;}
.sb-logo img{width:36px;height:36px;object-fit:contain;border-radius:9px;}
.sb-name{font-size:.93rem;font-weight:800;color:#fff;}
.sb-badge{font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:#fff;padding:2px 7px;border-radius:100px;display:inline-block;margin-top:2px;}
.sb-user{padding:12px 18px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:9px;}
.sb-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#1d4ed8,#7c3aed);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.78rem;font-weight:600;color:#fff;} .sb-urole{font-size:.62rem;color:rgba(255,255,255,.35);}
.sb-nav{flex:1;padding:10px 0;}
.sb-section{font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.25);padding:10px 16px 4px;}
.sb-item{display:flex;align-items:center;gap:9px;padding:8px 16px;margin:1px 8px;border-radius:8px;cursor:pointer;color:rgba(255,255,255,.55);font-size:.82rem;font-weight:500;text-decoration:none;transition:all .15s;}
.sb-item:hover{background:rgba(255,255,255,.08);color:#fff;} .sb-item.active{background:rgba(37,99,235,.25);color:#60a5fa;font-weight:600;} .sb-item svg{width:15px;height:15px;flex-shrink:0;}
.sb-pill{margin-left:auto;background:#ef4444;color:#fff;font-size:.62rem;font-weight:700;padding:1px 6px;border-radius:100px;}
.sb-footer{padding:12px 14px;border-top:1px solid rgba(255,255,255,.08);}
.sb-logout{display:flex;align-items:center;gap:8px;font-size:.8rem;color:rgba(255,255,255,.35);text-decoration:none;padding:7px 8px;border-radius:8px;transition:all .15s;}
.sb-logout:hover{color:#f87171;background:rgba(239,68,68,.1);} .sb-logout svg{width:14px;height:14px;}
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;min-width:0;width:calc(100% - var(--sw));max-width:calc(100vw - var(--sw));overflow-x:hidden;}
.topbar{height:58px;padding:0 26px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;width:100%;box-sizing:border-box;}
.topbar-title{font-size:1rem;font-weight:700;white-space:nowrap;}
.super-chip{font-size:.7rem;font-weight:700;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:#fff;padding:3px 10px;border-radius:100px;white-space:nowrap;}
.content{padding:22px 26px;flex:1;min-width:0;overflow-x:hidden;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;width:100%;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;display:flex;align-items:flex-start;gap:12px;}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;} .stat-icon svg{width:18px;height:18px;}
.stat-label{font-size:.7rem;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.stat-value{font-size:1.5rem;font-weight:800;color:var(--text);line-height:1;} .stat-sub{font-size:.71rem;color:var(--text-dim);margin-top:2px;}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:16px;min-width:0;width:100%;box-sizing:border-box;}
.card-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;}
.card-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text);}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;width:100%;min-width:0;}
table{width:100%;border-collapse:collapse;}
th{font-size:.67rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-dim);padding:7px 11px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;}
td{padding:10px 11px;font-size:.81rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
tr:last-child td{border-bottom:none;} tr:hover td{background:#f8fafc;}
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.67rem;font-weight:700;padding:2px 8px;border-radius:100px;}
.b-blue{background:#dbeafe;color:#1d4ed8;} .b-green{background:#dcfce7;color:#15803d;} .b-red{background:#fee2e2;color:#dc2626;} .b-yellow{background:#fef3c7;color:#b45309;} .b-purple{background:#f3e8ff;color:#7c3aed;} .b-gray{background:#f1f5f9;color:#475569;} .b-orange{background:#ffedd5;color:#c2410c;} .b-teal{background:#ccfbf1;color:#0f766e;}
.plan-ent{background:linear-gradient(135deg,#dbeafe,#ede9fe);color:#4338ca;border:1px solid #c7d2fe;} .plan-pro{background:#fef3c7;color:#b45309;} .plan-starter{background:#f1f5f9;color:#475569;}
.b-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}
.btn-sm{padding:5px 12px;border-radius:7px;font-size:.73rem;font-weight:600;cursor:pointer;border:1px solid var(--border);background:#fff;color:var(--text-m);text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .15s;margin-right:4px;}
.btn-sm:hover{background:var(--bg);} .btn-primary{background:var(--blue-acc);color:#fff;border-color:var(--blue-acc);} .btn-success{background:var(--success);color:#fff;border-color:var(--success);} .btn-danger{background:var(--danger);color:#fff;border-color:var(--danger);} .btn-warning{background:var(--warning);color:#fff;border-color:var(--warning);}
.alert{padding:10px 16px;border-radius:10px;font-size:.82rem;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;} .alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;}
.empty-state{text-align:center;padding:40px 20px;color:var(--text-dim);}
.empty-state svg{width:34px;height:34px;margin:0 auto 9px;display:block;opacity:.3;} .empty-state p{font-size:.83rem;}
.ticket-tag{font-family:monospace;font-size:.77rem;color:var(--blue-acc);font-weight:700;}
.chart-wrap{position:relative;height:220px;}
.donut-wrap{position:relative;height:200px;display:flex;align-items:center;justify-content:center;}
.legend-row{display:flex;align-items:center;gap:6px;font-size:.74rem;color:var(--text-m);margin-bottom:4px;}
.legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;width:480px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:mIn .22s ease both;}
@keyframes mIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.mhdr{padding:20px 22px 0;display:flex;align-items:center;justify-content:space-between;}
.mtitle{font-size:1rem;font-weight:800;} .msub{font-size:.78rem;color:var(--text-dim);margin-top:2px;}
.mclose{width:28px;height:28px;border-radius:7px;border:1.5px solid var(--border);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-dim);}
.mclose svg{width:13px;height:13px;} .mbody{padding:18px 22px 22px;}
.flabel{display:block;font-size:.74rem;font-weight:600;color:var(--text-m);margin-bottom:4px;}
.finput{width:100%;border:1.5px solid var(--border);border-radius:8px;padding:9px 11px;font-family:inherit;font-size:.85rem;color:var(--text);outline:none;background:#fff;transition:border .2s;}
.finput:focus{border-color:var(--blue-acc);box-shadow:0 0 0 3px rgba(37,99,235,.1);} .finput::placeholder{color:#c8d0db;} select.finput{cursor:pointer;}
.filter-bar{display:flex;align-items:center;gap:8px 10px;flex-wrap:wrap;margin-bottom:16px;background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 16px;width:100%;box-sizing:border-box;}
.filter-bar label{font-size:.74rem;font-weight:600;color:var(--text-dim);white-space:nowrap;flex-shrink:0;}
.filter-bar .filter-group{display:flex;align-items:center;gap:6px;flex-shrink:0;}
.filter-bar .filter-actions{display:flex;align-items:center;gap:6px;margin-left:auto;flex-shrink:0;}
.filter-select{border:1.5px solid var(--border);border-radius:7px;padding:6px 10px;font-family:inherit;font-size:.81rem;color:var(--text);outline:none;background:#fff;transition:border .2s;min-width:110px;max-width:200px;}
.filter-input{border:1.5px solid var(--border);border-radius:7px;padding:6px 10px;font-family:inherit;font-size:.81rem;color:var(--text);outline:none;background:#fff;transition:border .2s;}
.filter-input[type="date"]{min-width:130px;}
.filter-input[type="text"]{min-width:140px;max-width:180px;}
.filter-select:focus,.filter-input:focus{border-color:var(--blue-acc);}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;}
.summary-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;}
.summary-item{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center;min-width:0;overflow:hidden;}
.summary-num{font-size:1.3rem;font-weight:800;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;} .summary-lbl{font-size:.7rem;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-top:2px;}
.act-approve,.act-activate{background:#dcfce7;color:#15803d;} .act-reject,.act-deactivate,.act-delete{background:#fee2e2;color:#dc2626;}
.act-login{background:#f3e8ff;color:#7c3aed;} .act-logout{background:#f1f5f9;color:#475569;}
.act-create,.act-add{background:#ccfbf1;color:#0f766e;} .act-update,.act-edit{background:#fef3c7;color:#b45309;} .act-other{background:#f1f5f9;color:#475569;}
.rank-1{background:linear-gradient(135deg,#fef3c7,#fde68a);color:#92400e;border:1px solid #fcd34d;}
.rank-2{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
.rank-3{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;}
.pagination{display:flex;align-items:center;gap:6px;margin-top:16px;flex-wrap:wrap;}
.page-btn{padding:5px 11px;border-radius:7px;font-size:.74rem;font-weight:600;border:1.5px solid var(--border);background:#fff;color:var(--text-m);text-decoration:none;transition:all .15s;}
.page-btn:hover{background:var(--bg);} .page-btn.active{background:var(--blue-acc);color:#fff;border-color:var(--blue-acc);}
/* Subscription page dark cards */
.sub-stat-card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px;}
.page-section{color:#fff;}
@media(max-width:1200px){
  .stats-grid,.summary-grid{grid-template-columns:repeat(2,1fr);}
  .two-col{grid-template-columns:1fr;}
}
@media(max-width:900px){
  .filter-bar{gap:8px;}
  .filter-bar .filter-actions{margin-left:0;}
}
@media(max-width:768px){
  /* Sidebar */
  .sidebar{transform:translateX(-100%);transition:transform .3s ease;box-shadow:none;}
  .sidebar.mobile-open{transform:translateX(0);box-shadow:4px 0 30px rgba(0,0,0,.5);}
  /* Main layout */
  .main{margin-left:0!important;width:100%;max-width:100vw;}
  .topbar{padding:0 14px;}
  #mob-menu-btn{display:flex!important;}
  .mob-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99;backdrop-filter:blur(2px);}
  .mob-overlay.open{display:block;}
  .topbar-title{font-size:.88rem;}
  .content{padding:12px;overflow-x:hidden;}
  /* Stats grid: 2x2 on mobile */
  .stats-grid{grid-template-columns:repeat(2,1fr);gap:10px;}
  .stat-card{flex-direction:row;align-items:center;gap:10px;padding:12px 14px;}
  .stat-icon{width:34px;height:34px;flex-shrink:0;}
  .stat-value{font-size:1.25rem;}
  .stat-sub{font-size:.68rem;}
  /* Summary grids: 2 cols */
  .summary-grid,.summary-grid-3{grid-template-columns:repeat(2,1fr);}
  /* two-col: single column */
  .two-col{grid-template-columns:1fr;}
  /* Cards */
  .card{padding:14px;}
  /* Tables — scrollable horizontally, never overflow */
  .card{overflow:hidden;}
  div[style*="overflow-x:auto"]{
    overflow-x:auto!important;
    -webkit-overflow-scrolling:touch;
    max-width:100%;
  }
  /* Filter bar */
  .filter-bar{flex-direction:column;align-items:stretch;gap:8px;}
  .filter-bar .filter-group{width:100%;flex-direction:row;align-items:center;}
  .filter-bar .filter-group label{min-width:48px;flex-shrink:0;}
  .filter-bar .filter-group .filter-input,
  .filter-bar .filter-group .filter-select{flex:1;width:100%;min-width:0;max-width:100%;}
  .filter-bar .filter-actions{width:100%;display:flex;gap:8px;}
  .filter-bar .filter-actions .btn-sm{flex:1;justify-content:center;}
  /* Charts */
  .chart-wrap{height:180px!important;}
  .donut-wrap{height:160px!important;}
}
@media(max-width:480px){
  .stats-grid{grid-template-columns:repeat(2,1fr);gap:8px;}
  .stat-card{padding:10px 12px;}
  .stat-value{font-size:1.1rem;}
  .stat-icon{width:30px;height:30px;}
  .summary-grid,.summary-grid-3{grid-template-columns:repeat(2,1fr);}
  .summary-num{font-size:1.1rem;}
  .content{padding:10px;}
  .card{padding:12px;}
  .card-hdr{flex-direction:column;align-items:flex-start;gap:4px;}
  .topbar-title{font-size:.82rem;}
}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════════ -->
<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><img src="data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/4gHYSUNDX1BST0ZJTEUAAQEAAAHIAAAAAAQwAABtbnRyUkdCIFhZWiAH4AABAAEAAAAAAABhY3NwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAA9tYAAQAAAADTLQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAlkZXNjAAAA8AAAACRyWFlaAAABFAAAABRnWFlaAAABKAAAABRiWFlaAAABPAAAABR3dHB0AAABUAAAABRyVFJDAAABZAAAAChnVFJDAAABZAAAAChiVFJDAAABZAAAAChjcHJ0AAABjAAAADxtbHVjAAAAAAAAAAEAAAAMZW5VUwAAAAgAAAAcAHMAUgBHAEJYWVogAAAAAAAAb6IAADj1AAADkFhZWiAAAAAAAABimQAAt4UAABjaWFlaIAAAAAAAACSgAAAPhAAAts9YWVogAAAAAAAA9tYAAQAAAADTLXBhcmEAAAAAAAQAAAACZmYAAPKnAAANWQAAE9AAAApbAAAAAAAAAABtbHVjAAAAAAAAAAEAAAAMZW5VUwAAACAAAAAcAEcAbwBvAGcAbABlACAASQBuAGMALgAgADIAMAAxADb/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAfQB9ADASIAAhEBAxEB/8QAHAABAQADAQEBAQAAAAAAAAAAAAgFBgcEAwIB/8QAWBABAAEDAQQCDAgJCQYGAgIDAAECAwQFBgcREiFRCBMUFTFBYXGBkZTRIjdVVqGisbIWFxgyM2JydJIjQlJzgpOz0tMkNXWEpME2Q1NjlbQl4cLwNETi/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AJ/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA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AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAB//9k=" alt="PawnHub Logo"/></div>
    <div><div class="sb-name">PawnHub</div><div class="sb-badge">Super Admin</div></div>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($u['name'], 0, 1)) ?></div>
    <div><div class="sb-uname"><?= htmlspecialchars($u['name']) ?></div><div class="sb-urole">Super Administrator</div></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Overview</div>
    <a href="?page=dashboard" class="sb-item <?= $active_page==='dashboard'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard
    </a>
    <div class="sb-section">Management</div>
    <a href="?page=tenants" class="sb-item <?= $active_page==='tenants'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>Tenant Management
      <?php if($pending_tenants>0):?><span class="sb-pill"><?=$pending_tenants?></span><?php endif;?>
    </a>
    <a href="?page=invitations" class="sb-item <?= $active_page==='invitations'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Email Invitations
      <?php if($pending_inv>0):?><span class="sb-pill"><?=$pending_inv?></span><?php endif;?>
    </a>
    <a href="?page=subscriptions" class="sb-item <?= $active_page==='subscriptions'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Subscriptions
      <?php if($pending_sub_count>0):?><span class="sb-pill"><?=$pending_sub_count?></span><?php endif;?>
    </a>
    <div class="sb-section">Analytics</div>
    <a href="?page=reports" class="sb-item <?= $active_page==='reports'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Reports
    </a>
    <a href="?page=sales_report" class="sb-item <?= $active_page==='sales_report'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>Sales Report
    </a>
    <div class="sb-section">System</div>
    <a href="?page=audit_logs" class="sb-item <?= $active_page==='audit_logs'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Audit Logs
    </a>
    <a href="?page=settings" class="sb-item <?= $active_page==='settings'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Settings
    </a>
  </nav>
  <div class="sb-footer">
    <button type="button" class="sb-logout" onclick="showLogoutModal('logout.php?role=super_admin')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sign Out
    </button>
  </div>
</aside>

<!-- ══ MAIN ══════════════════════════════════════════════════════ -->
<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <button id="mob-menu-btn" onclick="toggleSidebar()" style="display:none;width:36px;height:36px;border:1px solid var(--border);border-radius:8px;background:#fff;cursor:pointer;align-items:center;justify-content:center;flex-shrink:0;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span class="topbar-title">
        <?php $titles=['dashboard'=>'System Dashboard','tenants'=>'Tenant Management','invitations'=>'Email Invitations','subscriptions'=>'Subscription Management','reports'=>'Reports','sales_report'=>'Sales Report','audit_logs'=>'Audit Logs','settings'=>'System Settings'];
        echo $titles[$active_page]??'Dashboard'; ?>
      </span>
      <span class="super-chip">SUPER ADMIN</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
      <button onclick="document.getElementById('addTenantModal').classList.add('open')" class="btn-sm btn-primary topbar-add-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:13px;height:13px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg><span class="btn-add-full">Add Tenant + Invite</span><span class="btn-add-short">+ Add</span>
      </button>
      <!-- Notification Bell -->
      <div style="position:relative;display:inline-block;">
        <button id="notifBtn" onclick="toggleNotif()" style="position:relative;width:36px;height:36px;border:1px solid var(--border);border-radius:10px;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:17px;height:17px;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <?php if ($notif_count > 0): ?>
          <span style="position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;font-size:.6rem;font-weight:800;min-width:16px;height:16px;border-radius:100px;display:flex;align-items:center;justify-content:center;padding:0 3px;border:2px solid #fff;"><?= $notif_count ?></span>
          <?php endif; ?>
        </button>
        <!-- Dropdown -->
        <div id="notifDropdown" style="display:none;position:absolute;right:0;top:44px;width:320px;background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.12);z-index:999;overflow:hidden;">
          <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:.82rem;font-weight:700;color:#0f172a;">💳 Recent Payments</span>
            <span style="font-size:.72rem;color:#64748b;">Last 7 days</span>
          </div>
          <div style="max-height:320px;overflow-y:auto;">
            <?php if (empty($notif_items)): ?>
              <div style="padding:24px 16px;text-align:center;color:#94a3b8;font-size:.82rem;">No recent payments</div>
            <?php else: ?>
              <?php foreach ($notif_items as $n): ?>
              <div style="padding:11px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:flex-start;gap:10px;cursor:pointer;transition:background .12s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''" onclick="window.location='?page=tenants'">
                <div style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#dcfce7,#bbf7d0);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;">
                  <span style="font-size:.9rem;">💳</span>
                </div>
                <div style="flex:1;min-width:0;">
                  <div style="font-size:.8rem;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($n['business_name']) ?></div>
                  <div style="font-size:.73rem;color:#475569;margin-top:1px;"><?= htmlspecialchars($n['owner_name']) ?> · <span style="color:#16a34a;font-weight:600;"><?= htmlspecialchars($n['plan']) ?> Plan</span> <span style="color:#2563eb;font-weight:600;"><?php $pt=$n['payment_type']??'signup'; echo match($pt){'renewal'=>'Renewed','upgrade'=>'Upgraded','reactivation'=>'Reactivated','downgrade'=>'Downgraded',default=>'Signup'}; ?></span></div>
                  <div style="font-size:.68rem;color:#94a3b8;margin-top:2px;"><?= date('M d, Y · h:i A', strtotime($n['paymongo_paid_at'])) ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div style="padding:10px 16px;text-align:center;border-top:1px solid #f1f5f9;">
            <a href="?page=tenants" style="font-size:.78rem;color:#2563eb;font-weight:600;text-decoration:none;">View all tenants →</a>
          </div>
        </div>
      </div>
      <div class="topbar-date" style="font-size:.78rem;color:var(--text-dim);"><?= date('F d, Y') ?></div>
    </div>
  </header>

  <div class="content">
    <?php if($success_msg):?><div class="alert alert-success">✅ <?=$success_msg?></div><?php endif;?>
    <?php if($error_msg):?><div class="alert alert-error">⚠ <?=htmlspecialchars($error_msg)?></div><?php endif;?>

    <!-- ══ DASHBOARD ═══════════════════════════════════════════ -->
    <?php if($active_page==='dashboard'): ?>
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background:#dbeafe;"><svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg></div>
          <div><div class="stat-label">Total Tenants</div><div class="stat-value"><?=$total_tenants?></div><div class="stat-sub"><?=$active_tenants?> active · <?=$pending_tenants?> pending</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#dcfce7;"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
          <div><div class="stat-label">Total Users</div><div class="stat-value"><?=$total_users?></div><div class="stat-sub"><?=$active_users?> active · <?=$inactive_users?> inactive</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
          <div><div class="stat-label">Pending Approvals</div><div class="stat-value" style="color:var(--warning);"><?=$pending_tenants?></div><div class="stat-sub">Tenants awaiting review</div></div>
        </div>
        <?php try{$sc=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_suspended=1 AND role!='super_admin'")->fetchColumn();}catch(PDOException $e){$sc=0;}?>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fee2e2;"><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="8" x2="23" y2="14"/><line x1="23" y1="8" x2="17" y2="14"/></svg></div>
          <div><div class="stat-label">Suspended Users</div><div class="stat-value" style="color:var(--danger);"><?=$sc?></div><div class="stat-sub">Across all tenants</div></div>
        </div>
      </div>

      <div class="two-col">
        <div class="card"><div class="card-hdr"><span class="card-title">👥 User Growth (6 Months)</span></div><div class="chart-wrap"><canvas id="userGrowthChart"></canvas></div></div>
        <div class="card"><div class="card-hdr"><span class="card-title">🏢 New Tenants (6 Months)</span></div><div class="chart-wrap"><canvas id="tenantActivityChart"></canvas></div></div>
      </div>

      <div class="two-col">
        <div class="card">
          <div class="card-hdr"><span class="card-title">👤 User Role Distribution</span></div>
          <?php try{$rc=$pdo->query("SELECT role,COUNT(*) AS cnt FROM users WHERE role!='super_admin' GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);}catch(PDOException $e){$rc=[];}
          $rca=(int)($rc['admin']??0);$rcs=(int)($rc['staff']??0);$rcc=(int)($rc['cashier']??0);?>
          <div style="display:flex;align-items:center;gap:24px;">
            <div class="donut-wrap" style="flex:1;"><canvas id="roleDonutChart"></canvas></div>
            <div style="flex-shrink:0;">
              <div class="legend-row"><span class="legend-dot" style="background:#2563eb;"></span>Admin — <?=$rca?></div>
              <div class="legend-row"><span class="legend-dot" style="background:#16a34a;"></span>Staff — <?=$rcs?></div>
              <div class="legend-row"><span class="legend-dot" style="background:#d97706;"></span>Cashier — <?=$rcc?></div>
              <div style="margin-top:10px;font-size:.72rem;color:var(--text-dim);border-top:1px solid var(--border);padding-top:8px;">Total: <?=$rca+$rcs+$rcc?> users</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-hdr"><span class="card-title">⭐ Plan Distribution</span></div>
          <div style="display:flex;align-items:center;gap:24px;">
            <div class="donut-wrap" style="flex:1;"><canvas id="planDonutChart"></canvas></div>
            <div style="flex-shrink:0;">
              <div class="legend-row"><span class="legend-dot" style="background:#475569;"></span>Starter — <?=$plan_dist['Starter']?></div>
              <div class="legend-row"><span class="legend-dot" style="background:#b45309;"></span>Pro — <?=$plan_dist['Pro']?></div>
              <div class="legend-row"><span class="legend-dot" style="background:#4338ca;"></span>Enterprise — <?=$plan_dist['Enterprise']?></div>
              <div style="margin-top:10px;font-size:.72rem;color:var(--text-dim);border-top:1px solid var(--border);padding-top:8px;">Total: <?=$total_tenants?> tenants</div>
            </div>
          </div>
        </div>
      </div>

      <div class="two-col">
        <div class="card">
          <div class="card-hdr"><span class="card-title">📊 Tenant Status Overview</span></div>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach([['Active',$active_tenants,$total_tenants,'#16a34a'],['Pending',$pending_tenants,$total_tenants,'#d97706'],['Inactive',$inactive_tenants,$total_tenants,'#dc2626']] as [$lbl,$val,$tot,$color]):?>
            <div><div style="display:flex;justify-content:space-between;font-size:.78rem;font-weight:600;margin-bottom:4px;"><span><?=$lbl?></span><span style="color:<?=$color?>;"><?=$val?>/<?=$tot?></span></div><div style="height:7px;background:#f1f5f9;border-radius:100px;overflow:hidden;"><?php $pct=$tot>0?round($val/$tot*100):0;?><div style="height:100%;width:<?=$pct?>%;background:<?=$color?>;border-radius:100px;"></div></div></div>
            <?php endforeach;?>
          </div>
        </div>
        <div class="card">
          <div class="card-hdr"><span class="card-title">🕐 Recent Tenants</span><a href="?page=tenants" style="font-size:.74rem;color:var(--blue-acc);font-weight:600;text-decoration:none;">View All →</a></div>
          <?php if(empty($tenants)):?><div class="empty-state"><p>No tenants yet.</p></div>
          <?php else:?><div style="overflow-x:auto;"><table><thead><tr><th>Business</th><th>Plan</th><th>Status</th><th>Users</th><th>Date</th></tr></thead><tbody>
          <?php foreach(array_slice($tenants,0,6) as $t):?>
          <tr><td style="font-weight:600;"><?=htmlspecialchars($t['business_name'])?></td><td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':($t['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$t['plan']?></span></td><td><span class="badge <?=$t['status']==='active'?'b-green':($t['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($t['status'])?></span></td><td><?=$t['user_count']?></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($t['created_at']))?></td></tr>
          <?php endforeach;?></tbody></table></div><?php endif;?>
        </div>
      </div>

      <script>
      const cd={plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{font:{size:10},color:'#94a3b8'}},y:{grid:{color:'#f1f5f9'},ticks:{font:{size:10},color:'#94a3b8'},beginAtZero:true}}};
      new Chart(document.getElementById('userGrowthChart'),{type:'bar',data:{labels:<?=json_encode(array_column($monthly_regs,'month_label'))?>,datasets:[{data:<?=json_encode(array_column($monthly_regs,'count'))?>,backgroundColor:'rgba(37,99,235,0.15)',borderColor:'#2563eb',borderWidth:2,borderRadius:6}]},options:{...cd,responsive:true,maintainAspectRatio:false}});
      new Chart(document.getElementById('tenantActivityChart'),{type:'bar',data:{labels:<?=json_encode(array_column($monthly_tenants,'month_label'))?>,datasets:[{data:<?=json_encode(array_column($monthly_tenants,'count'))?>,backgroundColor:'rgba(16,185,129,0.15)',borderColor:'#10b981',borderWidth:2,borderRadius:6}]},options:{...cd,responsive:true,maintainAspectRatio:false}});
      new Chart(document.getElementById('roleDonutChart'),{type:'doughnut',data:{labels:['Admin','Staff','Cashier'],datasets:[{data:[<?=$rca?>,<?=$rcs?>,<?=$rcc?>],backgroundColor:['#2563eb','#16a34a','#d97706'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{display:false}}}});
      new Chart(document.getElementById('planDonutChart'),{type:'doughnut',data:{labels:['Starter','Pro','Enterprise'],datasets:[{data:[<?=$plan_dist['Starter']?>,<?=$plan_dist['Pro']?>,<?=$plan_dist['Enterprise']?>],backgroundColor:['#94a3b8','#d97706','#4338ca'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{display:false}}}});
      </script>

    <!-- ══ TENANT MANAGEMENT ════════════════════════════════════ -->
    <?php elseif($active_page==='tenants'): ?>
      <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon" style="background:#dbeafe;"><svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg></div><div><div class="stat-label">Total</div><div class="stat-value"><?=$total_tenants?></div></div></div>
        <div class="stat-card"><div class="stat-icon" style="background:#dcfce7;"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg></div><div><div class="stat-label">Active</div><div class="stat-value" style="color:var(--success);"><?=$active_tenants?></div></div></div>
        <div class="stat-card"><div class="stat-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div><div class="stat-label">Pending</div><div class="stat-value" style="color:var(--warning);"><?=$pending_tenants?></div></div></div>
        <div class="stat-card"><div class="stat-icon" style="background:#fee2e2;"><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div><div><div class="stat-label">Inactive</div><div class="stat-value" style="color:var(--danger);"><?=$inactive_tenants?></div></div></div>
      </div>

      <?php
      // Pending Approval: SA-added tenants + self-signup Starter + self-signup Paid but NOT yet paid
      // Self-signup Paid+Paid tenants are auto-moved to Permit Review — no need to approve here
      $pts = array_filter($tenants, function($t) {
          if ($t['status'] !== 'pending') return false;
          $is_sa_added   = !empty($t['invite_status']);
          $is_free       = ($t['plan'] === 'Starter');
          $is_paid_plan  = in_array($t['plan'], ['Pro','Enterprise']);
          $has_paid      = ($t['payment_status'] === 'paid');
          // Exclude self-signup paid-plan tenants who already paid — they belong in Permit Review
          if (!$is_sa_added && $is_paid_plan && $has_paid) return false;
          return true;
      });
      if(!empty($pts)):?>
      <div class="card" style="border-color:#fde68a;">
        <div class="card-hdr"><span class="card-title" style="color:#b45309;">⏳ Pending Approval (<?=count($pts)?>)</span></div>
        <div style="overflow-x:auto;"><table><thead><tr><th>Business Name</th><th>Owner</th><th>Email</th><th>Plan</th><th>Payment</th><th>Applied</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($pts as $t):
          $pmt_status = $t['payment_status'] ?? null;
          $is_free    = ($t['plan'] === 'Starter');
          if ($is_free) {
            $pmt_badge = '<span class="badge b-gray" style="font-size:.68rem;">Free</span>';
          } elseif ($pmt_status === 'paid') {
            $pmt_badge = '<span class="badge b-green" style="font-size:.68rem;">💳 Paid</span>';
          } else {
            $pmt_badge = '<span class="badge b-yellow" style="font-size:.68rem;">⏳ Unpaid</span>';
          }
        ?>
        <tr>
          <td style="font-weight:600;"><?=htmlspecialchars($t['business_name'])?></td>
          <td><?=htmlspecialchars($t['owner_name'])?></td>
          <td style="font-size:.76rem;color:var(--text-dim);"><?=htmlspecialchars($t['email'])?></td>
          <td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':($t['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$t['plan']?></span></td>
          <td><?= $pmt_badge ?></td>
          <td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($t['created_at']))?></td>
          <td style="white-space:nowrap;">
            <?php
              $is_sa_added = !empty($t['invite_status']); // SA-added tenants have an invite record
            ?>
            <?php if ($is_sa_added): ?>
              <?php /* SA manually added — payment link auto-sent on add; show status only */ ?>
              <?php if (!$is_free && $pmt_status !== 'paid'): ?>
                <span style="font-size:.72rem;color:#f59e0b;font-weight:600;">⏳ Awaiting Payment</span>
              <?php else: ?>
                <span style="font-size:.72rem;color:#16a34a;font-weight:600;">✓ Paid — invite will auto-send</span>
              <?php endif; ?>
            <?php else: ?>
              <?php /* Self-signup — needs approval flow */ ?>
              <button onclick="openApproveModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-success" style="font-size:.7rem;">✓ Approve</button>
              <button onclick="openRejectModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-danger" style="font-size:.7rem;">✗ Reject</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach;?></tbody></table></div>
      </div>
      <?php endif;?>

      <?php
      // Pending Permit Review:
      // 1. Active tenants (Pro/Enterprise, self-signup) whose permit not yet reviewed
      // 2. ALSO pending self-signup paid-plan tenants who already paid — show here instead of Pending Approval
      $permit_review_tenants = array_filter($tenants, function($t) {
          $is_sa_added  = !empty($t['invite_status']);
          $is_paid_plan = in_array($t['plan'], ['Pro','Enterprise']);
          $has_paid     = ($t['payment_status'] === 'paid');
          $permit_reviewed = in_array($t['business_permit_status'] ?? 'pending', ['sa_approved','sa_rejected']);

          // Case 1: already active, self-signup, paid plan, has permit, not yet reviewed
          if ($t['status'] === 'active' && $is_paid_plan && !$is_sa_added && !empty($t['business_permit_url']) && !$permit_reviewed) return true;

          // Case 2: still pending, self-signup, paid plan, already paid — needs permit review + approval
          if ($t['status'] === 'pending' && !$is_sa_added && $is_paid_plan && $has_paid) return true;

          return false;
      });
      if (!empty($permit_review_tenants)): ?>
      <div class="card" style="border-color:#fb923c;">
        <div class="card-hdr"><span class="card-title" style="color:#c2410c;">🔍 Pending Permit Review (<?=count($permit_review_tenants)?>)</span><span style="font-size:.73rem;color:#92400e;">Review business permits of newly activated tenants</span></div>
        <div style="overflow-x:auto;"><table><thead><tr><th>Business Name</th><th>Owner</th><th>Plan</th><th>Permit</th><th>Paid</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($permit_review_tenants as $t): ?>
        <tr>
          <td style="font-weight:600;"><?=htmlspecialchars($t['business_name'])?></td>
          <td style="font-size:.78rem;color:var(--text-dim);"><?=htmlspecialchars($t['owner_name'])?></td>
          <td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':'plan-pro'?>"><?=$t['plan']?></span></td>
          <td>
            <?php if (!empty($t['business_permit_url'])): ?>
              <?php $ext = strtolower(pathinfo($t['business_permit_url'], PATHINFO_EXTENSION)); ?>
              <?php if ($ext === 'pdf'): ?>
                <a href="<?=htmlspecialchars($t['business_permit_url'])?>" target="_blank" class="btn-sm" style="font-size:.69rem;background:#0f172a;color:#fff;border:none;">📄 View PDF</a>
              <?php else: ?>
                <a href="<?=htmlspecialchars($t['business_permit_url'])?>" target="_blank" class="btn-sm" style="font-size:.69rem;background:#0f172a;color:#fff;border:none;">🖼 View Image</a>
              <?php endif; ?>
            <?php else: ?>
              <span style="font-size:.72rem;color:#94a3b8;">No file</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($t['payment_status'] === 'paid'): ?>
              <span class="badge b-green" style="font-size:.68rem;">💳 Paid</span>
            <?php else: ?>
              <span class="badge b-yellow" style="font-size:.68rem;">⏳ Unpaid</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <?php if ($t['status'] === 'pending'): ?>
              <?php /* Self-signup paid+paid — needs permit check AND tenant approval */ ?>
              <button onclick="openApproveModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-success" style="font-size:.69rem;">✓ Approve</button>
              <button onclick="openRejectModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-danger" style="font-size:.69rem;">✗ Reject</button>
            <?php else: ?>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Approve business permit for <?=htmlspecialchars(addslashes($t['business_name']))?> ?');">
                <input type="hidden" name="action" value="approve_permit"/>
                <input type="hidden" name="tenant_id" value="<?=$t['id']?>"/>
                <button type="submit" class="btn-sm btn-success" style="font-size:.69rem;">✓ Approve Permit</button>
              </form>
              <button onclick="openRejectPermitModal(<?=$t['id']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-danger" style="font-size:.69rem;">✗ Reject Permit</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach;?></tbody></table></div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-hdr"><span class="card-title">🏢 All Tenants</span><span style="font-size:.75rem;color:var(--text-dim);"><?=$total_tenants?> total</span></div>
        <?php if(empty($tenants)):?><div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg><p>No tenants yet.</p></div>
        <?php else:?><div style="overflow-x:auto;-webkit-overflow-scrolling:touch;"><table style="font-size:.79rem;min-width:600px;"><thead><tr><th style="width:40px;">ID</th><th>Business Name</th><th>Email</th><th style="white-space:nowrap;">Plan</th><th>Status</th></tr></thead><tbody>
        <?php foreach($tenants as $t): ?>
        <tr>
          <td style="color:var(--text-dim);font-size:.7rem;">#<?=$t['id']?></td>
          <td>
            <div style="font-weight:600;font-size:.8rem;"><?=htmlspecialchars($t['business_name'])?></div>
            <div style="font-size:.68rem;color:var(--text-dim);"><?=htmlspecialchars($t['owner_name'])?></div>
          </td>
          <td style="font-size:.71rem;color:var(--text-dim);"><?=htmlspecialchars($t['email'])?></td>
          <td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':($t['plan']==='Pro'?'plan-pro':'plan-starter')?>" style="font-size:.63rem;"><?=$t['plan']?></span></td>
          <td><span class="badge <?=$t['status']==='active'?'b-green':($t['status']==='pending'?'b-yellow':($t['status']==='inactive'?'b-red':'b-gray'))?>" style="font-size:.63rem;"><span class="b-dot"></span><?=ucfirst($t['status'])?></span></td>
        </tr>
        <?php endforeach;?></tbody></table></div><?php endif;?>
      </div>

    <!-- ══ SUBSCRIPTIONS PAGE ═══════════════════════════════════ -->
    <?php elseif($active_page==='subscriptions'): ?>
    <div class="page-section" style="color:inherit;">

      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
        <div>
          <h2 style="font-size:1.1rem;font-weight:800;color:var(--text);margin:0 0 2px;">Subscription Management</h2>
          <p style="font-size:.78rem;color:var(--text-dim);margin:0;">Monitor tenant subscriptions, approve renewals, and manage billing.</p>
        </div>
        <form method="POST" action="" style="margin:0;">
          <input type="hidden" name="action" value="run_sub_cron"/>
          <button type="submit" class="btn-sm" style="display:flex;align-items:center;gap:6px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            Run Expiry Check
          </button>
        </form>
      </div>

      <!-- Stats -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:24px;">
        <?php
        $total_sub     = count($all_tenants_sub);
        $active_sub    = count(array_filter($all_tenants_sub, fn($t) => $t['subscription_status'] === 'active' && ($t['days_left'] ?? 999) > 7));
        $expiring_sub  = count(array_filter($all_tenants_sub, fn($t) => in_array($t['subscription_status'],['active','expiring_soon']) && isset($t['days_left']) && $t['days_left'] >= 0 && $t['days_left'] <= 7));
        $expired_sub   = count(array_filter($all_tenants_sub, fn($t) => $t['subscription_status'] === 'expired' || (isset($t['days_left']) && $t['days_left'] < 0)));
        $sub_stats = [
          ['label'=>'Total Tenants',      'value'=>$total_sub,    'icon'=>'#2563eb', 'emoji'=>'🏢'],
          ['label'=>'Active',             'value'=>$active_sub,   'icon'=>'#16a34a', 'emoji'=>'✅'],
          ['label'=>'Expiring (≤7 days)', 'value'=>$expiring_sub, 'icon'=>'#d97706', 'emoji'=>'⚠️'],
          ['label'=>'Expired',            'value'=>$expired_sub,  'icon'=>'#dc2626', 'emoji'=>'❌'],
          ['label'=>'Pending Renewals',   'value'=>$pending_sub_count,'icon'=>'#7c3aed','emoji'=>'🔔'],
        ];
        foreach ($sub_stats as $s): ?>
        <div class="stat-card">
          <div>
            <div class="stat-label"><?= $s['label'] ?></div>
            <div class="stat-value" style="color:<?= $s['icon'] ?>;"><?= $s['value'] ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Pending Renewals -->
      <?php if (!empty($pending_sub_renewals)): ?>
      <div class="card" style="border-color:#fde68a;margin-bottom:24px;">
        <div class="card-hdr">
          <span class="card-title" style="color:#b45309;">🔔 Pending Requests (<?= count($pending_sub_renewals) ?>)</span>
        </div>
        <?php foreach ($pending_sub_renewals as $pr):
          $pr_is_upgrade   = !empty($pr['is_upgrade']) && !empty($pr['upgrade_to']);
          $pr_notes_text   = $pr['notes'] ?? '';
          $pr_is_scheduled = (strpos($pr_notes_text, '[SCHEDULED') !== false);

          // Detect downgrade: not marked as upgrade, but upgrade_from/upgrade_to columns set and from > to
          $plan_hier_sa  = ['Starter' => 0, 'Pro' => 1, 'Enterprise' => 2];
          $pr_from_plan  = trim($pr['upgrade_from'] ?? '');
          $pr_to_plan    = trim($pr['upgrade_to']   ?? $pr['plan']);
          $pr_is_downgrade = !$pr_is_upgrade
              && !empty($pr_from_plan) && !empty($pr_to_plan)
              && isset($plan_hier_sa[$pr_from_plan]) && isset($plan_hier_sa[$pr_to_plan])
              && $plan_hier_sa[$pr_to_plan] < $plan_hier_sa[$pr_from_plan];

          $pr_from       = $pr_is_upgrade || $pr_is_downgrade ? htmlspecialchars($pr_from_plan ?: $pr['plan']) : htmlspecialchars($pr['plan']);
          $pr_to         = $pr_is_upgrade || $pr_is_downgrade ? htmlspecialchars($pr_to_plan)                  : htmlspecialchars($pr['plan']);
          $pr_proration  = (float)($pr['proration_credit'] ?? 0);

          if ($pr_is_upgrade) {
              $pr_type_label = $pr_is_scheduled ? '📅 SCHEDULED UPGRADE' : 'UPGRADE';
              $pr_card_bg    = '#f3e8ff'; $pr_card_border = '#ddd6fe';
              $pr_type_color = '#7c3aed'; $pr_type_bg = '#f3e8ff';
          } elseif ($pr_is_downgrade) {
              $pr_type_label = $pr_is_scheduled ? '📅 SCHEDULED DOWNGRADE' : 'DOWNGRADE';
              $pr_card_bg    = '#f0fdf4'; $pr_card_border = '#bbf7d0';
              $pr_type_color = '#15803d'; $pr_type_bg = '#dcfce7';
          } else {
              $pr_type_label = 'RENEWAL';
              $pr_card_bg    = '#fffbeb'; $pr_card_border = '#fde68a';
              $pr_type_color = '#b45309'; $pr_type_bg = '#fef3c7';
          }

          if ($pr_is_upgrade) {
              $pr_confirm_msg = $pr_is_scheduled
                  ? "Confirm SCHEDULED UPGRADE for {$pr['business_name']}?\\n\\n{$pr_from} → {$pr_to}\\n\\nThe plan will switch after their current subscription ends."
                  : "Approve UPGRADE for {$pr['business_name']}?\\n\\n{$pr_from} → {$pr_to}\\n\\nThis will change their plan to {$pr_to} and extend their subscription.";
          } elseif ($pr_is_downgrade) {
              $pr_confirm_msg = $pr_is_scheduled
                  ? "Confirm SCHEDULED DOWNGRADE for {$pr['business_name']}?\\n\\n{$pr_from} → {$pr_to}\\n\\nThe plan will switch after their current subscription ends."
                  : "Approve DOWNGRADE for {$pr['business_name']}?\\n\\n{$pr_from} → {$pr_to}\\n\\nThis will change their plan to {$pr_to}.";
          } else {
              $pr_confirm_msg = "Approve RENEWAL for {$pr['business_name']}? ({$pr_to}, " . ucfirst($pr['billing_cycle']) . ")";
          }
        ?>
        <div style="background:<?= $pr_card_bg ?>;border:1px solid <?= $pr_card_border ?>;border-radius:10px;padding:16px;margin-bottom:10px;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
            <div style="flex:1;min-width:0;">

              <!-- Header row: name + type badge -->
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                <span style="font-weight:700;color:var(--text);font-size:.92rem;"><?= htmlspecialchars($pr['business_name']) ?></span>
                <span style="font-size:.67rem;font-weight:800;padding:2px 9px;border-radius:100px;background:<?= $pr_type_bg ?>;color:<?= $pr_type_color ?>;letter-spacing:.04em;"><?= $pr_type_label ?></span>
              </div>
              <p style="color:var(--text-dim);font-size:.76rem;margin:0 0 10px;"><?= htmlspecialchars($pr['email']) ?></p>

              <!-- Plan info -->
              <?php if ($pr_is_upgrade): ?>
              <div style="display:inline-flex;align-items:center;gap:6px;background:rgba(124,58,237,.08);border:1px solid rgba(124,58,237,.18);border-radius:8px;padding:6px 12px;margin-bottom:10px;">
                <span style="font-size:.8rem;font-weight:700;color:#6d28d9;"><?= $pr_from ?></span>
                <svg viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2.5" style="width:13px;height:13px;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                <span style="font-size:.8rem;font-weight:800;color:#7c3aed;"><?= $pr_to ?></span>
                <span style="font-size:.72rem;color:rgba(124,58,237,.6);">· <?= ucfirst($pr['billing_cycle']) ?></span>
              </div>
              <?php elseif ($pr_is_downgrade): ?>
              <div style="display:inline-flex;align-items:center;gap:6px;background:rgba(21,128,61,.08);border:1px solid rgba(21,128,61,.18);border-radius:8px;padding:6px 12px;margin-bottom:10px;">
                <span style="font-size:.8rem;font-weight:700;color:#166534;"><?= $pr_from ?></span>
                <svg viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2.5" style="width:13px;height:13px;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                <span style="font-size:.8rem;font-weight:800;color:#15803d;"><?= $pr_to ?></span>
                <span style="font-size:.72rem;color:rgba(21,128,61,.6);">· <?= ucfirst($pr['billing_cycle']) ?></span>
              </div>
              <?php else: ?>
              <div style="display:inline-flex;align-items:center;gap:6px;background:rgba(180,83,9,.07);border:1px solid rgba(180,83,9,.15);border-radius:8px;padding:6px 12px;margin-bottom:10px;">
                <span style="font-size:.8rem;font-weight:700;color:#92400e;">🔄 <?= $pr_to ?> · <?= ucfirst($pr['billing_cycle']) ?></span>
              </div>
              <?php endif; ?>

              <!-- Amount + proration row -->
              <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:6px;">
                <span style="font-size:.8rem;color:var(--text-m);">💰 <strong>₱<?= number_format((float)$pr['amount'], 2) ?></strong></span>
                <?php if ($pr_proration > 0): ?>
                <span style="font-size:.78rem;color:#16a34a;">✓ Proration credit: ₱<?= number_format($pr_proration, 2) ?> applied</span>
                <?php endif; ?>
                <span style="font-size:.78rem;color:var(--text-m);">💳 <?= htmlspecialchars($pr['payment_method'] ?: '—') ?></span>
                <?php if ($pr['payment_reference']): ?>
                <span style="font-size:.78rem;color:var(--text-m);">🔖 <?= htmlspecialchars($pr['payment_reference']) ?></span>
                <?php endif; ?>
              </div>

              <!-- Date + notes -->
              <div style="display:flex;gap:16px;flex-wrap:wrap;">
                <span style="font-size:.74rem;color:var(--text-dim);">🕐 <?= date('M d, Y h:i A', strtotime($pr['requested_at'])) ?></span>
                <span style="font-size:.74rem;color:var(--text-dim);">📅 Current expiry: <?= $pr['subscription_end'] ? date('M d, Y', strtotime($pr['subscription_end'])) : 'None' ?></span>
              </div>
              <?php if ($pr['notes']): ?>
              <p style="font-size:.76rem;color:var(--text-dim);margin:6px 0 0;font-style:italic;border-left:2px solid <?= $pr_card_border ?>;padding-left:8px;"><?= htmlspecialchars($pr['notes']) ?></p>
              <?php endif; ?>

            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;align-items:flex-start;">
              <form method="POST" action="" style="margin:0;">
                <input type="hidden" name="action" value="approve_sub_renewal"/>
                <input type="hidden" name="renewal_id" value="<?= $pr['id'] ?>"/>
                <button type="submit" class="btn-sm btn-success"
                  onclick="return confirm('<?= addslashes($pr_confirm_msg) ?>')">
                  <?php
                  if ($pr_is_scheduled && $pr_is_upgrade)       echo '📅 Confirm Scheduled Upgrade';
                  elseif ($pr_is_scheduled && $pr_is_downgrade) echo '📅 Confirm Scheduled Downgrade';
                  elseif ($pr_is_upgrade)                       echo '🚀 Approve Upgrade';
                  elseif ($pr_is_downgrade)                     echo '↓ Approve Downgrade';
                  else                                          echo '✓ Approve';
                  ?>
                </button>
              </form>
              <button type="button" class="btn-sm btn-danger"
                onclick="document.getElementById('reject-modal-<?= $pr['id'] ?>').classList.add('open')">
                ✗ Reject
              </button>
            </div>
          </div>
        </div>

        <!-- Reject Modal per renewal -->
        <div id="reject-modal-<?= $pr['id'] ?>" class="modal-overlay">
          <div class="modal" style="width:420px;">
            <div class="mhdr">
              <div>
                <div class="mtitle">✗ Reject <?= $pr_is_upgrade ? 'Upgrade' : ($pr_is_downgrade ? 'Downgrade' : 'Renewal') ?> Request</div>
                <div class="msub"><?= htmlspecialchars($pr['business_name']) ?><?= ($pr_is_upgrade || $pr_is_downgrade) ? " — {$pr_from} → {$pr_to}" : '' ?></div>
              </div>
              <button class="mclose" onclick="document.getElementById('reject-modal-<?= $pr['id'] ?>').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div class="mbody">
              <form method="POST" action="">
                <input type="hidden" name="action" value="reject_sub_renewal"/>
                <input type="hidden" name="renewal_id" value="<?= $pr['id'] ?>"/>
                <div style="margin-bottom:14px;">
                  <label class="flabel">Reason for Rejection</label>
                  <textarea name="reject_notes" class="finput" rows="3" style="resize:vertical;" placeholder="e.g. Payment not verified, invalid reference number..."></textarea>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                  <button type="button" class="btn-sm" onclick="document.getElementById('reject-modal-<?= $pr['id'] ?>').classList.remove('open')">Cancel</button>
                  <button type="submit" class="btn-sm btn-danger">Confirm Reject</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- All Tenant Subscriptions Table -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
          <span class="card-title">All Tenant Subscriptions</span>
          <input type="text" id="sub-search" placeholder="Search tenant..." oninput="filterSubTable(this.value)"
            style="border:1.5px solid var(--border);border-radius:8px;color:var(--text);font-family:inherit;font-size:.82rem;padding:7px 12px;outline:none;width:200px;"/>
        </div>
        <div style="overflow-x:auto;">
          <table id="sub-table">
            <thead>
              <tr>
                <th>Business</th><th>Plan</th><th>Start</th><th>Expiry</th><th>Days Left</th><th>Status</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($all_tenants_sub as $ts):
                $dl = $ts['days_left'];
                $ss_status = $ts['subscription_status'];
                if ($ts['status'] === 'inactive') {
                    $ss_status = 'deactivated';
                } elseif ($ts['subscription_end'] && strtotime($ts['subscription_end']) < strtotime('today')) {
                    $ss_status = 'expired';
                }
                $sc = match(true) {
                    $ss_status === 'deactivated'    => ['Deactivated',   '#7c3aed','#f3e8ff','#d8b4fe'],
                    $ss_status === 'expired'        => ['Expired',       '#dc2626','#fee2e2','#fecaca'],
                    $ss_status === 'expiring_soon'  => ['Expiring Soon', '#d97706','#fef3c7','#fde68a'],
                    $dl !== null && $dl <= 7        => ['Expiring Soon', '#d97706','#fef3c7','#fde68a'],
                    $ss_status === 'active'         => ['Active',        '#15803d','#f0fdf4','#bbf7d0'],
                    $ss_status === 'cancelled'      => ['Cancelled',     '#64748b','#f8fafc','#e2e8f0'],
                    default                         => ['—',             '#64748b','#f8fafc','#e2e8f0'],
                };
              ?>
              <tr class="sub-row" data-name="<?= strtolower(htmlspecialchars($ts['business_name'])) ?>">
                <td>
                  <div style="font-weight:600;"><?= htmlspecialchars($ts['business_name']) ?></div>
                  <div style="font-size:.72rem;color:var(--text-dim);"><?= htmlspecialchars($ts['email']) ?></div>
                </td>
                <td><?= htmlspecialchars($ts['plan']) ?></td>
                <td style="font-size:.78rem;color:var(--text-dim);"><?= $ts['subscription_start'] ? date('M d, Y', strtotime($ts['subscription_start'])) : '—' ?></td>
                <td style="font-size:.78rem;"><?= $ts['subscription_end'] ? date('M d, Y', strtotime($ts['subscription_end'])) : '—' ?></td>
                <td>
                  <?php if ($dl !== null): ?>
                  <span style="color:<?= $dl <= 0 ? '#dc2626' : ($dl <= 7 ? '#d97706' : ($dl <= 14 ? '#d97706' : 'var(--text-m)')) ?>;font-weight:<?= $dl <= 7 ? '700' : '400' ?>;">
                    <?= $dl <= 0 ? 'Expired ' . abs($dl) . 'd ago' : $dl . ' days' ?>
                  </span>
                  <?php else: ?><span style="color:var(--text-dim);">—</span><?php endif; ?>
                </td>
                <td>
                  <span style="display:inline-block;padding:3px 10px;border-radius:100px;font-size:.72rem;font-weight:700;color:<?= $sc[1] ?>;background:<?= $sc[2] ?>;border:1px solid <?= $sc[3] ?>;"><?= $sc[0] ?></span>
                </td>
                <td>
                  <button type="button" class="btn-sm" style="font-size:.73rem;"
                    onclick="openSetSubModal(<?= $ts['id'] ?>, '<?= addslashes($ts['business_name']) ?>', '<?= $ts['subscription_start'] ?? '' ?>', '<?= $ts['subscription_end'] ?? '' ?>', '<?= $ts['plan'] ?>')">
                    ✏️ Set Dates
                  </button>
                  <?php if ($ss_status === 'expired' || $ts['status'] === 'inactive'): ?>
                  <form method="POST" action="" style="display:inline;">
                    <input type="hidden" name="action" value="extend_1_month"/>
                    <input type="hidden" name="tenant_id" value="<?= $ts['id'] ?>"/>
                    <button type="submit" class="btn-sm btn-success" style="font-size:.73rem;"
                      onclick="return confirm('Extend subscription of \'<?= addslashes($ts['business_name']) ?>\' by 1 month from today?\n\nThis will:\n• Set new expiry to ' + new Date(Date.now() + 30*86400000).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) + '\n• Re-activate the tenant\n• Unsuspend all users')">
                      🔄 Extend 1 Month
                    </button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Renewal History -->
      <?php if (!empty($sub_renewals)): ?>
      <div class="card" style="margin-top:8px;">
        <div class="card-hdr"><span class="card-title">📋 All Renewal Requests</span></div>
        <div style="overflow-x:auto;">
          <table>
            <thead>
              <tr>
                <?php foreach (['Business','Plan','Type','Billing','Method','Ref #','Amount','Status','Requested','Reviewed'] as $th): ?>
                <th><?= $th ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sub_renewals as $r):
                $rc = match($r['status']) {
                  'approved' => ['#15803d','#f0fdf4'], 'rejected' => ['#dc2626','#fee2e2'], default => ['#d97706','#fef3c7']
                };
                // Determine type label from notes or upgrade columns
                $r_type = '—';
                if (!empty($r['is_upgrade']) && ($r['upgrade_from'] ?? '') < ($r['upgrade_to'] ?? '')) {
                    $r_type = '⬆️ Upgrade';
                } elseif (!empty($r['upgrade_from']) && !empty($r['upgrade_to']) && $r['upgrade_from'] !== $r['upgrade_to']) {
                    $r_type = '⬇️ Downgrade';
                } elseif (stripos($r['notes'] ?? '', 'reactivation') !== false || stripos($r['notes'] ?? '', 'reactivated') !== false) {
                    $r_type = '🔄 Reactivation';
                } elseif (stripos($r['payment_reference'] ?? '', 'starter-free') !== false) {
                    $r_type = '🆓 Free Starter';
                } else {
                    $r_type = '🔁 Renewal';
                }
              ?>
              <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($r['business_name']) ?></td>
                <td><?= htmlspecialchars($r['plan']) ?></td>
                <td style="font-size:.75rem;"><?= $r_type ?></td>
                <td><?= ucfirst($r['billing_cycle']) ?></td>
                <td style="color:var(--text-dim);"><?= htmlspecialchars($r['payment_method']?:'—') ?></td>
                <td style="font-size:.75rem;color:var(--text-dim);"><?= htmlspecialchars($r['payment_reference']?:'—') ?></td>
                <td style="font-weight:600;">₱<?= number_format($r['amount'],2) ?></td>
                <td><span style="display:inline-block;padding:2px 9px;border-radius:100px;font-size:.7rem;font-weight:700;color:<?= $rc[0] ?>;background:<?= $rc[1] ?>;"><?= ucfirst($r['status']) ?></span></td>
                <td style="font-size:.74rem;color:var(--text-dim);"><?= date('M d, Y', strtotime($r['requested_at'])) ?></td>
                <td style="font-size:.74rem;color:var(--text-dim);"><?= $r['reviewed_at'] ? date('M d, Y', strtotime($r['reviewed_at'])) : '—' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /page-section -->

    <!-- Set Subscription Dates Modal -->
    <div id="set-sub-modal" class="modal-overlay">
      <div class="modal" style="width:460px;">
        <div class="mhdr">
          <div><div class="mtitle">✏️ Set Subscription Dates</div><div class="msub" id="set-sub-biz"></div></div>
          <button class="mclose" onclick="document.getElementById('set-sub-modal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="mbody">
          <form method="POST" action="">
            <input type="hidden" name="action" value="set_subscription"/>
            <input type="hidden" name="tenant_id" id="set-sub-tid"/>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
              <div>
                <label class="flabel">Start Date</label>
                <input type="date" name="sub_start" id="set-sub-start" class="finput"/>
              </div>
              <div>
                <label class="flabel">End Date</label>
                <input type="date" name="sub_end" id="set-sub-end" class="finput"/>
              </div>
            </div>
            <div style="margin-bottom:18px;">
              <label class="flabel">Plan Override (optional)</label>
              <select name="sub_plan" id="set-sub-plan" class="finput">
                <option value="">— Keep current plan —</option>
                <option value="Starter">Starter</option>
                <option value="Pro">Pro</option>
                <option value="Enterprise">Enterprise</option>
              </select>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
              <button type="button" class="btn-sm" onclick="document.getElementById('set-sub-modal').classList.remove('open')">Cancel</button>
              <button type="submit" class="btn-sm btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
    function openSetSubModal(tid, biz, start, end, plan) {
        document.getElementById('set-sub-tid').value = tid;
        document.getElementById('set-sub-biz').textContent = biz;

        const today = new Date();
        const todayStr = today.toISOString().split('T')[0];
        const nextMonth = new Date(today);
        nextMonth.setMonth(nextMonth.getMonth() + 1);
        const nextMonthStr = nextMonth.toISOString().split('T')[0];

        // If tenant already has dates, show them for editing.
        // If blank (no subscription yet), auto-fill today → +1 month.
        document.getElementById('set-sub-start').value = start || todayStr;
        document.getElementById('set-sub-end').value   = end   || nextMonthStr;
        document.getElementById('set-sub-plan').value  = '';
        document.getElementById('set-sub-modal').classList.add('open');
    }
    function filterSubTable(q) {
        q = q.toLowerCase();
        document.querySelectorAll('.sub-row').forEach(r => {
            r.style.display = r.dataset.name.includes(q) ? '' : 'none';
        });
    }
    </script>




    <!-- ══ REPORTS ══════════════════════════════════════════════ -->
    <?php elseif($active_page==='reports'): ?>
      <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
        <?php foreach(['tenant_activity'=>['🏢 Tenant Activity','#2563eb','#eff6ff'],'user_registration'=>['👤 User Registration','#16a34a','#f0fdf4'],'usage_statistics'=>['📊 Usage Statistics','#7c3aed','#f3e8ff']] as $rk=>[$rl,$rc2,$rb]):?>
        <a href="?page=reports&report_type=<?=$rk?>&date_from=<?=$filter_date_from?>&date_to=<?=$filter_date_to?>" style="padding:9px 18px;border-radius:9px;font-size:.82rem;font-weight:700;text-decoration:none;border:2px solid <?=$report_type===$rk?$rc2:'var(--border)'?>;background:<?=$report_type===$rk?$rb:'#fff'?>;color:<?=$report_type===$rk?$rc2:'var(--text-m)'?>;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;"><?=$rl?></a>
        <?php endforeach;?>
      </div>
      <form method="GET"><input type="hidden" name="page" value="reports"><input type="hidden" name="report_type" value="<?=htmlspecialchars($report_type)?>">
        <div class="filter-bar">
          <div class="filter-group">
            <label>From:</label><input type="date" name="date_from" class="filter-input" value="<?=htmlspecialchars($filter_date_from)?>">
          </div>
          <div class="filter-group">
            <label>To:</label><input type="date" name="date_to" class="filter-input" value="<?=htmlspecialchars($filter_date_to)?>">
          </div>
          <?php if(in_array($report_type,['tenant_activity','user_registration'])):?>
          <div class="filter-group">
            <label>Status:</label><select name="filter_status" class="filter-select"><option value="">All</option>
              <?php if($report_type==='user_registration'):?><option value="approved" <?=$filter_status==='approved'?'selected':''?>>Approved</option><option value="pending" <?=$filter_status==='pending'?'selected':''?>>Pending</option><option value="rejected" <?=$filter_status==='rejected'?'selected':''?>>Rejected</option>
              <?php else:?><option value="active" <?=$filter_status==='active'?'selected':''?>>Active</option><option value="inactive" <?=$filter_status==='inactive'?'selected':''?>>Inactive</option><option value="pending" <?=$filter_status==='pending'?'selected':''?>>Pending</option><?php endif;?></select>
          </div>
          <?php endif;?>
          <div class="filter-group">
            <label>Tenant:</label><select name="filter_tenant" class="filter-select" style="min-width:160px;"><option value="0">All</option><?php foreach($tenants as $t):?><option value="<?=$t['id']?>" <?=$filter_tenant===(int)$t['id']?'selected':''?>><?=htmlspecialchars($t['business_name'])?></option><?php endforeach;?></select>
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn-sm btn-primary">Apply</button><a href="?page=reports&report_type=<?=$report_type?>" class="btn-sm">Reset</a>
          </div>
        </div>
      </form>

      <?php if($report_type==='tenant_activity'):
        $rt=count($report_data);$ru=array_sum(array_column($report_data,'user_count'));$rat=count(array_filter($report_data,fn($r)=>$r['status']==='active'));?>
        <div class="summary-grid-3"><div class="summary-item"><div class="summary-num"><?=$rt?></div><div class="summary-lbl">Tenants</div></div><div class="summary-item"><div class="summary-num" style="color:var(--success);"><?=$rat?></div><div class="summary-lbl">Active</div></div><div class="summary-item"><div class="summary-num"><?=$ru?></div><div class="summary-lbl">Total Users</div></div></div>
        <div class="card"><div class="card-hdr"><span class="card-title">🏢 Tenant Activity Report</span><span style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($filter_date_from)?> — <?=htmlspecialchars($filter_date_to)?></span></div>
        <?php if(empty($report_data)):?><div class="empty-state"><p>No data found.</p></div>
        <?php else:?><div style="overflow-x:auto;-webkit-overflow-scrolling:touch;"><table style="min-width:700px;"><thead><tr><th>#</th><th>Business</th><th>Owner</th><th>Email</th><th>Plan</th><th>Status</th><th>Branches</th><th>Users</th><th>Admins</th><th>Staff</th><th>Cashiers</th><th>Registered</th></tr></thead><tbody>
        <?php foreach($report_data as $i=>$r):?><tr><td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td><td style="font-weight:600;"><?=htmlspecialchars($r['business_name'])?></td><td><?=htmlspecialchars($r['owner_name'])?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($r['email'])?></td><td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td><td><span class="badge <?=$r['status']==='active'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($r['status'])?></span></td><td><?=$r['branches']?></td><td style="font-weight:700;"><?=$r['user_count']?></td><td><?=$r['admin_count']?></td><td><?=$r['staff_count']?></td><td><?=$r['cashier_count']?></td><td style="font-size:.73rem;color:var(--text-dim);white-space:nowrap;"><?=date('M d, Y',strtotime($r['created_at']))?></td></tr><?php endforeach;?>
        </tbody><tfoot><tr style="background:#f8fafc;"><td colspan="7" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTALS</td><td style="font-weight:800;"><?=$ru?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'admin_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'staff_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'cashier_count'))?></td><td></td></tr></tfoot></table></div><?php endif;?></div>

      <?php elseif($report_type==='user_registration'):
        $rt=count($report_data);$ra=count(array_filter($report_data,fn($r)=>$r['status']==='approved'));$rp=count(array_filter($report_data,fn($r)=>$r['status']==='pending'));?>
        <div class="summary-grid-3"><div class="summary-item"><div class="summary-num"><?=$rt?></div><div class="summary-lbl">Registrations</div></div><div class="summary-item"><div class="summary-num" style="color:var(--success);"><?=$ra?></div><div class="summary-lbl">Approved</div></div><div class="summary-item"><div class="summary-num" style="color:var(--warning);"><?=$rp?></div><div class="summary-lbl">Pending</div></div></div>
        <div class="card"><div class="card-hdr"><span class="card-title">👤 User Registration Report</span><span style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($filter_date_from)?> — <?=htmlspecialchars($filter_date_to)?></span></div>
        <?php if(empty($report_data)):?><div class="empty-state"><p>No data found.</p></div>
        <?php else:?><div style="overflow-x:auto;-webkit-overflow-scrolling:touch;"><table style="min-width:650px;"><thead><tr><th>#</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Tenant</th><th>Status</th><th>Suspended</th><th>Registered</th></tr></thead><tbody>
        <?php foreach($report_data as $i=>$r):?><tr><td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td><td style="font-weight:600;"><?=htmlspecialchars($r['fullname'])?></td><td style="font-family:monospace;font-size:.77rem;color:var(--blue-acc);"><?=htmlspecialchars($r['username'])?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($r['email'])?></td><td><span class="badge <?=['admin'=>'b-blue','staff'=>'b-green','cashier'=>'b-yellow'][$r['role']]??'b-gray'?>"><?=ucfirst($r['role'])?></span></td><td style="font-size:.78rem;"><?=htmlspecialchars($r['business_name']??'—')?></td><td><span class="badge <?=$r['status']==='approved'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><?=ucfirst($r['status'])?></span></td><td><?=$r['is_suspended']?'<span class="badge b-red">Yes</span>':'<span class="badge b-green">No</span>'?></td><td style="font-size:.73rem;color:var(--text-dim);white-space:nowrap;"><?=date('M d, Y',strtotime($r['created_at']))?></td></tr><?php endforeach;?>
        </tbody></table></div><?php endif;?></div>

      <?php elseif($report_type==='usage_statistics'):
        $rtu=array_sum(array_column($report_data,'total_users'));$rau=array_sum(array_column($report_data,'active_users'));$rsu=array_sum(array_column($report_data,'suspended_users'));?>
        <div class="summary-grid-3"><div class="summary-item"><div class="summary-num"><?=$rtu?></div><div class="summary-lbl">Total Users</div></div><div class="summary-item"><div class="summary-num" style="color:var(--success);"><?=$rau?></div><div class="summary-lbl">Active</div></div><div class="summary-item"><div class="summary-num" style="color:var(--danger);"><?=$rsu?></div><div class="summary-lbl">Suspended</div></div></div>
        <div class="card"><div class="card-hdr"><span class="card-title">📊 Usage Statistics — User Breakdown per Tenant</span></div>
        <?php if(empty($report_data)):?><div class="empty-state"><p>No data found.</p></div>
        <?php else:?><div style="overflow-x:auto;-webkit-overflow-scrolling:touch;"><table style="min-width:620px;"><thead><tr><th>#</th><th>Tenant</th><th>Plan</th><th>Status</th><th>Branches</th><th>Total</th><th>Admins</th><th>Staff</th><th>Cashiers</th><th>Active</th><th>Suspended</th></tr></thead><tbody>
        <?php foreach($report_data as $i=>$r):?><tr><td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td><td style="font-weight:600;"><?=htmlspecialchars($r['business_name'])?></td><td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td><td><span class="badge <?=$r['status']==='active'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($r['status'])?></span></td><td><?=$r['branches']?></td><td style="font-weight:700;"><?=$r['total_users']?></td><td><?=$r['admin_count']?></td><td><?=$r['staff_count']?></td><td><?=$r['cashier_count']?></td><td><span class="badge b-green"><?=$r['active_users']?></span></td><td><span class="badge <?=$r['suspended_users']>0?'b-red':'b-gray'?>"><?=$r['suspended_users']?></span></td></tr><?php endforeach;?>
        </tbody><tfoot><tr style="background:#f8fafc;"><td colspan="5" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTALS</td><td style="font-weight:800;"><?=$rtu?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'admin_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'staff_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'cashier_count'))?></td><td style="font-weight:800;color:var(--success);"><?=$rau?></td><td style="font-weight:800;color:var(--danger);"><?=$rsu?></td></tr></tfoot></table></div><?php endif;?></div>
      <?php endif;?>

    <!-- ══ SALES REPORT ═════════════════════════════════════════ -->
    <?php elseif($active_page==='sales_report'): ?>

      <form method="GET"><input type="hidden" name="page" value="sales_report">
        <div class="filter-bar">
          <div class="filter-group">
            <label>Period:</label>
            <select name="sales_period" class="filter-select">
              <option value="daily"   <?=$sales_period==='daily'  ?'selected':''?>>Daily</option>
              <option value="weekly"  <?=$sales_period==='weekly' ?'selected':''?>>Weekly</option>
              <option value="monthly" <?=$sales_period==='monthly'?'selected':''?>>Monthly</option>
            </select>
          </div>
          <div class="filter-group">
            <label>From:</label><input type="date" name="sales_from" class="filter-input" value="<?=htmlspecialchars($sales_date_from)?>">
          </div>
          <div class="filter-group">
            <label>To:</label><input type="date" name="sales_to" class="filter-input" value="<?=htmlspecialchars($sales_date_to)?>">
          </div>
          <div class="filter-group">
            <label>Tenant:</label>
            <select name="sales_tenant" class="filter-select" style="min-width:160px;"><option value="0">All Tenants</option>
              <?php foreach($tenants as $t):?><option value="<?=$t['id']?>" <?=$sales_tenant===(int)$t['id']?'selected':''?>><?=htmlspecialchars($t['business_name'])?></option><?php endforeach;?>
            </select>
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn-sm btn-primary">Apply</button>
            <a href="?page=sales_report" class="btn-sm">Reset</a>
          </div>
        </div>
      </form>

      <div class="summary-grid">
        <div class="summary-item"><div class="summary-num" style="color:var(--success);">₱<?=number_format($sales_summary['total_revenue']??0,2)?></div><div class="summary-lbl">Total Subscription Revenue</div></div>
        <div class="summary-item"><div class="summary-num" style="color:var(--blue-acc);"><?=number_format($sales_summary['total_transactions']??0)?></div><div class="summary-lbl">Renewals Collected</div></div>
        <div class="summary-item"><div class="summary-num"><?=$sales_summary['active_tenants']??0?></div><div class="summary-lbl">Paying Tenants</div></div>
        <div class="summary-item"><div class="summary-num" style="color:#7c3aed;">₱<?=number_format($sales_summary['avg_transaction']??0,2)?></div><div class="summary-lbl">Avg. Payment</div></div>
      </div>

      <div class="card">
        <div class="card-hdr"><span class="card-title">📈 Subscription Revenue Trend — <?=ucfirst($sales_period)?></span><span style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($sales_date_from)?> — <?=htmlspecialchars($sales_date_to)?></span></div>
        <?php if(empty($sales_data)):?><div class="empty-state"><p>No subscription payments collected in the selected period.</p></div>
        <?php else:?><div class="chart-wrap" style="height:260px;"><canvas id="salesTrendChart"></canvas></div>
        <script>
        new Chart(document.getElementById('salesTrendChart'),{type:'line',data:{labels:<?=json_encode($sales_chart_labels)?>,datasets:[{label:'Subscription Revenue (₱)',data:<?=json_encode(array_map('floatval',$sales_chart_data))?>,borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,0.08)',borderWidth:2.5,tension:0.4,fill:true,pointRadius:3,pointBackgroundColor:'#2563eb'},{label:'Renewals',data:<?=json_encode(array_map('intval',array_column($sales_data,'tx_count')))?>,borderColor:'#10b981',backgroundColor:'transparent',borderWidth:2,tension:0.4,pointRadius:3,pointBackgroundColor:'#10b981',yAxisID:'y2'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:true,labels:{font:{size:11},boxWidth:12}}},scales:{x:{grid:{display:false},ticks:{font:{size:10},color:'#94a3b8'}},y:{grid:{color:'#f1f5f9'},ticks:{font:{size:10},color:'#94a3b8'},beginAtZero:true,title:{display:true,text:'Revenue (₱)',font:{size:10},color:'#94a3b8'}},y2:{position:'right',grid:{display:false},ticks:{font:{size:10},color:'#10b981'},beginAtZero:true,title:{display:true,text:'Renewals',font:{size:10},color:'#10b981'}}}}});
        </script>
        <!-- ── Period Breakdown Table ─────────────────────────── -->
        <div style="margin-top:18px;border-top:1px solid var(--border);padding-top:14px;">
          <div style="font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-dim);margin-bottom:10px;">
            <?php if($sales_period==='daily'):?>📅 Daily Breakdown
            <?php elseif($sales_period==='weekly'):?>📅 Weekly Breakdown
            <?php else:?>📅 Monthly Breakdown<?php endif;?>
          </div>
          <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
          <table style="min-width:420px;">
            <thead>
              <tr>
                <?php if($sales_period==='daily'):?>
                  <th>#</th><th>Date</th><th style="text-align:right;">Revenue (₱)</th><th style="text-align:right;">Renewals</th>
                <?php elseif($sales_period==='weekly'):?>
                  <th>#</th><th>Week</th><th style="text-align:right;">Revenue (₱)</th><th style="text-align:right;">Renewals</th>
                <?php else:?>
                  <th>#</th><th>Month</th><th style="text-align:right;">Revenue (₱)</th><th style="text-align:right;">Renewals</th>
                <?php endif;?>
              </tr>
            </thead>
            <tbody>
            <?php foreach($sales_data as $si=>$sd):?>
            <tr>
              <td style="color:var(--text-dim);font-size:.73rem;"><?=$si+1?></td>
              <td style="font-weight:600;font-size:.82rem;">
                <?php if($sales_period==='daily'):?>
                  <?=date('M d, Y (D)',strtotime($sd['period_label']))?>
                <?php elseif($sales_period==='weekly'):?>
                  <?php
                    // Convert "2026-W04" to a readable date range
                    $wparts = explode('-W', $sd['period_label']);
                    if(count($wparts)===2){
                      $wdate = new DateTime();
                      $wdate->setISODate((int)$wparts[0],(int)$wparts[1]);
                      $wend  = clone $wdate; $wend->modify('+6 days');
                      echo htmlspecialchars($sd['period_label']).' <span style="color:var(--text-dim);font-size:.72rem;font-weight:400;">('.$wdate->format('M d').'–'.$wend->format('M d, Y').')</span>';
                    } else { echo htmlspecialchars($sd['period_label']); }
                  ?>
                <?php else:?>
                  <?=htmlspecialchars($sd['period_label'])?>
                <?php endif;?>
              </td>
              <td style="text-align:right;font-weight:700;color:var(--success);">₱<?=number_format((float)$sd['revenue'],2)?></td>
              <td style="text-align:right;font-weight:600;color:var(--blue-acc);"><?=number_format((int)$sd['tx_count'])?></td>
            </tr>
            <?php endforeach;?>
            </tbody>
            <tfoot>
              <tr style="background:#f8fafc;">
                <td colspan="2" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTAL</td>
                <td style="text-align:right;font-weight:800;color:var(--success);">₱<?=number_format(array_sum(array_column($sales_data,'revenue')),2)?></td>
                <td style="text-align:right;font-weight:800;color:var(--blue-acc);"><?=number_format(array_sum(array_column($sales_data,'tx_count')))?></td>
              </tr>
            </tfoot>
          </table>
          </div>
        </div>
        <?php endif;?>
      </div>

      <div class="two-col">
        <div class="card">
          <div class="card-hdr"><span class="card-title">🏢 Subscription Payments Per Tenant</span></div>
          <?php if(empty($sales_per_tenant)):?><div class="empty-state"><p>No data.</p></div>
          <?php else:?>
          <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
          <table style="min-width:520px;"><thead><tr><th>Rank</th><th>Tenant</th><th>Plan</th><th>Renewals</th><th>Amount Paid (₱)</th><th>Avg (₱)</th><th>Last Payment</th></tr></thead><tbody>
          <?php foreach($sales_per_tenant as $i=>$r):?>
          <tr><td><span class="badge <?=$i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'b-gray'))?>">#<?=$i+1?></span></td><td style="font-weight:600;"><?=htmlspecialchars($r['business_name'])?></td><td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td><td style="font-weight:700;"><?=number_format($r['tx_count'])?></td><td style="font-weight:700;color:var(--success);">₱<?=number_format($r['revenue'],2)?></td><td>₱<?=number_format($r['avg_tx'],2)?></td><td style="font-size:.73rem;color:var(--text-dim);"><?=$r['last_tx']?date('M d, Y',strtotime($r['last_tx'])):'—'?></td></tr>
          <?php endforeach;?></tbody>
          <tfoot><tr style="background:#f8fafc;"><td colspan="3" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTALS</td><td style="font-weight:800;"><?=number_format(array_sum(array_column($sales_per_tenant,'tx_count')))?></td><td style="font-weight:800;color:var(--success);">₱<?=number_format(array_sum(array_column($sales_per_tenant,'revenue')),2)?></td><td colspan="2"></td></tr></tfoot>
          </table>
          </div>
          <?php endif;?>
        </div>
        <div class="card">
          <div class="card-hdr"><span class="card-title">🏆 Top Paying Tenants</span></div>
          <?php if(empty($top_tenants)):?><div class="empty-state"><p>No subscription payments in this period.</p></div>
          <?php else:$mx=max(array_column($top_tenants,'revenue'));$bar_colors=['#2563eb','#10b981','#d97706','#7c3aed','#dc2626'];
          foreach($top_tenants as $i=>$r):$bp=$mx>0?round($r['revenue']/$mx*100):0;$bc=$bar_colors[$i]??'#94a3b8';?>
          <div style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
              <div style="display:flex;align-items:center;gap:8px;"><span style="font-size:.7rem;font-weight:800;color:<?=$bc?>;background:<?=$bc?>20;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><?=$i+1?></span><span style="font-size:.8rem;font-weight:600;"><?=htmlspecialchars($r['business_name'])?></span></div>
              <span style="font-size:.78rem;font-weight:700;color:var(--success);">₱<?=number_format($r['revenue'],0)?></span>
            </div>
            <div style="height:7px;background:#f1f5f9;border-radius:100px;overflow:hidden;"><div style="height:100%;width:<?=$bp?>%;background:<?=$bc?>;border-radius:100px;"></div></div>
            <div style="font-size:.7rem;color:var(--text-dim);margin-top:2px;"><?=number_format($r['tx_count'])?> renewal<?=$r['tx_count']!=1?'s':''?></div>
          </div>
          <?php endforeach;endif;?>
        </div>
      </div>

      <div class="card">
        <div class="card-hdr"><span class="card-title">📋 Subscription Payment History (Latest 100)</span><span style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($sales_date_from)?> — <?=htmlspecialchars($sales_date_to)?></span></div>
        <?php if(empty($tx_history)):?><div class="empty-state"><p>No subscription payments found for the selected period.</p></div>
        <?php else:?>
        <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table style="min-width:600px;"><thead><tr><th>#</th><th>Tenant</th><th>Plan</th><th>Billing Cycle</th><th>Payment Method</th><th>Amount Paid (₱)</th><th>Date Approved</th></tr></thead><tbody>
        <?php foreach($tx_history as $i=>$tx):?>
        <tr>
          <td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td>
          <td style="font-weight:600;font-size:.79rem;"><?=htmlspecialchars($tx['business_name']??'—')?></td>
          <td><span class="badge <?=($tx['plan']??'')==='Enterprise'?'plan-ent':(($tx['plan']??'')==='Pro'?'plan-pro':'plan-starter')?>"><?=htmlspecialchars($tx['plan']??'—')?></span></td>
          <td style="font-size:.78rem;"><?=ucfirst($tx['billing_cycle']??'—')?></td>
          <td style="font-size:.77rem;"><?php $pm_=$tx['payment_method']??'';if(str_starts_with($pm_,'PayMongo')):?><span style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:6px;padding:2px 8px;font-weight:700;font-size:.7rem;">⚡ <?=htmlspecialchars($pm_)?></span><?php elseif($pm_):?><span style="color:var(--text-m);"><?=htmlspecialchars($pm_)?></span><?php if($tx['payment_reference']??''):?><div style="font-size:.68rem;color:var(--text-dim);">Ref: <?=htmlspecialchars($tx['payment_reference'])?></div><?php endif;?><?php else:?>—<?php endif;?></td>
          <td style="font-weight:700;color:var(--success);">₱<?=number_format($tx['amount']??0,2)?></td>
          <td style="font-size:.73rem;color:var(--text-dim);white-space:nowrap;"><?=date('M d, Y h:i A',strtotime($tx['created_at']))?></td>
        </tr>
        <?php endforeach;?></tbody></table>
        </div>
        <?php endif;?>
      </div>

    <!-- ══ SETTINGS PAGE ═══════════════════════════════════════ -->
    <?php elseif($active_page==='settings'): ?>

      <form method="POST">
        <input type="hidden" name="action" value="save_system_settings">

        <div class="card" style="margin-bottom:16px;">
          <div class="card-hdr"><span class="card-title">🎨 System Branding</span></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div>
              <label class="flabel">System Name</label>
              <input type="text" name="system_name" class="finput" value="<?=htmlspecialchars($ss['system_name'])?>" placeholder="PawnHub">
              <div style="font-size:.71rem;color:var(--text-dim);margin-top:4px;">Shown in the browser title and sidebar.</div>
            </div>
            <div>
              <label class="flabel">System Tagline</label>
              <input type="text" name="system_tagline" class="finput" value="<?=htmlspecialchars($ss['system_tagline'])?>" placeholder="Multi-Tenant Pawnshop Management">
              <div style="font-size:.71rem;color:var(--text-dim);margin-top:4px;">Shown on the login page.</div>
            </div>
          </div>
        </div>

        <div class="card" style="margin-bottom:16px;">
          <div class="card-hdr">
            <span class="card-title">📦 Subscription Plan Limits</span>
            <span style="font-size:.74rem;color:var(--text-dim);">These limits are enforced per tenant based on their plan.</span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
            <div style="grid-column:1/-1;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:11px 16px;font-size:.78rem;color:#1d4ed8;margin-bottom:4px;">
              📍 <strong>1 subscription = 1 branch.</strong> Tenants subscribe per branch — plan determines features, not branch count.
            </div>
            <div style="border:2px solid #e2e8f0;border-radius:12px;overflow:hidden;">
              <div style="background:#f1f5f9;padding:14px 16px;border-bottom:1px solid #e2e8f0;">
                <div style="font-size:.9rem;font-weight:800;color:#475569;">Starter</div>
                <div style="font-size:.75rem;color:#94a3b8;margin-top:2px;">Basic pawnshop operations</div>
              </div>
              <div style="padding:16px;display:flex;flex-direction:column;gap:12px;">
                <div><label class="flabel">Price / Label</label><input type="text" name="starter_price" class="finput" value="<?=htmlspecialchars($ss['starter_price'])?>" placeholder="Free"></div>
                <div><label class="flabel">Max Staff + Cashiers</label><input type="number" name="starter_staff" class="finput" value="<?=(int)$ss['starter_staff']?>" min="1" max="999"><div style="font-size:.7rem;color:var(--text-dim);margin-top:3px;">Combined staff + cashier limit</div></div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;font-size:.75rem;color:var(--text-m);line-height:1.8;"><div>✅ 1 Branch Manager</div><div>✅ Pawn tickets &amp; customers</div><div>✅ Inventory management</div><div>✅ Basic reports</div><div>✅ Email support</div></div>
              </div>
            </div>
            <div style="border:2px solid #bfdbfe;border-radius:12px;overflow:hidden;">
              <div style="background:#eff6ff;padding:14px 16px;border-bottom:1px solid #bfdbfe;">
                <div style="font-size:.9rem;font-weight:800;color:#1d4ed8;">Pro</div>
                <div style="font-size:.75rem;color:#93c5fd;margin-top:2px;">For growing pawnshops</div>
              </div>
              <div style="padding:16px;display:flex;flex-direction:column;gap:12px;">
                <div><label class="flabel">Price / Label</label><input type="text" name="pro_price" class="finput" value="<?=htmlspecialchars($ss['pro_price'])?>" placeholder="₱999/mo"></div>
                <div><label class="flabel">Max Staff + Cashiers <span style="color:#94a3b8;">(0 = unlimited)</span></label><input type="number" name="pro_staff" class="finput" value="<?=(int)$ss['pro_staff']?>" min="0"><div style="font-size:.7rem;color:var(--text-dim);margin-top:3px;">0 = no limit</div></div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;font-size:.75rem;color:var(--text-m);line-height:1.8;"><div>✅ Everything in Starter</div><div>✅ Unlimited staff &amp; cashiers</div><div>✅ Advanced reports &amp; analytics</div><div>✅ Custom branding &amp; theme</div><div>✅ Priority support</div></div>
              </div>
            </div>
            <div style="border:2px solid #ddd6fe;border-radius:12px;overflow:hidden;">
              <div style="background:#f3e8ff;padding:14px 16px;border-bottom:1px solid #ddd6fe;">
                <div style="font-size:.9rem;font-weight:800;color:#7c3aed;">Enterprise</div>
                <div style="font-size:.75rem;color:#c4b5fd;margin-top:2px;">Large pawnshop operations</div>
              </div>
              <div style="padding:16px;display:flex;flex-direction:column;gap:12px;">
                <div><label class="flabel">Price / Label</label><input type="text" name="ent_price" class="finput" value="<?=htmlspecialchars($ss['ent_price'])?>" placeholder="₱2,499/mo"></div>
                <div><label class="flabel">Max Staff + Cashiers <span style="color:#94a3b8;">(0 = unlimited)</span></label><input type="number" name="ent_staff" class="finput" value="<?=(int)$ss['ent_staff']?>" min="0"><div style="font-size:.7rem;color:var(--text-dim);margin-top:3px;">0 = no limit</div></div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;font-size:.75rem;color:var(--text-m);line-height:1.8;"><div>✅ Everything in Pro</div><div>✅ White-label system name</div><div>✅ Data export (CSV/PDF)</div><div>✅ Dedicated account manager</div><div>✅ 24/7 priority support</div></div>
              </div>
            </div>
          </div>
        </div>

        <div class="card" style="margin-bottom:16px;">
          <div class="card-hdr">
            <span class="card-title">🛡️ Super Admin Accounts</span>
            <?php if ($is_main_sa): ?>
            <button type="button" onclick="document.getElementById('addSuperAdminModal').classList.add('open')" class="btn-sm btn-primary" style="font-size:.75rem;">
              ➕ Add Super Admin
            </button>
            <?php endif; ?>
          </div>
          <?php
            // Fetch all super admins
            try {
                $sa_list = $pdo->query("SELECT id, fullname, username, email, created_at FROM users WHERE role = 'super_admin' AND status = 'approved' ORDER BY created_at ASC")->fetchAll();
            } catch (PDOException $e) { $sa_list = []; }
          ?>
          <div style="overflow-x:auto;">
            <table>
              <thead>
                <tr><th>#</th><th>Full Name</th><th>Username</th><th>Email</th><th>Date Added</th><th>Action</th></tr>
              </thead>
              <tbody>
                <?php foreach ($sa_list as $i => $sa): ?>
                <tr>
                  <td style="color:var(--text-dim);font-size:.73rem;"><?= $i + 1 ?></td>
                  <td style="font-weight:600;"><?= htmlspecialchars($sa['fullname']) ?></td>
                  <td style="font-family:monospace;font-size:.78rem;color:var(--blue-acc);">@<?= htmlspecialchars($sa['username']) ?></td>
                  <td style="font-size:.78rem;color:var(--text-dim);"><?= htmlspecialchars($sa['email']) ?></td>
                  <td style="font-size:.73rem;color:var(--text-dim);"><?= date('M d, Y', strtotime($sa['created_at'])) ?></td>
                  <td>
                    <?php if ((int)$sa['id'] === (int)$u['id']): ?>
                      <span class="badge b-purple">You</span>
                    <?php elseif ($is_main_sa): ?>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Remove Super Admin \"<?= htmlspecialchars($sa['username'], ENT_QUOTES) ?>\"? This cannot be undone.')">
                        <input type="hidden" name="action" value="remove_super_admin">
                        <input type="hidden" name="target_id" value="<?= $sa['id'] ?>">
                        <button type="submit" class="btn-sm btn-danger" style="font-size:.7rem;">✗ Remove</button>
                      </form>
                    <?php else: ?>
                      <span style="font-size:.72rem;color:var(--text-dim);">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sa_list)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:20px;">No super admins found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div style="margin-top:10px;font-size:.75rem;color:var(--text-dim);background:#f8fafc;border-radius:8px;padding:10px 14px;">
            ⚠️ Super Admins have full access to the system. Only add trusted users. You cannot remove your own account.
          </div>
        </div>

        <div class="card" style="margin-bottom:16px;">
          <div class="card-hdr"><span class="card-title">👤 User Role Permissions</span></div>
          <div style="overflow-x:auto;">
            <table>
              <thead><tr><th>Permission</th><th style="text-align:center;">Super Admin</th><th style="text-align:center;">Admin (Owner)</th><th style="text-align:center;">Manager</th><th style="text-align:center;">Staff</th><th style="text-align:center;">Cashier</th></tr></thead>
              <tbody>
                <?php
                $perms = [
                  ['Manage Tenants',true,false,false,false,false],['Approve/Reject Tenants',true,false,false,false,false],
                  ['View Sales Report',true,false,false,false,false],['View Audit Logs',true,false,false,false,false],
                  ['System Settings',true,false,false,false,false],['Invite Managers',false,true,false,false,false],
                  ['Theme & Branding',false,true,false,false,false],['View Tenant Reports',false,true,false,false,false],
                  ['Manage Staff/Cashiers',false,false,true,false,false],['Approve Void Requests',false,false,true,false,false],
                  ['Approve Renewals',false,false,true,false,false],['Create Pawn Tickets',false,false,false,true,false],
                  ['Register Customers',false,false,false,true,false],['Request Void',false,false,false,true,false],
                  ['Process Payment',false,false,false,true,true],['View Ticket Status',false,true,true,true,true],
                ];
                foreach($perms as [$label,$sa,$ow,$mg,$st,$ca]):?>
                <tr><td style="font-size:.8rem;font-weight:500;"><?=$label?></td>
                <?php foreach([$sa,$ow,$mg,$st,$ca] as $allowed):?>
                <td style="text-align:center;"><?php if($allowed):?><span style="color:#16a34a;font-size:1rem;">✓</span><?php else:?><span style="color:#e2e8f0;font-size:1rem;">—</span><?php endif;?></td>
                <?php endforeach;?></tr>
                <?php endforeach;?>
              </tbody>
            </table>
          </div>
          <div style="margin-top:12px;font-size:.75rem;color:var(--text-dim);background:#f8fafc;border-radius:8px;padding:10px 14px;">ℹ️ Role permissions are fixed by the system. Contact the developer to modify role-level access.</div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-bottom:20px;">
          <button type="submit" class="btn-sm btn-primary" style="padding:10px 24px;font-size:.88rem;">💾 Save Settings</button>
        </div>
      </form>

    <?php elseif($active_page==='audit_logs'): ?>

      <form method="GET"><input type="hidden" name="page" value="audit_logs">
        <div class="filter-bar">
          <div class="filter-group">
            <label>From:</label><input type="date" name="audit_from" class="filter-input" value="<?=htmlspecialchars($audit_date_from)?>">
          </div>
          <div class="filter-group">
            <label>To:</label><input type="date" name="audit_to" class="filter-input" value="<?=htmlspecialchars($audit_date_to)?>">
          </div>
          <div class="filter-group">
            <label>Action:</label>
            <select name="audit_action" class="filter-select" style="min-width:160px;"><option value="">All Actions</option>
              <?php foreach($audit_actions_list as $act):?><option value="<?=htmlspecialchars($act)?>" <?=$audit_action===$act?'selected':''?>><?=htmlspecialchars($act)?></option><?php endforeach;?>
            </select>
          </div>
          <div class="filter-group">
            <label>Actor:</label><input type="text" name="audit_actor" class="filter-input" placeholder="Search username..." value="<?=htmlspecialchars($audit_actor)?>">
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn-sm btn-primary">Apply</button>
            <a href="?page=audit_logs" class="btn-sm">Reset</a>
          </div>
        </div>
      </form>

      <div class="summary-grid-3">
        <div class="summary-item"><div class="summary-num"><?=number_format($audit_total)?></div><div class="summary-lbl">Total Entries</div></div>
        <div class="summary-item"><div class="summary-num" style="color:var(--blue-acc);"><?=$audit_page?>/<?=$audit_total_pages?></div><div class="summary-lbl">Page</div></div>
        <div class="summary-item"><div class="summary-num" style="font-size:.85rem;color:var(--text-dim);"><?=htmlspecialchars($audit_date_from)?> — <?=htmlspecialchars($audit_date_to)?></div><div class="summary-lbl">Date Range</div></div>
      </div>

      <div class="card">
        <div class="card-hdr"><span class="card-title">📋 Audit Logs</span><span style="font-size:.74rem;color:var(--text-dim);">Showing <?=count($audit_logs)?> of <?=number_format($audit_total)?> entries</span></div>
        <?php if(empty($audit_logs)):?>
          <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p>No audit log entries found for the selected filters.</p>
          </div>
        <?php else:?>
          <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
          <table style="min-width:700px;">
            <thead><tr><th style="white-space:nowrap;">Date & Time</th><th>Actor</th><th>Role</th><th>Action</th><th>Entity</th><th style="min-width:220px;max-width:340px;">Message</th></tr></thead>
            <tbody>
            <?php foreach($audit_logs as $log):
              $av=strtoupper($log['action']??'');
              if(str_contains($av,'APPROVE')||str_contains($av,'ACTIVATE')) $ac='act-activate';
              elseif(str_contains($av,'REJECT')||str_contains($av,'DELETE')||str_contains($av,'DEACTIVATE')) $ac='act-reject';
              elseif(str_contains($av,'LOGIN')) $ac='act-login';
              elseif(str_contains($av,'LOGOUT')) $ac='act-logout';
              elseif(str_contains($av,'CREATE')||str_contains($av,'ADD')) $ac='act-create';
              elseif(str_contains($av,'UPDATE')||str_contains($av,'EDIT')) $ac='act-update';
              else $ac='act-other';
              $msg_full = htmlspecialchars($log['message']??'—');
              $msg_short = mb_strlen($log['message']??'') > 80 ? htmlspecialchars(mb_substr($log['message'],0,80)).'…' : $msg_full;
            ?>
            <tr>
              <td style="font-size:.73rem;color:var(--text-dim);white-space:nowrap;"><?=date('M d, Y h:i A',strtotime($log['created_at']))?></td>
              <td style="font-weight:600;font-size:.79rem;white-space:nowrap;"><?=htmlspecialchars($log['actor_username']??'—')?></td>
              <td style="white-space:nowrap;"><span class="badge <?=['super_admin'=>'b-purple','admin'=>'b-blue','staff'=>'b-green','cashier'=>'b-yellow'][$log['actor_role']??'']??'b-gray'?>"><?=ucwords(str_replace('_',' ',$log['actor_role']??'—'))?></span></td>
              <td style="white-space:nowrap;"><span class="badge <?=$ac?>" style="font-size:.65rem;letter-spacing:.03em;"><?=htmlspecialchars($log['action']??'—')?></span></td>
              <td style="font-size:.74rem;white-space:nowrap;"><?php if(!empty($log['entity_type'])):?><span style="color:var(--text-dim);"><?=htmlspecialchars(ucfirst($log['entity_type']))?></span><?php if(!empty($log['entity_id'])):?> <span class="ticket-tag">#<?=htmlspecialchars($log['entity_id'])?></span><?php endif;?><?php else:?>—<?php endif;?></td>
              <td style="font-size:.77rem;color:var(--text-m);max-width:340px;word-break:break-word;overflow-wrap:anywhere;" title="<?=$msg_full?>"><?=$msg_short?></td>
            </tr>
            <?php endforeach;?>
            </tbody>
          </table>
          </div>
          <?php if($audit_total_pages>1):
            $bu="?page=audit_logs&audit_from=".urlencode($audit_date_from)."&audit_to=".urlencode($audit_date_to)."&audit_action=".urlencode($audit_action)."&audit_actor=".urlencode($audit_actor);?>
          <div class="pagination">
            <?php if($audit_page>1):?><a href="<?=$bu?>&audit_page=<?=$audit_page-1?>" class="page-btn">← Prev</a><?php endif;?>
            <?php $ps=max(1,$audit_page-2);$pe=min($audit_total_pages,$audit_page+2);
            if($ps>1){echo '<a href="'.$bu.'&audit_page=1" class="page-btn">1</a>';if($ps>2)echo '<span style="color:var(--text-dim);padding:0 4px;">...</span>';}
            for($p=$ps;$p<=$pe;$p++):?><a href="<?=$bu?>&audit_page=<?=$p?>" class="page-btn <?=$p===$audit_page?'active':''?>"><?=$p?></a><?php endfor;
            if($pe<$audit_total_pages){if($pe<$audit_total_pages-1)echo '<span style="color:var(--text-dim);padding:0 4px;">...</span>';echo '<a href="'.$bu.'&audit_page='.$audit_total_pages.'" class="page-btn">'.$audit_total_pages.'</a>';}?>
            <?php if($audit_page<$audit_total_pages):?><a href="<?=$bu?>&audit_page=<?=$audit_page+1?>" class="page-btn">Next →</a><?php endif;?>
            <span style="font-size:.73rem;color:var(--text-dim);margin-left:6px;">Page <?=$audit_page?> of <?=$audit_total_pages?> · <?=number_format($audit_total)?> total</span>
          </div>
          <?php endif;?>
        <?php endif;?>
      </div>

    <!-- ══ INVITATIONS PAGE ════════════════════════════════════ -->
    <?php elseif($active_page==='invitations'): ?>
      <div class="card">
        <div class="card-hdr">
          <span class="card-title">📧 Email Invitations</span>
          <span style="font-size:.75rem;color:var(--text-dim);"><?=count($invitations)?> total · <?=$pending_inv?> pending</span>
        </div>
        <?php if(empty($invitations)):?>
          <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/></svg>
            <p>No invitations sent yet. Click "Add Tenant + Invite" to get started.</p>
          </div>
        <?php else:?>
          <table>
            <thead><tr><th>Tenant</th><th>Owner</th><th>Email</th><th>Plan</th><th>Status</th><th>Expires</th><th>Sent</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach($invitations as $inv):?>
            <tr>
              <td style="font-weight:600;"><?=htmlspecialchars($inv['business_name'])?></td>
              <td><?=htmlspecialchars($inv['owner_name'])?></td>
              <td style="font-family:monospace;font-size:.76rem;"><?=htmlspecialchars($inv['email'])?></td>
              <td><span class="badge <?=$inv['plan']==='Enterprise'?'plan-ent':($inv['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$inv['plan']?></span></td>
              <td><span class="badge <?=$inv['status']==='used'?'b-green':($inv['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($inv['status'])?></span></td>
              <td style="font-size:.75rem;color:<?=strtotime($inv['expires_at'])<time()&&$inv['status']==='pending'?'var(--danger)':'var(--text-dim)'?>;"><?=date('M d, Y h:i A',strtotime($inv['expires_at']))?></td>
              <td style="font-size:.74rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($inv['created_at']))?></td>
              <td>
                <?php if($inv['status']==='pending'):?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="action" value="resend_invite">
                  <input type="hidden" name="inv_id" value="<?=$inv['id']?>">
                  <button type="submit" class="btn-sm btn-warning" style="font-size:.7rem;">📧 Resend</button>
                </form>
                <?php else:?><span style="font-size:.74rem;color:var(--text-dim);">✓ Registered</span><?php endif;?>
              </td>
            </tr>
            <?php endforeach;?>
            </tbody>
          </table>
        <?php endif;?>
      </div>

    <?php endif;?>
  </div><!-- /content -->
</div><!-- /main -->

<!-- ══ ADD TENANT MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="addTenantModal">
  <div class="modal" style="width:560px;">
    <div class="mhdr">
      <div><div class="mtitle">➕ Add Tenant</div><div class="msub">Create tenant account. You can send the payment link separately after saving.</div></div>
      <button class="mclose" onclick="document.getElementById('addTenantModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mbody">
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:11px 14px;font-size:.78rem;color:#15803d;margin-bottom:16px;line-height:1.8;">📧 <strong>Flow:</strong> Fill form → Token generated → Email sent to owner → Owner clicks link → Owner sets username & password → Owner accesses system ✅</div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_tenant">

        <div style="font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:10px;display:block;">Business Information</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div style="grid-column:1/-1;"><label class="flabel">Business Name *</label><input type="text" name="business_name" class="finput" placeholder="GoldKing Pawnshop" required></div>
          <div><label class="flabel">Owner Full Name *</label><input type="text" name="owner_name" class="finput" placeholder="Juan Dela Cruz" required></div>
          <div><label class="flabel">Owner Gmail *</label><input type="email" name="email" class="finput" placeholder="owner@gmail.com" required></div>
          <div><label class="flabel">Phone</label><input type="text" name="phone" class="finput" placeholder="09XXXXXXXXX"></div>
          <div><label class="flabel">Address</label><input type="text" name="address" class="finput" placeholder="Street, City, Province"></div>
          <div style="grid-column:1/-1;"><label class="flabel">Plan *</label><select name="plan" class="finput"><option value="Starter">Starter — Free</option><option value="Pro">Pro — ₱999/mo</option><option value="Enterprise">Enterprise — ₱2,499/mo</option></select></div>
          <div style="grid-column:1/-1;">
            <label class="flabel">Business Permit <span style="font-weight:400;opacity:.6;">(optional)</span></label>
            <input type="file" name="business_permit" accept="image/*,.pdf" class="finput" style="padding:8px 12px;cursor:pointer;">
          </div>
          <input type="hidden" name="branches" value="1">
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;font-size:.77rem;color:#1d4ed8;margin-bottom:14px;">ℹ️ No password needed — the owner will set their own via the invitation link sent to their Gmail.</div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('addTenantModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:13px;height:13px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Create + Send Invite
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ CHANGE PLAN MODAL ═══════════════════════════════════════ -->
<div class="modal-overlay" id="planModal">
  <div class="modal" style="width:440px;">
    <div class="mhdr">
      <div><div class="mtitle">⭐ Change Tenant Plan</div><div class="msub" id="plan_modal_sub"></div></div>
      <button class="mclose" onclick="document.getElementById('planModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="change_plan">
        <input type="hidden" name="tenant_id" id="plan_tid">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;">
          <?php foreach(['Starter'=>['Free','#475569','#f1f5f9','1 Branch, 3 Staff'],'Pro'=>['₱999/mo','#1d4ed8','#eff6ff','3 Branches, Unlimited'],'Enterprise'=>['₱2,499/mo','#7c3aed','#f3e8ff','10 Branches, Unlimited']] as $pn=>[$pp,$pc,$pbg,$pf]):?>
          <label style="cursor:pointer;">
            <input type="radio" name="new_plan" value="<?=$pn?>" id="plan_<?=$pn?>" style="display:none;" onchange="updatePlanCard('<?=$pn?>')">
            <div id="plan_card_<?=$pn?>" style="border:2px solid #e2e8f0;border-radius:10px;padding:12px;text-align:center;transition:all .15s;">
              <div style="font-size:.82rem;font-weight:800;color:<?=$pc?>;margin-bottom:2px;"><?=$pn?></div>
              <div style="font-size:.75rem;font-weight:700;color:<?=$pc?>;margin-bottom:6px;"><?=$pp?></div>
              <div style="font-size:.68rem;color:#94a3b8;"><?=$pf?></div>
            </div>
          </label>
          <?php endforeach;?>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:11px;font-size:.78rem;color:#1d4ed8;margin-bottom:14px;line-height:1.6;">ℹ️ Changing the plan takes effect immediately. If downgrading, excess staff will be suspended automatically.</div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('planModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary" style="background:#7c3aed;border-color:#7c3aed;">Save Plan Change</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ APPROVE MODAL ══════════════════════════════════════════ -->
<div class="modal-overlay" id="approveModal">
  <div class="modal" style="width:560px;max-width:97vw;">
    <div class="mhdr">
      <div><div class="mtitle">✓ Review & Approve Tenant</div><div class="msub" id="approve_sub"></div></div>
      <button class="mclose" onclick="document.getElementById('approveModal').classList.remove('open')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="mbody">

      <!-- Payment Details Section -->
      <div style="margin-bottom:16px;">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-dim);margin-bottom:8px;">💳 Payment Details</div>
        <div id="approve_payment_wrap" style="background:#f8fafc;border:1.5px solid var(--border);border-radius:10px;padding:12px 14px;font-size:.81rem;">
          <div id="approve_payment_content" style="color:var(--text-dim);">Loading...</div>
        </div>
      </div>

      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:11px 14px;font-size:.8rem;color:#15803d;margin-bottom:16px;line-height:1.7;">
        ✅ Approving will set the tenant status to <strong>Active</strong>.
      </div>

      <form method="POST">
        <input type="hidden" name="action" value="approve_tenant">
        <input type="hidden" name="tenant_id" id="approve_tid">
        <input type="hidden" name="user_id" id="approve_uid">
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('approveModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-success">✓ Confirm Approval</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ REJECT MODAL ═══════════════════════════════════════════ -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal">
    <div class="mhdr"><div><div class="mtitle">✗ Reject Tenant</div><div class="msub" id="reject_sub"></div></div><button class="mclose" onclick="document.getElementById('rejectModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mbody">
      <form method="POST"><input type="hidden" name="action" value="reject_tenant"><input type="hidden" name="tenant_id" id="reject_tid"><input type="hidden" name="user_id" id="reject_uid">
        <div style="margin-bottom:13px;"><label class="flabel">Reason for Rejection</label><textarea name="reject_reason" class="finput" rows="3" placeholder="e.g. Incomplete information, duplicate account..." style="resize:vertical;"></textarea></div>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 13px;font-size:.78rem;color:#dc2626;margin-bottom:14px;">⚠️ This action cannot be undone.</div>
        <div style="display:flex;justify-content:flex-end;gap:9px;"><button type="button" class="btn-sm" onclick="document.getElementById('rejectModal').classList.remove('open')">Cancel</button><button type="submit" class="btn-sm btn-danger">✗ Confirm Rejection</button></div>
      </form>
    </div>
  </div>
</div>

<!-- ── REJECT PERMIT MODAL ─────────────────────────────── -->
<div class="modal-overlay" id="rejectPermitModal">
  <div class="modal">
    <div class="mhdr"><div><div class="mtitle">✗ Reject Business Permit</div><div class="msub" id="reject_permit_sub"></div></div><button class="mclose" onclick="document.getElementById('rejectPermitModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="reject_permit"/>
        <input type="hidden" name="tenant_id" id="reject_permit_tid"/>
        <div style="margin-bottom:13px;">
          <label class="flabel">Reason for Rejection</label>
          <textarea name="reject_reason" class="finput" rows="3" placeholder="e.g. Permit is expired, fake document, business name does not match..." style="resize:vertical;"></textarea>
        </div>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 13px;font-size:.78rem;color:#dc2626;margin-bottom:14px;">
          ⚠️ This will <strong>immediately deactivate</strong> the tenant account and notify them by email. This action cannot be undone.
        </div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('rejectPermitModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-danger">✗ Reject &amp; Deactivate</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Close modals when clicking backdrop
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

// ── Tenant permit & payment data (for approve modal) ─────────
const tenantsData = <?php
  $td_map = [];
  foreach ($tenants as $t) {
    $payment = null;
    if (!empty($t['payment_info'])) {
      $decoded = json_decode($t['payment_info'], true);
      if ($decoded) $payment = $decoded;
    }
    $td_map[(int)$t['id']] = [
      'permit'          => !empty($t['business_permit_url']) ? htmlspecialchars($t['business_permit_url'], ENT_QUOTES) : null,
      'payment'         => $payment,
      'payment_status'  => $t['payment_status'] ?? null,
      'paymongo_paid_at'=> !empty($t['paymongo_paid_at']) ? date('M d, Y g:i A', strtotime($t['paymongo_paid_at'])) : null,
      'plan'            => $t['plan'] ?? '',
    ];
  }
  echo json_encode($td_map);
?>;

function openApproveModal(tid,uid,name){
  document.getElementById('approve_tid').value=tid;
  document.getElementById('approve_uid').value=uid;
  document.getElementById('approve_sub').textContent='Business: '+name;

  // Reset content
  const paymentWrap  = document.getElementById('approve_payment_content');
  paymentWrap.innerHTML = '<div style="color:#94a3b8;font-size:.8rem;">Loading payment info...</div>';

  // Fetch tenant payment info via JS from tenants data
  const td = tenantsData[tid];
  if (td) {
    const isPaid   = td.payment_status === 'paid';
    const isStarter = (td.plan || '').toLowerCase() === 'starter';

    if (isStarter) {
      // Starter plan is free — no payment needed
      paymentWrap.innerHTML = `
        <div style="display:flex;align-items:center;gap:8px;padding:8px 0;">
          <span style="font-size:1.1rem;">✅</span>
          <div>
            <strong style="color:#15803d;">Free Plan — No Payment Required</strong>
            <p style="font-size:.76rem;color:#6b7280;margin:2px 0 0;">Starter plan is free of charge.</p>
          </div>
        </div>`;
    } else if (isPaid) {
      // PayMongo payment confirmed by webhook
      let html = `
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;margin-bottom:10px;display:flex;align-items:center;gap:8px;">
          <span style="font-size:1.2rem;">💳</span>
          <div>
            <strong style="color:#15803d;font-size:.88rem;">Payment Confirmed via PayMongo</strong>
            ${td.paymongo_paid_at ? `<p style="font-size:.74rem;color:#6b7280;margin:2px 0 0;">Paid on: ${td.paymongo_paid_at}</p>` : ''}
          </div>
        </div>`;
      if (td.payment) {
        const p = td.payment;
        html += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.8rem;">`;
        html += `<div><span style="color:#94a3b8;">Method:</span> <strong>${p.method||'—'}</strong></div>`;
        html += `<div><span style="color:#94a3b8;">Reference #:</span> <strong>${p.reference||'—'}</strong></div>`;
        if (p.method === 'Credit Card') {
          html += `<div><span style="color:#94a3b8;">Card Holder:</span> <strong>${p.cc_name||'—'}</strong></div>`;
          html += `<div><span style="color:#94a3b8;">Card Number:</span> <strong style="font-family:monospace;">${p.cc_number||'—'}</strong></div>`;
          html += `<div><span style="color:#94a3b8;">Expiry:</span> <strong>${p.cc_expiry||'—'}</strong></div>`;
        }
        html += `</div>`;
      }
      paymentWrap.innerHTML = html;
    } else {
      // Paid plan but payment NOT yet received
      paymentWrap.innerHTML = `
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;display:flex;align-items:center;gap:8px;">
          <span style="font-size:1.2rem;">⏳</span>
          <div>
            <strong style="color:#b45309;font-size:.88rem;">Payment Not Yet Received</strong>
            <p style="font-size:.74rem;color:#92400e;margin:2px 0 0;">
              PayMongo has not confirmed payment for this ${td.plan} plan tenant.<br>
              Do not approve until payment is confirmed.
            </p>
          </div>
        </div>`;
    }

  } else {
    paymentWrap.innerHTML = '<span style="color:#94a3b8;font-size:.8rem;">⚠️ No payment information found.</span>';
  }

  document.getElementById('approveModal').classList.add('open');
}
function openRejectModal(tid,uid,name){document.getElementById('reject_tid').value=tid;document.getElementById('reject_uid').value=uid;document.getElementById('reject_sub').textContent='Business: '+name;document.getElementById('rejectModal').classList.add('open');}
function openRejectPermitModal(tid,name){
  document.getElementById('reject_permit_tid').value=tid;
  document.getElementById('reject_permit_sub').textContent='Business: '+name;
  document.getElementById('rejectPermitModal').classList.add('open');
}
function openPlanModal(tid,name,currentPlan){
  document.getElementById('plan_tid').value=tid;
  document.getElementById('plan_modal_sub').textContent=name;
  const radio=document.getElementById('plan_'+currentPlan);
  if(radio){radio.checked=true;updatePlanCard(currentPlan);}
  document.getElementById('planModal').classList.add('open');
}
function updatePlanCard(selected){
  const colors={Starter:'#475569',Pro:'#1d4ed8',Enterprise:'#7c3aed'};
  const bgs={Starter:'#f1f5f9',Pro:'#eff6ff',Enterprise:'#f3e8ff'};
  ['Starter','Pro','Enterprise'].forEach(p=>{
    const card=document.getElementById('plan_card_'+p);
    if(!card)return;
    if(p===selected){card.style.borderColor=colors[p];card.style.background=bgs[p];}
    else{card.style.borderColor='#e2e8f0';card.style.background='#fff';}
  });
}
</script>

<!-- ══ ADD SUPER ADMIN MODAL ══════════════════════════════════ -->
<div class="modal-overlay" id="addSuperAdminModal">
  <div class="modal" style="width:500px;">
    <div class="mhdr">
      <div><div class="mtitle">🛡️ Add New Super Admin</div><div class="msub">This account will have full system access.</div></div>
      <button class="mclose" onclick="document.getElementById('addSuperAdminModal').classList.remove('open')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="mbody">
      <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:9px;padding:11px 14px;font-size:.78rem;color:#92400e;margin-bottom:16px;line-height:1.7;">
        ⚠️ <strong>Warning:</strong> Super Admins have unrestricted access to all tenants, data, and system settings. Only add someone you fully trust.
      </div>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:11px 14px;font-size:.78rem;color:#1d4ed8;margin-bottom:16px;line-height:1.7;">
        📧 An invitation email will be sent to the new Super Admin with a link to <strong>set up their own password</strong>. The link expires in <strong>24 hours</strong>.
      </div>
      <form method="POST" action="?page=settings">
        <input type="hidden" name="action" value="add_super_admin">
        <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:16px;">
          <div>
            <label class="flabel">Full Name *</label>
            <input type="text" name="sa_fullname" class="finput" placeholder="e.g. Maria Santos" required>
          </div>
          <div>
            <label class="flabel">Email *</label>
            <input type="email" name="sa_email" class="finput" placeholder="e.g. maria@email.com" required>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('addSuperAdminModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary" style="background:linear-gradient(135deg,#4338ca,#7c3aed);border-color:#7c3aed;">
            📧 Send Invitation
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- LOGOUT CONFIRMATION MODAL -->
<div id="logoutModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:16px;">
  <div style="background:#1a1d26;border:1px solid rgba(255,255,255,.1);border-radius:20px;width:100%;max-width:380px;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.6);animation:logoutIn .22s ease both;">
    <div style="background:linear-gradient(135deg,#7f1d1d,#991b1b);padding:24px 24px 20px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </div>
      <div>
        <div style="font-family:'DM Serif Display',serif;font-size:1.2rem;color:#fff;line-height:1.2;">Sign Out</div>
        <div style="font-size:.75rem;color:rgba(255,255,255,.6);margin-top:2px;">Confirm your action</div>
      </div>
    </div>
    <div style="padding:22px 24px 24px;">
      <p style="font-size:.9rem;color:rgba(240,242,247,.65);line-height:1.65;margin-bottom:22px;">Are you sure you want to log out? Any unsaved changes may be lost.</p>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <a id="logoutConfirmBtn" href="#" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;background:#dc2626;color:#fff;font-weight:700;font-size:.9rem;border-radius:12px;text-decoration:none;transition:filter .18s;" onmouseover="this.style.filter='brightness(1.1)'" onmouseout="this.style.filter=''">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Yes, Log Out
        </a>
        <button onclick="hideLogoutModal()" style="width:100%;padding:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:rgba(240,242,247,.6);font-weight:600;font-size:.9rem;border-radius:12px;cursor:pointer;font-family:inherit;transition:all .18s;" onmouseover="this.style.background='rgba(255,255,255,.1)'" onmouseout="this.style.background='rgba(255,255,255,.06)'">
          Cancel
        </button>
      </div>
    </div>
  </div>
</div>
<style>
@keyframes logoutIn { from { opacity:0;transform:translateY(14px) } to { opacity:1;transform:none } }
.sb-logout { background:none; border:none; cursor:pointer; font-family:inherit; width:100%; text-align:left; }
/* Topbar button responsive */
.btn-add-short { display: none; }
.topbar-date { display: block; }
@media(max-width:768px){
  .btn-add-full { display: none; }
  .btn-add-short { display: inline; }
  .topbar-date { display: none; }
  .topbar-add-btn { padding: 5px 10px !important; font-size: .72rem !important; }
}
</style>
<script>
function showLogoutModal(url) {
  document.getElementById('logoutConfirmBtn').href = url;
  const m = document.getElementById('logoutModal');
  m.style.display = 'flex';
}
function hideLogoutModal() {
  document.getElementById('logoutModal').style.display = 'none';
}
document.getElementById('logoutModal').addEventListener('click', function(e){ if(e.target===this) hideLogoutModal(); });
</script>

<div class="mob-overlay" id="mobOverlay" onclick="toggleSidebar()"></div>
<script>
function toggleNotif() {
  const d = document.getElementById('notifDropdown');
  d.style.display = d.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
  const btn = document.getElementById('notifBtn');
  const dd  = document.getElementById('notifDropdown');
  if (dd && btn && !btn.contains(e.target) && !dd.contains(e.target)) {
    dd.style.display = 'none';
  }
});
function toggleSidebar(){
  document.querySelector('.sidebar').classList.toggle('mobile-open');
  document.getElementById('mobOverlay').classList.toggle('open');
}
</script>

</script>
</body>
</html>