<?php
require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('staff');
require 'db.php';
require 'theme_helper.php';

function write_audit(PDO $pdo, $actor_id, $actor_username, $actor_role, string $action, string $entity_type = '', string $entity_id = '', string $message = '', $tenant_id = null): void {
    try {
        $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$tenant_id,$actor_id,$actor_username,$actor_role,$action,$entity_type,$entity_id,$message,$_SERVER['REMOTE_ADDR']??'::1']);
    } catch (PDOException $e) {}
}



if (empty($_SESSION['user'])) {
    header('Location: /'); exit;
}
$u = $_SESSION['user'];
if ($u['role'] !== 'staff') {
    $slug = $u['tenant_slug'] ?? '';
    header('Location: ' . ($slug ? '/' . rawurlencode($slug) . '?login=1' : '/login.php')); exit;
}

$tid         = $u['tenant_id'];
$active_page = $_GET['page'] ?? 'dashboard';
$success_msg = '';
$error_msg   = '';

$theme     = getTenantTheme($pdo, $tid);
$sys_name  = $theme['system_name'] ?? 'PawnHub';
$logo_text = $theme['logo_text'] ?: $sys_name;
$logo_url  = $theme['logo_url']  ?? '';

$tenant = null;
if ($tid) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$tid]);
    $tenant = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'register_customer') {
        $full_name    = trim($_POST['full_name']      ?? '');
        $contact      = trim($_POST['contact_number'] ?? '');
        $email        = trim($_POST['email']          ?? '');
        $birthdate    = trim($_POST['birthdate']      ?? '');
        $address      = trim($_POST['address']        ?? '');
        $id_type      = trim($_POST['valid_id_type']  ?? '');
        $id_number    = trim($_POST['valid_id_number']?? '');
        $mob_username = trim($_POST['mob_username']   ?? '');
        $mob_password = trim($_POST['mob_password']   ?? '');

        // Handle valid ID photo upload
        $valid_id_image = null;
        if (!empty($_FILES['valid_id_photo']['name'])) {
            $upload_dir = __DIR__ . '/uploads/valid_ids/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['valid_id_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp'];
            if (in_array($ext, $allowed) && $_FILES['valid_id_photo']['size'] <= 5242880) {
                $filename = 'id_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['valid_id_photo']['tmp_name'], $upload_dir . $filename)) {
                    $valid_id_image = 'uploads/valid_ids/' . $filename;
                }
            } else {
                $error_msg = 'Invalid ID photo. Use JPG/PNG/WEBP under 5MB.';
                $active_page = 'register_customer';
            }
        }

        // Check mobile username uniqueness
        $mob_username_taken = false;
        if (!$error_msg && $mob_username !== '') {
            $chk = $pdo->prepare("SELECT id FROM mobile_customers WHERE tenant_id=? AND username=? LIMIT 1");
            $chk->execute([$tid, $mob_username]);
            if ($chk->fetch()) {
                $mob_username_taken = true;
                $error_msg = 'Mobile username already taken. Please choose another.';
                $active_page = 'register_customer';
            }
        }

        if ($full_name && $contact && !$error_msg) {
            try {
                $birthdate_val = $birthdate !== '' ? $birthdate : null;

                $pdo->prepare("INSERT INTO customers (tenant_id,full_name,contact_number,email,birthdate,address,valid_id_type,valid_id_number,valid_id_image,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$tid,$full_name,$contact,$email ?: null,$birthdate_val,$address ?: null,$id_type,$id_number,$valid_id_image,$u['id']]);
                $cust_id = (int)$pdo->lastInsertId();

                if ($mob_username !== '' && strlen($mob_password) >= 8) {
                    $pdo->prepare("INSERT INTO mobile_customers (tenant_id,full_name,username,email,password,contact_number,birthdate,address,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
                        ->execute([$tid,$full_name,$mob_username,$email ?: null,password_hash($mob_password,PASSWORD_BCRYPT),$contact ?: null,$birthdate_val,$address ?: null]);
                }

                write_audit($pdo,$u['id'],$u['username'],'staff','CUSTOMER_CREATE','customer',(string)$cust_id,"Registered customer: $full_name.",$tid);
                $success_msg = "Customer $full_name registered successfully!" . ($mob_username !== '' && strlen($mob_password) >= 8 ? ' Mobile account created.' : '');
                $active_page = 'customers';

            } catch (Throwable $e) {
                $error_msg = 'Registration failed: ' . $e->getMessage();
                $active_page = 'register_customer';
            }
        } elseif (!$error_msg) {
            $error_msg = 'Full name and contact number are required.';
            $active_page = 'register_customer';
        }
    }

    if ($_POST['action'] === 'create_ticket') {
        $customer_name  = trim($_POST['customer_name']   ?? '');
        $contact_number = trim($_POST['contact_number']  ?? '');
        $email          = trim($_POST['email']           ?? '');
        $address        = trim($_POST['address']         ?? '');
        $birthdate      = trim($_POST['birthdate']       ?? '');
        $valid_id_type  = trim($_POST['valid_id_type']   ?? '');
        $valid_id_no    = trim($_POST['valid_id_number'] ?? '');
        // Unused fields — kept as empty for DB compatibility
        $gender      = ''; $nationality = 'Filipino'; $birthplace  = '';
        $src_income  = ''; $nature_work = ''; $occupation  = ''; $business = '';
        $item_category  = trim($_POST['item_category']   ?? '');
        $item_desc      = trim($_POST['item_description']?? '');
        $item_condition = trim($_POST['item_condition']  ?? 'Excellent');
        $item_weight    = floatval($_POST['item_weight'] ?? 0);
        $item_karat     = trim($_POST['item_karat']      ?? '');
        $serial_number  = trim($_POST['serial_number']   ?? '');

        // Handle item photo upload
        $item_photo_path = null;
        if (!empty($_FILES['item_photo']['name'])) {
            $upload_dir = __DIR__ . '/uploads/pawn_items/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['item_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp'];
            if (in_array($ext, $allowed) && $_FILES['item_photo']['size'] <= 5242880) {
                $filename = 'item_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['item_photo']['tmp_name'], $upload_dir . $filename)) {
                    $item_photo_path = 'uploads/pawn_items/' . $filename;
                }
            }
        }
        $appraisal      = floatval($_POST['appraisal_value'] ?? 0);
        $loan_amount    = floatval($_POST['loan_amount']     ?? 0);
        $interest_rate  = floatval($_POST['interest_rate']   ?? 0.02);
        $claim_term     = trim($_POST['claim_term']      ?? '1-15');

        $term_days = match($claim_term) {
            '1-15'  => 15, '16-30' => 30,
            '2m'    => 60, '3m'    => 90, '4m' => 120, default => 30,
        };
        $pawn_date       = date('Y-m-d');
        $maturity_date   = date('Y-m-d', strtotime("+$term_days days"));
        $expiry_date     = date('Y-m-d', strtotime("+".($term_days + 90)." days"));
        $interest_amount = round($loan_amount * $interest_rate, 2);
        $total_redeem    = $loan_amount + $interest_amount;
        $ticket_no       = 'TP-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

        if ($customer_name && $item_category && $appraisal > 0 && $loan_amount > 0) {
            $pdo->prepare("INSERT INTO pawn_transactions (tenant_id,ticket_no,customer_name,contact_number,email,address,birthdate,gender,nationality,birthplace,source_of_income,nature_of_work,occupation,business_office_school,valid_id_type,valid_id_number,item_category,item_description,item_condition,item_weight,item_karat,serial_number,appraisal_value,loan_amount,interest_rate,claim_term,interest_amount,total_redeem,pawn_date,maturity_date,expiry_date,status,created_by,assigned_staff_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Stored',?,?)")
                ->execute([$tid,$ticket_no,$customer_name,$contact_number,$email,$address,$birthdate?:null,$gender,$nationality,$birthplace,$src_income,$nature_work,$occupation,$business,$valid_id_type,$valid_id_no,$item_category,$item_desc,$item_condition,$item_weight,$item_karat,$serial_number,$appraisal,$loan_amount,$interest_rate,$claim_term,$interest_amount,$total_redeem,$pawn_date,$maturity_date,$expiry_date,$u['id'],$u['id']]);

            $inv_id = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO item_inventory (tenant_id,pawn_id,ticket_no,item_name,item_category,serial_no,condition_notes,appraisal_value,loan_amount,item_photo_path,status) VALUES (?,?,?,?,?,?,?,?,?,?,'pawned')")
                ->execute([$tid,$inv_id,$ticket_no,$item_desc,$item_category,$serial_number,$item_condition,$appraisal,$loan_amount,$item_photo_path]);

            $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address) VALUES (?,?,?,?,'PAWN_CREATE','pawn_transaction',?,?,?)")
                ->execute([$tid,$u['id'],$u['username'],'staff',$ticket_no,"Created pawn ticket",$_SERVER['REMOTE_ADDR']??'::1']);

            // ── Notify mobile app ─────────────────────────────
            require_once __DIR__ . '/session_helper.php';
            write_pawn_update($pdo, $tid, $ticket_no, 'PAWNED',
                "Your item has been successfully pawned. Ticket #$ticket_no — Loan: ₱" . number_format($loan_amount, 2) . ". Maturity: $maturity_date.");

            $success_msg = "Pawn ticket $ticket_no created successfully!";
            $active_page = 'tickets';
        } else {
            $error_msg = 'Please fill all required fields.';
        }
    }

    if ($_POST['action'] === 'void_request') {
        $ticket_no = trim($_POST['ticket_no'] ?? '');
        $reason    = trim($_POST['reason']    ?? '');
        if ($ticket_no && $reason) {
            $pdo->prepare("INSERT INTO pawn_void_requests (tenant_id,ticket_no,requested_by,reason,status) VALUES (?,?,?,?,'pending')")
                ->execute([$tid,$ticket_no,$u['id'],$reason]);
            $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address) VALUES (?,?,?,?,'PAWN_VOID_REQUEST','pawn_transaction',?,?,?)")
                ->execute([$tid,$u['id'],$u['username'],'staff',$ticket_no,"Void request: $reason",$_SERVER['REMOTE_ADDR']??'::1']);
            $success_msg = 'Void request submitted for admin approval.';
            $active_page = 'tickets';
        }
    }

    // ── Mobile Pawn Request: Staff verifies item & sends loan offer ──────────
    if ($_POST['action'] === 'send_offer') {
        $req_id        = (int)trim($_POST['request_id']   ?? 0);
        $offer_amount  = floatval($_POST['offer_amount']  ?? 0);
        $interest_rate = floatval($_POST['interest_rate'] ?? 0.02);
        $appraisal     = floatval($_POST['appraisal']     ?? 0);
        $remarks       = trim($_POST['staff_notes']       ?? '');
        $claim_term    = trim($_POST['claim_term']        ?? '1-15');

        if ($req_id > 0 && $offer_amount > 0) {
            $preq = $pdo->prepare("SELECT * FROM pawn_requests WHERE id=? AND tenant_id=? AND status='pending' LIMIT 1");
            $preq->execute([$req_id, $tid]);
            $pr = $preq->fetch();

            if ($pr) {
                $pdo->prepare("UPDATE pawn_requests SET
                    offer_amount    = ?,
                    interest_rate   = ?,
                    appraisal_value = ?,
                    remarks         = ?,
                    claim_term      = ?,
                    staff_id        = ?,
                    status          = 'approved',
                    updated_at      = NOW()
                  WHERE id = ? AND tenant_id = ?")
                  ->execute([$offer_amount, $interest_rate, $appraisal, $remarks, $claim_term, $u['id'], $req_id, $tid]);

                write_pawn_update($pdo, $tid, $pr['request_no'], 'OFFER_SENT',
                    "Staff has reviewed your item and is offering a loan of ₱" . number_format($offer_amount, 2) .
                    " at " . ($interest_rate * 100) . "% interest. Please open the app to accept or decline.");

                write_audit($pdo, $u['id'], $u['username'], 'staff', 'MOBILE_OFFER_SENT', 'pawn_request', (string)$req_id,
                    "Sent offer ₱{$offer_amount} for request #{$req_id} ({$pr['request_no']})", $tid);

                $success_msg = "Offer of ₱" . number_format($offer_amount, 2) . " sent to customer successfully.";
            } else {
                $error_msg = 'Request not found or already processed.';
            }
        } else {
            $error_msg = 'Please enter a valid offer amount.';
        }
        $active_page = 'mobile_requests';
    }

    // ── Mobile Pawn Request: Staff finalizes → convert to pawn ticket ────────
    if ($_POST['action'] === 'finalize_pawn_request') {
        $req_id = (int)trim($_POST['request_id'] ?? 0);

        if ($req_id > 0) {
            // 'approved' = offer sent; 'customer_accepted' = customer confirmed via app
            $preq = $pdo->prepare("SELECT * FROM pawn_requests WHERE id=? AND tenant_id=? AND status IN ('approved','customer_accepted') LIMIT 1");
            $preq->execute([$req_id, $tid]);
            $pr = $preq->fetch();

            if ($pr) {
                $ticket_no       = 'TP-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
                $loan_amount     = (float)($pr['offer_amount']    ?? 0);
                $interest_rate   = (float)($pr['interest_rate']   ?? 0.02);
                $appraisal       = (float)($pr['appraisal_value'] ?? 0);
                $claim_term      = $pr['claim_term'] ?? '1-15';
                $interest_amount = round($loan_amount * $interest_rate, 2);
                $total_redeem    = $loan_amount + $interest_amount;
                $term_days       = match($claim_term) {
                    '1-15'=>15,'16-30'=>30,'2m'=>60,'3m'=>90,'4m'=>120, default=>30
                };
                $pawn_date     = date('Y-m-d');
                $maturity_date = date('Y-m-d', strtotime("+{$term_days} days"));
                $expiry_date   = date('Y-m-d', strtotime("+".($term_days+90)." days"));

                // Fetch mobile customer details
                $mc = null;
                if (!empty($pr['customer_id'])) {
                    $mcs = $pdo->prepare("SELECT * FROM mobile_customers WHERE id=? LIMIT 1");
                    $mcs->execute([$pr['customer_id']]);
                    $mc = $mcs->fetch();
                }
                $customer_name  = $mc['full_name']      ?? $pr['customer_name']  ?? 'Mobile Customer';
                $contact_number = $mc['contact_number'] ?? $pr['contact_number'] ?? '';
                $email          = $mc['email']          ?? '';
                $address        = $mc['address']        ?? '';
                $birthdate      = $mc['birthdate']      ?? null;

                $pdo->prepare("INSERT INTO pawn_transactions
                    (tenant_id,ticket_no,customer_name,contact_number,email,address,birthdate,
                     gender,nationality,birthplace,source_of_income,nature_of_work,occupation,business_office_school,
                     valid_id_type,valid_id_number,
                     item_category,item_description,item_condition,item_weight,item_karat,serial_number,
                     appraisal_value,loan_amount,interest_rate,claim_term,interest_amount,total_redeem,
                     pawn_date,maturity_date,expiry_date,status,created_by,assigned_staff_id)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Stored',?,?)")
                  ->execute([
                    $tid,$ticket_no,$customer_name,$contact_number,$email,$address,$birthdate,
                    '','Filipino','','','','','',
                    '','',
                    $pr['item_category'],$pr['item_description'],$pr['item_condition']??'Good',
                    0,'',$pr['serial_number']??'',
                    $appraisal,$loan_amount,$interest_rate,$claim_term,$interest_amount,$total_redeem,
                    $pawn_date,$maturity_date,$expiry_date,$u['id'],$u['id']
                  ]);

                $inv_id = (int)$pdo->lastInsertId();
                // Use front_photo_path as the item photo
                $item_photo = $pr['front_photo_path'] ?? null;
                $pdo->prepare("INSERT INTO item_inventory (tenant_id,pawn_id,ticket_no,item_name,item_category,serial_no,condition_notes,appraisal_value,loan_amount,item_photo_path,status) VALUES (?,?,?,?,?,?,?,?,?,?,'pawned')")
                  ->execute([$tid,$inv_id,$ticket_no,$pr['item_description'],$pr['item_category'],$pr['serial_number']??'',$pr['item_condition']??'Good',$appraisal,$loan_amount,$item_photo]);

                // Mark pawn_request as cancelled (closed/finalized) and store ticket_no
                $pdo->prepare("UPDATE pawn_requests SET status='cancelled', ticket_no=?, updated_at=NOW() WHERE id=?")
                  ->execute([$ticket_no, $req_id]);

                write_pawn_update($pdo, $tid, $ticket_no, 'PAWNED',
                    "Your item has been successfully pawned. Ticket #{$ticket_no} — Loan: ₱" . number_format($loan_amount, 2) . ". Maturity: {$maturity_date}.");

                write_audit($pdo, $u['id'], $u['username'], 'staff', 'PAWN_CREATE', 'pawn_transaction', $ticket_no,
                    "Finalized mobile request #{$req_id} → ticket {$ticket_no}", $tid);

                $success_msg = "Pawn ticket {$ticket_no} created from mobile request!";
            } else {
                $error_msg = 'Request not found or offer not yet sent.';
            }
        }
        $active_page = 'mobile_requests';
    }

    // ── Mobile Pawn Request: Reject ───────────────────────────────────────────
    if ($_POST['action'] === 'decline_request') {
        $req_id = (int)trim($_POST['request_id'] ?? 0);
        $reason = trim($_POST['decline_reason'] ?? '');
        if ($req_id > 0) {
            $preq = $pdo->prepare("SELECT * FROM pawn_requests WHERE id=? AND tenant_id=? LIMIT 1");
            $preq->execute([$req_id, $tid]);
            $pr = $preq->fetch();
            if ($pr && in_array($pr['status'], ['pending','approved'])) {
                $pdo->prepare("UPDATE pawn_requests SET status='rejected', remarks=?, staff_id=?, updated_at=NOW() WHERE id=?")
                  ->execute([$reason ?: $pr['remarks'], $u['id'], $req_id]);
                write_pawn_update($pdo, $tid, $pr['request_no'], 'REJECTED',
                    "Unfortunately, your pawn request has been declined." . ($reason ? " Reason: {$reason}" : ''));
                write_audit($pdo, $u['id'], $u['username'], 'staff', 'MOBILE_REQUEST_REJECTED', 'pawn_request', (string)$req_id,
                    "Rejected mobile request #{$req_id}", $tid);
                $success_msg = 'Request rejected and customer notified.';
            } else {
                $error_msg = 'Request not found or already finalized.';
            }
        }
        $active_page = 'mobile_requests';
    }
}

$today = date('Y-m-d');
$my_tickets_today = $pdo->prepare("SELECT COUNT(*) FROM pawn_transactions WHERE tenant_id=? AND created_by=? AND DATE(created_at)=?"); $my_tickets_today->execute([$tid,$u['id'],$today]); $my_tickets_today=$my_tickets_today->fetchColumn();
$active_count     = $pdo->prepare("SELECT COUNT(*) FROM pawn_transactions WHERE tenant_id=? AND assigned_staff_id=? AND status='Stored'"); $active_count->execute([$tid,$u['id']]); $active_count=$active_count->fetchColumn();

$all_tickets  = $pdo->prepare("SELECT * FROM pawn_transactions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 100"); $all_tickets->execute([$tid]); $all_tickets=$all_tickets->fetchAll();
$my_active    = $pdo->prepare("SELECT * FROM pawn_transactions WHERE tenant_id=? AND assigned_staff_id=? AND status='Stored' ORDER BY maturity_date ASC"); $my_active->execute([$tid,$u['id']]); $my_active=$my_active->fetchAll();
$customers    = $pdo->prepare("SELECT * FROM customers WHERE tenant_id=? ORDER BY full_name"); $customers->execute([$tid]); $customers=$customers->fetchAll();
$my_void_reqs = $pdo->prepare("SELECT * FROM pawn_void_requests WHERE tenant_id=? AND requested_by=? ORDER BY requested_at DESC"); $my_void_reqs->execute([$tid,$u['id']]); $my_void_reqs=$my_void_reqs->fetchAll();

// Mobile pawn requests
$mobile_requests = [];
$mobile_req_pending_count = 0;
try {
    $mrq = $pdo->prepare("
        SELECT pr.*, mc.full_name AS mc_name, mc.contact_number AS mc_contact, mc.email AS mc_email
        FROM pawn_requests pr
        LEFT JOIN mobile_customers mc ON mc.id = pr.customer_id
        WHERE pr.tenant_id = ?
        ORDER BY FIELD(pr.status,'customer_accepted','pending','approved','rejected','cancelled') DESC, pr.created_at DESC
        LIMIT 100
    ");
    $mrq->execute([$tid]);
    $mobile_requests = $mrq->fetchAll();
    $mobile_req_pending_count = count(array_filter($mobile_requests, fn($r) => in_array($r['status'], ['pending', 'customer_accepted'])));
} catch (Throwable $e) { $mobile_requests = []; $mobile_req_pending_count = 0; }

$business_name = $tenant['business_name'] ?? 'My Branch';

function normalize_photo_path(string $p): string {
    if (!$p) return '';
    if (strpos($p, 'http') === 0) return $p;
    return '/' . ltrim($p, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?=htmlspecialchars($business_name)?> — Staff</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<?= renderThemeCSS($theme) ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --sw:265px;
  --blue-acc:var(--t-primary,#2563eb);
  --bg:#0a0d14;
  --text:#f0f2f5;
  --text-m:rgba(255,255,255,.55);
  --text-dim:rgba(255,255,255,.35);
  --success:#10b981;
  --danger:#ef4444;
  --warning:#f59e0b;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow:hidden;}
.bg-scene{position:fixed;inset:0;z-index:0;}
.bg-scene img{width:100%;height:100%;object-fit:cover;opacity:.12;filter:brightness(0.5) saturate(0.8);}
.bg-overlay{position:absolute;inset:0;background:linear-gradient(135deg,rgba(10,13,20,.98) 0%,rgba(10,13,20,.85) 60%,rgba(var(--t-sidebar-rgb,30,58,138),.1) 100%);}

.sidebar{
  width:var(--sw);min-height:100vh;
  background:#ffffff;
  border-right:1px solid #e4e6eb;
  display:flex;flex-direction:column;
  position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;
}
.sb-brand{padding:22px 18px 14px;border-bottom:1px solid #e4e6eb;display:flex;align-items:center;gap:11px;}
.sb-logo{width:38px;height:38px;background:linear-gradient(135deg,var(--t-primary,#3b82f6),var(--t-secondary,#1e3a8a));border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;box-shadow:0 4px 14px rgba(37,99,235,.2);}
.sb-logo img{width:100%;height:100%;object-fit:cover;}
.sb-logo svg{width:19px;height:19px;}
.sb-name{font-size:.92rem;font-weight:800;color:#1c1e21;letter-spacing:-.02em;}
.sb-subtitle{font-size:.58rem;color:#8a8d91;font-weight:600;letter-spacing:.1em;text-transform:uppercase;margin-top:1px;}

.sb-tenant-card{margin:10px 10px 0;background:#f0f2f5;border:1px solid #e4e6eb;border-radius:12px;padding:12px 14px;}
.sb-tenant-label{font-size:.58rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#8a8d91;margin-bottom:5px;}
.sb-tenant-name{font-size:.85rem;font-weight:700;color:#1c1e21;}
.sb-tenant-badge{display:inline-flex;align-items:center;gap:4px;font-size:.66rem;font-weight:700;background:color-mix(in srgb,var(--t-primary,#2563eb) 12%,transparent);color:var(--t-primary,#2563eb);padding:2px 8px;border-radius:100px;margin-top:5px;}

.sb-user{padding:10px 18px;border-bottom:1px solid #e4e6eb;display:flex;align-items:center;gap:9px;margin-top:8px;}
.sb-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--t-primary,#3b82f6),var(--t-secondary,#1e3a8a));display:flex;align-items:center;justify-content:center;font-size:.74rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.79rem;font-weight:700;color:#1c1e21;}
.sb-urole{font-size:.62rem;color:#65676b;}
.sb-status{display:inline-flex;align-items:center;gap:3px;font-size:.6rem;font-weight:700;background:rgba(16,185,129,.12);color:#059669;padding:2px 7px;border-radius:100px;margin-top:3px;}

.sb-nav{flex:1;padding:10px 0;}
.sb-section{font-size:.58rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#8a8d91;padding:12px 16px 4px;}
.sb-item{display:flex;align-items:center;gap:10px;padding:9px 14px;margin:1px 8px;border-radius:10px;cursor:pointer;color:#65676b;font-size:.82rem;font-weight:500;text-decoration:none;transition:all .18s;}
.sb-item:hover{background:#f2f2f2;color:#1c1e21;}
.sb-item.active{background:color-mix(in srgb,var(--t-primary,#2563eb) 12%,transparent);color:var(--t-primary,#2563eb);font-weight:600;}
.sb-item .material-symbols-outlined{font-size:18px;flex-shrink:0;}

.sb-footer{padding:12px 14px;border-top:1px solid #e4e6eb;}
.sb-logout{display:flex;align-items:center;gap:9px;font-size:.8rem;color:#65676b;text-decoration:none;padding:9px 10px;border-radius:10px;transition:all .18s;}
.sb-logout:hover{color:#ef4444;background:rgba(239,68,68,.08);}
.sb-logout .material-symbols-outlined{font-size:18px;}

.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;position:relative;z-index:10;height:100vh;overflow-y:auto;}
.topbar{height:60px;padding:0 26px;background:#ffffff;border-bottom:1px solid #e4e6eb;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:.97rem;font-weight:700;color:#1c1e21;}
.tenant-badge{font-size:.68rem;font-weight:700;background:color-mix(in srgb,var(--t-primary,#2563eb) 10%,transparent);color:var(--t-primary,#2563eb);padding:3px 11px;border-radius:100px;border:1px solid color-mix(in srgb,var(--t-primary,#2563eb) 25%,transparent);}
.topbar-icon{width:34px;height:34px;border-radius:50%;background:#e4e6eb;border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#65676b;transition:all .15s;position:relative;}
.topbar-icon:hover{background:#d8dadf;color:#1c1e21;}
.topbar-icon .material-symbols-outlined{font-size:17px;}
.notif-badge{position:absolute;top:4px;right:4px;min-width:16px;height:16px;background:#ef4444;border-radius:100px;border:2px solid #ffffff;font-size:.6rem;font-weight:800;color:#fff;display:flex;align-items:center;justify-content:center;padding:0 3px;line-height:1;}
.notif-panel{position:absolute;top:calc(100% + 10px);right:0;width:310px;background:#ffffff;border:1px solid #e4e6eb;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.15);z-index:200;overflow:hidden;display:none;animation:panelIn .18s ease both;}
.notif-panel.open{display:block;}
@keyframes panelIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.notif-panel-head{padding:12px 14px;border-bottom:1px solid #e4e6eb;display:flex;align-items:center;justify-content:space-between;}
.notif-panel-title{font-size:.83rem;font-weight:700;color:#1c1e21;}
.notif-panel-clear{font-size:.7rem;color:#65676b;cursor:pointer;background:none;border:none;font-family:inherit;}
.notif-list{max-height:280px;overflow-y:auto;}
.notif-item{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-bottom:1px solid #e4e6eb;text-decoration:none;transition:background .15s;}
.notif-item:hover{background:#f7f8fa;}
.notif-item:last-child{border-bottom:none;}
.notif-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.notif-icon .material-symbols-outlined{font-size:14px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;}
.notif-text-title{font-size:.76rem;font-weight:600;color:#1c1e21;line-height:1.3;margin-bottom:2px;}
.notif-text-sub{font-size:.67rem;color:#65676b;line-height:1.4;}
.notif-empty{padding:24px 14px;text-align:center;color:#8a8d91;font-size:.78rem;}
.content{padding:22px 26px;flex:1;}

.card{background:color-mix(in srgb,var(--t-primary,#2563eb) 8%,rgba(255,255,255,.06));border:1px solid color-mix(in srgb,var(--t-primary,#2563eb) 25%,rgba(255,255,255,.1));border-radius:16px;padding:18px 20px;}
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:20px;}
.stat-card{background:color-mix(in srgb,var(--t-primary,#2563eb) 8%,rgba(255,255,255,.06));border:1px solid color-mix(in srgb,var(--t-primary,#2563eb) 25%,rgba(255,255,255,.1));border-radius:14px;padding:16px 18px;}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;}
.stat-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;}
.stat-icon .material-symbols-outlined{font-size:18px;}
.stat-value{font-size:1.5rem;font-weight:800;color:#fff;letter-spacing:-.03em;}
.stat-label{font-size:.68rem;color:rgba(255,255,255,.45);margin-top:3px;}

.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.page-hdr h2{font-size:1.1rem;font-weight:800;color:#fff;}
.page-hdr p{font-size:.78rem;color:#65676b;margin-top:2px;}

table{width:100%;border-collapse:collapse;}
th{font-size:.63rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#8a8d91;padding:8px 12px;text-align:left;border-bottom:1px solid #e4e6eb;}
td{padding:11px 12px;font-size:.81rem;color:#1c1e21;border-bottom:1px solid #f0f2f5;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#f7f8fa;}
.ticket-tag{font-family:monospace;font-size:.76rem;color:var(--t-primary,#2563eb);font-weight:700;}
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.63rem;font-weight:700;padding:3px 9px;border-radius:100px;}
.b-blue{background:rgba(37,99,235,.12);color:#1d4ed8;}.b-green{background:rgba(16,185,129,.12);color:#059669;}.b-red{background:rgba(239,68,68,.12);color:#dc2626;}.b-yellow{background:rgba(245,158,11,.12);color:#d97706;}.b-gray{background:#e4e6eb;color:#65676b;}
.b-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}

.btn-xs{padding:5px 11px;border-radius:7px;font-size:.73rem;font-weight:600;cursor:pointer;border:1px solid #e4e6eb;background:#f0f2f5;color:#1c1e21;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all .15s;margin-right:3px;font-family:inherit;}
.btn-xs:hover{background:#e4e6eb;}
.btn-primary-xs{background:var(--t-primary,#2563eb);color:#fff;border-color:transparent;}
.btn-danger-xs{background:rgba(239,68,68,.9);color:#fff;border-color:transparent;}

.qa-btn{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;font-family:inherit;font-size:.83rem;font-weight:600;cursor:pointer;border:none;width:100%;text-align:left;transition:all .18s;margin-bottom:8px;text-decoration:none;color:#fff;}
.qa-primary{background:var(--t-primary,#2563eb);}
.qa-primary:hover{filter:brightness(1.1);}
.qa-secondary{background:#f0f2f5;color:#1c1e21;border:1px solid #e4e6eb;}
.qa-secondary:hover{background:#e4e6eb;color:#1c1e21;}
.qa-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.qa-icon .material-symbols-outlined{font-size:16px;}

.flabel{display:block;font-size:.73rem;font-weight:600;color:#65676b;margin-bottom:5px;}
.finput{width:100%;border:1.5px solid #e4e6eb;border-radius:10px;padding:9px 12px;font-family:inherit;font-size:.84rem;color:#1c1e21;outline:none;background:#f7f8fa;transition:border .2s;}
.finput:focus{border-color:var(--t-primary,#2563eb);box-shadow:0 0 0 3px color-mix(in srgb,var(--t-primary,#2563eb) 15%,transparent);background:#fff;}
.finput::placeholder{color:#8a8d91;}
.finput option{background:#ffffff;color:#1c1e21;}
.fgroup{margin-bottom:12px;}
.form-grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

.alert{padding:11px 16px;border-radius:12px;font-size:.82rem;margin-bottom:18px;display:flex;align-items:center;gap:9px;}
.alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:#059669;}
.alert-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#dc2626;}

.empty-state{text-align:center;padding:48px 20px;color:#8a8d91;}
.empty-state .material-symbols-outlined{font-size:46px;display:block;margin:0 auto 14px;opacity:.4;}
.empty-state p{font-size:.82rem;}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(4px);}
.modal-overlay.open{display:flex;}
.modal{background:#ffffff;border:1px solid #e4e6eb;border-radius:20px;width:580px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.15);animation:mIn .25s ease both;}
@keyframes mIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.mhdr{padding:22px 24px 0;display:flex;align-items:center;justify-content:space-between;}
.mtitle{font-size:1rem;font-weight:800;color:#1c1e21;}
.mclose{width:30px;height:30px;border-radius:8px;border:1px solid #e4e6eb;background:#f0f2f5;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#65676b;}
.mclose .material-symbols-outlined{font-size:16px;}
.mbody{padding:18px 24px 24px;}
.card-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#8a8d91;margin-bottom:14px;}

@media(max-width:1000px){.stats-row{grid-template-columns:repeat(2,1fr);}}
@media(max-width:768px){
  .sidebar{transform:translateX(-100%);transition:transform .3s ease;box-shadow:none;}
  .sidebar.mobile-open{transform:translateX(0);box-shadow:4px 0 30px rgba(0,0,0,.7);}
  .main{margin-left:0!important;width:100%;}
  .topbar{padding:0 14px;}
  #mob-menu-btn{display:flex!important;}
  .mob-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99;backdrop-filter:blur(2px);}
  .mob-overlay.open{display:block;}
  .content{padding:14px;}
  .form-grid2{grid-template-columns:1fr;}
}
@media(max-width:600px){.stats-row{grid-template-columns:1fr;}}
</style>
</head>
<body>
<?php
$staffBg = getTenantBgImage($theme, 'https://images.unsplash.com/photo-1611532736597-de2d4265fba3?w=1600&auto=format&fit=crop&q=60');
?>
<div class="bg-scene">
  <img src="<?= $staffBg ?>" alt="">
  <div class="bg-overlay"></div>
</div>

<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">
      <?php if($logo_url): ?>
        <img src="<?=htmlspecialchars($logo_url)?>" alt="logo">
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
      <?php endif; ?>
    </div>
    <div>
      <div class="sb-name"><?=htmlspecialchars($business_name)?></div>
      <div class="sb-subtitle">Staff Portal</div>
    </div>
  </div>

  <?php if($tenant): ?>
  <div class="sb-tenant-card">
    <div class="sb-tenant-label">My Branch</div>
    <div class="sb-tenant-name"><?=htmlspecialchars($tenant['business_name'])?></div>
    <div class="sb-tenant-badge">Tenant #<?=$tenant['id']?></div>
  </div>
  <?php else: ?>
  <div style="margin:10px 10px 0;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);border-radius:12px;padding:11px 13px;font-size:.76rem;color:#fcd34d;">⚠️ No tenant assigned.</div>
  <?php endif; ?>

  <div class="sb-user">
    <div class="sb-avatar"><?=strtoupper(substr($u['name'],0,1))?></div>
    <div>
      <div class="sb-uname"><?=htmlspecialchars(explode(' ',$u['name'])[0]??$u['name'])?></div>
      <div class="sb-urole">Staff Member</div>
      <div class="sb-status">● ONLINE</div>
    </div>
  </div>

  <nav class="sb-nav">
    <div class="sb-section">Main</div>
    <a href="?page=dashboard" class="sb-item <?=$active_page==='dashboard'?'active':''?>">
      <span class="material-symbols-outlined">dashboard</span>Dashboard
    </a>
    <a href="?page=create_ticket" class="sb-item <?=$active_page==='create_ticket'?'active':''?>">
      <span class="material-symbols-outlined">add_card</span>Create Pawn Ticket
    </a>
    <div class="sb-section">Records</div>
    <a href="?page=tickets" class="sb-item <?=$active_page==='tickets'?'active':''?>">
      <span class="material-symbols-outlined">receipt_long</span>All Tickets
    </a>
    <a href="?page=customers" class="sb-item <?=$active_page==='customers'?'active':''?>">
      <span class="material-symbols-outlined">group</span>Customers
    </a>
    <a href="?page=register_customer" class="sb-item <?=$active_page==='register_customer'?'active':''?>">
      <span class="material-symbols-outlined">person_add</span>Register Customer
    </a>
    <div class="sb-section">Mobile App</div>
    <a href="?page=mobile_requests" class="sb-item <?=$active_page==='mobile_requests'?'active':''?>" style="position:relative;">
      <span class="material-symbols-outlined">smartphone</span>Mobile Requests
      <?php if($mobile_req_pending_count > 0): ?>
        <span style="margin-left:auto;background:#ef4444;color:#fff;font-size:.58rem;font-weight:800;padding:1px 6px;border-radius:100px;min-width:18px;text-align:center;"><?=$mobile_req_pending_count?></span>
      <?php endif; ?>
    </a>
    <div class="sb-section">Records</div>
    <a href="?page=void_requests" class="sb-item <?=$active_page==='void_requests'?'active':''?>">
      <span class="material-symbols-outlined">cancel_presentation</span>My Void Requests
    </a>
  </nav>
  <div class="sb-footer">
    <?php $logout_url = 'logout.php?role=staff&slug=' . rawurlencode($u['tenant_slug'] ?? ''); ?>
    <button type="button" class="sb-logout" onclick="showLogoutModal('<?= $logout_url ?>')">
      <span class="material-symbols-outlined">logout</span>Sign Out
    </button>
  </div>
</aside>

<?php
// ── Staff Notification queries ─────────────────────────────────
$notifs = [];
try {
  if ($tid) {
    // Overdue tickets (staff can see, not act on sub level)
    $od = $pdo->prepare("SELECT COUNT(*) FROM pawn_transactions WHERE tenant_id=? AND status='Stored' AND maturity_date < CURDATE()");
    $od->execute([$tid]); $od_c = (int)$od->fetchColumn();
    if ($od_c > 0) $notifs[] = ['type'=>'danger','icon'=>'receipt_long','title'=>$od_c.' Overdue Ticket'.($od_c>1?'s':''),'sub'=>'Items past maturity date.','link'=>'?page=tickets'];
    // Expiring in 3 days
    $exp = $pdo->prepare("SELECT COUNT(*) FROM pawn_transactions WHERE tenant_id=? AND status='Stored' AND maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
    $exp->execute([$tid]); $exp_c = (int)$exp->fetchColumn();
    if ($exp_c > 0) $notifs[] = ['type'=>'warn','icon'=>'hourglass_bottom','title'=>$exp_c.' Ticket'.($exp_c>1?'s':'').' Expiring in 3 Days','sub'=>'Remind customers to redeem or renew.','link'=>'?page=tickets'];
    // My own pending void requests
    $vr = $pdo->prepare("SELECT COUNT(*) FROM pawn_void_requests WHERE requested_by=? AND status='pending'");
    $vr->execute([$u['id']]); $vr_c = (int)$vr->fetchColumn();
    if ($vr_c > 0) $notifs[] = ['type'=>'info','icon'=>'cancel_presentation','title'=>$vr_c.' Void Request'.($vr_c>1?'s':'').' Pending','sub'=>'Waiting for admin approval.','link'=>'?page=void_requests'];
    // Mobile pawn requests pending verification
    if ($mobile_req_pending_count > 0) $notifs[] = ['type'=>'warn','icon'=>'smartphone','title'=>$mobile_req_pending_count.' Mobile Request'.($mobile_req_pending_count>1?'s':'').' Awaiting Review','sub'=>'Customers submitted items from the app.','link'=>'?page=mobile_requests'];
  }
} catch (Throwable $e) {}
$notif_count = count($notifs);
?>

<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <button id="mob-menu-btn" onclick="toggleSidebar()" style="display:none;width:34px;height:34px;border:1px solid rgba(255,255,255,.12);border-radius:8px;background:rgba(255,255,255,.06);cursor:pointer;align-items:center;justify-content:center;flex-shrink:0;color:#fff;">
        <span class="material-symbols-outlined" style="font-size:18px;">menu</span>
      </button>
      <?php if($tenant): ?><span class="tenant-badge"><?=htmlspecialchars($tenant['business_name'])?></span><?php endif;?>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <div style="display:flex;align-items:center;gap:7px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);padding:5px 11px;border-radius:100px;">
        <span style="width:9px;height:9px;border-radius:50%;background:var(--t-primary,#3b82f6);display:inline-block;"></span>
        <span style="font-size:.69rem;color:rgba(255,255,255,.5);font-weight:600;"><?=htmlspecialchars($sys_name)?></span>
      </div>
      <span style="font-size:.72rem;color:rgba(255,255,255,.3);">📅 <?=date('M d, Y')?></span>
      <div class="topbar-icon" id="notifBtn" onclick="toggleNotifPanel(event)" style="<?=$notif_count>0?'color:#fff;background:rgba(255,255,255,.08);':''?>">
        <span class="material-symbols-outlined">notifications</span>
        <?php if($notif_count>0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?>
        <div class="notif-panel" id="notifPanel" onclick="event.stopPropagation()">
          <div class="notif-panel-head">
            <span class="notif-panel-title">Notifications<?php if($notif_count>0):?> <span style="background:rgba(239,68,68,.2);color:#fca5a5;font-size:.62rem;padding:1px 6px;border-radius:100px;"><?=$notif_count?></span><?php endif;?></span>
            <button class="notif-panel-clear" onclick="document.getElementById('notifPanel').classList.remove('open')">Close ✕</button>
          </div>
          <div class="notif-list">
            <?php if(empty($notifs)):?>
            <div class="notif-empty"><span class="material-symbols-outlined" style="font-size:26px;display:block;margin-bottom:5px;opacity:.3;">check_circle</span>No notifications.</div>
            <?php else: foreach($notifs as $n):
              $ic_bg  = match($n['type']){'danger'=>'background:rgba(239,68,68,.15);','warn'=>'background:rgba(245,158,11,.15);',default=>'background:rgba(59,130,246,.15);'};
              $ic_col = match($n['type']){'danger'=>'color:#fca5a5;','warn'=>'color:#fcd34d;',default=>'color:#93c5fd;'};
            ?>
            <a href="<?=htmlspecialchars($n['link']??'#')?>" class="notif-item">
              <div class="notif-icon" style="<?=$ic_bg?>"><span class="material-symbols-outlined" style="<?=$ic_col?>"><?=$n['icon']?></span></div>
              <div><div class="notif-text-title"><?=$n['title']?></div><div class="notif-text-sub"><?=$n['sub']?></div></div>
            </a>
            <?php endforeach; endif;?>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="content">
  <?php if($success_msg):?><div class="alert alert-success"><span class="material-symbols-outlined" style="font-size:17px;">check_circle</span><?=htmlspecialchars($success_msg)?></div><?php endif;?>
  <?php if($error_msg):?><div class="alert alert-error"><span class="material-symbols-outlined" style="font-size:17px;">warning</span><?=htmlspecialchars($error_msg)?></div><?php endif;?>

  <?php if(!$tid): ?>
    <div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);border-radius:14px;padding:26px;text-align:center;color:#fcd34d;">
      <div style="font-size:1.1rem;font-weight:700;margin-bottom:8px;">⚠️ No Tenant Assigned</div>
      <p style="font-size:.85rem;opacity:.7;">Your account has not been assigned to a branch yet. Please contact your Super Admin.</p>
    </div>
  <?php elseif($active_page==='dashboard'): ?>

    <div class="page-hdr">
      <div>
        <h2>Welcome back, <?=htmlspecialchars(explode(' ',$u['name'])[0])?>! 👋</h2>
        <p>Here's your branch activity for today — <?=date('F j, Y')?>.</p>
      </div>
    </div>

    <!-- Branch Banner -->
    <div style="background:linear-gradient(135deg,var(--t-sidebar,#0f172a),var(--t-secondary,#1e3a8a));border-radius:14px;padding:18px 22px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border:1px solid rgba(0,0,0,.08);">
      <div>
        <div style="font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t-on-primary-dim,rgba(255,255,255,.4));margin-bottom:4px;">Your Branch</div>
        <div style="font-size:1.05rem;font-weight:800;color:var(--t-on-primary,#fff);"><?=htmlspecialchars($tenant['business_name'])?></div>
        <div style="font-size:.76rem;color:var(--t-on-primary-mid,rgba(255,255,255,.5));margin-top:2px;"><?=$tenant['plan']?> Plan · <?=$tenant['branches']?> Branch<?=$tenant['branches']>1?'es':''?></div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:.65rem;color:var(--t-on-primary-dim,rgba(255,255,255,.4));margin-bottom:3px;">Tenant ID</div>
        <div style="font-size:1.5rem;font-weight:800;color:var(--t-on-primary,#fff);">#<?=$tid?></div>
      </div>
    </div>

    <div class="stats-row">
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(59,130,246,.15);"><span class="material-symbols-outlined" style="color:#93c5fd;">confirmation_number</span></div></div><div class="stat-value"><?=$my_tickets_today?></div><div class="stat-label">Tickets Today</div></div>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(236,72,153,.15);"><span class="material-symbols-outlined" style="color:#f9a8d4;">shield</span></div></div><div class="stat-value"><?=$active_count?></div><div class="stat-label">My Active Tickets</div></div>
      <?php $cust_today=(int)$pdo->query("SELECT COUNT(*) FROM customers WHERE tenant_id=$tid AND created_by={$u['id']} AND DATE(registered_at)='$today'")->fetchColumn(); ?>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(16,185,129,.15);"><span class="material-symbols-outlined" style="color:#6ee7b7;">person_add</span></div></div><div class="stat-value"><?=$cust_today?></div><div class="stat-label">Customers Today</div></div>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(139,92,246,.15);"><span class="material-symbols-outlined" style="color:#c4b5fd;">cancel_presentation</span></div></div><div class="stat-value"><?=count($my_void_reqs)?></div><div class="stat-label">Void Requests</div></div>
    </div>

    <div style="display:grid;grid-template-columns:280px 1fr;gap:16px;">
      <div>
        <div class="card" style="margin-bottom:14px;">
          <div class="card-title">⚡ Quick Actions</div>
          <a href="?page=create_ticket" class="qa-btn qa-primary">
            <div class="qa-icon" style="background:rgba(255,255,255,.15);"><span class="material-symbols-outlined">add</span></div>New Pawn Ticket
          </a>
          <a href="?page=register_customer" class="qa-btn qa-secondary">
            <div class="qa-icon" style="background:rgba(16,185,129,.15);"><span class="material-symbols-outlined" style="color:#6ee7b7;">person_add</span></div>Register Customer
          </a>
        </div>
        <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.15);border-radius:12px;padding:14px;">
          <div style="font-size:.74rem;font-weight:700;color:#fcd34d;margin-bottom:5px;">📋 Branch Note</div>
          <p style="font-size:.73rem;color:rgba(255,255,255,.4);line-height:1.6;">You are assigned to <strong style="color:rgba(255,255,255,.7);"><?=htmlspecialchars($tenant['business_name'])?></strong>. All your tickets and customers are saved under this branch.</p>
        </div>
      </div>
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="card-title" style="margin:0;">My Active Tickets</div>
          <a href="?page=tickets" style="font-size:.72rem;color:var(--t-primary,#60a5fa);font-weight:600;text-decoration:none;">View All →</a>
        </div>
        <?php if(empty($my_active)): ?><div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>No active tickets yet.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;"><table><thead><tr><th>Ticket</th><th>Customer</th><th>Item</th><th>Loan</th><th>Maturity</th></tr></thead><tbody>
        <?php foreach(array_slice($my_active,0,6) as $t): ?>
        <tr><td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td><td style="font-weight:600;color:#fff;"><?=htmlspecialchars($t['customer_name'])?></td><td><?=htmlspecialchars($t['item_category'])?></td><td>₱<?=number_format($t['loan_amount'],2)?></td><td style="font-size:.73rem;color:<?=strtotime($t['maturity_date'])<time()?'#fca5a5':'rgba(255,255,255,.35)'?>;"><?=$t['maturity_date']?></td></tr>
        <?php endforeach;?></tbody></table></div>
        <?php endif;?>
      </div>
    </div>

  <?php elseif($active_page==='create_ticket'): ?>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="create_ticket">
      <input type="hidden" name="customer_id" id="selected_customer_id" value="">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:1020px;">
        <div>
          <div class="card" style="margin-bottom:16px;">
            <div class="card-title">Customer Information</div>
            <div class="form-grid2">

              <!-- Customer Name with autocomplete -->
              <div class="fgroup" style="grid-column:1/-1;position:relative;">
                <label class="flabel">Customer Name * <span style="font-weight:400;color:rgba(255,255,255,.3);">(type to search registered customers)</span></label>
                <input type="text" name="customer_name" id="cust_name_input" class="finput"
                  placeholder="Last, First M." required autocomplete="off"
                  oninput="searchCustomers(this.value)"
                  onblur="setTimeout(()=>document.getElementById('cust_dropdown').style.display='none',200)">
                <div id="cust_dropdown" style="display:none;position:absolute;z-index:999;background:#0a0d14;border:1px solid rgba(255,255,255,.15);border-radius:10px;margin-top:4px;width:100%;max-height:220px;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.6);">
                  <?php foreach($customers as $c): ?>
                  <div class="cust-opt"
                    data-name="<?=htmlspecialchars($c['full_name'])?>"
                    data-contact="<?=htmlspecialchars($c['contact_number']??'')?>"
                    data-email="<?=htmlspecialchars($c['email']??'')?>"
                    data-birthdate="<?=htmlspecialchars($c['birthdate']??'')?>"
                    data-address="<?=htmlspecialchars($c['address']??'')?>"
                    data-id_type="<?=htmlspecialchars($c['valid_id_type']??'')?>"
                    data-id_number="<?=htmlspecialchars($c['valid_id_number']??'')?>"
                    data-id="<?=$c['id']?>"
                    onclick="selectCustomer(this)"
                    style="padding:10px 14px;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.05);">
                    <div style="font-size:.83rem;font-weight:600;color:#fff;"><?=htmlspecialchars($c['full_name'])?></div>
                    <div style="font-size:.72rem;color:rgba(255,255,255,.4);font-family:monospace;"><?=htmlspecialchars($c['contact_number']??'')?></div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="fgroup"><label class="flabel">Contact Number *</label><input type="text" name="contact_number" id="cust_contact" class="finput" placeholder="+63XXXXXXXXX" required></div>
              <div class="fgroup"><label class="flabel">Email</label><input type="email" name="email" id="cust_email" class="finput" placeholder="email@example.com"></div>
              <div class="fgroup"><label class="flabel">Birthdate</label><input type="date" name="birthdate" id="cust_birthdate" class="finput"></div>
              <div class="fgroup" style="grid-column:1/-1;"><label class="flabel">Address</label><input type="text" name="address" id="cust_address" class="finput" placeholder="Street, City, Province"></div>
              <div class="fgroup"><label class="flabel">Valid ID Type</label>
                <select name="valid_id_type" id="cust_id_type" class="finput">
                  <option value="">— Select —</option>
                  <option>Passport</option><option>Driver's License</option>
                  <option>PhilSys ID</option><option>UMID</option>
                  <option>Voter's ID</option><option>Postal ID</option>
                  <option>SSS ID</option><option>PRC ID</option>
                </select>
              </div>
              <div class="fgroup"><label class="flabel">ID Number</label><input type="text" name="valid_id_number" id="cust_id_number" class="finput" placeholder="ID Number"></div>
            </div>
          </div>
        </div>
        <div>
          <div class="card" style="margin-bottom:16px;">
            <div class="card-title">Item Information</div>
            <div class="form-grid2">
              <div class="fgroup" style="grid-column:1/-1;"><label class="flabel">Item Description *</label><input type="text" name="item_description" class="finput" placeholder="e.g. Gold Ring 18k 5g" required></div>
              <div class="fgroup"><label class="flabel">Category *</label><select name="item_category" id="item_category" class="finput" required onchange="toggleGoldFields()"><option value="">— Select Category —</option><option>Gadget</option><option>Jewelry</option><option>Gold</option><option>Silver</option><option>Watch</option><option>Laptop</option><option>Appliance</option><option>Others</option></select></div>
              <div class="fgroup"><label class="flabel">Condition</label><select name="item_condition" class="finput"><option>Excellent</option><option>Good</option><option>Fair</option><option>Poor</option></select></div>
              <div class="fgroup gold-only" id="gold_weight_wrap" style="display:none;"><label class="flabel">Weight (g)</label><input type="number" name="item_weight" id="item_weight" class="finput" placeholder="0.00" step="0.01"></div>
              <div class="fgroup gold-only" id="gold_karat_wrap" style="display:none;"><label class="flabel">Karat</label><input type="text" name="item_karat" id="item_karat" class="finput" placeholder="18k / 24k"></div>
              <div class="fgroup" style="grid-column:1/-1;"><label class="flabel">Serial No.</label><input type="text" name="serial_number" class="finput" placeholder="Serial / Reference No."></div>
              <div class="fgroup" style="grid-column:1/-1;">
                <label class="flabel">Item Photo</label>
                <input type="file" name="item_photo" class="finput" accept="image/jpeg,image/png,image/webp" style="padding:8px;" onchange="previewItemPhoto(this)">
                <div style="margin-top:8px;">
                  <img id="item_photo_preview" src="" style="display:none;max-height:130px;border-radius:8px;border:1px solid rgba(255,255,255,.12);object-fit:cover;">
                </div>
                <div style="font-size:.7rem;color:rgba(255,255,255,.25);margin-top:4px;">JPG, PNG, or WEBP · Max 5MB</div>
              </div>
            </div>
          </div>
          <div class="card" style="margin-bottom:16px;">
            <div class="card-title">Loan Details</div>
            <div class="form-grid2">
              <div class="fgroup"><label class="flabel">Appraisal Value (₱) *</label><input type="number" name="appraisal_value" id="appraisal" class="finput" placeholder="0.00" step="0.01" oninput="calcLoan()" required></div>
              <div class="fgroup"><label class="flabel">Loan Amount (₱) *</label><input type="number" name="loan_amount" id="loan_amt" class="finput" placeholder="0.00" step="0.01" oninput="calcSummary()" required></div>
              <div class="fgroup"><label class="flabel">Interest Rate</label><select name="interest_rate" id="irate" class="finput" onchange="calcSummary()"><option value="0.02">2%</option><option value="0.04">4%</option><option value="0.10">10%</option><option value="0.16">16%</option><option value="0.22">22%</option></select></div>
              <div class="fgroup"><label class="flabel">Claim Term</label><select name="claim_term" class="finput"><option value="1-15">1–15 days</option><option value="16-30">16–30 days</option><option value="2m">2 months</option><option value="3m">3 months</option><option value="4m">4 months</option></select></div>
            </div>
            <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:10px;padding:12px 14px;font-size:.8rem;">
              <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:rgba(110,231,183,.7);">Appraisal</span><span id="d_a" style="font-weight:700;color:#6ee7b7;">₱0.00</span></div>
              <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:rgba(110,231,183,.7);">Loan</span><span id="d_l" style="font-weight:700;color:#6ee7b7;">₱0.00</span></div>
              <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:rgba(110,231,183,.7);">Interest</span><span id="d_i" style="font-weight:700;color:#6ee7b7;">₱0.00</span></div>
              <div style="display:flex;justify-content:space-between;border-top:1px solid rgba(16,185,129,.2);padding-top:7px;margin-top:5px;"><span style="color:#6ee7b7;font-weight:700;">Total Redeem</span><span id="d_t" style="font-weight:800;color:#6ee7b7;font-size:.92rem;">₱0.00</span></div>
            </div>
          </div>
          <button type="submit" style="width:100%;background:linear-gradient(135deg,var(--t-primary,#2563eb),var(--t-secondary,#1d4ed8));color:#fff;border:none;border-radius:12px;padding:13px;font-family:inherit;font-size:.9rem;font-weight:700;cursor:pointer;box-shadow:0 4px 18px rgba(37,99,235,.3);">Issue Pawn Ticket</button>
        </div>
      </div>
    </form>

  <?php elseif($active_page==='tickets'): ?>
    <div class="page-hdr"><div><h2>All Tickets</h2><p><?=count($all_tickets)?> records</p></div><a href="?page=create_ticket" class="btn-xs btn-primary-xs" style="padding:7px 14px;">+ New Ticket</a></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($all_tickets)): ?><div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>No tickets yet.</p></div>
      <?php else: ?><table><thead><tr><th>Ticket No.</th><th>Customer</th><th>Contact</th><th>Item</th><th>Loan</th><th>Total Redeem</th><th>Maturity</th><th>Status</th><th>Action</th></tr></thead><tbody>
      <?php foreach($all_tickets as $t): $sc=['Stored'=>'b-blue','Released'=>'b-green','Renewed'=>'b-yellow','Voided'=>'b-red','Auctioned'=>'b-gray'];?>
      <tr><td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td><td style="font-weight:600;color:#fff;"><?=htmlspecialchars($t['customer_name'])?></td><td style="font-family:monospace;font-size:.75rem;"><?=htmlspecialchars($t['contact_number'])?></td><td><?=htmlspecialchars($t['item_category'])?></td><td>₱<?=number_format($t['loan_amount'],2)?></td><td style="font-weight:700;color:#fff;">₱<?=number_format($t['total_redeem'],2)?></td><td style="font-size:.73rem;color:<?=strtotime($t['maturity_date'])<time()&&$t['status']==='Stored'?'#fca5a5':'rgba(255,255,255,.35)'?>;"><?=$t['maturity_date']?></td><td><span class="badge <?=$sc[$t['status']]??'b-gray'?>"><?=$t['status']?></span></td>
      <td><?php if($t['status']==='Stored' && $t['assigned_staff_id']==$u['id']):?><button onclick="openVoid('<?=htmlspecialchars($t['ticket_no'])?>')" class="btn-xs btn-danger-xs" style="font-size:.7rem;">Void Req</button><?php else:?>—<?php endif;?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='customers'): ?>
    <div class="page-hdr"><div><h2>Customers</h2><p><?=count($customers)?> records</p></div><a href="?page=register_customer" class="btn-xs btn-primary-xs" style="padding:7px 14px;">+ Register</a></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($customers)):?><div class="empty-state"><span class="material-symbols-outlined">group</span><p>No customers yet.</p></div>
      <?php else:?><table><thead><tr><th>Name</th><th>Contact</th><th>Email</th><th>ID Type</th><th>ID Photo</th><th>Registered</th></tr></thead><tbody>
      <?php foreach($customers as $c):?>
      <tr>
        <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($c['full_name'])?></td>
        <td style="font-family:monospace;font-size:.75rem;"><?=htmlspecialchars($c['contact_number'])?></td>
        <td style="font-size:.75rem;color:rgba(255,255,255,.4);"><?=htmlspecialchars($c['email']??'—')?></td>
        <td><?=htmlspecialchars($c['valid_id_type']??'—')?></td>
        <td><?php if(!empty($c['valid_id_image'])):?>
          <a href="<?=htmlspecialchars($c['valid_id_image'])?>" target="_blank" style="display:inline-block;">
            <img src="<?=htmlspecialchars($c['valid_id_image'])?>" style="height:36px;border-radius:5px;border:1px solid rgba(255,255,255,.1);object-fit:cover;" onerror="this.style.display='none'">
          </a>
        <?php else:?><span style="color:rgba(255,255,255,.2);font-size:.73rem;">—</span><?php endif;?></td>
        <td style="font-size:.73rem;color:rgba(255,255,255,.35);"><?=date('M d, Y',strtotime($c['registered_at']))?></td>
      </tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='register_customer'): ?>
    <div style="max-width:680px;">
      <div class="card">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="register_customer">
          <div class="card-title">Register New Customer</div>
          <div class="form-grid2">

            <div class="fgroup" style="grid-column:1/-1;">
              <label class="flabel">Full Name * (Last, First M.)</label>
              <input type="text" name="full_name" class="finput" placeholder="Rivera, Juan D." required>
            </div>

            <div class="fgroup">
              <label class="flabel">Contact Number *</label>
              <input type="text" name="contact_number" class="finput" placeholder="+63XXXXXXXXX" required>
            </div>

            <div class="fgroup">
              <label class="flabel">Email</label>
              <input type="email" name="email" class="finput" placeholder="email@example.com">
            </div>

            <div class="fgroup">
              <label class="flabel">Birthdate</label>
              <input type="date" name="birthdate" class="finput">
            </div>

            <div class="fgroup">
              <label class="flabel">Address</label>
              <input type="text" name="address" class="finput" placeholder="Street, City, Province">
            </div>

            <div class="fgroup">
              <label class="flabel">Valid ID Type</label>
              <select name="valid_id_type" class="finput">
                <option value="">— Select —</option>
                <option>Passport</option>
                <option>Driver's License</option>
                <option>PhilSys ID</option>
                <option>UMID</option>
                <option>Voter's ID</option>
                <option>Postal ID</option>
                <option>SSS ID</option>
                <option>PRC ID</option>
              </select>
            </div>

            <div class="fgroup">
              <label class="flabel">ID Number</label>
              <input type="text" name="valid_id_number" class="finput" placeholder="ID Number">
            </div>

            <div class="fgroup" style="grid-column:1/-1;">
              <label class="flabel">Valid ID Photo</label>
              <input type="file" name="valid_id_photo" class="finput" accept="image/jpeg,image/png,image/webp" style="padding:8px;" onchange="previewId(this)">
              <div style="margin-top:8px;">
                <img id="id_preview" src="" style="display:none;max-height:140px;border-radius:8px;border:1px solid rgba(255,255,255,.12);object-fit:cover;">
              </div>
              <div style="font-size:.7rem;color:rgba(255,255,255,.25);margin-top:4px;">JPG, PNG, or WEBP · Max 5MB</div>
            </div>

            <!-- Mobile App Credentials -->
            <div class="fgroup" style="grid-column:1/-1;margin-top:8px;padding-top:12px;border-top:1px solid rgba(255,255,255,.08);">
              <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.3);margin-bottom:10px;">📱 Mobile App Login <span style="font-weight:400;opacity:.6;">(Optional)</span></div>
            </div>

            <div class="fgroup">
              <label class="flabel">Mobile Username</label>
              <input type="text" name="mob_username" class="finput" placeholder="e.g. juandelacruz">
            </div>

            <div class="fgroup">
              <label class="flabel">Mobile Password <span style="font-weight:400;color:rgba(255,255,255,.3)">(min. 8 chars)</span></label>
              <input type="password" name="mob_password" class="finput" placeholder="Customer sets this">
            </div>

          </div>
          <div style="display:flex;justify-content:flex-end;gap:9px;margin-top:10px;">
            <a href="?page=customers" class="btn-xs">Cancel</a>
            <button type="submit" class="btn-xs btn-primary-xs" style="padding:7px 18px;">Save Customer</button>
          </div>
        </form>
      </div>
    </div>

  <?php elseif($active_page==='void_requests'): ?>
    <div class="page-hdr"><div><h2>My Void Requests</h2><p>Void requests you've submitted</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($my_void_reqs)):?><div class="empty-state"><span class="material-symbols-outlined">cancel_presentation</span><p>No void requests yet.</p></div>
      <?php else:?><table><thead><tr><th>Ticket</th><th>Reason</th><th>Status</th><th>Submitted</th><th>Decided</th></tr></thead><tbody>
      <?php foreach($my_void_reqs as $v):?>
      <tr><td><span class="ticket-tag"><?=htmlspecialchars($v['ticket_no'])?></span></td><td style="max-width:200px;font-size:.78rem;"><?=htmlspecialchars($v['reason'])?></td><td><span class="badge <?=$v['status']==='approved'?'b-green':($v['status']==='pending'?'b-yellow':'b-red')?>"><?=ucfirst($v['status'])?></span></td><td style="font-size:.72rem;color:rgba(255,255,255,.35);"><?=date('M d, Y h:i A',strtotime($v['requested_at']))?></td><td style="font-size:.72rem;color:rgba(255,255,255,.35);"><?=$v['decided_at']?date('M d, Y h:i A',strtotime($v['decided_at'])):'—'?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='mobile_requests'): ?>
    <div class="page-hdr">
      <div>
        <h2>Mobile Pawn Requests</h2>
        <p><?=count($mobile_requests)?> total · <?=$mobile_req_pending_count?> pending review</p>
      </div>
    </div>

    <?php if(empty($mobile_requests)): ?>
      <div class="empty-state">
        <span class="material-symbols-outlined">smartphone</span>
        <p>No mobile requests yet. Customers submit pawn requests via the app.</p>
      </div>
    <?php else: ?>

    <?php
      $status_groups = [
        'pending'           => ['label'=>'Pending Review',      'color'=>'#f59e0b','bg'=>'rgba(245,158,11,.1)','border'=>'rgba(245,158,11,.2)'],
        'approved'          => ['label'=>'Offer Sent',          'color'=>'#3b82f6','bg'=>'rgba(59,130,246,.1)','border'=>'rgba(59,130,246,.2)'],
        'customer_accepted' => ['label'=>'Customer Accepted ✓', 'color'=>'#10b981','bg'=>'rgba(16,185,129,.1)','border'=>'rgba(16,185,129,.25)'],
        'rejected'          => ['label'=>'Rejected',            'color'=>'#ef4444','bg'=>'rgba(239,68,68,.08)','border'=>'rgba(239,68,68,.15)'],
        'cancelled'         => ['label'=>'Finalized / Pawned',  'color'=>'#6ee7b7','bg'=>'rgba(16,185,129,.06)','border'=>'rgba(16,185,129,.12)'],
      ];

      foreach ($status_groups as $sg_key => $sg):
        $group_items = array_filter($mobile_requests, fn($r) => $r['status'] === $sg_key);
        if(empty($group_items)) continue;
    ?>
    <div style="margin-bottom:28px;">
      <div style="display:flex;align-items:center;gap:9px;margin-bottom:14px;">
        <span style="font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:<?=$sg['color']?>;background:<?=$sg['bg']?>;border:1px solid <?=$sg['border']?>;padding:3px 11px;border-radius:100px;"><?=$sg['label']?></span>
        <span style="font-size:.7rem;color:rgba(255,255,255,.3);"><?=count($group_items)?> request<?=count($group_items)>1?'s':''?></span>
      </div>

      <?php foreach($group_items as $mr): ?>
      <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:20px;margin-bottom:12px;">

        <!-- Header row -->
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
          <div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
              <span style="font-size:.72rem;font-weight:700;letter-spacing:.06em;background:rgba(255,255,255,.07);padding:2px 9px;border-radius:6px;color:rgba(255,255,255,.5);font-family:monospace;"><?=htmlspecialchars($mr['request_no']??'—')?></span>
              <span style="font-size:.7rem;font-weight:700;color:<?=$sg['color']?>;background:<?=$sg['bg']?>;border:1px solid <?=$sg['border']?>;padding:2px 8px;border-radius:100px;"><?=ucfirst(str_replace('_',' ',$mr['status']))?></span>
            </div>
            <div style="font-size:.95rem;font-weight:700;color:#fff;"><?=htmlspecialchars($mr['mc_name'] ?? $mr['customer_name'] ?? 'Customer')?></div>
            <div style="font-size:.75rem;color:rgba(255,255,255,.4);margin-top:2px;">
              <?=htmlspecialchars($mr['mc_contact'] ?? $mr['contact_number'] ?? '')?><?php if(!empty($mr['mc_email'])): ?> · <?=htmlspecialchars($mr['mc_email'])?><?php endif; ?>
            </div>
          </div>
          <div style="text-align:right;font-size:.7rem;color:rgba(255,255,255,.3);"><?=date('M d, Y h:i A', strtotime($mr['created_at']))?></div>
        </div>

        <!-- Item details -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:16px;">
          <?php
            $item_fields = [
              'Item'        => $mr['item_description'] ?? '—',
              'Category'    => $mr['item_category']    ?? '—',
              'Condition'   => $mr['item_condition']   ?? '—',
              'Serial No.'  => $mr['serial_number']    ?? '—',
            ];
            if(!empty($mr['item_weight'])) $item_fields['Weight'] = $mr['item_weight'].'g';
            if(!empty($mr['item_karat']))  $item_fields['Karat']  = $mr['item_karat'];
          ?>
          <?php foreach($item_fields as $lbl => $val): ?>
          <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:10px 13px;">
            <div style="font-size:.6rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.3);margin-bottom:3px;"><?=$lbl?></div>
            <div style="font-size:.83rem;font-weight:600;color:#fff;"><?=htmlspecialchars($val)?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Item photos submitted by customer -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
          <?php
            $photos = [
              'Front Photo'  => normalize_photo_path($mr['front_photo_path']  ?? ''),
              'Back Photo'   => normalize_photo_path($mr['back_photo_path']   ?? ''),
              'Detail Photo' => normalize_photo_path($mr['detail_photo_path'] ?? ''),
            ];
            foreach($photos as $plabel => $ppath): if(empty($ppath)) continue;
          ?>
          <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.3);margin-bottom:5px;"><?=$plabel?></div>
            <a href="<?=htmlspecialchars($ppath)?>" target="_blank">
              <img src="<?=htmlspecialchars($ppath)?>" style="height:90px;width:120px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,.1);" onerror="this.style.display='none'">
            </a>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Offer details if already sent -->
        <?php if(in_array($mr['status'],['approved','cancelled']) && !empty($mr['offer_amount'])): ?>
        <div style="background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.18);border-radius:12px;padding:12px 16px;margin-bottom:14px;display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
          <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(147,197,253,.5);margin-bottom:2px;">Offer Amount</div>
            <div style="font-size:1.1rem;font-weight:800;color:#93c5fd;">₱<?=number_format((float)$mr['offer_amount'],2)?></div>
          </div>
          <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(147,197,253,.5);margin-bottom:2px;">Interest Rate</div>
            <div style="font-size:.88rem;font-weight:700;color:#93c5fd;"><?=number_format((float)$mr['interest_rate']*100,0)?>%</div>
          </div>
          <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(147,197,253,.5);margin-bottom:2px;">Appraisal</div>
            <div style="font-size:.88rem;font-weight:700;color:#93c5fd;">₱<?=number_format((float)$mr['appraisal_value'],2)?></div>
          </div>
          <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(147,197,253,.5);margin-bottom:2px;">Claim Term</div>
            <div style="font-size:.88rem;font-weight:700;color:#93c5fd;"><?=htmlspecialchars($mr['claim_term']??'—')?></div>
          </div>
          <?php if(!empty($mr['remarks'])): ?>
          <div style="flex-basis:100%;">
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(147,197,253,.5);margin-bottom:2px;">Remarks / Notes</div>
            <div style="font-size:.8rem;color:rgba(255,255,255,.5);"><?=htmlspecialchars($mr['remarks'])?></div>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Action buttons -->
        <?php if($mr['status'] === 'pending'): ?>
        <button onclick="openOfferModal(<?=(int)$mr['id']?>, '<?=htmlspecialchars(addslashes($mr['request_no']??''))?>', '<?=htmlspecialchars(addslashes($mr['mc_name']??$mr['customer_name']??'Customer'))?>')"
          style="background:linear-gradient(135deg,var(--t-primary,#2563eb),var(--t-secondary,#1d4ed8));color:#fff;border:none;border-radius:10px;padding:9px 18px;font-family:inherit;font-size:.82rem;font-weight:700;cursor:pointer;margin-right:8px;">
          <span class="material-symbols-outlined" style="font-size:15px;vertical-align:-3px;">local_offer</span> Send Loan Offer
        </button>
        <button onclick="openDeclineModal(<?=(int)$mr['id']?>, '<?=htmlspecialchars(addslashes($mr['request_no']??''))?>')"
          style="background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:9px 18px;font-family:inherit;font-size:.82rem;font-weight:700;cursor:pointer;">
          <span class="material-symbols-outlined" style="font-size:15px;vertical-align:-3px;">cancel</span> Reject
        </button>

        <?php elseif($mr['status'] === 'approved'): ?>
        <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:12px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;gap:10px;">
          <span class="material-symbols-outlined" style="color:#93c5fd;font-size:20px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">schedule</span>
          <span style="font-size:.82rem;color:#93c5fd;font-weight:600;">⏳ Offer sent — waiting for customer to accept or decline in the app.</span>
        </div>
        <button onclick="openDeclineModal(<?=(int)$mr['id']?>, '<?=htmlspecialchars(addslashes($mr['request_no']??''))?>')"
          style="background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.18);border-radius:8px;padding:7px 14px;font-family:inherit;font-size:.76rem;font-weight:600;cursor:pointer;">
          Cancel / Reject
        </button>

        <?php elseif($mr['status'] === 'customer_accepted'): ?>
        <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);border-radius:12px;padding:14px 18px;margin-bottom:14px;">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
            <span class="material-symbols-outlined" style="color:#6ee7b7;font-size:22px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">check_circle</span>
            <span style="font-size:.9rem;color:#6ee7b7;font-weight:700;">Customer accepted the offer!</span>
          </div>
          <p style="font-size:.78rem;color:rgba(110,231,183,.7);margin-left:32px;">The customer confirmed via the app. Issue the pawn ticket below to complete the transaction.</p>
        </div>
        <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center;">
          <form method="POST" onsubmit="return confirm('Issue pawn ticket for this request?');" style="display:inline;">
            <input type="hidden" name="action" value="finalize_pawn_request">
            <input type="hidden" name="request_id" value="<?=(int)$mr['id']?>">
            <button type="submit" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:10px;padding:10px 22px;font-family:inherit;font-size:.85rem;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(16,185,129,.35);">
              <span class="material-symbols-outlined" style="font-size:16px;vertical-align:-3px;">add_card</span> Issue Pawn Ticket
            </button>
          </form>
          <button onclick="openDeclineModal(<?=(int)$mr['id']?>, '<?=htmlspecialchars(addslashes($mr['request_no']??''))?>')"
            style="background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.18);border-radius:8px;padding:7px 14px;font-family:inherit;font-size:.76rem;font-weight:600;cursor:pointer;">
            Reject Anyway
          </button>
        </div>

        <?php elseif($mr['status'] === 'cancelled'): ?>
        <div style="font-size:.78rem;color:rgba(110,231,183,.6);">✅ Pawn ticket issued: <span style="font-family:monospace;font-weight:700;"><?=htmlspecialchars($mr['ticket_no']??'—')?></span></div>

        <?php elseif($mr['status'] === 'rejected'): ?>
        <div style="font-size:.78rem;color:rgba(252,165,165,.6);">❌ Rejected<?= !empty($mr['remarks']) ? ' — ' . htmlspecialchars($mr['remarks']) : '' ?></div>
        <?php endif; ?>

      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

  <?php endif;?>
  </div>
</div>

<!-- VOID REQUEST MODAL -->
<div class="modal-overlay" id="voidModal">
  <div class="modal" style="width:440px;">
    <div class="mhdr"><div class="mtitle">Submit Void Request</div><button class="mclose" onclick="document.getElementById('voidModal').classList.remove('open')"><span class="material-symbols-outlined">close</span></button></div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="void_request">
        <input type="hidden" name="ticket_no" id="void_ticket_no">
        <div class="fgroup"><label class="flabel">Ticket No.</label><input type="text" id="void_display" class="finput" readonly style="opacity:.7;"></div>
        <div class="fgroup"><label class="flabel">Reason *</label><textarea name="reason" class="finput" rows="3" placeholder="Enter reason..." required style="resize:vertical;"></textarea></div>
        <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.15);border-radius:10px;padding:11px 13px;font-size:.76rem;color:#fcd34d;margin-bottom:14px;">⚠️ Requires admin approval before the ticket is voided.</div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-xs" onclick="document.getElementById('voidModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-xs btn-danger-xs">Submit Void Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- LOGOUT CONFIRMATION MODAL -->
<div id="logoutModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:16px;">
  <div style="background:#1a1d26;border:1px solid rgba(255,255,255,.1);border-radius:20px;width:100%;max-width:380px;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.6);">
    <div style="background:linear-gradient(135deg,#7f1d1d,#991b1b);padding:24px 24px 20px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <span class="material-symbols-outlined" style="color:#fff;font-size:22px;">logout</span>
      </div>
      <div><div style="font-size:1.1rem;font-weight:700;color:#fff;">Sign Out</div><div style="font-size:.75rem;color:rgba(255,255,255,.6);margin-top:2px;">Confirm your action</div></div>
    </div>
    <div style="padding:22px 24px 24px;">
      <p style="font-size:.9rem;color:rgba(240,242,247,.65);line-height:1.65;margin-bottom:22px;">Are you sure you want to log out? Any unsaved changes may be lost.</p>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <a id="logoutConfirmBtn" href="#" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;background:#dc2626;color:#fff;font-weight:700;font-size:.9rem;border-radius:12px;text-decoration:none;">
          <span class="material-symbols-outlined" style="font-size:17px;">logout</span>Yes, Log Out
        </a>
        <button onclick="hideLogoutModal()" style="width:100%;padding:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:rgba(240,242,247,.6);font-weight:600;font-size:.9rem;border-radius:12px;cursor:pointer;font-family:inherit;">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!-- ── SEND LOAN OFFER MODAL ──────────────────────────────── -->
<div class="modal-overlay" id="offerModal">
  <div class="modal" style="width:520px;">
    <div class="mhdr">
      <div class="mtitle">Send Loan Offer</div>
      <button class="mclose" onclick="document.getElementById('offerModal').classList.remove('open')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="mbody">
      <div id="offerModalInfo" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:11px 14px;margin-bottom:16px;">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.3);margin-bottom:3px;">Customer</div>
        <div id="offerModalCustomer" style="font-size:.9rem;font-weight:700;color:#fff;"></div>
        <div id="offerModalRef" style="font-size:.72rem;color:rgba(255,255,255,.35);font-family:monospace;margin-top:2px;"></div>
      </div>
      <form method="POST" id="offerForm">
        <input type="hidden" name="action" value="send_offer">
        <input type="hidden" name="request_id" id="offer_request_id">
        <div class="form-grid2">
          <div class="fgroup">
            <label class="flabel">Appraisal Value (₱) *</label>
            <input type="number" name="appraisal" id="offer_appraisal" class="finput" placeholder="0.00" step="0.01" required oninput="calcOfferSummary()">
          </div>
          <div class="fgroup">
            <label class="flabel">Loan Offer Amount (₱) *</label>
            <input type="number" name="offer_amount" id="offer_amount" class="finput" placeholder="0.00" step="0.01" required oninput="calcOfferSummary()">
          </div>
          <div class="fgroup">
            <label class="flabel">Interest Rate</label>
            <select name="interest_rate" id="offer_irate" class="finput" onchange="calcOfferSummary()">
              <option value="0.02">2%</option>
              <option value="0.04">4%</option>
              <option value="0.10">10%</option>
              <option value="0.16">16%</option>
              <option value="0.22">22%</option>
            </select>
          </div>
          <div class="fgroup">
            <label class="flabel">Claim Term</label>
            <select name="claim_term" class="finput">
              <option value="1-15">1–15 days</option>
              <option value="16-30">16–30 days</option>
              <option value="2m">2 months</option>
              <option value="3m">3 months</option>
              <option value="4m">4 months</option>
            </select>
          </div>
          <div class="fgroup" style="grid-column:1/-1;">
            <label class="flabel">Staff Notes <span style="font-weight:400;color:rgba(255,255,255,.3)">(visible to customer)</span></label>
            <textarea name="staff_notes" class="finput" rows="2" placeholder="e.g. Item is in good condition, offer based on current gold rate…" style="resize:vertical;"></textarea>
          </div>
        </div>
        <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:10px;padding:12px 14px;font-size:.8rem;margin-bottom:14px;">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:rgba(110,231,183,.7);">Appraisal</span><span id="os_a" style="font-weight:700;color:#6ee7b7;">₱0.00</span></div>
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:rgba(110,231,183,.7);">Loan Offer</span><span id="os_l" style="font-weight:700;color:#6ee7b7;">₱0.00</span></div>
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:rgba(110,231,183,.7);">Interest</span><span id="os_i" style="font-weight:700;color:#6ee7b7;">₱0.00</span></div>
          <div style="display:flex;justify-content:space-between;border-top:1px solid rgba(16,185,129,.2);padding-top:7px;margin-top:5px;">
            <span style="color:#6ee7b7;font-weight:700;">Total to Redeem</span>
            <span id="os_t" style="font-weight:800;color:#6ee7b7;font-size:.92rem;">₱0.00</span>
          </div>
        </div>
        <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.18);border-radius:10px;padding:10px 13px;font-size:.76rem;color:#93c5fd;margin-bottom:14px;">
          📱 Once you send this offer, the customer will receive a notification in the app. They can then <strong>Accept</strong> or <strong>Decline</strong> the offer.
        </div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-xs" onclick="document.getElementById('offerModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-xs btn-primary-xs">Send Offer to Customer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── DECLINE REQUEST MODAL ─────────────────────────────── -->
<div class="modal-overlay" id="declineModal">
  <div class="modal" style="width:440px;">
    <div class="mhdr">
      <div class="mtitle">Decline Request</div>
      <button class="mclose" onclick="document.getElementById('declineModal').classList.remove('open')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="decline_request">
        <input type="hidden" name="request_id" id="decline_request_id">
        <div class="fgroup">
          <label class="flabel">Reference No.</label>
          <input type="text" id="decline_ref_display" class="finput" readonly style="opacity:.6;font-family:monospace;">
        </div>
        <div class="fgroup">
          <label class="flabel">Reason for Declining <span style="font-weight:400;color:rgba(255,255,255,.3)">(optional)</span></label>
          <textarea name="decline_reason" class="finput" rows="3" placeholder="e.g. Item condition does not meet requirements…" style="resize:vertical;"></textarea>
        </div>
        <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.18);border-radius:10px;padding:10px 13px;font-size:.76rem;color:#fca5a5;margin-bottom:14px;">
          ⚠️ The customer will be notified that their request has been declined.
        </div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-xs" onclick="document.getElementById('declineModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-xs btn-danger-xs">Decline Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- VOID REQUEST MODAL -->
<div class="modal-overlay" id="voidModal">
  <div class="modal" style="width:440px;">
    <div class="mhdr"><div class="mtitle">Submit Void Request</div><button class="mclose" onclick="document.getElementById('voidModal').classList.remove('open')"><span class="material-symbols-outlined">close</span></button></div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="void_request">
        <input type="hidden" name="ticket_no" id="void_ticket_no">
        <div class="fgroup"><label class="flabel">Ticket No.</label><input type="text" id="void_display" class="finput" readonly style="opacity:.7;"></div>
        <div class="fgroup"><label class="flabel">Reason *</label><textarea name="reason" class="finput" rows="3" placeholder="Enter reason..." required style="resize:vertical;"></textarea></div>
        <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.15);border-radius:10px;padding:11px 13px;font-size:.76rem;color:#fcd34d;margin-bottom:14px;">⚠️ Requires admin approval before the ticket is voided.</div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-xs" onclick="document.getElementById('voidModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-xs btn-danger-xs">Submit Void Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="mob-overlay" id="mobOverlay"></div>

<style>
@keyframes logoutIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.sb-logout{background:none;border:none;cursor:pointer;font-family:inherit;width:100%;text-align:left;}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

  // ── Sidebar ─────────────────────────────────────────────
  window.toggleSidebar = function () {
    document.querySelector('.sidebar').classList.toggle('mobile-open');
    document.getElementById('mobOverlay').classList.toggle('open');
  };
  var mo = document.getElementById('mobOverlay');
  if (mo) mo.addEventListener('click', function () { toggleSidebar(); });

  // ── Notification panel ───────────────────────────────────
  window.toggleNotifPanel = function (e) {
    e.stopPropagation();
    document.getElementById('notifPanel')?.classList.toggle('open');
  };
  document.addEventListener('click', function () {
    document.getElementById('notifPanel')?.classList.remove('open');
  });

  // ── Logout modal ─────────────────────────────────────────
  window.showLogoutModal = function (url) {
    document.getElementById('logoutConfirmBtn').href = url;
    document.getElementById('logoutModal').style.display = 'flex';
  };
  window.hideLogoutModal = function () {
    document.getElementById('logoutModal').style.display = 'none';
  };
  var lm = document.getElementById('logoutModal');
  if (lm) lm.addEventListener('click', function (e) { if (e.target === this) hideLogoutModal(); });

  // ── Offer modal ──────────────────────────────────────────
  window.openOfferModal = function (reqId, refNo, customerName) {
    document.getElementById('offer_request_id').value = reqId;
    document.getElementById('offerModalCustomer').textContent = customerName;
    document.getElementById('offerModalRef').textContent = refNo;
    document.getElementById('offer_appraisal').value = '';
    document.getElementById('offer_amount').value = '';
    calcOfferSummary();
    document.getElementById('offerModal').classList.add('open');
  };
  window.calcOfferSummary = function () {
    var a = parseFloat(document.getElementById('offer_appraisal')?.value) || 0;
    var l = parseFloat(document.getElementById('offer_amount')?.value)    || 0;
    var r = parseFloat(document.getElementById('offer_irate')?.value)     || 0.02;
    var i = l * r;
    document.getElementById('os_a').textContent = '₱' + a.toFixed(2);
    document.getElementById('os_l').textContent = '₱' + l.toFixed(2);
    document.getElementById('os_i').textContent = '₱' + i.toFixed(2);
    document.getElementById('os_t').textContent = '₱' + (l + i).toFixed(2);
  };
  var om = document.getElementById('offerModal');
  if (om) om.addEventListener('click', function (e) { if (e.target === this) this.classList.remove('open'); });

  // ── Decline modal ────────────────────────────────────────
  window.openDeclineModal = function (reqId, reqNo) {
    document.getElementById('decline_request_id').value = reqId;
    document.getElementById('decline_ref_display').value = reqNo;
    document.getElementById('declineModal').classList.add('open');
  };
  var dm = document.getElementById('declineModal');
  if (dm) dm.addEventListener('click', function (e) { if (e.target === this) this.classList.remove('open'); });

  // ── Void modal ───────────────────────────────────────────
  window.openVoid = function (tn) {
    document.getElementById('void_ticket_no').value = tn;
    document.getElementById('void_display').value = tn;
    document.getElementById('voidModal').classList.add('open');
  };
  var vm = document.getElementById('voidModal');
  if (vm) vm.addEventListener('click', function (e) { if (e.target === this) this.classList.remove('open'); });

  // ── Loan calculators (create_ticket page) ────────────────
  window.calcLoan = function () {
    var a = parseFloat(document.getElementById('appraisal')?.value) || 0;
    var lf = document.getElementById('loan_amt');
    if (lf && !lf.value) lf.value = (a * 0.70).toFixed(2);
    calcSummary();
  };
  window.calcSummary = function () {
    var a = parseFloat(document.getElementById('appraisal')?.value)  || 0;
    var l = parseFloat(document.getElementById('loan_amt')?.value)   || 0;
    var r = parseFloat(document.getElementById('irate')?.value)      || 0.02;
    var i = l * r;
    var da = document.getElementById('d_a'); if (da) da.textContent = '₱' + a.toFixed(2);
    var dl = document.getElementById('d_l'); if (dl) dl.textContent = '₱' + l.toFixed(2);
    var di = document.getElementById('d_i'); if (di) di.textContent = '₱' + i.toFixed(2);
    var dt = document.getElementById('d_t'); if (dt) dt.textContent = '₱' + (l + i).toFixed(2);
  };

  // ── Gold fields toggle ───────────────────────────────────
  window.toggleGoldFields = function () {
    var cat = document.getElementById('item_category')?.value || '';
    var isGold = (cat === 'Gold');
    var ww = document.getElementById('gold_weight_wrap');
    var kw = document.getElementById('gold_karat_wrap');
    if (ww) ww.style.display = isGold ? '' : 'none';
    if (kw) kw.style.display = isGold ? '' : 'none';
    if (!isGold) {
      var iw = document.getElementById('item_weight'); if (iw) iw.value = '';
      var ik = document.getElementById('item_karat');  if (ik) ik.value = '';
    }
  };

  // ── Customer search / select ─────────────────────────────
  window.searchCustomers = function (val) {
    var dropdown = document.getElementById('cust_dropdown');
    if (!dropdown) return;
    var opts = document.querySelectorAll('.cust-opt');
    if (val.length < 1) { dropdown.style.display = 'none'; return; }
    var q = val.toLowerCase(), any = false;
    opts.forEach(function (o) {
      var name = o.dataset.name.toLowerCase();
      var contact = o.dataset.contact;
      var words = name.split(/[\s,]+/);
      var match = words.some(function (w) { return w.startsWith(q); }) || contact.includes(q);
      o.style.display = match ? 'block' : 'none';
      if (match) any = true;
    });
    dropdown.style.display = any ? 'block' : 'none';
    var sci = document.getElementById('selected_customer_id');
    if (sci) sci.value = '';
  };
  window.selectCustomer = function (el) {
    var set = function (id, val) { var e = document.getElementById(id); if (e) e.value = val; };
    set('cust_name_input', el.dataset.name);
    set('cust_contact',    el.dataset.contact);
    set('cust_email',      el.dataset.email);
    set('cust_birthdate',  el.dataset.birthdate);
    set('cust_address',    el.dataset.address);
    set('selected_customer_id', el.dataset.id);
    var idSel = document.getElementById('cust_id_type');
    if (idSel) {
      for (var o of idSel.options) {
        if (o.value === el.dataset.id_type || o.text === el.dataset.id_type) { o.selected = true; break; }
      }
    }
    set('cust_id_number', el.dataset.id_number);
    var dd = document.getElementById('cust_dropdown');
    if (dd) dd.style.display = 'none';
  };

  // ── Photo previews ───────────────────────────────────────
  window.previewItemPhoto = function (input) {
    var preview = document.getElementById('item_photo_preview');
    if (!preview || !input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function (e) { preview.src = e.target.result; preview.style.display = 'block'; };
    reader.readAsDataURL(input.files[0]);
  };
  window.previewId = function (input) {
    var preview = document.getElementById('id_preview');
    if (!preview || !input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function (e) { preview.src = e.target.result; preview.style.display = 'block'; };
    reader.readAsDataURL(input.files[0]);
  };

});
</script>
</body>
</html>