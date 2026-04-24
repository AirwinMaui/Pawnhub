<?php
require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('super_admin');
require 'db.php';
require 'mailer.php';

if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
$u = $_SESSION['user'];
if ($u['role'] !== 'super_admin') { header('Location: login.php'); exit; }


$active_page = $_GET['page'] ?? 'dashboard';
$success_msg = $error_msg = '';

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
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $error_msg = 'Email already registered in the system.';
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
                    try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'TENANT_INVITE','tenant',?,?,?,NOW())")->execute([$new_tid,$u['id'],$u['username'],'super_admin',$new_tid,"Super Admin added tenant \"$bname\" (pending approval). Invitation will be sent upon approval.",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
                    // ── No email yet — invitation will be sent when SA approves the tenant ──
                    $success_msg = "✅ Tenant \"<strong>$bname</strong>\" added and is now pending approval. Send the invitation email when you approve the tenant.";
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
                // Check if this tenant was SA-invited (has a pending invitation token)
                $inv_stmt = $pdo->prepare("SELECT token, owner_name FROM tenant_invitations WHERE tenant_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
                $inv_stmt->execute([$tid]);
                $inv_row = $inv_stmt->fetch();

                if ($inv_row) {
                    // SA-invited tenant: send the setup/invitation email with the token link
                    $email_sent = sendTenantInvitation(
                        $t_row['email'],
                        $t_row['owner_name'],
                        $t_row['business_name'],
                        $inv_row['token'],
                        $slug
                    );
                } else {
                    // Self-signup tenant: send the approved/login link email
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
            $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $dup->execute([$sa_email]);
            if ($dup->fetch()) {
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
        FROM tenants t ORDER BY t.created_at DESC
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
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>PawnHub — Super Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--sw:252px;--navy:#0f172a;--blue-acc:#2563eb;--bg:#f1f5f9;--card:#fff;--border:#e2e8f0;--text:#1e293b;--text-m:#475569;--text-dim:#94a3b8;--success:#16a34a;--danger:#dc2626;--warning:#d97706;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden;width:100%;}
.sidebar{width:var(--sw);min-height:100vh;background:var(--navy);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;}
.sb-brand{padding:20px 18px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px;}
.sb-logo{width:36px;height:36px;background:linear-gradient(135deg,#1d4ed8,#7c3aed);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sb-logo svg{width:18px;height:18px;}
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
@media(max-width:1200px){.stats-grid,.summary-grid{grid-template-columns:repeat(2,1fr)}.two-col{grid-template-columns:1fr;}}
@media(max-width:900px){.filter-bar{gap:8px;}.filter-bar .filter-actions{margin-left:0;}}
@media(max-width:768px){
  .sidebar{transform:translateX(-100%);transition:transform .3s ease;box-shadow:none;}
  .sidebar.mobile-open{transform:translateX(0);box-shadow:4px 0 30px rgba(0,0,0,.5);}
  .main{margin-left:0!important;width:100%;}
  .topbar{padding:0 16px;}
  #mob-menu-btn{display:flex!important;}
  .mob-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99;backdrop-filter:blur(2px);}
  .mob-overlay.open{display:block;}
  .topbar-title{font-size:.88rem;}
  .content{padding:14px;}
  /* Stats: 2 columns, cards show full content (column layout) */
  .stats-grid{grid-template-columns:repeat(2,1fr);gap:10px;}
  .stat-card{flex-direction:column;align-items:flex-start;gap:8px;padding:14px;}
  .stat-icon{width:34px;height:34px;}
  .stat-value{font-size:1.3rem;}
  /* two-col: single column on mobile */
  .two-col{grid-template-columns:1fr;}
  /* Tables: wrap only the inner div[overflow-x:auto] — NOT the card itself */
  div[style*="overflow-x:auto"]{-webkit-overflow-scrolling:touch;}
  div[style*="overflow-x:auto"] table{min-width:480px;}
}
@media(max-width:600px){
  .stats-grid,.summary-grid,.summary-grid-3{grid-template-columns:repeat(2,1fr);}
  .filter-bar{flex-direction:column;align-items:flex-start;}
  .filter-bar .filter-group{width:100%;}
  .filter-bar .filter-group .filter-input,.filter-bar .filter-group .filter-select{width:100%;}
  .filter-bar .filter-actions{width:100%;justify-content:flex-start;}
  .content{padding:12px;}
}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════════ -->
<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg></div>
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
    <div style="display:flex;align-items:center;gap:10px;">
      <button onclick="document.getElementById('addTenantModal').classList.add('open')" class="btn-sm btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:13px;height:13px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Add Tenant + Invite
      </button>
      <div style="font-size:.78rem;color:var(--text-dim);"><?= date('F d, Y') ?></div>
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

      <?php $pts=array_filter($tenants,fn($t)=>$t['status']==='pending');if(!empty($pts)):?>
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
          <td>
            <button onclick="openApproveModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-success" style="font-size:.7rem;">✓ Approve</button>
            <button onclick="openRejectModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-danger" style="font-size:.7rem;">✗ Reject</button>
          </td>
        </tr>
        <?php endforeach;?></tbody></table></div>
      </div>
      <?php endif;?>

      <div class="card">
        <div class="card-hdr"><span class="card-title">🏢 All Tenants</span><span style="font-size:.75rem;color:var(--text-dim);"><?=$total_tenants?> total</span></div>
        <?php if(empty($tenants)):?><div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg><p>No tenants yet.</p></div>
        <?php else:?><div style="overflow-x:auto;-webkit-overflow-scrolling:touch;"><table style="font-size:.79rem;min-width:600px;"><thead><tr><th style="width:40px;">ID</th><th>Business Name</th><th>Email</th><th style="white-space:nowrap;">Plan</th><th>Status</th><th style="white-space:nowrap;">Subscription</th><th style="white-space:nowrap;">Expiry</th><th style="width:36px;text-align:center;">Users</th><th style="width:130px;">Actions</th></tr></thead><tbody>
        <?php foreach($tenants as $t):
          // Subscription expiry display logic
          $sub_end   = $t['subscription_end'] ?? null;
          $days_left = isset($t['days_left']) ? (int)$t['days_left'] : null;
          $sub_stat  = $t['subscription_status'] ?? null;

          if (!$sub_end || $t['status'] === 'pending') {
            $sub_badge   = '<span class="badge b-gray" style="font-size:.63rem;">—</span>';
            $expiry_cell = '<span style="font-size:.7rem;color:var(--text-dim);">—</span>';
          } elseif ($sub_stat === 'expired' || $days_left < 0) {
            $expired_days    = abs($days_left ?? 0);
            $auto_deact_days = max(0, 7 - $expired_days);
            $sub_badge = '<span class="badge b-red" style="font-size:.63rem;">❌ Expired</span>';
            if ($auto_deact_days > 0) {
              $expiry_cell = '<div style="font-size:.7rem;color:#dc2626;font-weight:700;white-space:nowrap;">' . date('M d, Y', strtotime($sub_end)) . '</div>'
                           . '<div style="font-size:.63rem;color:#ef4444;">Deactivate in ' . $auto_deact_days . 'd</div>';
            } else {
              $expiry_cell = '<div style="font-size:.7rem;color:#dc2626;font-weight:700;white-space:nowrap;">' . date('M d, Y', strtotime($sub_end)) . '</div>'
                           . '<div style="font-size:.63rem;color:#ef4444;">Overdue ' . $expired_days . 'd</div>';
            }
          } elseif ($days_left <= 3) {
            $sub_badge   = '<span class="badge b-red" style="font-size:.63rem;">🚨 Critical</span>';
            $expiry_cell = '<div style="font-size:.7rem;color:#dc2626;font-weight:700;white-space:nowrap;">' . date('M d, Y', strtotime($sub_end)) . '</div>'
                         . '<div style="font-size:.63rem;color:#ef4444;">' . $days_left . 'd left</div>';
          } elseif ($days_left <= 7) {
            $sub_badge   = '<span class="badge b-orange" style="font-size:.63rem;">⚠️ Expiring</span>';
            $expiry_cell = '<div style="font-size:.7rem;color:#c2410c;font-weight:700;white-space:nowrap;">' . date('M d, Y', strtotime($sub_end)) . '</div>'
                         . '<div style="font-size:.63rem;color:#ea580c;">' . $days_left . 'd left</div>';
          } elseif ($days_left <= 14) {
            $sub_badge   = '<span class="badge b-yellow" style="font-size:.63rem;">⏰ Soon</span>';
            $expiry_cell = '<div style="font-size:.7rem;color:#b45309;white-space:nowrap;">' . date('M d, Y', strtotime($sub_end)) . '</div>'
                         . '<div style="font-size:.63rem;color:#d97706;">' . $days_left . 'd left</div>';
          } else {
            $sub_badge   = '<span class="badge b-green" style="font-size:.63rem;">✅ Active</span>';
            $expiry_cell = '<div style="font-size:.7rem;color:var(--text-dim);white-space:nowrap;">' . date('M d, Y', strtotime($sub_end)) . '</div>'
                         . '<div style="font-size:.63rem;color:#16a34a;">' . $days_left . 'd left</div>';
          }
        ?>
        <tr>
          <td style="color:var(--text-dim);font-size:.7rem;">#<?=$t['id']?></td>
          <td>
            <div style="font-weight:600;font-size:.8rem;"><?=htmlspecialchars($t['business_name'])?></div>
            <div style="font-size:.68rem;color:var(--text-dim);"><?=htmlspecialchars($t['owner_name'])?></div>
          </td>
          <td style="font-size:.71rem;color:var(--text-dim);"><?=htmlspecialchars($t['email'])?></td>
          <td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':($t['plan']==='Pro'?'plan-pro':'plan-starter')?>" style="font-size:.63rem;"><?=$t['plan']?></span></td>
          <td><span class="badge <?=$t['status']==='active'?'b-green':($t['status']==='pending'?'b-yellow':($t['status']==='inactive'?'b-red':'b-gray'))?>" style="font-size:.63rem;"><span class="b-dot"></span><?=ucfirst($t['status'])?></span></td>
          <td><?= $sub_badge ?></td>
          <td><?= $expiry_cell ?></td>
          <td style="text-align:center;"><?=$t['user_count']?></td>
          <td>
            <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;">
            <?php if($t['status']==='active'):?>
              <form method="POST" style="margin:0;" onsubmit="return confirm('Deactivate? Their users cannot login until reactivated.')">
                <input type="hidden" name="action" value="deactivate_tenant">
                <input type="hidden" name="tenant_id" value="<?=$t['id']?>">
                <button type="submit" class="btn-sm btn-danger" style="font-size:.67rem;padding:4px 9px;margin:0;">Deactivate</button>
              </form>
              <button onclick="openPlanModal(<?=$t['id']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>','<?=$t['plan']?>')" class="btn-sm btn-warning" style="font-size:.67rem;padding:4px 9px;margin:0;">⭐ Plan</button>
            <?php elseif($t['status']==='inactive'):?>
              <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="activate_tenant">
                <input type="hidden" name="tenant_id" value="<?=$t['id']?>">
                <button type="submit" class="btn-sm btn-success" style="font-size:.67rem;padding:4px 9px;margin:0;">Activate</button>
              </form>
              <button onclick="openPlanModal(<?=$t['id']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>','<?=$t['plan']?>')" class="btn-sm btn-warning" style="font-size:.67rem;padding:4px 9px;margin:0;">⭐ Plan</button>
            <?php elseif($t['status']==='pending'):?>
              <button onclick="openApproveModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-success" style="font-size:.67rem;padding:4px 9px;margin:0;">✓ Approve</button>
              <button onclick="openRejectModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-danger" style="font-size:.67rem;padding:4px 9px;margin:0;">✗ Reject</button>
            <?php else:?><span style="font-size:.7rem;color:var(--text-dim);">—</span><?php endif;?>
            </div>
          </td>
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
                if ($ts['subscription_end'] && strtotime($ts['subscription_end']) < strtotime('today')) {
                    $ss_status = 'expired';
                }
                $sc = match(true) {
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
                <?php foreach (['Business','Plan','Billing','Method','Ref #','Amount','Status','Requested','Reviewed'] as $th): ?>
                <th><?= $th ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sub_renewals as $r):
                $rc = match($r['status']) {
                  'approved' => ['#15803d','#f0fdf4'], 'rejected' => ['#dc2626','#fee2e2'], default => ['#d97706','#fef3c7']
                };
              ?>
              <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($r['business_name']) ?></td>
                <td><?= htmlspecialchars($r['plan']) ?></td>
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
        <?php else:?><table><thead><tr><th>#</th><th>Business</th><th>Owner</th><th>Email</th><th>Plan</th><th>Status</th><th>Branches</th><th>Users</th><th>Admins</th><th>Staff</th><th>Cashiers</th><th>Registered</th></tr></thead><tbody>
        <?php foreach($report_data as $i=>$r):?><tr><td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td><td style="font-weight:600;"><?=htmlspecialchars($r['business_name'])?></td><td><?=htmlspecialchars($r['owner_name'])?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($r['email'])?></td><td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td><td><span class="badge <?=$r['status']==='active'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($r['status'])?></span></td><td><?=$r['branches']?></td><td style="font-weight:700;"><?=$r['user_count']?></td><td><?=$r['admin_count']?></td><td><?=$r['staff_count']?></td><td><?=$r['cashier_count']?></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($r['created_at']))?></td></tr><?php endforeach;?>
        </tbody><tfoot><tr style="background:#f8fafc;"><td colspan="7" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTALS</td><td style="font-weight:800;"><?=$ru?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'admin_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'staff_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'cashier_count'))?></td><td></td></tr></tfoot></table><?php endif;?></div>

      <?php elseif($report_type==='user_registration'):
        $rt=count($report_data);$ra=count(array_filter($report_data,fn($r)=>$r['status']==='approved'));$rp=count(array_filter($report_data,fn($r)=>$r['status']==='pending'));?>
        <div class="summary-grid-3"><div class="summary-item"><div class="summary-num"><?=$rt?></div><div class="summary-lbl">Registrations</div></div><div class="summary-item"><div class="summary-num" style="color:var(--success);"><?=$ra?></div><div class="summary-lbl">Approved</div></div><div class="summary-item"><div class="summary-num" style="color:var(--warning);"><?=$rp?></div><div class="summary-lbl">Pending</div></div></div>
        <div class="card"><div class="card-hdr"><span class="card-title">👤 User Registration Report</span><span style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($filter_date_from)?> — <?=htmlspecialchars($filter_date_to)?></span></div>
        <?php if(empty($report_data)):?><div class="empty-state"><p>No data found.</p></div>
        <?php else:?><table><thead><tr><th>#</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Tenant</th><th>Status</th><th>Suspended</th><th>Registered</th></tr></thead><tbody>
        <?php foreach($report_data as $i=>$r):?><tr><td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td><td style="font-weight:600;"><?=htmlspecialchars($r['fullname'])?></td><td style="font-family:monospace;font-size:.77rem;color:var(--blue-acc);"><?=htmlspecialchars($r['username'])?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($r['email'])?></td><td><span class="badge <?=['admin'=>'b-blue','staff'=>'b-green','cashier'=>'b-yellow'][$r['role']]??'b-gray'?>"><?=ucfirst($r['role'])?></span></td><td style="font-size:.78rem;"><?=htmlspecialchars($r['business_name']??'—')?></td><td><span class="badge <?=$r['status']==='approved'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><?=ucfirst($r['status'])?></span></td><td><?=$r['is_suspended']?'<span class="badge b-red">Yes</span>':'<span class="badge b-green">No</span>'?></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($r['created_at']))?></td></tr><?php endforeach;?>
        </tbody></table><?php endif;?></div>

      <?php elseif($report_type==='usage_statistics'):
        $rtu=array_sum(array_column($report_data,'total_users'));$rau=array_sum(array_column($report_data,'active_users'));$rsu=array_sum(array_column($report_data,'suspended_users'));?>
        <div class="summary-grid-3"><div class="summary-item"><div class="summary-num"><?=$rtu?></div><div class="summary-lbl">Total Users</div></div><div class="summary-item"><div class="summary-num" style="color:var(--success);"><?=$rau?></div><div class="summary-lbl">Active</div></div><div class="summary-item"><div class="summary-num" style="color:var(--danger);"><?=$rsu?></div><div class="summary-lbl">Suspended</div></div></div>
        <div class="card"><div class="card-hdr"><span class="card-title">📊 Usage Statistics — User Breakdown per Tenant</span></div>
        <?php if(empty($report_data)):?><div class="empty-state"><p>No data found.</p></div>
        <?php else:?><table><thead><tr><th>#</th><th>Tenant</th><th>Plan</th><th>Status</th><th>Branches</th><th>Total</th><th>Admins</th><th>Staff</th><th>Cashiers</th><th>Active</th><th>Suspended</th></tr></thead><tbody>
        <?php foreach($report_data as $i=>$r):?><tr><td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td><td style="font-weight:600;"><?=htmlspecialchars($r['business_name'])?></td><td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td><td><span class="badge <?=$r['status']==='active'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($r['status'])?></span></td><td><?=$r['branches']?></td><td style="font-weight:700;"><?=$r['total_users']?></td><td><?=$r['admin_count']?></td><td><?=$r['staff_count']?></td><td><?=$r['cashier_count']?></td><td><span class="badge b-green"><?=$r['active_users']?></span></td><td><span class="badge <?=$r['suspended_users']>0?'b-red':'b-gray'?>"><?=$r['suspended_users']?></span></td></tr><?php endforeach;?>
        </tbody><tfoot><tr style="background:#f8fafc;"><td colspan="5" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTALS</td><td style="font-weight:800;"><?=$rtu?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'admin_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'staff_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'cashier_count'))?></td><td style="font-weight:800;color:var(--success);"><?=$rau?></td><td style="font-weight:800;color:var(--danger);"><?=$rsu?></td></tr></tfoot></table><?php endif;?></div>
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
        </script><?php endif;?>
      </div>

      <div class="two-col">
        <div class="card">
          <div class="card-hdr"><span class="card-title">🏢 Subscription Payments Per Tenant</span></div>
          <?php if(empty($sales_per_tenant)):?><div class="empty-state"><p>No data.</p></div>
          <?php else:?><table><thead><tr><th>Rank</th><th>Tenant</th><th>Plan</th><th>Renewals</th><th>Amount Paid (₱)</th><th>Avg (₱)</th><th>Last Payment</th></tr></thead><tbody>
          <?php foreach($sales_per_tenant as $i=>$r):?>
          <tr><td><span class="badge <?=$i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'b-gray'))?>">#<?=$i+1?></span></td><td style="font-weight:600;"><?=htmlspecialchars($r['business_name'])?></td><td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td><td style="font-weight:700;"><?=number_format($r['tx_count'])?></td><td style="font-weight:700;color:var(--success);">₱<?=number_format($r['revenue'],2)?></td><td>₱<?=number_format($r['avg_tx'],2)?></td><td style="font-size:.73rem;color:var(--text-dim);"><?=$r['last_tx']?date('M d, Y',strtotime($r['last_tx'])):'—'?></td></tr>
          <?php endforeach;?></tbody>
          <tfoot><tr style="background:#f8fafc;"><td colspan="3" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTALS</td><td style="font-weight:800;"><?=number_format(array_sum(array_column($sales_per_tenant,'tx_count')))?></td><td style="font-weight:800;color:var(--success);">₱<?=number_format(array_sum(array_column($sales_per_tenant,'revenue')),2)?></td><td colspan="2"></td></tr></tfoot>
          </table><?php endif;?>
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
        <?php else:?><table><thead><tr><th>#</th><th>Tenant</th><th>Plan</th><th>Billing Cycle</th><th>Payment Method</th><th>Amount Paid (₱)</th><th>Date Approved</th></tr></thead><tbody>
        <?php foreach($tx_history as $i=>$tx):?>
        <tr>
          <td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td>
          <td style="font-weight:600;font-size:.79rem;"><?=htmlspecialchars($tx['business_name']??'—')?></td>
          <td><span class="badge <?=($tx['plan']??'')==='Enterprise'?'plan-ent':(($tx['plan']??'')==='Pro'?'plan-pro':'plan-starter')?>"><?=htmlspecialchars($tx['plan']??'—')?></span></td>
          <td style="font-size:.78rem;"><?=ucfirst($tx['billing_cycle']??'—')?></td>
          <td style="font-size:.77rem;"><?php $pm_=$tx['payment_method']??'';if(str_starts_with($pm_,'PayMongo')):?><span style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:6px;padding:2px 8px;font-weight:700;font-size:.7rem;">⚡ <?=htmlspecialchars($pm_)?></span><?php elseif($pm_):?><span style="color:var(--text-m);"><?=htmlspecialchars($pm_)?></span><?php if($tx['payment_reference']??''):?><div style="font-size:.68rem;color:var(--text-dim);">Ref: <?=htmlspecialchars($tx['payment_reference'])?></div><?php endif;?><?php else:?>—<?php endif;?></td>
          <td style="font-weight:700;color:var(--success);">₱<?=number_format($tx['amount']??0,2)?></td>
          <td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y h:i A',strtotime($tx['created_at']))?></td>
        </tr>
        <?php endforeach;?></tbody></table><?php endif;?>
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
          <table>
            <thead><tr><th>Date & Time</th><th>Actor</th><th>Role</th><th>Action</th><th>Entity</th><th>Message</th></tr></thead>
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
            ?>
            <tr>
              <td style="font-size:.73rem;color:var(--text-dim);white-space:nowrap;"><?=date('M d, Y h:i A',strtotime($log['created_at']))?></td>
              <td style="font-weight:600;font-size:.79rem;"><?=htmlspecialchars($log['actor_username']??'—')?></td>
              <td><span class="badge <?=['super_admin'=>'b-purple','admin'=>'b-blue','staff'=>'b-green','cashier'=>'b-yellow'][$log['actor_role']??'']??'b-gray'?>"><?=ucwords(str_replace('_',' ',$log['actor_role']??'—'))?></span></td>
              <td><span class="badge <?=$ac?>" style="font-size:.65rem;letter-spacing:.03em;"><?=htmlspecialchars($log['action']??'—')?></span></td>
              <td style="font-size:.74rem;"><?php if(!empty($log['entity_type'])):?><span style="color:var(--text-dim);"><?=htmlspecialchars(ucfirst($log['entity_type']))?></span><?php if(!empty($log['entity_id'])):?> <span class="ticket-tag">#<?=htmlspecialchars($log['entity_id'])?></span><?php endif;?><?php else:?>—<?php endif;?></td>
              <td style="font-size:.77rem;color:var(--text-m);max-width:280px;"><?=htmlspecialchars($log['message']??'—')?></td>
            </tr>
            <?php endforeach;?>
            </tbody>
          </table>
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
      <div><div class="mtitle">➕ Add Tenant + Send Invite</div><div class="msub">An invitation link will be sent to the owner's Gmail.</div></div>
      <button class="mclose" onclick="document.getElementById('addTenantModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mbody">
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:11px 14px;font-size:.78rem;color:#15803d;margin-bottom:16px;line-height:1.8;">📧 <strong>Flow:</strong> Fill form → Token generated → Email sent to owner → Owner clicks link → Owner sets username & password → Owner accesses system ✅</div>
      <form method="POST">
        <input type="hidden" name="action" value="add_tenant">
        <div style="font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:10px;display:block;">Business Information</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div style="grid-column:1/-1;"><label class="flabel">Business Name *</label><input type="text" name="business_name" class="finput" placeholder="GoldKing Pawnshop" required></div>
          <div><label class="flabel">Owner Full Name *</label><input type="text" name="owner_name" class="finput" placeholder="Juan Dela Cruz" required></div>
          <div><label class="flabel">Owner Gmail *</label><input type="email" name="email" class="finput" placeholder="owner@gmail.com" required></div>
          <div><label class="flabel">Phone</label><input type="text" name="phone" class="finput" placeholder="09XXXXXXXXX"></div>
          <div><label class="flabel">Address</label><input type="text" name="address" class="finput" placeholder="Street, City, Province"></div>
          <div style="grid-column:1/-1;"><label class="flabel">Plan *</label><select name="plan" class="finput"><option value="Starter">Starter — Free</option><option value="Pro">Pro — ₱999/mo</option><option value="Enterprise">Enterprise — ₱2,499/mo</option></select></div>
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

      <!-- Business Permit Section -->
      <div style="margin-bottom:16px;">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-dim);margin-bottom:8px;">📄 Business Permit</div>
        <div id="approve_permit_wrap" style="border:1.5px solid var(--border);border-radius:10px;overflow:hidden;min-height:80px;display:flex;align-items:center;justify-content:center;background:#f8fafc;">
          <div id="approve_permit_loading" style="color:var(--text-dim);font-size:.8rem;">Loading...</div>
          <!-- Permit content injected by JS -->
        </div>
      </div>

      <!-- Payment Details Section -->
      <div style="margin-bottom:16px;">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-dim);margin-bottom:8px;">💳 Payment Details</div>
        <div id="approve_payment_wrap" style="background:#f8fafc;border:1.5px solid var(--border);border-radius:10px;padding:12px 14px;font-size:.81rem;">
          <div id="approve_payment_content" style="color:var(--text-dim);">Loading...</div>
        </div>
      </div>

      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:11px 14px;font-size:.8rem;color:#15803d;margin-bottom:16px;line-height:1.7;">
        ✅ Please review the Business Permit and Payment details above before approving. Approving will set the tenant status to <strong>Active</strong>.
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
  const permitWrap   = document.getElementById('approve_permit_wrap');
  const paymentWrap  = document.getElementById('approve_payment_content');
  permitWrap.innerHTML  = '<div style="color:#94a3b8;font-size:.8rem;padding:16px;">Loading permit...</div>';
  paymentWrap.innerHTML = '<div style="color:#94a3b8;font-size:.8rem;">Loading payment info...</div>';

  // Fetch tenant permit & payment info via JS from tenants data
  const td = tenantsData[tid];
  if (td) {
    // ── Business Permit ──────────────────────────────────
    if (td.permit) {
      const ext = td.permit.split('.').pop().toLowerCase();
      if (ext === 'pdf') {
        permitWrap.innerHTML = `
          <div style="padding:16px;text-align:center;">
            <span style="font-size:2rem;">📄</span>
            <p style="font-size:.8rem;color:#475569;margin:6px 0 10px;">PDF Business Permit</p>
            <a href="${td.permit}" target="_blank" class="btn-sm btn-primary" style="font-size:.74rem;">View PDF →</a>
          </div>`;
      } else {
        permitWrap.innerHTML = `
          <div style="text-align:center;">
            <img src="${td.permit}" alt="Business Permit"
              style="max-width:100%;max-height:260px;object-fit:contain;border-radius:8px;cursor:pointer;"
              onclick="window.open('${td.permit}','_blank')">
            <p style="font-size:.7rem;color:#94a3b8;padding:6px;">Click image to view full size</p>
          </div>`;
      }
    } else {
      permitWrap.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:.8rem;">⚠️ No business permit uploaded.</div>';
    }

    // ── Payment Info ─────────────────────────────────────
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
    permitWrap.innerHTML  = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:.8rem;">⚠️ No business permit uploaded.</div>';
    paymentWrap.innerHTML = '<span style="color:#94a3b8;font-size:.8rem;">⚠️ No payment information found.</span>';
  }

  document.getElementById('approveModal').classList.add('open');
}
function openRejectModal(tid,uid,name){document.getElementById('reject_tid').value=tid;document.getElementById('reject_uid').value=uid;document.getElementById('reject_sub').textContent='Business: '+name;document.getElementById('rejectModal').classList.add('open');}
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
function toggleSidebar(){
  document.querySelector('.sidebar').classList.toggle('mobile-open');
  document.getElementById('mobOverlay').classList.toggle('open');
}
</script>
</body>
</html>