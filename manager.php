<?php
require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('manager');
require 'db.php';
require 'theme_helper.php';

require_once __DIR__ . '/vendor/autoload.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;

function validate_shop_image(array $file): array {
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (empty($file['name']) || empty($file['tmp_name'])) {
        throw new RuntimeException('Item photo is required.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid uploaded file.');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Invalid photo. Use JPG/PNG/WEBP only.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Invalid photo. Max file size is 5MB.');
    }

    return [$ext, $file['type'] ?? 'application/octet-stream'];
}

function blob_client(): BlobRestProxy {
    $connectionString = getenv('AZURE_STORAGE_CONNECTION_STRING');
    if (!$connectionString) {
        throw new RuntimeException('Azure storage is not configured.');
    }

    return BlobRestProxy::createBlobService($connectionString);
}

function blob_container_name(): string {
    return getenv('AZURE_BLOB_CONTAINER') ?: 'item-images';
}

function blob_base_url(): string {
    return rtrim(getenv('AZURE_BLOB_BASE_URL') ?: 'https://pawnhubstorage.blob.core.windows.net/item-images', '/');
}

function upload_shop_photo_to_blob(array $file, int $tenantId, int $itemId): string {
    [$ext, $mime] = validate_shop_image($file);

    $container = blob_container_name();
    $baseUrl = blob_base_url();
    $client = blob_client();

    $blobName = "tenants/{$tenantId}/items/{$itemId}/cover.{$ext}";

    $content = fopen($file['tmp_name'], 'r');
    if ($content === false) {
        throw new RuntimeException('Unable to read uploaded file.');
    }

    $options = new CreateBlockBlobOptions();
    $options->setContentType($mime);

    $client->createBlockBlob($container, $blobName, $content, $options);

    return "{$baseUrl}/{$blobName}";
}

function write_audit(PDO $pdo, $actor_id, $actor_username, $actor_role, string $action, string $entity_type = '', string $entity_id = '', string $message = '', $tenant_id = null): void {
    try {
        $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$tenant_id,$actor_id,$actor_username,$actor_role,$action,$entity_type,$entity_id,$message,$_SERVER['REMOTE_ADDR']??'::1']);
    } catch (PDOException $e) {}
}

function redirectToTenantLogin(): void {
    $slug = $_SESSION['user']['tenant_slug'] ?? '';
    header('Location: ' . ($slug ? '/' . rawurlencode($slug) . '?login=1' : '/login.php'));
    exit;
}
if (empty($_SESSION['user'])) { redirectToTenantLogin(); }
$u = $_SESSION['user'];
if ($u['role'] !== 'manager') { redirectToTenantLogin(); }

$tid         = $u['tenant_id'];
$active_page = $_GET['page'] ?? 'dashboard';
$success_msg = '';
$error_msg   = '';

// ── Plan feature check for data export ───────────────────────
$plan_chk = $pdo->prepare("SELECT plan FROM tenants WHERE id=? LIMIT 1");
$plan_chk->execute([$tid]);
$tenant_plan_mgr = strtolower($plan_chk->fetchColumn() ?? 'starter');
$mgr_can_export  = ($tenant_plan_mgr === 'enterprise');

// ── Block if tenant is deactivated ────────────────────────────
try {
    $chk = $pdo->prepare("SELECT status FROM tenants WHERE id=? LIMIT 1");
    $chk->execute([$tid]);
    $t_status = $chk->fetchColumn();
    if ($t_status === 'inactive') {
        session_unset(); session_destroy();
        redirectToTenantLogin();
    }
} catch (Throwable $e) {}

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

// ── POST ACTIONS ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Invite Staff or Cashier only (Manager CANNOT invite another Manager)
    if ($_POST['action'] === 'invite_staff') {
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name']  ?? '');
        $role  = in_array($_POST['role'], ['staff','cashier']) ? $_POST['role'] : 'staff';

        if (!$email || !$name) {
            $error_msg = 'Please fill in name and email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Invalid email address.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND tenant_id=?");
            $chk->execute([$email, $tid]);
            if ($chk->fetch()) {
                $error_msg = 'This email already has an account in this branch.';
            } else {
                $pdo->prepare("UPDATE tenant_invitations SET status='expired' WHERE email=? AND tenant_id=? AND status='pending' AND role IN ('staff','cashier')")
                    ->execute([$email, $tid]);

                $token      = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

                $pdo->prepare("INSERT INTO tenant_invitations (tenant_id, email, owner_name, role, token, status, expires_at, created_by) VALUES (?,?,?,?,?,'pending',?,?)")
                    ->execute([$tid, $email, $name, $role, $token, $expires_at, $u['id']]);

                try {
                    require_once __DIR__ . '/mailer.php';
                    $biz_name_for_mail = $tenant['business_name'] ?? 'PawnHub';
                    sendStaffInvitation($email, $name, $biz_name_for_mail, $role, $token);
                    $success_msg = ucfirst($role) . " invitation sent to {$email}!";
                    write_audit($pdo, $u['id'], $u['username'], 'manager', 'STAFF_INVITE', 'user', '', "Manager invited $role: $name ($email)", $tid);
                } catch (Throwable $e) {
                    error_log('Invite email failed: ' . $e->getMessage());
                    $error_msg = 'Invitation created but email failed. Error: ' . htmlspecialchars($e->getMessage());
                }
                $active_page = 'team';
            }
        }
    }

    // Suspend / Unsuspend — Manager can only affect staff/cashier
    if ($_POST['action'] === 'toggle_user') {
        $uid  = intval($_POST['user_id']);
        $susp = intval($_POST['is_suspended']);

        $target = $pdo->prepare("SELECT * FROM users WHERE id=? AND tenant_id=? AND role IN ('staff','cashier') LIMIT 1");
        $target->execute([$uid, $tid]);
        $target = $target->fetch();

        if ($target) {
            if ($susp) {
                $pdo->prepare("UPDATE users SET is_suspended=0,suspended_at=NULL,suspension_reason=NULL WHERE id=?")->execute([$uid]);
                $success_msg = 'User unsuspended.';
                write_audit($pdo, $u['id'], $u['username'], 'manager', 'USER_UNSUSPEND', 'user', (string)$uid, "Unsuspended {$target['role']}: {$target['fullname']}", $tid);
            } else {
                $pdo->prepare("UPDATE users SET is_suspended=1,suspended_at=NOW(),suspension_reason='Suspended by manager.' WHERE id=?")->execute([$uid]);
                $success_msg = 'User suspended.';
                write_audit($pdo, $u['id'], $u['username'], 'manager', 'USER_SUSPEND', 'user', (string)$uid, "Suspended {$target['role']}: {$target['fullname']}", $tid);
            }
        } else {
            $error_msg = 'You do not have permission to modify this user.';
        }
        $active_page = 'team';
    }

    // Approve void
    if ($_POST['action'] === 'approve_void') {
        $vrid      = intval($_POST['void_id']);
        $ticket_no = trim($_POST['ticket_no']);
        $pdo->prepare("UPDATE pawn_void_requests SET status='approved',decided_by=?,decided_at=NOW() WHERE id=? AND tenant_id=?")->execute([$u['id'],$vrid,$tid]);
        $pdo->prepare("UPDATE pawn_transactions SET status='Voided' WHERE ticket_no=? AND tenant_id=?")->execute([$ticket_no,$tid]);
        $pdo->prepare("UPDATE item_inventory SET status='voided' WHERE ticket_no=? AND tenant_id=?")->execute([$ticket_no,$tid]);
        write_audit($pdo, $u['id'], $u['username'], 'manager', 'PAWN_VOID_APPROVE', 'pawn_transaction', $ticket_no, "Void approved: $ticket_no", $tid);

        // ── Notify mobile app ─────────────────────────────────
        write_pawn_update($pdo, $tid, $ticket_no, 'VOIDED',
            "Your pawn ticket #$ticket_no has been voided/cancelled. Please contact the branch for more information.");
        $success_msg = 'Void approved.';
        $active_page = 'void_requests';
    }

    if ($_POST['action'] === 'reject_void') {
        $vrid = intval($_POST['void_id']);
        $pdo->prepare("UPDATE pawn_void_requests SET status='rejected',decided_by=?,decided_at=NOW() WHERE id=? AND tenant_id=?")->execute([$u['id'],$vrid,$tid]);
        $success_msg = 'Void rejected.';
        $active_page = 'void_requests';
    }

    // ── SHOP: Add/Edit Category ───────────────────────────────
    if ($_POST['action'] === 'save_category') {
        $cat_id   = intval($_POST['cat_id'] ?? 0);
        $cat_name = trim($_POST['cat_name'] ?? '');
        $cat_icon = trim($_POST['cat_icon'] ?? 'category');
        if ($cat_name !== '') {
            if ($cat_id > 0) {
                $pdo->prepare("UPDATE shop_categories SET name=?,icon=?,updated_at=NOW() WHERE id=? AND tenant_id=?")
                    ->execute([$cat_name, $cat_icon, $cat_id, $tid]);
                $success_msg = 'Category updated.';
            } else {
                $pdo->prepare("INSERT INTO shop_categories (tenant_id,name,icon,is_active,sort_order,created_at) VALUES (?,?,?,1,0,NOW())")
                    ->execute([$tid, $cat_name, $cat_icon]);
                $success_msg = 'Category added.';
            }
        }
        $active_page = 'shop_categories';
    }

    if ($_POST['action'] === 'delete_category') {
        $cat_id = intval($_POST['cat_id'] ?? 0);
        $pdo->prepare("DELETE FROM shop_categories WHERE id=? AND tenant_id=?")->execute([$cat_id, $tid]);
        $success_msg = 'Category deleted.';
        $active_page = 'shop_categories';
    }

    // ── SHOP: Toggle item visibility / update shop fields ─────
    if ($_POST['action'] === 'update_shop_item') {
    $item_id        = intval($_POST['item_id'] ?? 0);
    $is_visible     = intval($_POST['is_shop_visible'] ?? 0);
    $is_featured    = intval($_POST['is_featured'] ?? 0);
    $display_price  = floatval($_POST['display_price'] ?? 0);
    $category_id    = intval($_POST['category_id'] ?? 0) ?: null;
    $stock_qty      = intval($_POST['stock_qty'] ?? 1);

    try {
        $photo_path = null;

        if (!empty($_FILES['item_photo']['name'])) {
            $photo_path = upload_shop_photo_to_blob($_FILES['item_photo'], $tid, $item_id);
        }

        if ($photo_path) {
            $pdo->prepare("
                UPDATE item_inventory
                SET is_shop_visible=?,
                    is_featured=?,
                    display_price=?,
                    category_id=?,
                    stock_qty=?,
                    item_photo_path=?,
                    updated_at=NOW()
                WHERE id=? AND tenant_id=?
            ")->execute([
                $is_visible, $is_featured, $display_price, $category_id,
                $stock_qty, $photo_path, $item_id, $tid
            ]);
        } else {
            $pdo->prepare("
                UPDATE item_inventory
                SET is_shop_visible=?,
                    is_featured=?,
                    display_price=?,
                    category_id=?,
                    stock_qty=?,
                    updated_at=NOW()
                WHERE id=? AND tenant_id=?
            ")->execute([
                $is_visible, $is_featured, $display_price, $category_id,
                $stock_qty, $item_id, $tid
            ]);
        }

        write_audit(
            $pdo,
            $u['id'],
            $u['username'],
            'manager',
            'SHOP_ITEM_UPDATE',
            'item_inventory',
            (string)$item_id,
            "Shop item updated: visibility=$is_visible",
            $tid
        );

        $success_msg = 'Shop item updated.';
        $active_page = 'shop_items';
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
        $active_page = 'shop_items';
    }
}


    // ── SHOP: Add New Item directly ───────────────────────────
    if ($_POST['action'] === 'add_shop_item') {
    $item_name     = trim($_POST['item_name'] ?? '');
    $item_category = trim($_POST['item_category'] ?? '');
    $category_id   = intval($_POST['category_id'] ?? 0) ?: null;
    $display_price = floatval($_POST['display_price'] ?? 0);
    $condition     = trim($_POST['condition_notes'] ?? '');
    $stock_qty     = intval($_POST['stock_qty'] ?? 1);
    $is_featured   = intval($_POST['is_featured'] ?? 0);

    if ($item_name === '' || $display_price <= 0) {
        $error_msg = 'Item name and display price are required.';
        $active_page = 'add_shop_item';
    } else {
        try {
            if (empty($_FILES['item_photo']['name'])) {
                throw new RuntimeException('Item photo is required.');
            }

            // Validate image first
            validate_shop_image($_FILES['item_photo']);

            $pdo->beginTransaction();

            // Auto-create / resolve category
            if ($item_category !== '' && !$category_id) {
                $chk_cat = $pdo->prepare("SELECT id FROM shop_categories WHERE tenant_id=? AND LOWER(name)=LOWER(?) LIMIT 1");
                $chk_cat->execute([$tid, $item_category]);
                $existing_cat = $chk_cat->fetchColumn();

                if ($existing_cat) {
                    $category_id = (int)$existing_cat;
                } else {
                    $cat_lower = strtolower($item_category);
                    $auto_icon = match(true) {
                        str_contains($cat_lower,'phone') || str_contains($cat_lower,'gadget') || str_contains($cat_lower,'mobile') => 'smartphone',
                        str_contains($cat_lower,'laptop') || str_contains($cat_lower,'computer') => 'laptop',
                        str_contains($cat_lower,'jewel') || str_contains($cat_lower,'ring') || str_contains($cat_lower,'necklace') => 'diamond',
                        str_contains($cat_lower,'gold') || str_contains($cat_lower,'silver') => 'diamond',
                        str_contains($cat_lower,'watch') => 'watch',
                        str_contains($cat_lower,'camera') => 'photo_camera',
                        str_contains($cat_lower,'bag') => 'shopping_bag',
                        str_contains($cat_lower,'appliance') || str_contains($cat_lower,'tv') => 'tv',
                        default => 'category',
                    };

                    $pdo->prepare("
                        INSERT INTO shop_categories (tenant_id,name,icon,is_active,sort_order,created_at)
                        VALUES (?,?,?,1,0,NOW())
                    ")->execute([$tid, $item_category, $auto_icon]);

                    $category_id = (int)$pdo->lastInsertId();
                }
            }

            // Insert item first with null photo
            $pdo->prepare("
                INSERT INTO item_inventory
                    (tenant_id, item_name, item_category, category_id, condition_notes,
                     display_price, appraisal_value, stock_qty, item_photo_path,
                     is_shop_visible, is_featured, status, received_at)
                VALUES (?,?,?,?,?,?,?,?,?,1,?,?,NOW())
            ")->execute([
                $tid, $item_name, $item_category, $category_id, $condition,
                $display_price, $display_price, $stock_qty, null,
                $is_featured, 'available'
            ]);

            $new_id = (int)$pdo->lastInsertId();

            // Upload to blob using item id
            $photo_url = upload_shop_photo_to_blob($_FILES['item_photo'], $tid, $new_id);

            // Save final blob URL
            $pdo->prepare("
                UPDATE item_inventory
                SET item_photo_path = ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ")->execute([$photo_url, $new_id, $tid]);

            $pdo->commit();

            write_audit(
                $pdo,
                $u['id'],
                $u['username'],
                'manager',
                'SHOP_ITEM_ADD',
                'item_inventory',
                (string)$new_id,
                "Added shop item: $item_name",
                $tid
            );

            $success_msg = "Item $item_name added to shop!";
            $active_page = 'shop_items';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_msg = $e->getMessage();
            $active_page = 'add_shop_item';
        }
    }
}

    // Quick toggle visibility
    if ($_POST['action'] === 'toggle_shop_visible') {
        $item_id    = intval($_POST['item_id'] ?? 0);
        $new_visible = intval($_POST['new_visible'] ?? 0);
        $pdo->prepare("UPDATE item_inventory SET is_shop_visible=?,updated_at=NOW() WHERE id=? AND tenant_id=?")
            ->execute([$new_visible, $item_id, $tid]);
        $success_msg = $new_visible ? 'Item is now visible in shop.' : 'Item hidden from shop.';
        $active_page = 'shop_items';
    }

    // ── PROMOS & ANNOUNCEMENTS: Save (add or edit) ────────────
    if ($_POST['action'] === 'save_promo') {
        $promo_id     = intval($_POST['promo_id'] ?? 0);
        $title        = trim($_POST['promo_title'] ?? '');
        $body         = trim($_POST['promo_body']  ?? '');
        $type         = in_array($_POST['promo_type'] ?? '', ['announcement','promo','sale','warning'])
                          ? $_POST['promo_type'] : 'announcement';
        $is_pinned    = intval($_POST['is_pinned']    ?? 0);
        $is_active    = intval($_POST['is_active']    ?? 1);
        $start_date   = trim($_POST['start_date']   ?? '') ?: null;
        $end_date     = trim($_POST['end_date']     ?? '') ?: null;
        $image_url    = trim($_POST['image_url']    ?? '') ?: null;
        $linked_item_id = intval($_POST['linked_item_id'] ?? 0) ?: null;
        $discount_pct   = floatval($_POST['discount_pct'] ?? 0);

        if ($title === '') {
            $error_msg = 'Title is required.';
        } else {
            // If a discount % is set and an item is linked, apply discounted price
            $original_price = null;
            if ($linked_item_id && $discount_pct > 0 && $discount_pct <= 100) {
                $item_row = $pdo->prepare("SELECT display_price FROM item_inventory WHERE id=? AND tenant_id=? LIMIT 1");
                $item_row->execute([$linked_item_id, $tid]);
                $item_row = $item_row->fetch();
                if ($item_row) {
                    $original_price   = (float)$item_row['display_price'];
                    $discounted_price = round($original_price * (1 - $discount_pct / 100), 2);
                    $pdo->prepare("UPDATE item_inventory SET display_price=?, promo_original_price=?, updated_at=NOW() WHERE id=? AND tenant_id=?")
                        ->execute([$discounted_price, $original_price, $linked_item_id, $tid]);
                }
            }
            // If previously had a linked item and discount, restore price when item is unlinked or discount removed
            if ($promo_id > 0) {
                $old_promo = $pdo->prepare("SELECT linked_item_id, discount_pct FROM tenant_promos WHERE id=? AND tenant_id=? LIMIT 1");
                $old_promo->execute([$promo_id, $tid]);
                $old_promo = $old_promo->fetch();
                if ($old_promo && $old_promo['linked_item_id'] && (!$linked_item_id || $linked_item_id !== (int)$old_promo['linked_item_id'] || $discount_pct == 0)) {
                    // Restore original price on the old linked item
                    $pdo->prepare("UPDATE item_inventory SET display_price=COALESCE(promo_original_price, display_price), promo_original_price=NULL, updated_at=NOW() WHERE id=? AND tenant_id=? AND promo_original_price IS NOT NULL")
                        ->execute([$old_promo['linked_item_id'], $tid]);
                }
            }

            if ($promo_id > 0) {
                $pdo->prepare("UPDATE tenant_promos SET title=?,body=?,type=?,is_pinned=?,is_active=?,start_date=?,end_date=?,image_url=?,linked_item_id=?,discount_pct=?,original_price=?,updated_at=NOW() WHERE id=? AND tenant_id=?")
                    ->execute([$title,$body,$type,$is_pinned,$is_active,$start_date,$end_date,$image_url,$linked_item_id,$discount_pct,$original_price,$promo_id,$tid]);
                $success_msg = 'Promo/Announcement updated.';
                write_audit($pdo,$u['id'],$u['username'],'manager','PROMO_UPDATE','tenant_promos',(string)$promo_id,"Updated: $title",$tid);
            } else {
                $pdo->prepare("INSERT INTO tenant_promos (tenant_id,title,body,type,is_pinned,is_active,start_date,end_date,image_url,linked_item_id,discount_pct,original_price,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                    ->execute([$tid,$title,$body,$type,$is_pinned,$is_active,$start_date,$end_date,$image_url,$linked_item_id,$discount_pct,$original_price]);
                $success_msg = 'Promo/Announcement posted!';
                write_audit($pdo,$u['id'],$u['username'],'manager','PROMO_ADD','tenant_promos',(string)$pdo->lastInsertId(),"Added: $title",$tid);
            }
        }
        $active_page = 'promos';
    }

    // ── PROMOS: Delete ────────────────────────────────────────
    if ($_POST['action'] === 'delete_promo') {
        $promo_id = intval($_POST['promo_id'] ?? 0);
        // Restore original price if item was linked with a discount
        try {
            $dp = $pdo->prepare("SELECT linked_item_id FROM tenant_promos WHERE id=? AND tenant_id=? LIMIT 1");
            $dp->execute([$promo_id,$tid]);
            $dp = $dp->fetch();
            if ($dp && $dp['linked_item_id']) {
                $pdo->prepare("UPDATE item_inventory SET display_price=COALESCE(promo_original_price, display_price), promo_original_price=NULL, updated_at=NOW() WHERE id=? AND tenant_id=? AND promo_original_price IS NOT NULL")
                    ->execute([$dp['linked_item_id'], $tid]);
            }
        } catch (Throwable $e) {}
        $pdo->prepare("DELETE FROM tenant_promos WHERE id=? AND tenant_id=?")->execute([$promo_id,$tid]);
        $success_msg = 'Deleted.';
        write_audit($pdo,$u['id'],$u['username'],'manager','PROMO_DELETE','tenant_promos',(string)$promo_id,"Promo deleted",$tid);
        $active_page = 'promos';
    }

    // ── PROMOS: Toggle active ─────────────────────────────────
    if ($_POST['action'] === 'toggle_promo') {
        $promo_id  = intval($_POST['promo_id'] ?? 0);
        $new_state = intval($_POST['new_state'] ?? 0);
        $pdo->prepare("UPDATE tenant_promos SET is_active=?,updated_at=NOW() WHERE id=? AND tenant_id=?")->execute([$new_state,$promo_id,$tid]);
        $success_msg = $new_state ? 'Promo activated.' : 'Promo deactivated.';
        $active_page = 'promos';
    }

}

// ── Fetch data ─────────────────────────────────────────────────
$today = date('Y-m-d');

$my_team     = $pdo->prepare("SELECT * FROM users WHERE tenant_id=? AND role IN ('staff','cashier') ORDER BY role,fullname");
$my_team->execute([$tid]); $my_team = $my_team->fetchAll();

$all_tickets = $pdo->prepare("SELECT * FROM pawn_transactions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 100");
$all_tickets->execute([$tid]); $all_tickets = $all_tickets->fetchAll();

$customers   = $pdo->prepare("SELECT * FROM customers WHERE tenant_id=? ORDER BY full_name");
$customers->execute([$tid]); $customers = $customers->fetchAll();

$void_reqs   = $pdo->prepare("SELECT v.*,u.fullname as req_name FROM pawn_void_requests v JOIN users u ON v.requested_by=u.id WHERE v.tenant_id=? ORDER BY v.requested_at DESC");
$void_reqs->execute([$tid]); $void_reqs = $void_reqs->fetchAll();

$audit_logs  = $pdo->prepare("SELECT * FROM audit_logs WHERE tenant_id=? AND actor_role IN ('manager','staff','cashier') ORDER BY created_at DESC LIMIT 200");
$audit_logs->execute([$tid]); $audit_logs = $audit_logs->fetchAll();

$tickets_today  = count(array_filter($all_tickets, fn($t)=>substr($t['created_at'],0,10)===$today));
$active_tickets = count(array_filter($all_tickets, fn($t)=>$t['status']==='Stored'));
$pending_voids  = array_filter($void_reqs, fn($v)=>$v['status']==='pending');

$shop_items      = $pdo->prepare("SELECT i.*, c.name AS cat_name FROM item_inventory i LEFT JOIN shop_categories c ON c.id=i.category_id WHERE i.tenant_id=? ORDER BY i.is_shop_visible DESC, i.is_featured DESC, i.id DESC LIMIT 200");
$shop_items->execute([$tid]); $shop_items = $shop_items->fetchAll();

$shop_categories_list = $pdo->prepare("SELECT * FROM shop_categories WHERE tenant_id=? ORDER BY sort_order ASC, name ASC");
$shop_categories_list->execute([$tid]); $shop_categories_list = $shop_categories_list->fetchAll();

$shop_visible_count  = count(array_filter($shop_items, fn($i)=>(int)$i['is_shop_visible']===1));
$shop_featured_count = count(array_filter($shop_items, fn($i)=>(int)$i['is_featured']===1));

// Promos & Announcements
$mgr_promos = [];
try {
    $ps = $pdo->prepare("
        SELECT p.*, i.item_name AS linked_item_name, i.item_photo_path AS linked_item_photo,
               i.display_price AS linked_item_price, i.promo_original_price AS linked_item_orig_price
        FROM tenant_promos p
        LEFT JOIN item_inventory i ON i.id = p.linked_item_id AND i.tenant_id = p.tenant_id
        WHERE p.tenant_id=?
        ORDER BY p.is_pinned DESC, p.created_at DESC
        LIMIT 100
    ");
    $ps->execute([$tid]); $mgr_promos = $ps->fetchAll();
} catch (Throwable $e) { $mgr_promos = []; }
$active_promos_count = count(array_filter($mgr_promos, fn($p)=>(int)$p['is_active']===1));

$business_name = $tenant['business_name'] ?? 'My Branch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?=htmlspecialchars($business_name)?> — Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<?= renderThemeCSS($theme) ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --sw:268px;
  --g:var(--t-primary,#059669); --gd:var(--t-primary-d,#047857);
  --bg:#070d0a; --text:#f1f5f9;
  --text-m:rgba(255,255,255,.65); --text-dim:rgba(255,255,255,.35);
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow:hidden;}
.bg-scene{position:fixed;inset:0;z-index:0;}
.bg-scene img{width:100%;height:100%;object-fit:cover;opacity:.09;filter:brightness(.5) saturate(.5);}
.bg-overlay{position:absolute;inset:0;background:linear-gradient(135deg,rgba(6,78,59,.18) 0%,rgba(7,13,10,.97) 45%);}

/* SIDEBAR */
.sidebar{width:var(--sw);min-height:100vh;background:rgba(4,14,9,.9);backdrop-filter:blur(40px);border-right:1px solid rgba(var(--t-primary,5,150,105),.1);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;}
.sb-brand{padding:22px 18px 14px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:11px;}
.sb-logo{width:38px;height:38px;background:linear-gradient(135deg,var(--t-primary,#059669),var(--t-secondary,#064e3b));border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,.4);}
.sb-logo img{width:100%;height:100%;object-fit:cover;}
.sb-logo svg{width:19px;height:19px;}
.sb-name{font-size:.92rem;font-weight:800;color:#fff;letter-spacing:-.02em;}
.sb-subtitle{font-size:.58rem;color:rgba(255,255,255,.3);font-weight:600;letter-spacing:.1em;text-transform:uppercase;margin-top:1px;}

.sb-role-card{margin:10px 10px 0;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px 14px;}
.sb-role-label{font-size:.58rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.3);margin-bottom:4px;}
.sb-role-name{font-size:.85rem;font-weight:700;color:#fff;}
.sb-role-badge{display:inline-flex;align-items:center;gap:4px;font-size:.66rem;font-weight:700;background:rgba(255,255,255,.08);color:rgba(255,255,255,.6);padding:2px 8px;border-radius:100px;margin-top:5px;}

.sb-user{padding:10px 18px;border-bottom:1px solid rgba(255,255,255,.05);display:flex;align-items:center;gap:9px;margin-top:8px;}
.sb-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--t-primary,#059669),var(--t-secondary,#064e3b));display:flex;align-items:center;justify-content:center;font-size:.74rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.79rem;font-weight:700;color:#fff;}
.sb-urole{font-size:.62rem;color:rgba(255,255,255,.3);}
.sb-status{display:inline-flex;align-items:center;gap:3px;font-size:.6rem;font-weight:700;background:rgba(16,185,129,.18);color:#6ee7b7;padding:2px 7px;border-radius:100px;margin-top:3px;}

.sb-nav{flex:1;padding:10px 0;}
.sb-section{font-size:.58rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.2);padding:12px 16px 4px;}
.sb-item{display:flex;align-items:center;gap:10px;padding:9px 14px;margin:1px 8px;border-radius:10px;color:rgba(255,255,255,.4);font-size:.82rem;font-weight:500;text-decoration:none;transition:all .18s;}
.sb-item:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.9);}
.sb-item.active{background:color-mix(in srgb,var(--t-primary,#059669) 20%,transparent);color:var(--t-accent,#6ee7b7);font-weight:600;}
.sb-item .material-symbols-outlined{font-size:18px;flex-shrink:0;}
.sb-pill{margin-left:auto;background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;padding:1px 7px;border-radius:100px;}
.sb-footer{padding:12px 14px;border-top:1px solid rgba(255,255,255,.05);}
.sb-logout{display:flex;align-items:center;gap:9px;font-size:.8rem;color:rgba(255,255,255,.3);text-decoration:none;padding:9px 10px;border-radius:10px;transition:all .18s;}
.sb-logout:hover{color:#f87171;background:rgba(239,68,68,.1);}
.sb-logout .material-symbols-outlined{font-size:18px;}

/* MAIN */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;position:relative;z-index:10;height:100vh;overflow-y:auto;}
.topbar{height:60px;padding:0 26px;background:rgba(4,14,9,.8);backdrop-filter:blur(20px);border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:.97rem;font-weight:700;color:#fff;}
.mgr-chip{font-size:.68rem;font-weight:700;background:color-mix(in srgb,var(--t-primary,#059669) 15%,transparent);color:var(--t-accent,#6ee7b7);padding:3px 11px;border-radius:100px;border:1px solid color-mix(in srgb,var(--t-primary,#059669) 30%,transparent);}
.topbar-icon{width:34px;height:34px;border-radius:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,.5);transition:all .15s;position:relative;}
.topbar-icon:hover{background:rgba(255,255,255,.1);color:#fff;}
.topbar-icon .material-symbols-outlined{font-size:17px;}
.notif-badge{position:absolute;top:4px;right:4px;min-width:16px;height:16px;background:#ef4444;border-radius:100px;border:2px solid rgba(4,14,9,1);font-size:.6rem;font-weight:800;color:#fff;display:flex;align-items:center;justify-content:center;padding:0 3px;line-height:1;}
@keyframes notifPulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.5);}50%{box-shadow:0 0 0 4px rgba(239,68,68,0);}}
.notif-panel{position:absolute;top:calc(100% + 10px);right:0;width:320px;background:#0e1117;border:1px solid rgba(255,255,255,.1);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.7);z-index:200;overflow:hidden;display:none;animation:panelIn .18s ease both;}
.notif-panel.open{display:block;}
@keyframes panelIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.notif-panel-head{padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;justify-content:space-between;}
.notif-panel-title{font-size:.83rem;font-weight:700;color:#fff;}
.notif-panel-clear{font-size:.7rem;color:rgba(255,255,255,.35);cursor:pointer;background:none;border:none;font-family:inherit;}
.notif-list{max-height:300px;overflow-y:auto;}
.notif-item{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);text-decoration:none;transition:background .15s;}
.notif-item:hover{background:rgba(255,255,255,.03);}
.notif-item:last-child{border-bottom:none;}
.notif-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.notif-icon .material-symbols-outlined{font-size:14px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;}
.notif-text-title{font-size:.76rem;font-weight:600;color:#fff;line-height:1.3;margin-bottom:2px;}
.notif-text-sub{font-size:.67rem;color:rgba(255,255,255,.4);line-height:1.4;}
.notif-empty{padding:24px 14px;text-align:center;color:rgba(255,255,255,.25);font-size:.78rem;}
.content{padding:22px 26px;flex:1;}

.card{background:rgba(255,255,255,.04);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.07);border-radius:16px;padding:18px 20px;}
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:20px;}
.stat-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:16px 18px;}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;}
.stat-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;}
.stat-icon .material-symbols-outlined{font-size:18px;}
.stat-value{font-size:1.5rem;font-weight:800;color:#fff;letter-spacing:-.03em;}
.stat-label{font-size:.68rem;color:rgba(255,255,255,.35);margin-top:3px;}

.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.page-hdr h2{font-size:1.1rem;font-weight:800;color:#fff;}
.page-hdr p{font-size:.78rem;color:rgba(255,255,255,.35);margin-top:2px;}

table{width:100%;border-collapse:collapse;}
th{font-size:.63rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(255,255,255,.3);padding:8px 12px;text-align:left;border-bottom:1px solid rgba(255,255,255,.06);}
td{padding:11px 12px;font-size:.81rem;color:rgba(255,255,255,.7);border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.02);}
.ticket-tag{font-family:monospace;font-size:.76rem;color:var(--t-accent,#34d399);font-weight:700;}
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.63rem;font-weight:700;padding:3px 9px;border-radius:100px;}
.b-blue{background:rgba(59,130,246,.2);color:#93c5fd;}
.b-green{background:rgba(16,185,129,.2);color:#6ee7b7;}
.b-red{background:rgba(239,68,68,.2);color:#fca5a5;}
.b-yellow{background:rgba(245,158,11,.2);color:#fcd34d;}
.b-purple{background:rgba(139,92,246,.2);color:#c4b5fd;}
.b-gray{background:rgba(255,255,255,.07);color:rgba(255,255,255,.5);}
.b-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}

.btn-sm{padding:6px 12px;border-radius:8px;font-size:.75rem;font-weight:600;cursor:pointer;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .15s;font-family:inherit;}
.btn-sm:hover{background:rgba(255,255,255,.11);}
.btn-primary{background:var(--t-primary,#059669);color:#fff;border-color:transparent;}
.btn-primary:hover{background:var(--t-primary-d,#047857);}
.btn-danger{background:rgba(239,68,68,.8);color:#fff;border-color:transparent;}
.btn-success{background:rgba(16,185,129,.8);color:#fff;border-color:transparent;}

.flabel{display:block;font-size:.73rem;font-weight:600;color:rgba(255,255,255,.45);margin-bottom:5px;}
.finput{width:100%;border:1.5px solid rgba(255,255,255,.1);border-radius:10px;padding:9px 12px;font-family:inherit;font-size:.84rem;color:#fff;outline:none;background:rgba(255,255,255,.06);transition:border .2s;}
.finput:focus{border-color:var(--t-primary,#059669);box-shadow:0 0 0 3px color-mix(in srgb,var(--t-primary,#059669) 20%,transparent);}
.finput::placeholder{color:rgba(255,255,255,.2);}
.finput option{background:#0a150e;color:#fff;}
.fgroup{margin-bottom:12px;}

.alert{padding:11px 16px;border-radius:12px;font-size:.82rem;margin-bottom:18px;display:flex;align-items:center;gap:9px;}
.alert-success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.25);color:#6ee7b7;}
.alert-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;}

.empty-state{text-align:center;padding:48px 20px;color:rgba(255,255,255,.25);}
.empty-state .material-symbols-outlined{font-size:46px;display:block;margin:0 auto 14px;opacity:.3;}
.empty-state p{font-size:.82rem;}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(6px);}
.modal-overlay.open{display:flex;}
.modal{background:#070d0a;border:1px solid rgba(5,150,105,.15);border-radius:20px;width:480px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.7);animation:mIn .25s ease both;}
@keyframes mIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.mhdr{padding:22px 24px 0;display:flex;align-items:center;justify-content:space-between;}
.mtitle{font-size:1rem;font-weight:800;color:#fff;}
.mclose{width:30px;height:30px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);cursor:pointer;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.4);}
.mclose .material-symbols-outlined{font-size:16px;}
.mbody{padding:18px 24px 24px;}
.card-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.35);margin-bottom:14px;}
.qa-btn{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;font-family:inherit;font-size:.83rem;font-weight:600;cursor:pointer;border:none;width:100%;text-align:left;transition:all .18s;margin-bottom:8px;text-decoration:none;color:#fff;}
.qa-primary{background:var(--g);}
.qa-primary:hover{background:var(--gd);}
.qa-secondary{background:rgba(255,255,255,.05);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.08);}
.qa-secondary:hover{background:rgba(255,255,255,.1);color:#fff;}
.qa-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.qa-icon .material-symbols-outlined{font-size:16px;}
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
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
  .topbar-title{font-size:.85rem;}
}
@media(max-width:600px){.stats-row{grid-template-columns:1fr;}}
</style>
</head>
<body>
<?php $bgImg = getTenantBgImage($theme, 'https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?w=1600&auto=format&fit=crop&q=60'); ?>
<div class="bg-scene">
  <img src="<?=$bgImg?>" alt="">
  <div class="bg-overlay"></div>
</div>

<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">
      <?php if($logo_url):?><img src="<?=htmlspecialchars($logo_url)?>" alt="logo">
      <?php else:?><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg><?php endif;?>
    </div>
    <div>
      <div class="sb-name"><?=htmlspecialchars($business_name)?></div>
      <div class="sb-subtitle">Manager Portal</div>
    </div>
  </div>

  <?php if($tenant):?>
  <div class="sb-role-card">
    <div class="sb-role-label">My Branch</div>
    <div class="sb-role-name"><?=htmlspecialchars($tenant['business_name'])?></div>
    <div class="sb-role-badge">
      <span class="material-symbols-outlined" style="font-size:11px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">manage_accounts</span>
      Branch Manager
    </div>
  </div>
  <?php endif;?>

  <div class="sb-user">
    <div class="sb-avatar"><?=strtoupper(substr($u['name'],0,1))?></div>
    <div>
      <div class="sb-uname"><?=htmlspecialchars(explode(' ',$u['name'])[0]??$u['name'])?></div>
      <div class="sb-urole">Branch Manager</div>
      <div class="sb-status">● ONLINE</div>
    </div>
  </div>

  <nav class="sb-nav">
    <div class="sb-section">Overview</div>
    <a href="?page=dashboard" class="sb-item <?=$active_page==='dashboard'?'active':''?>">
      <span class="material-symbols-outlined">dashboard</span>Dashboard
    </a>

    <div class="sb-section">Branch Records</div>
    <a href="?page=tickets" class="sb-item <?=$active_page==='tickets'?'active':''?>">
      <span class="material-symbols-outlined">receipt_long</span>Pawn Tickets
    </a>
    <a href="?page=customers" class="sb-item <?=$active_page==='customers'?'active':''?>">
      <span class="material-symbols-outlined">group</span>Customers
    </a>

    <div class="sb-section">Approvals</div>
    <a href="?page=void_requests" class="sb-item <?=$active_page==='void_requests'?'active':''?>">
      <span class="material-symbols-outlined">cancel_presentation</span>Void Requests
      <?php if(count($pending_voids)>0):?><span class="sb-pill"><?=count($pending_voids)?></span><?php endif;?>
    </a>

    <div class="sb-section">Team Management</div>
    <a href="?page=team" class="sb-item <?=$active_page==='team'?'active':''?>">
      <span class="material-symbols-outlined">badge</span>Staff &amp; Cashier
    </a>
    <a href="?page=invite" class="sb-item <?=$active_page==='invite'?'active':''?>">
      <span class="material-symbols-outlined">person_add</span>Invite Member
    </a>

    <div class="sb-section">Shop Management</div>
    <a href="?page=shop_items" class="sb-item <?=$active_page==='shop_items'?'active':''?>">
      <span class="material-symbols-outlined">storefront</span>Shop Items
    </a>
    <a href="?page=add_shop_item" class="sb-item <?=$active_page==='add_shop_item'?'active':''?>">
      <span class="material-symbols-outlined">add_box</span>Add Shop Item
    </a>
    <a href="?page=shop_categories" class="sb-item <?=$active_page==='shop_categories'?'active':''?>">
      <span class="material-symbols-outlined">category</span>Categories
    </a>
    <a href="?page=promos" class="sb-item <?=$active_page==='promos'?'active':''?>">
      <span class="material-symbols-outlined">campaign</span>Promos &amp; Announcements
      <?php if($active_promos_count>0):?><span class="sb-pill" style="background:var(--g);"><?=$active_promos_count?></span><?php endif;?>
    </a>

    <div class="sb-section">Reports</div>
    <a href="?page=audit" class="sb-item <?=$active_page==='audit'?'active':''?>">
      <span class="material-symbols-outlined">manage_search</span>Audit Logs
    </a>
    <?php if($mgr_can_export):?>
    <a href="?page=export" class="sb-item <?=$active_page==='export'?'active':''?>">
      <span class="material-symbols-outlined">download</span>Export to PDF
    </a>
    <?php endif;?>
  </nav>

  <div class="sb-footer">
    <?php $logout_url = 'logout.php?role=manager&slug=' . rawurlencode($u['tenant_slug'] ?? ''); ?>
    <button type="button" class="sb-logout" onclick="showLogoutModal('<?= $logout_url ?>')">
      <span class="material-symbols-outlined">logout</span>Sign Out
    </button>
  </div>
</aside>

<?php
// ── Manager Notification queries ──────────────────────────────
$notifs = [];
try {
  if ($tid) {
    // Overdue tickets
    $od = $pdo->prepare("SELECT COUNT(*) FROM pawn_transactions WHERE tenant_id=? AND status='Stored' AND maturity_date < CURDATE()");
    $od->execute([$tid]); $od_c = (int)$od->fetchColumn();
    if ($od_c > 0) $notifs[] = ['type'=>'danger','icon'=>'receipt_long','title'=>$od_c.' Overdue Ticket'.($od_c>1?'s':''),'sub'=>'Items past maturity date.','link'=>'?page=tickets'];
    // Expiring in 3 days
    $exp = $pdo->prepare("SELECT COUNT(*) FROM pawn_transactions WHERE tenant_id=? AND status='Stored' AND maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
    $exp->execute([$tid]); $exp_c = (int)$exp->fetchColumn();
    if ($exp_c > 0) $notifs[] = ['type'=>'warn','icon'=>'hourglass_bottom','title'=>$exp_c.' Ticket'.($exp_c>1?'s':'').' Expiring in 3 Days','sub'=>'Remind customers to redeem or renew.','link'=>'?page=tickets'];
    // Pending void requests
    $vr = $pdo->prepare("SELECT COUNT(*) FROM pawn_void_requests WHERE tenant_id=? AND status='pending'");
    $vr->execute([$tid]); $vr_c = (int)$vr->fetchColumn();
    if ($vr_c > 0) $notifs[] = ['type'=>'warn','icon'=>'cancel_presentation','title'=>$vr_c.' Pending Void Request'.($vr_c>1?'s':''),'sub'=>'Awaiting your review.','link'=>'?page=void_requests'];
    // Low stock
    $ls = $pdo->prepare("SELECT COUNT(*) FROM item_inventory WHERE tenant_id=? AND stock_qty <= 2 AND stock_qty > 0 AND is_shop_visible=1");
    $ls->execute([$tid]); $ls_c = (int)$ls->fetchColumn();
    if ($ls_c > 0) $notifs[] = ['type'=>'warn','icon'=>'inventory_2','title'=>$ls_c.' Item'.($ls_c>1?'s':'').' Low on Stock','sub'=>'Stock at 2 or below in shop.','link'=>'?page=shop_items'];
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
      <span class="topbar-title"><?=htmlspecialchars($titles[$active_page]??'Dashboard')?></span>
      <?php if($tenant):?><span class="mgr-chip"><?=htmlspecialchars($tenant['business_name'])?></span><?php endif;?>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <div style="display:flex;align-items:center;gap:7px;background:rgba(5,150,105,.1);border:1px solid rgba(5,150,105,.18);padding:5px 11px;border-radius:100px;">
        <span style="width:8px;height:8px;border-radius:50%;background:var(--t-primary,#059669);display:inline-block;animation:pulse 2s infinite;"></span>
        <span style="font-size:.69rem;color:#6ee7b7;font-weight:600;">Manager</span>
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
              $ic_bg = match($n['type']){'danger'=>'background:rgba(239,68,68,.15);','warn'=>'background:rgba(245,158,11,.15);',default=>'background:rgba(59,130,246,.15);'};
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
  <?php if($success_msg):?><div class="alert alert-success"><span class="material-symbols-outlined" style="font-size:17px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">check_circle</span><?=htmlspecialchars($success_msg)?></div><?php endif;?>
  <?php if($error_msg):?><div class="alert alert-error"><span class="material-symbols-outlined" style="font-size:17px;">warning</span><?=htmlspecialchars($error_msg)?></div><?php endif;?>

  <?php if($active_page==='dashboard'): ?>
    <div class="page-hdr">
      <div>
        <h2>Welcome, <?=htmlspecialchars(explode(' ',$u['name'])[0])?>! 🧑‍💼</h2>
        <p>Branch overview for <?=date('F j, Y')?>.</p>
      </div>
      <button onclick="document.getElementById('inviteModal').classList.add('open')" class="btn-sm btn-primary">
        <span class="material-symbols-outlined" style="font-size:15px;">person_add</span>Invite Staff / Cashier
      </button>
    </div>

    <!-- Branch banner -->
    <div style="background:linear-gradient(135deg,var(--t-secondary,#064e3b),var(--t-primary,#059669));border-radius:14px;padding:18px 22px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border:1px solid rgba(255,255,255,.1);">
      <div>
        <div style="font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:4px;">Your Branch</div>
        <div style="font-size:1.05rem;font-weight:800;color:#fff;"><?=htmlspecialchars($tenant['business_name']??'—')?></div>
        <div style="font-size:.76rem;color:rgba(255,255,255,.5);margin-top:2px;"><?=$tenant['plan']?> Plan &middot; Branch Manager</div>
        <div style="font-size:.72rem;color:rgba(255,255,255,.35);margin-top:4px;font-family:monospace;">Tenant #<?=str_pad($tid,4,'0',STR_PAD_LEFT)?></div>
        <?php if(!empty($tenant['phone'])):?>
        <div style="font-size:.74rem;color:rgba(255,255,255,.6);margin-top:5px;display:flex;align-items:center;gap:5px;">
          <span class="material-symbols-outlined" style="font-size:14px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">call</span>
          <?=htmlspecialchars($tenant['phone'])?>
        </div>
        <?php endif;?>
        <?php if(!empty($tenant['address'])):?>
        <div style="font-size:.74rem;color:rgba(255,255,255,.6);margin-top:3px;display:flex;align-items:center;gap:5px;">
          <span class="material-symbols-outlined" style="font-size:14px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">location_on</span>
          <?=htmlspecialchars($tenant['address'])?>
        </div>
        <?php endif;?>
      </div>
      <div style="text-align:right;">
        <div style="font-size:.65rem;color:rgba(255,255,255,.4);margin-bottom:3px;">Team Members</div>
        <div style="font-size:1.5rem;font-weight:800;color:#fff;"><?=count($my_team)?></div>
      </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-top"><div class="stat-icon" style="background:rgba(5,150,105,.15);"><span class="material-symbols-outlined" style="color:#6ee7b7;">confirmation_number</span></div></div>
        <div class="stat-value"><?=$tickets_today?></div><div class="stat-label">Tickets Today</div>
      </div>
      <div class="stat-card">
        <div class="stat-top"><div class="stat-icon" style="background:rgba(59,130,246,.15);"><span class="material-symbols-outlined" style="color:#93c5fd;">shield</span></div></div>
        <div class="stat-value"><?=$active_tickets?></div><div class="stat-label">Active Tickets</div>
      </div>
      <div class="stat-card">
        <div class="stat-top"><div class="stat-icon" style="background:rgba(245,158,11,.15);"><span class="material-symbols-outlined" style="color:#fcd34d;">cancel_presentation</span></div></div>
        <div class="stat-value"><?=count($pending_voids)?></div><div class="stat-label">Pending Voids</div>
      </div>
      <div class="stat-card">
        <div class="stat-top"><div class="stat-icon" style="background:rgba(139,92,246,.15);"><span class="material-symbols-outlined" style="color:#c4b5fd;">badge</span></div></div>
        <div class="stat-value"><?=count($my_team)?></div><div class="stat-label">Staff &amp; Cashiers</div>
      </div>
      <div class="stat-card" style="grid-column:span 2;">
        <div class="stat-top"><div class="stat-icon" style="background:rgba(16,185,129,.15);"><span class="material-symbols-outlined" style="color:#6ee7b7;">storefront</span></div></div>
        <div style="display:flex;gap:24px;">
          <div><div class="stat-value"><?=$shop_visible_count?></div><div class="stat-label">Items in Shop</div></div>
          <div><div class="stat-value"><?=$shop_featured_count?></div><div class="stat-label">Featured</div></div>
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:260px 1fr;gap:16px;">
      <!-- Quick actions + mini team -->
      <div>
        <div class="card" style="margin-bottom:14px;">
          <div class="card-title">⚡ Quick Actions</div>
          <button onclick="document.getElementById('inviteModal').classList.add('open')" class="qa-btn qa-primary">
            <div class="qa-icon" style="background:rgba(255,255,255,.15);"><span class="material-symbols-outlined">person_add</span></div>Invite Staff / Cashier
          </button>
          <a href="?page=void_requests" class="qa-btn qa-secondary">
            <div class="qa-icon" style="background:rgba(245,158,11,.12);"><span class="material-symbols-outlined" style="color:#fcd34d;">cancel_presentation</span></div>Review Voids <?php if(count($pending_voids)):?><span style="background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;padding:1px 6px;border-radius:100px;margin-left:4px;"><?=count($pending_voids)?></span><?php endif;?>
          </a>
          <a href="?page=team" class="qa-btn qa-secondary">
            <div class="qa-icon" style="background:rgba(5,150,105,.12);"><span class="material-symbols-outlined" style="color:#6ee7b7;">badge</span></div>Manage Team
          </a>
        </div>

        <?php if(!empty($my_team)):?>
        <div class="card">
          <div class="card-title">👥 My Team</div>
          <?php foreach(array_slice($my_team,0,5) as $m):?>
          <div style="display:flex;align-items:center;gap:9px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04);">
            <div style="width:28px;height:28px;border-radius:50%;background:<?=$m['role']==='cashier'?'rgba(139,92,246,.4)':'rgba(59,130,246,.4)'?>;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;color:#fff;flex-shrink:0;"><?=strtoupper(substr($m['fullname'],0,1))?></div>
            <div style="flex:1;min-width:0;">
              <div style="font-size:.8rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($m['fullname'])?></div>
              <div style="font-size:.68rem;color:rgba(255,255,255,.3);"><?=ucfirst($m['role'])?></div>
            </div>
            <span class="badge <?=$m['is_suspended']?'b-red':'b-green'?>"><?=$m['is_suspended']?'Susp':'Active'?></span>
          </div>
          <?php endforeach;?>
          <?php if(count($my_team)>5):?><a href="?page=team" style="display:block;text-align:center;font-size:.74rem;color:#6ee7b7;margin-top:10px;text-decoration:none;">All <?=count($my_team)?> members →</a><?php endif;?>
        </div>
        <?php endif;?>
      </div>

      <!-- Recent tickets -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="card-title" style="margin:0;">Recent Pawn Tickets</div>
          <a href="?page=tickets" style="font-size:.72rem;color:#6ee7b7;font-weight:600;text-decoration:none;">View All →</a>
        </div>
        <?php if(empty($all_tickets)):?>
          <div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>No tickets yet.</p></div>
        <?php else:?>
        <div style="overflow-x:auto;"><table><thead><tr><th>Ticket</th><th>Customer</th><th>Item</th><th>Loan</th><th>Status</th><th>Maturity</th></tr></thead><tbody>
        <?php foreach(array_slice($all_tickets,0,8) as $t):
          $sc=['Stored'=>'b-blue','Released'=>'b-green','Renewed'=>'b-yellow','Voided'=>'b-red','Auctioned'=>'b-gray'];?>
        <tr>
          <td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td>
          <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($t['customer_name'])?></td>
          <td><?=htmlspecialchars($t['item_category'])?></td>
          <td>₱<?=number_format($t['loan_amount'],2)?></td>
          <td><span class="badge <?=$sc[$t['status']]??'b-gray'?>"><?=$t['status']?></span></td>
          <td style="font-size:.73rem;color:<?=strtotime($t['maturity_date'])<time()&&$t['status']==='Stored'?'#fca5a5':'rgba(255,255,255,.35)'?>;"><?=$t['maturity_date']?></td>
        </tr>
        <?php endforeach;?></tbody></table></div>
        <?php endif;?>
      </div>
    </div>

  <?php elseif($active_page==='tickets'): ?>
    <div class="page-hdr"><div><h2>Pawn Tickets</h2><p><?=count($all_tickets)?> records</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($all_tickets)):?><div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>No tickets yet.</p></div>
      <?php else:?>
      <table><thead><tr><th>Ticket No.</th><th>Customer</th><th>Contact</th><th>Item</th><th>Loan</th><th>Total Redeem</th><th>Maturity</th><th>Status</th></tr></thead><tbody>
      <?php foreach($all_tickets as $t):
        $sc=['Stored'=>'b-blue','Released'=>'b-green','Renewed'=>'b-yellow','Voided'=>'b-red','Auctioned'=>'b-gray'];?>
      <tr>
        <td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td>
        <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($t['customer_name'])?></td>
        <td style="font-family:monospace;font-size:.75rem;"><?=htmlspecialchars($t['contact_number'])?></td>
        <td><?=htmlspecialchars($t['item_category'])?></td>
        <td>₱<?=number_format($t['loan_amount'],2)?></td>
        <td style="font-weight:700;color:#fff;">₱<?=number_format($t['total_redeem'],2)?></td>
        <td style="font-size:.73rem;color:<?=strtotime($t['maturity_date'])<time()&&$t['status']==='Stored'?'#fca5a5':'rgba(255,255,255,.35)'?>;"><?=$t['maturity_date']?></td>
        <td><span class="badge <?=$sc[$t['status']]??'b-gray'?>"><?=$t['status']?></span></td>
      </tr>
      <?php endforeach;?></tbody></table>
      <?php endif;?>
    </div>

  <?php elseif($active_page==='customers'): ?>
    <?php
      $cust_tickets_map = [];
      foreach($all_tickets as $t) {
          $cust_tickets_map[strtolower(trim($t['customer_name']))][] = $t;
      }
    ?>
    <div class="page-hdr"><div><h2>Customers</h2><p><?=count($customers)?> records</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($customers)):?><div class="empty-state"><span class="material-symbols-outlined">group</span><p>No customers yet.</p></div>
      <?php else:?>
      <table><thead><tr><th>Name</th><th>Contact</th><th>Email</th><th>Gender</th><th>ID Type</th><th>Registered</th><th>Action</th></tr></thead><tbody>
      <?php foreach($customers as $c):
        $ckey = strtolower(trim($c['full_name']));
        $c_json = htmlspecialchars(json_encode([
          'full_name'       => $c['full_name'],
          'contact_number'  => $c['contact_number'] ?? '',
          'email'           => $c['email'] ?? '',
          'gender'          => $c['gender'] ?? '',
          'address'         => $c['address'] ?? '',
          'birthdate'       => $c['birthdate'] ?? '',
          'nationality'     => $c['nationality'] ?? '',
          'valid_id_type'   => $c['valid_id_type'] ?? '',
          'valid_id_number' => $c['valid_id_number'] ?? '',
          'valid_id_image'  => $c['valid_id_image'] ?? '',
          'customer_photo'  => $c['customer_photo'] ?? '',
          'registered_at'   => $c['registered_at'] ?? '',
        ]), ENT_QUOTES);
        $c_tickets_json = htmlspecialchars(json_encode($cust_tickets_map[$ckey] ?? []), ENT_QUOTES);
      ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:9px;">
            <?php if(!empty($c['customer_photo'])): ?>
              <img src="<?=htmlspecialchars($c['customer_photo'])?>" style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid rgba(255,255,255,.12);flex-shrink:0;" onerror="this.style.display='none'">
            <?php else: ?>
              <div style="width:30px;height:30px;border-radius:50%;background:var(--t-primary,#059669);display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:#fff;flex-shrink:0;"><?=strtoupper(substr($c['full_name'],0,1))?></div>
            <?php endif; ?>
            <span style="font-weight:600;color:#fff;"><?=htmlspecialchars($c['full_name'])?></span>
          </div>
        </td>
        <td style="font-family:monospace;font-size:.75rem;"><?=htmlspecialchars($c['contact_number'])?></td>
        <td style="font-size:.75rem;color:rgba(255,255,255,.4);"><?=htmlspecialchars($c['email']??'—')?></td>
        <td><?=$c['gender']?></td>
        <td><?=htmlspecialchars($c['valid_id_type']??'—')?></td>
        <td style="font-size:.73rem;color:rgba(255,255,255,.35);"><?=date('M d, Y',strtotime($c['registered_at']))?></td>
        <td>
          <button class="btn-sm btn-primary" style="font-size:.7rem;" onclick="openCustomerModal(<?=$c_json?>,<?=$c_tickets_json?>)">
            <span class="material-symbols-outlined" style="font-size:13px;">person</span>View
          </button>
        </td>
      </tr>
      <?php endforeach;?></tbody></table>
      <?php endif;?>
    </div>

    <!-- CUSTOMER INFO MODAL -->
    <div class="modal-overlay" id="customerModal" style="z-index:9999;">
      <div class="modal" style="width:720px;max-width:97vw;max-height:90vh;overflow-y:auto;">
        <div class="mhdr">
          <div class="mtitle" id="cModal_title">Customer Profile</div>
          <button class="mclose" onclick="document.getElementById('customerModal').classList.remove('open')">
            <span class="material-symbols-outlined">close</span>
          </button>
        </div>
        <div class="mbody" id="cModal_body"></div>
      </div>
    </div>

    <!-- Lightbox -->
    <div id="imgLightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:99999;align-items:center;justify-content:center;cursor:zoom-out;" onclick="this.style.display='none'">
      <img id="imgLightboxImg" src="" style="max-width:92vw;max-height:90vh;border-radius:12px;box-shadow:0 0 60px rgba(0,0,0,.8);object-fit:contain;">
    </div>

    <script>
    function openImgLightbox(src){
      document.getElementById('imgLightboxImg').src=src;
      document.getElementById('imgLightbox').style.display='flex';
    }
    function openCustomerModal(c, tickets) {
      document.getElementById('cModal_title').textContent = c.full_name || 'Customer Profile';
      const sColor={'Stored':'#93c5fd','Released':'#6ee7b7','Renewed':'#fcd34d','Voided':'#fca5a5','Auctioned':'#c4b5fd'};
      const sBg   ={'Stored':'rgba(59,130,246,.18)','Released':'rgba(16,185,129,.18)','Renewed':'rgba(245,158,11,.18)','Voided':'rgba(239,68,68,.18)','Auctioned':'rgba(139,92,246,.18)'};

      const hasPhoto = c.customer_photo && c.customer_photo.trim();
      const hasId    = c.valid_id_image && c.valid_id_image.trim();
      let photosHtml = '';
      if (hasPhoto || hasId) {
        const photoCard = hasPhoto ? `
          <div style="text-align:center;">
            <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.35);margin-bottom:8px;">Customer Photo</div>
            <img src="${c.customer_photo}" onclick="openImgLightbox('${c.customer_photo}')"
              style="width:90px;height:90px;object-fit:cover;border-radius:50%;border:2px solid rgba(255,255,255,.15);cursor:zoom-in;"
              onerror="this.closest('div').style.display='none'">
            <div style="font-size:.68rem;color:rgba(255,255,255,.25);margin-top:5px;">Click to enlarge</div>
          </div>` : '';
        const idCard = hasId ? `
          <div>
            <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.35);margin-bottom:8px;">Valid ID Image</div>
            <img src="${c.valid_id_image}" onclick="openImgLightbox('${c.valid_id_image}')"
              style="width:100%;max-height:220px;object-fit:contain;border-radius:10px;border:1px solid rgba(255,255,255,.12);cursor:zoom-in;background:rgba(255,255,255,.04);"
              onerror="this.closest('div').innerHTML='<span style=\'font-size:.75rem;color:rgba(255,255,255,.2);\'>Image unavailable</span>'">
            <div style="font-size:.68rem;color:rgba(255,255,255,.25);margin-top:5px;">Click to enlarge</div>
          </div>` : '';
        const cols = (hasPhoto && hasId) ? '100px 1fr' : '1fr';
        photosHtml = `<div style="display:grid;grid-template-columns:${cols};gap:16px;align-items:start;margin-bottom:18px;padding:14px 16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:12px;">${photoCard}${idCard}</div>`;
      }

      let ticketsHtml = '';
      if (tickets && tickets.length > 0) {
        const rows = tickets.map(t => {
          const sc = sColor[t.status]||'rgba(255,255,255,.4)';
          const sb = sBg[t.status]||'rgba(255,255,255,.08)';
          const od = t.status==='Stored'&&new Date(t.maturity_date)<new Date()?'color:#fca5a5;':'color:rgba(255,255,255,.45);';
          return `<tr>
            <td><span class="ticket-tag" style="font-size:.72rem;">${t.ticket_no}</span></td>
            <td style="font-size:.77rem;">${t.item_category||'—'}</td>
            <td style="font-size:.77rem;">₱${parseFloat(t.loan_amount||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
            <td style="font-size:.77rem;font-weight:700;color:#fff;">₱${parseFloat(t.total_redeem||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
            <td style="font-size:.73rem;${od}">${t.maturity_date||'—'}</td>
            <td><span style="font-size:.68rem;font-weight:700;padding:2px 9px;border-radius:100px;background:${sb};color:${sc};">${t.status}</span></td>
            <td style="font-size:.72rem;color:rgba(255,255,255,.3);">${t.created_at?t.created_at.substring(0,10):'—'}</td>
          </tr>`;
        }).join('');
        ticketsHtml = `<div style="margin-top:22px;"><div style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:10px;">Pawn Ticket History (${tickets.length})</div><div style="overflow-x:auto;"><table><thead><tr><th>Ticket</th><th>Item</th><th>Loan</th><th>Total Redeem</th><th>Maturity</th><th>Status</th><th>Date</th></tr></thead><tbody>${rows}</tbody></table></div></div>`;
      } else {
        ticketsHtml = `<div style="margin-top:22px;text-align:center;padding:18px 0;color:rgba(255,255,255,.25);font-size:.82rem;"><span class="material-symbols-outlined" style="display:block;font-size:32px;margin-bottom:6px;opacity:.3;">receipt_long</span>No pawn tickets on record.</div>`;
      }

      const avatarHtml = hasPhoto
        ? `<img src="${c.customer_photo}" style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.15);flex-shrink:0;" onerror="this.style.display='none'">`
        : `<div style="width:54px;height:54px;border-radius:50%;background:linear-gradient(135deg,var(--t-primary,#059669),var(--t-secondary,#064e3b));display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;color:#fff;flex-shrink:0;">${(c.full_name||'?')[0].toUpperCase()}</div>`;

      const row=(l,v)=>v?`<div style="margin-bottom:11px;"><div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.35);margin-bottom:3px;">${l}</div><div style="font-size:.85rem;color:#fff;font-weight:600;">${v}</div></div>`:'';

      document.getElementById('cModal_body').innerHTML = `
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding-bottom:18px;border-bottom:1px solid rgba(255,255,255,.07);">
          ${avatarHtml}
          <div>
            <div style="font-size:1.05rem;font-weight:800;color:#fff;">${c.full_name||'—'}</div>
            <div style="font-size:.78rem;color:rgba(255,255,255,.4);margin-top:3px;">${c.email||'No email'}</div>
            <div style="font-size:.75rem;color:rgba(255,255,255,.35);margin-top:2px;font-family:monospace;">${c.contact_number||'No contact'}</div>
          </div>
          <div style="margin-left:auto;text-align:right;">
            <div style="font-size:.68rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.06em;">Total Tickets</div>
            <div style="font-size:1.6rem;font-weight:900;color:var(--t-primary,#6ee7b7);">${tickets?tickets.length:0}</div>
          </div>
        </div>
        ${photosHtml}
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0 20px;">
          ${row('Gender',c.gender)}
          ${row('Nationality',c.nationality)}
          ${row('Birthdate',c.birthdate)}
          ${row('Registered',c.registered_at?c.registered_at.substring(0,10):'')}
          ${row('Valid ID Type',c.valid_id_type)}
          ${row('ID Number',c.valid_id_number)}
          ${c.address?`<div style="margin-bottom:11px;grid-column:1/-1;">${row('Address',c.address)}</div>`:''}
        </div>
        ${ticketsHtml}
      `;
      document.getElementById('customerModal').classList.add('open');
    }
    document.getElementById('customerModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
    </script>

  <?php elseif($active_page==='void_requests'): ?>
    <div class="page-hdr"><div><h2>Void Requests</h2><p>Approve or reject staff void requests</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($void_reqs)):?><div class="empty-state"><span class="material-symbols-outlined">cancel_presentation</span><p>No void requests yet.</p></div>
      <?php else:?>
      <table><thead><tr><th>Ticket</th><th>Requested By</th><th>Reason</th><th>Status</th><th>Date</th><th>Action</th></tr></thead><tbody>
      <?php foreach($void_reqs as $v):?>
      <tr>
        <td><span class="ticket-tag"><?=htmlspecialchars($v['ticket_no'])?></span></td>
        <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($v['req_name'])?></td>
        <td style="max-width:180px;font-size:.78rem;"><?=htmlspecialchars($v['reason'])?></td>
        <td><span class="badge <?=$v['status']==='approved'?'b-green':($v['status']==='pending'?'b-yellow':'b-red')?>"><?=ucfirst($v['status'])?></span></td>
        <td style="font-size:.72rem;color:rgba(255,255,255,.35);"><?=date('M d, Y h:i A',strtotime($v['requested_at']))?></td>
        <td>
          <?php if($v['status']==='pending'):?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="approve_void">
            <input type="hidden" name="void_id" value="<?=$v['id']?>">
            <input type="hidden" name="ticket_no" value="<?=htmlspecialchars($v['ticket_no'])?>">
            <button type="submit" class="btn-sm btn-success" onclick="return confirm('Approve void for <?=htmlspecialchars($v['ticket_no'])?>?')" style="font-size:.7rem;">Approve</button>
          </form>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="reject_void">
            <input type="hidden" name="void_id" value="<?=$v['id']?>">
            <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Reject this void request?')" style="font-size:.7rem;">Reject</button>
          </form>
          <?php else:?>—<?php endif;?>
        </td>
      </tr>
      <?php endforeach;?></tbody></table>
      <?php endif;?>
    </div>

  <?php elseif($active_page==='team'): ?>
    <div class="page-hdr">
      <div><h2>Staff &amp; Cashier Team</h2><p><?=count($my_team)?> member<?=count($my_team)!==1?'s':''?></p></div>
      <button onclick="document.getElementById('inviteModal').classList.add('open')" class="btn-sm btn-primary">
        <span class="material-symbols-outlined" style="font-size:15px;">person_add</span>Invite Member
      </button>
    </div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($my_team)):?>
        <div class="empty-state">
          <span class="material-symbols-outlined">badge</span>
          <p>No staff or cashiers yet.<br>Use the Invite button to add team members.</p>
        </div>
      <?php else:?>
      <table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($my_team as $m):
        $role_badge=$m['role']==='cashier'?'b-purple':'b-blue';
        $avatar_bg=$m['role']==='cashier'?'rgba(139,92,246,.4)':'rgba(59,130,246,.4)';?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:9px;">
            <div style="width:28px;height:28px;border-radius:50%;background:<?=$avatar_bg?>;display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;color:#fff;"><?=strtoupper(substr($m['fullname'],0,1))?></div>
            <div>
              <div style="font-weight:600;color:#fff;font-size:.83rem;"><?=htmlspecialchars($m['fullname'])?></div>
              <div style="font-size:.7rem;color:rgba(255,255,255,.3);"><?=htmlspecialchars($m['email']??'')?></div>
            </div>
          </div>
        </td>
        <td style="font-family:monospace;font-size:.76rem;color:#6ee7b7;"><?=htmlspecialchars($m['username'])?></td>
        <td><span class="badge <?=$role_badge?>"><?=ucfirst($m['role'])?></span></td>
        <td><span class="badge <?=$m['is_suspended']?'b-red':'b-green'?>"><span class="b-dot"></span><?=$m['is_suspended']?'Suspended':'Active'?></span></td>
        <td style="font-size:.72rem;color:rgba(255,255,255,.35);"><?=date('M d, Y',strtotime($m['created_at']))?></td>
        <td>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="toggle_user">
            <input type="hidden" name="user_id" value="<?=$m['id']?>">
            <input type="hidden" name="is_suspended" value="<?=$m['is_suspended']?>">
            <button type="submit" class="btn-sm <?=$m['is_suspended']?'btn-success':'btn-danger'?>" style="font-size:.7rem;" onclick="return confirm('<?=$m['is_suspended']?'Unsuspend':'Suspend'?> <?=htmlspecialchars($m['fullname'])?>?')">
              <?=$m['is_suspended']?'Unsuspend':'Suspend'?>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach;?></tbody></table>
      <?php endif;?>
    </div>

  <?php elseif($active_page==='invite'): ?>
    <div class="page-hdr"><div><h2>Invite Team Member</h2><p>Send an invitation email to a new staff or cashier.</p></div></div>
    <div style="max-width:500px;">
      <div class="card">
        <form method="POST">
          <input type="hidden" name="action" value="invite_staff">
          <div class="card-title">New Invitation</div>
          <div class="fgroup">
            <label class="flabel">Role *</label>
            <select name="role" class="finput" required>
              <option value="staff">Staff</option>
              <option value="cashier">Cashier</option>
            </select>
          </div>
          <div class="fgroup">
            <label class="flabel">Full Name *</label>
            <input type="text" name="name" class="finput" placeholder="Maria Santos" required>
          </div>
          <div class="fgroup">
            <label class="flabel">Email Address *</label>
            <input type="email" name="email" class="finput" placeholder="staff@example.com" required>
            <div style="font-size:.71rem;color:rgba(255,255,255,.25);margin-top:5px;">A secure invitation link will be sent here.</div>
          </div>
          <div style="background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.18);border-radius:10px;padding:11px 13px;font-size:.76rem;color:rgba(110,231,183,.8);margin-bottom:14px;line-height:1.6;">
            📧 They'll receive a link to set up their credentials. After registering, they'll be directed to the branch login page.
          </div>
          <div style="background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.15);border-radius:10px;padding:11px 13px;font-size:.75rem;color:#fcd34d;margin-bottom:14px;">
            ⚠️ As Manager, you can only invite <strong>Staff</strong> and <strong>Cashier</strong> roles. To add another Manager, contact the Branch Owner (Admin).
          </div>
          <button type="submit" class="btn-sm btn-primary" style="width:100%;padding:11px;justify-content:center;font-size:.88rem;">
            <span class="material-symbols-outlined" style="font-size:16px;">send</span>Send Invitation
          </button>
        </form>
      </div>
    </div>

  <?php elseif($active_page==='audit'): ?>
    <div class="page-hdr"><div><h2>Audit Logs</h2><p>Activity logs for your branch team</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($audit_logs)):?>
        <div style="text-align:center;padding:40px 20px;color:rgba(255,255,255,.3);">
          <span class="material-symbols-outlined" style="font-size:3rem;display:block;margin-bottom:10px;">manage_search</span>
          <p>No audit logs yet.</p>
        </div>
      <?php else:?>
      <table>
        <thead><tr><th>Date</th><th>Actor</th><th>Role</th><th>Action</th><th>Ref #</th><th>Message</th></tr></thead>
        <tbody>
        <?php foreach($audit_logs as $a):
          $role_colors = ['manager'=>'background:rgba(139,92,246,.25);color:#c4b5fd;','staff'=>'background:rgba(16,185,129,.2);color:#6ee7b7;','cashier'=>'background:rgba(245,158,11,.2);color:#fcd34d;'];
          $rbadge = $role_colors[$a['actor_role']??''] ?? 'background:rgba(255,255,255,.1);color:rgba(255,255,255,.5);';
        ?>
        <tr>
          <td style="font-size:.72rem;color:rgba(255,255,255,.35);white-space:nowrap;"><?=date('M d, Y h:i A',strtotime($a['created_at']))?></td>
          <td style="font-weight:600;color:#fff;font-size:.78rem;"><?=htmlspecialchars(ucfirst($a['actor_username']??''))?></td>
          <td><span style="font-size:.62rem;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase;letter-spacing:.05em;<?=$rbadge?>"><?=$a['actor_role']??''?></span></td>
          <td style="font-family:monospace;font-size:.72rem;color:#fcd34d;"><?=htmlspecialchars($a['action']??'')?></td>
          <td style="font-size:.72rem;color:rgba(255,255,255,.4);"><?=htmlspecialchars($a['entity_id']??'—')?></td>
          <td style="font-size:.75rem;color:rgba(255,255,255,.4);max-width:300px;"><?=htmlspecialchars($a['message']??'')?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
      <?php endif;?>
    </div>


  <?php elseif($active_page==='shop_items'): ?>
    <div class="page-hdr">
      <div><h2>Shop Items</h2><p><?=$shop_visible_count?> visible · <?=count($shop_items)?> total items</p></div>
      <div style="display:flex;gap:8px;">
        <a href="?page=shop_categories" class="btn-sm">
          <span class="material-symbols-outlined" style="font-size:15px;">category</span>Categories
        </a>
        <a href="?page=add_shop_item" class="btn-sm btn-primary">
          <span class="material-symbols-outlined" style="font-size:15px;">add</span>Add Item
        </a>
      </div>
    </div>

    <?php if(empty($shop_items)): ?>
      <div class="empty-state"><span class="material-symbols-outlined">storefront</span><p>No items in inventory yet. Items appear here once staff creates pawn tickets.</p></div>
    <?php else: ?>
    <div class="card" style="overflow-x:auto;">
      <table>
        <thead><tr><th>Photo</th><th>Item</th><th>Category</th><th>Appraisal</th><th>Display Price</th><th>Stock</th><th>Featured</th><th>Visible</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach($shop_items as $item): ?>
        <tr>
          <td>
            <?php if(!empty($item['item_photo_path'])): ?>
              <img src="<?=htmlspecialchars($item['item_photo_path'])?>" style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid rgba(255,255,255,.1);">
            <?php else: ?>
              <div style="width:44px;height:44px;border-radius:8px;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;">
                <span class="material-symbols-outlined" style="font-size:20px;color:rgba(255,255,255,.2);">image</span>
              </div>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-weight:600;color:#fff;font-size:.83rem;"><?=htmlspecialchars($item['item_name']??$item['ticket_no']??'—')?></div>
            <div style="font-size:.7rem;color:rgba(255,255,255,.3);font-family:monospace;"><?=htmlspecialchars($item['ticket_no']??'')?></div>
          </td>
          <td>
            <?php if($item['cat_name']): ?>
              <span class="badge b-blue"><?=htmlspecialchars($item['cat_name'])?></span>
            <?php else: ?>
              <span style="font-size:.75rem;color:rgba(255,255,255,.3);"><?=htmlspecialchars($item['item_category']??'—')?></span>
            <?php endif; ?>
          </td>
          <td style="font-size:.82rem;">₱<?=number_format($item['appraisal_value'],2)?></td>
          <td style="font-weight:700;color:#6ee7b7;">
            <?= $item['display_price'] > 0 ? '₱'.number_format($item['display_price'],2) : '<span style="color:rgba(255,255,255,.25);">not set</span>' ?>
          </td>
          <td style="font-size:.82rem;"><?=$item['stock_qty']?></td>
          <td>
            <?php if($item['is_featured']): ?>
              <span class="badge b-yellow"><span class="b-dot"></span>Yes</span>
            <?php else: ?>
              <span style="color:rgba(255,255,255,.2);font-size:.75rem;">—</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="toggle_shop_visible">
              <input type="hidden" name="item_id" value="<?=$item['id']?>">
              <input type="hidden" name="new_visible" value="<?=$item['is_shop_visible']?0:1?>">
              <button type="submit" class="btn-sm <?=$item['is_shop_visible']?'btn-success':'b-gray'?>" style="font-size:.7rem;<?=!$item['is_shop_visible']?'background:rgba(255,255,255,.06);color:rgba(255,255,255,.4);':''?>">
                <?=$item['is_shop_visible']?'● Visible':'○ Hidden'?>
              </button>
            </form>
          </td>
          <td>
            <button onclick="openShopEdit(<?=htmlspecialchars(json_encode($item))?>, <?=htmlspecialchars(json_encode($shop_categories_list))?>)" class="btn-sm" style="font-size:.7rem;">
              <span class="material-symbols-outlined" style="font-size:13px;">edit</span>Edit
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>


  <?php elseif($active_page==='add_shop_item'): ?>
    <div class="page-hdr">
      <div><h2>Add Shop Item</h2><p>Add a new item directly to the mobile shop</p></div>
      <a href="?page=shop_items" class="btn-sm">
        <span class="material-symbols-outlined" style="font-size:15px;">arrow_back</span>Back to Shop Items
      </a>
    </div>
    <div style="max-width:700px;">
      <div class="card">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="add_shop_item">
          <div class="card-title">📦 Item Details</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">

            <div class="fgroup" style="grid-column:1/-1;">
              <label class="flabel">Item Name *</label>
              <input type="text" name="item_name" class="finput" placeholder="e.g. Gold Ring 18k, iPhone 14 Pro" required>
            </div>

            <div class="fgroup" style="grid-column:1/-1;position:relative;">
              <label class="flabel">Category *</label>
              <input type="text" name="item_category" id="cat_input" class="finput"
                placeholder="e.g. Gadget, Jewelry, Gold, Watch..."
                autocomplete="off"
                oninput="filterCatSuggestions(this.value)"
                onfocus="showCatSuggestions()"
                onblur="setTimeout(()=>document.getElementById('cat_suggestions').style.display='none',200)"
                required>
              <input type="hidden" name="category_id" id="cat_id_hidden" value="">
              <div id="cat_suggestions" style="display:none;position:absolute;z-index:99;background:#0a150e;border:1px solid rgba(255,255,255,.15);border-radius:10px;margin-top:4px;width:100%;max-height:200px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.5);">
                <?php
                $default_cats = ['Gadget','Jewelry','Gold','Silver','Watch','Laptop','Phone','Camera','Appliance','Bag','Clothing','Others'];
                $all_cat_names = array_merge(
                  array_column($shop_categories_list, 'name'),
                  array_diff($default_cats, array_column($shop_categories_list, 'name'))
                );
                foreach($all_cat_names as $cn):
                  $cat_id = '';
                  foreach($shop_categories_list as $sc) { if($sc['name']==$cn) { $cat_id=$sc['id']; break; } }
                ?>
                <div class="cat-opt" data-name="<?=htmlspecialchars($cn)?>" data-id="<?=$cat_id?>"
                  onclick="selectCat('<?=htmlspecialchars(addslashes($cn))?>','<?=$cat_id?>')"
                  style="padding:9px 14px;cursor:pointer;font-size:.83rem;color:rgba(255,255,255,.8);display:flex;align-items:center;gap:8px;border-bottom:1px solid rgba(255,255,255,.04);">
                  <?php if($cat_id): ?><span style="font-size:.65rem;background:rgba(5,150,105,.2);color:#6ee7b7;padding:1px 7px;border-radius:100px;">saved</span><?php endif; ?>
                  <?=htmlspecialchars($cn)?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="fgroup">
              <label class="flabel">Display Price (₱) *</label>
              <input type="number" name="display_price" class="finput" placeholder="0.00" step="0.01" min="0.01" required>
            </div>

            <div class="fgroup">
              <label class="flabel">Stock Quantity</label>
              <input type="number" name="stock_qty" class="finput" value="1" min="1">
            </div>

            <div class="fgroup" style="grid-column:1/-1;">
              <label class="flabel">Condition</label>
              <select name="condition_notes" class="finput">
                <option value="Excellent">Excellent</option>
                <option value="Good">Good</option>
                <option value="Fair">Fair</option>
                <option value="Poor">Poor</option>
              </select>
            </div>

            <div class="fgroup" style="grid-column:1/-1;">
              <label class="flabel">Item Photo *</label>
              <input type="file" name="item_photo" class="finput" accept="image/jpeg,image/png,image/webp" style="padding:10px;" onchange="previewPhoto(this)">
              <div style="margin-top:10px;">
                <img id="photo_preview" src="" style="display:none;max-height:160px;border-radius:10px;border:1px solid rgba(255,255,255,.1);object-fit:cover;">
              </div>
              <div style="font-size:.71rem;color:rgba(255,255,255,.25);margin-top:5px;">JPG, PNG, or WEBP · Max 5MB</div>
            </div>

            <div class="fgroup" style="grid-column:1/-1;display:flex;gap:24px;">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.83rem;color:rgba(255,255,255,.6);">
                <input type="checkbox" name="is_featured" value="1" style="width:16px;height:16px;accent-color:#f59e0b;">
                ⭐ Mark as Featured
              </label>
            </div>

          </div>

          <div style="background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.15);border-radius:10px;padding:11px 14px;font-size:.76rem;color:rgba(110,231,183,.75);margin:14px 0;line-height:1.7;">
            💡 Items added here will be <strong>immediately visible</strong> in the mobile shop. Make sure to set the correct price and upload a clear photo.
          </div>

          <div style="display:flex;justify-content:flex-end;gap:9px;margin-top:6px;">
            <a href="?page=shop_items" class="btn-sm">Cancel</a>
            <button type="submit" class="btn-sm btn-primary" style="padding:9px 22px;">
              <span class="material-symbols-outlined" style="font-size:15px;">add_shopping_cart</span>Add to Shop
            </button>
          </div>
        </form>
      </div>
    </div>

  <?php elseif($active_page==='shop_categories'): ?>
    <div class="page-hdr">
      <div><h2>Shop Categories</h2><p><?=count($shop_categories_list)?> categories</p></div>
      <button onclick="openAddCat()" class="btn-sm btn-primary">
        <span class="material-symbols-outlined" style="font-size:15px;">add</span>Add Category
      </button>
    </div>

    <?php if(empty($shop_categories_list)): ?>
      <div class="empty-state"><span class="material-symbols-outlined">category</span><p>No categories yet. Add one to organize your shop items.</p></div>
    <?php else: ?>
    <div class="card" style="overflow-x:auto;">
      <table>
        <thead><tr><th>Name</th><th>Icon</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach($shop_categories_list as $cat): ?>
        <tr>
          <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($cat['name'])?></td>
          <td style="font-family:monospace;font-size:.75rem;color:rgba(255,255,255,.4);"><?=htmlspecialchars($cat['icon']??'—')?></td>
          <td><span class="badge <?=$cat['is_active']?'b-green':'b-gray'?>"><?=$cat['is_active']?'Active':'Inactive'?></span></td>
          <td style="font-size:.72rem;color:rgba(255,255,255,.35);"><?=date('M d, Y',strtotime($cat['created_at']))?></td>
          <td style="display:flex;gap:6px;">
            <button class="btn-sm btn-edit-cat" style="font-size:.7rem;"
              data-id="<?=$cat['id']?>"
              data-name="<?=htmlspecialchars($cat['name'],ENT_QUOTES)?>"
              data-icon="<?=htmlspecialchars($cat['icon']??'',ENT_QUOTES)?>">
              <span class="material-symbols-outlined" style="font-size:13px;">edit</span>Edit
            </button>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this category?')">
              <input type="hidden" name="action" value="delete_category">
              <input type="hidden" name="cat_id" value="<?=$cat['id']?>">
              <button type="submit" class="btn-sm btn-danger" style="font-size:.7rem;">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  <?php elseif($active_page==='export' && $mgr_can_export): ?>
    <?php
      $exp_type = $_GET['exp_type'] ?? 'tickets';
      $exp_from = $_GET['exp_from'] ?? date('Y-m-01');
      $exp_to   = $_GET['exp_to']   ?? date('Y-m-d');
      $valid_exp_types = ['tickets','customers','inventory','audit','payments'];
      if (!in_array($exp_type, $valid_exp_types)) $exp_type = 'tickets';
      $exp_rows=[]; $exp_cols=[]; $exp_title='';
      $exp_primary   = $theme['primary_color']   ?? '#059669';
      $exp_secondary = $theme['secondary_color'] ?? '#064e3b';
      try {
        switch($exp_type) {
          case 'tickets':
            $exp_title='Pawn Tickets'; $exp_cols=['Ticket No.','Customer','Contact','Category','Description','Loan Amount','Total Redeem','Maturity Date','Status','Date'];
            $s=$pdo->prepare("SELECT ticket_no,customer_name,contact_number,item_category,item_description,loan_amount,total_redeem,maturity_date,status,created_at FROM pawn_transactions WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
            $s->execute([$tid,$exp_from,$exp_to]); $exp_rows=$s->fetchAll(); break;
          case 'customers':
            $exp_title='Customer Records'; $exp_cols=['Full Name','Contact','Email','Gender','Address','ID Type','ID Number','Registered'];
            $s=$pdo->prepare("SELECT full_name,contact_number,email,gender,address,valid_id_type,valid_id_number,created_at FROM customers WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY full_name");
            $s->execute([$tid,$exp_from,$exp_to]); $exp_rows=$s->fetchAll(); break;
          case 'inventory':
            $exp_title='Item Inventory'; $exp_cols=['Ticket No.','Item','Category','Serial No.','Condition','Appraisal','Loan Amount','Status','Date'];
            $s=$pdo->prepare("SELECT ticket_no,item_name,item_category,serial_no,condition_notes,appraisal_value,loan_amount,status,created_at FROM item_inventory WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
            $s->execute([$tid,$exp_from,$exp_to]); $exp_rows=$s->fetchAll(); break;
          case 'audit':
            $exp_title='Audit Logs'; $exp_cols=['Date & Time','Actor','Role','Action','Ref #','Message'];
            $s=$pdo->prepare("SELECT created_at,actor_username,actor_role,action,entity_id,message FROM audit_logs WHERE tenant_id=? AND actor_role IN ('manager','staff','cashier') AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
            $s->execute([$tid,$exp_from,$exp_to]); $exp_rows=$s->fetchAll(); break;
          case 'payments':
            $exp_title='Payment History'; $exp_cols=['Date','Ticket No.','Action','OR No.','Amount Due','Cash Received','Change','Staff'];
            $s=$pdo->prepare("SELECT created_at,ticket_no,action,or_no,amount_due,cash_received,change_amount,staff_username FROM payment_transactions WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
            $s->execute([$tid,$exp_from,$exp_to]); $exp_rows=$s->fetchAll(); break;
        }
      } catch(Throwable $e) { $error_msg='Export error: '.$e->getMessage(); }
    ?>
    <style>@media print{.sidebar,.topbar,.content>.alert,.export-controls{display:none!important;}.main{margin-left:0!important;}.export-doc{box-shadow:none!important;border-radius:0!important;}}</style>
    <div class="export-controls" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px;">
      <form method="GET" id="exp-form" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1;">
        <input type="hidden" name="page" value="export">
        <div><div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.35);margin-bottom:4px;">Report Type</div>
        <select name="exp_type" class="finput" style="width:auto;padding:7px 12px;" onchange="document.getElementById('exp-form').submit()">
          <option value="tickets"   <?=$exp_type==='tickets'  ?'selected':''?>>📋 Pawn Tickets</option>
          <option value="customers" <?=$exp_type==='customers'?'selected':''?>>👥 Customers</option>
          <option value="inventory" <?=$exp_type==='inventory'?'selected':''?>>📦 Inventory</option>
          <option value="audit"     <?=$exp_type==='audit'    ?'selected':''?>>🔍 Audit Logs</option>
          <option value="payments"  <?=$exp_type==='payments' ?'selected':''?>>💳 Payment History</option>
        </select></div>
        <div><div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.35);margin-bottom:4px;">Date From</div>
        <input type="date" name="exp_from" class="finput" style="width:auto;padding:7px 12px;" value="<?=htmlspecialchars($exp_from)?>" onchange="document.getElementById('exp-form').submit()"></div>
        <div><div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.35);margin-bottom:4px;">Date To</div>
        <input type="date" name="exp_to" class="finput" style="width:auto;padding:7px 12px;" value="<?=htmlspecialchars($exp_to)?>" onchange="document.getElementById('exp-form').submit()"></div>
      </form>
      <button onclick="window.print()" style="padding:10px 22px;background:linear-gradient(135deg,<?=$exp_secondary?>,<?=$exp_primary?>);color:#fff;border:none;border-radius:10px;font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:7px;font-family:inherit;white-space:nowrap;">
        <span class="material-symbols-outlined" style="font-size:17px;">print</span>Print / Save as PDF
      </button>
    </div>
    <div class="export-doc card" style="padding:0;overflow:hidden;">
      <div style="background:linear-gradient(135deg,<?=$exp_secondary?>,<?=$exp_primary?>);padding:24px 28px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-size:1.1rem;font-weight:800;color:#fff;"><?=htmlspecialchars($tenant['business_name']??'Branch')?></div>
          <div style="font-size:.72rem;color:rgba(255,255,255,.6);margin-top:3px;">PawnHub — Branch Report</div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:1.3rem;font-weight:800;color:#fff;"><?=htmlspecialchars($exp_title)?></div>
          <div style="font-size:.72rem;color:rgba(255,255,255,.6);margin-top:3px;">📅 <?=date('M d, Y',strtotime($exp_from))?> — <?=date('M d, Y',strtotime($exp_to))?></div>
          <div style="font-size:.67rem;color:rgba(255,255,255,.4);margin-top:2px;">Generated: <?=date('F j, Y g:i A')?></div>
        </div>
      </div>
      <div style="padding:10px 24px;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.06);display:flex;gap:20px;flex-wrap:wrap;">
        <span style="font-size:.74rem;color:rgba(255,255,255,.4);">Total Records: <strong style="color:#fff;"><?=count($exp_rows)?></strong></span>
        <span style="font-size:.74rem;color:rgba(255,255,255,.4);">Prepared by: <strong style="color:#fff;"><?=htmlspecialchars($u['name'])?></strong> (Manager)</span>
      </div>
      <div style="padding:16px 20px;overflow-x:auto;">
        <?php if(empty($exp_rows)):?>
        <div style="text-align:center;padding:40px;color:rgba(255,255,255,.3);"><span class="material-symbols-outlined" style="font-size:48px;display:block;margin-bottom:12px;">inbox</span><p>No records found for the selected period.</p></div>
        <?php else:?>
        <table><thead><tr><?php foreach($exp_cols as $c):?><th><?=htmlspecialchars($c)?></th><?php endforeach;?></tr></thead><tbody>
        <?php foreach($exp_rows as $row): $vals=array_values($row); ?>
        <tr><?php foreach($vals as $i=>$val):
          $col=strtolower($exp_cols[$i]??'');
          if(str_contains($col,'ticket no')): echo '<td><span class="ticket-tag">'.htmlspecialchars($val??'—').'</span></td>';
          elseif(str_contains($col,'status')): $sc=['stored'=>'b-blue','released'=>'b-green','renewed'=>'b-yellow','voided'=>'b-red','pawned'=>'b-blue','redeemed'=>'b-green']; echo '<td><span class="badge '.($sc[strtolower($val??'')] ?? 'b-gray').'">'.htmlspecialchars($val??'—').'</span></td>';
          elseif(str_contains($col,'amount')||str_contains($col,'loan')||str_contains($col,'redeem')||str_contains($col,'cash')||str_contains($col,'change')||str_contains($col,'appraisal')): echo '<td>₱'.number_format((float)($val??0),2).'</td>';
          elseif(str_contains($col,'date')||str_contains($col,'registered')||str_contains($col,'time')||str_contains($col,'at')): echo '<td style="font-size:.73rem;color:rgba(255,255,255,.4);">'.($val ? date(str_contains($col,'time')?'M d, Y h:i A':'M d, Y',strtotime($val)) : '—').'</td>';
          else: echo '<td>'.htmlspecialchars($val??'—').'</td>';
          endif;
        endforeach;?></tr>
        <?php endforeach;?></tbody></table>
        <?php endif;?>
      </div>
      <div style="padding:12px 24px;border-top:1px solid rgba(255,255,255,.06);display:flex;justify-content:space-between;font-size:.69rem;color:rgba(255,255,255,.25);">
        <span>© <?=date('Y')?> <?=htmlspecialchars($tenant['business_name']??'Branch')?> · Powered by PawnHub</span>
        <span><?=count($exp_rows)?> records · <?=date('F j, Y g:i A')?></span>
      </div>
    </div>


  <?php elseif($active_page==='promos'): ?>
    <div class="page-hdr">
      <div>
        <h2>Promos &amp; Announcements</h2>
        <p><?=count($mgr_promos)?> total · <?=$active_promos_count?> active — visible on your public shop page</p>
      </div>
      <button onclick="openPromoModal()" class="btn-sm btn-primary">
        <span class="material-symbols-outlined" style="font-size:15px;">add</span>New Promo / Announcement
      </button>
    </div>

    <!-- Stats row -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px;">
      <?php
        $cnt_ann  = count(array_filter($mgr_promos, fn($p)=>($p['type']??'announcement')==='announcement'));
        $cnt_promo= count(array_filter($mgr_promos, fn($p)=>($p['type']??'')==='promo'));
        $cnt_sale = count(array_filter($mgr_promos, fn($p)=>($p['type']??'')==='sale'));
        $cnt_warn = count(array_filter($mgr_promos, fn($p)=>($p['type']??'')==='warning'));
      ?>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(16,185,129,.12);"><span class="material-symbols-outlined" style="color:#6ee7b7;font-size:18px;">campaign</span></div></div><div class="stat-value"><?=$active_promos_count?></div><div class="stat-label">Active</div></div>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(99,102,241,.12);"><span class="material-symbols-outlined" style="color:#a5b4fc;font-size:18px;">notifications</span></div></div><div class="stat-value"><?=$cnt_ann?></div><div class="stat-label">Announcements</div></div>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(59,130,246,.12);"><span class="material-symbols-outlined" style="color:#93c5fd;font-size:18px;">local_offer</span></div></div><div class="stat-value"><?=$cnt_promo?></div><div class="stat-label">Promos</div></div>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(245,158,11,.12);"><span class="material-symbols-outlined" style="color:#fcd34d;font-size:18px;">sell</span></div></div><div class="stat-value"><?=$cnt_sale?></div><div class="stat-label">Sales</div></div>
    </div>

    <?php if(empty($mgr_promos)): ?>
    <div class="card" style="text-align:center;padding:56px 24px;">
      <span class="material-symbols-outlined" style="font-size:52px;color:rgba(255,255,255,.1);display:block;margin-bottom:14px;">campaign</span>
      <div style="font-size:1rem;font-weight:700;color:rgba(255,255,255,.5);margin-bottom:8px;">No promos yet</div>
      <p style="font-size:.82rem;color:rgba(255,255,255,.25);margin-bottom:20px;">Promos and announcements you create here will appear on your public shop page.</p>
      <button onclick="openPromoModal()" class="btn-sm btn-primary">
        <span class="material-symbols-outlined" style="font-size:15px;">add</span>Create First Promo
      </button>
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,320px),1fr));gap:14px;">
    <?php foreach($mgr_promos as $promo):
      $type_color = match($promo['type']??'announcement') {
        'promo'   => 'rgba(59,130,246,.9)',
        'sale'    => 'rgba(245,158,11,.9)',
        'warning' => 'rgba(239,68,68,.9)',
        default   => 'var(--g)',
      };
      $type_icon = match($promo['type']??'announcement') {
        'promo'   => 'local_offer',
        'sale'    => 'sell',
        'warning' => 'warning',
        default   => 'campaign',
      };
      $type_label = match($promo['type']??'announcement') {
        'promo'   => 'Promo',
        'sale'    => 'Sale',
        'warning' => 'Notice',
        default   => 'Announcement',
      };
      $is_active = (int)($promo['is_active'] ?? 0);
      $is_pinned = (int)($promo['is_pinned'] ?? 0);

      // Check if expired
      $is_expired = false;
      if (!empty($promo['end_date']) && strtotime($promo['end_date']) < strtotime('today')) {
        $is_expired = true;
      }
    ?>
    <div style="background:rgba(255,255,255,.04);border:1px solid <?= $is_active && !$is_expired ? 'rgba(255,255,255,.1)' : 'rgba(255,255,255,.05)' ?>;border-radius:16px;overflow:hidden;display:flex;flex-direction:column;<?= $is_pinned ? 'border-color:color-mix(in srgb,'.$type_color.' 50%,transparent);' : '' ?>opacity:<?= $is_active && !$is_expired ? '1' : '.55' ?>;">
      <?php
        $card_photo = $promo['image_url'] ?? '';
        $has_item   = !empty($promo['linked_item_id']);
        $item_photo = $promo['linked_item_photo'] ?? '';
        $item_name  = $promo['linked_item_name']  ?? '';
        $item_price = $promo['linked_item_price']  ?? null;
        $item_orig  = $promo['linked_item_orig_price'] ?? null;
      ?>
      <?php if($card_photo || ($has_item && $item_photo)): ?>
      <div style="position:relative;height:130px;overflow:hidden;flex-shrink:0;background:rgba(0,0,0,.3);">
        <?php if($card_photo): ?>
          <img src="<?=htmlspecialchars($card_photo)?>" alt="" style="width:100%;height:100%;object-fit:cover;opacity:.85;">
        <?php elseif($item_photo): ?>
          <img src="<?=htmlspecialchars($item_photo)?>" alt="<?=htmlspecialchars($item_name)?>" style="width:100%;height:100%;object-fit:cover;opacity:.85;">
        <?php endif; ?>
        <?php if($has_item && $item_photo && !$card_photo): ?>
          <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.7),transparent 55%);"></div>
          <div style="position:absolute;bottom:10px;left:12px;right:12px;display:flex;align-items:center;gap:8px;">
            <span class="material-symbols-outlined" style="font-size:14px;color:rgba(255,255,255,.6);font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">link</span>
            <span style="font-size:.72rem;font-weight:600;color:rgba(255,255,255,.8);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($item_name)?></span>
          </div>
        <?php endif; ?>
      </div>
      <?php elseif($has_item): ?>
      <div style="height:56px;background:rgba(255,255,255,.03);display:flex;align-items:center;gap:10px;padding:0 16px;border-bottom:1px solid rgba(255,255,255,.05);">
        <div style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <span class="material-symbols-outlined" style="font-size:18px;color:rgba(255,255,255,.2);">diamond</span>
        </div>
        <div>
          <div style="font-size:.68rem;color:rgba(255,255,255,.3);font-weight:600;">Linked Item</div>
          <div style="font-size:.8rem;font-weight:700;color:rgba(255,255,255,.7);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;"><?=htmlspecialchars($item_name)?></div>
        </div>
      </div>
      <?php endif; ?>
      <div style="padding:16px 18px;flex:1;display:flex;flex-direction:column;gap:9px;">
        <!-- Badges row -->
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
          <span style="display:inline-flex;align-items:center;gap:4px;font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:2px 9px;border-radius:100px;background:color-mix(in srgb,<?=htmlspecialchars($type_color)?> 18%,transparent);color:<?=htmlspecialchars($type_color)?>;border:1px solid color-mix(in srgb,<?=htmlspecialchars($type_color)?> 35%,transparent);">
            <span class="material-symbols-outlined" style="font-size:11px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;"><?=$type_icon?></span>
            <?=$type_label?>
          </span>
          <?php if($is_pinned): ?>
          <span style="font-size:.6rem;font-weight:700;color:rgba(255,255,255,.3);display:inline-flex;align-items:center;gap:3px;">
            <span class="material-symbols-outlined" style="font-size:11px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">push_pin</span>Pinned
          </span>
          <?php endif; ?>
          <?php if($is_expired): ?>
          <span style="font-size:.6rem;font-weight:700;color:#fca5a5;background:rgba(239,68,68,.1);padding:2px 7px;border-radius:100px;border:1px solid rgba(239,68,68,.2);">Expired</span>
          <?php elseif(!$is_active): ?>
          <span style="font-size:.6rem;font-weight:700;color:rgba(255,255,255,.3);background:rgba(255,255,255,.06);padding:2px 7px;border-radius:100px;">Inactive</span>
          <?php else: ?>
          <span style="font-size:.6rem;font-weight:700;color:#6ee7b7;background:rgba(16,185,129,.12);padding:2px 7px;border-radius:100px;border:1px solid rgba(16,185,129,.2);">Live</span>
          <?php endif; ?>
        </div>
        <!-- Title -->
        <div style="font-size:.95rem;font-weight:700;color:#fff;line-height:1.3;"><?=htmlspecialchars($promo['title'])?></div>
        <!-- Sale price display -->
        <?php if($has_item && !empty($promo['discount_pct']) && (float)$promo['discount_pct'] > 0): ?>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span style="font-size:1rem;font-weight:800;color:#fcd34d;">₱<?=number_format((float)$item_price,2)?></span>
          <?php if($item_orig): ?>
          <span style="font-size:.78rem;color:rgba(255,255,255,.3);text-decoration:line-through;">₱<?=number_format((float)$item_orig,2)?></span>
          <?php endif; ?>
          <span style="font-size:.65rem;font-weight:800;background:rgba(245,158,11,.2);color:#fcd34d;border:1px solid rgba(245,158,11,.3);padding:2px 8px;border-radius:100px;"><?=(float)$promo['discount_pct']?>% OFF</span>
        </div>
        <?php endif; ?>
        <!-- Body preview -->
        <?php if(!empty($promo['body'])): ?>
        <div style="font-size:.79rem;color:rgba(255,255,255,.45);line-height:1.55;flex:1;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;"><?=htmlspecialchars($promo['body'])?></div>
        <?php endif; ?>
        <!-- Date range -->
        <?php if(!empty($promo['start_date']) || !empty($promo['end_date'])): ?>
        <div style="font-size:.7rem;color:rgba(255,255,255,.25);display:flex;align-items:center;gap:4px;">
          <span class="material-symbols-outlined" style="font-size:13px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">event</span>
          <?php
            $from = !empty($promo['start_date']) ? date('M d, Y', strtotime($promo['start_date'])) : null;
            $to   = !empty($promo['end_date'])   ? date('M d, Y', strtotime($promo['end_date'])) : null;
            if($from && $to) echo htmlspecialchars("$from – $to");
            elseif($from)    echo htmlspecialchars("From $from");
            elseif($to)      echo htmlspecialchars("Until $to");
          ?>
        </div>
        <?php endif; ?>
        <!-- Action row -->
        <div style="display:flex;align-items:center;gap:7px;padding-top:6px;border-top:1px solid rgba(255,255,255,.06);margin-top:auto;">
          <button onclick="openPromoModal(<?=htmlspecialchars(json_encode($promo),ENT_QUOTES)?>)" class="btn-sm" style="flex:1;justify-content:center;font-size:.72rem;">
            <span class="material-symbols-outlined" style="font-size:13px;">edit</span>Edit
          </button>
          <form method="POST" style="display:contents;">
            <input type="hidden" name="action" value="toggle_promo">
            <input type="hidden" name="promo_id" value="<?=(int)$promo['id']?>">
            <input type="hidden" name="new_state" value="<?=$is_active?0:1?>">
            <button type="submit" class="btn-sm" style="flex:1;justify-content:center;font-size:.72rem;<?=$is_active?'color:#fcd34d;':''?>">
              <span class="material-symbols-outlined" style="font-size:13px;"><?=$is_active?'visibility_off':'visibility'?></span><?=$is_active?'Deactivate':'Activate'?>
            </button>
          </form>
          <form method="POST" style="display:contents;" onsubmit="return confirm('Delete this promo?')">
            <input type="hidden" name="action" value="delete_promo">
            <input type="hidden" name="promo_id" value="<?=(int)$promo['id']?>">
            <button type="submit" class="btn-sm btn-danger" style="padding:6px 9px;">
              <span class="material-symbols-outlined" style="font-size:14px;">delete</span>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Info tip -->
    <div style="margin-top:18px;background:rgba(5,150,105,.07);border:1px solid rgba(5,150,105,.15);border-radius:12px;padding:13px 16px;font-size:.78rem;color:rgba(110,231,183,.65);display:flex;align-items:flex-start;gap:10px;">
      <span class="material-symbols-outlined" style="font-size:16px;flex-shrink:0;margin-top:1px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">info</span>
      <span>Active promos automatically appear on your public shop page under <strong style="color:#6ee7b7;">Promos &amp; Announcements</strong>. Inactive or expired promos are hidden from customers.</span>
    </div>

  <?php endif;?>
  </div>
</div>

<!-- INVITE MODAL -->
<div class="modal-overlay" id="inviteModal">
  <div class="modal">
    <div class="mhdr">
      <div class="mtitle">Invite Staff / Cashier</div>
      <button class="mclose" onclick="document.getElementById('inviteModal').classList.remove('open')">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="invite_staff">
        <div class="fgroup">
          <label class="flabel">Role *</label>
          <select name="role" class="finput" required>
            <option value="staff">Staff</option>
            <option value="cashier">Cashier</option>
          </select>
        </div>
        <div class="fgroup">
          <label class="flabel">Full Name *</label>
          <input type="text" name="name" class="finput" placeholder="Maria Santos" required>
        </div>
        <div class="fgroup">
          <label class="flabel">Email Address *</label>
          <input type="email" name="email" class="finput" placeholder="staff@example.com" required>
          <div style="font-size:.71rem;color:rgba(255,255,255,.25);margin-top:5px;">An invitation link will be sent to this email.</div>
        </div>
        <div style="background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.18);border-radius:10px;padding:11px 13px;font-size:.76rem;color:rgba(110,231,183,.8);margin-bottom:14px;line-height:1.6;">
          📧 They will receive a secure link to create their account credentials.
        </div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('inviteModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary">Send Invitation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
</style>
<script>
document.getElementById('inviteModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});
</script>

<!-- SHOP ITEM EDIT MODAL -->
<div class="modal-overlay" id="shopEditModal">
  <div class="modal" style="width:520px;">
    <div class="mhdr">
      <div class="mtitle">Edit Shop Item</div>
      <button class="mclose" onclick="document.getElementById('shopEditModal').classList.remove('open')">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="mbody">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_shop_item">
        <input type="hidden" name="item_id" id="edit_item_id">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="fgroup" style="grid-column:1/-1;">
            <label class="flabel">Item Name</label>
            <input type="text" id="edit_item_name" class="finput" readonly style="opacity:.5;cursor:not-allowed;">
          </div>
          <div class="fgroup">
            <label class="flabel">Display Price (₱) *</label>
            <input type="number" name="display_price" id="edit_display_price" class="finput" placeholder="0.00" step="0.01" min="0">
          </div>
          <div class="fgroup">
            <label class="flabel">Stock Qty</label>
            <input type="number" name="stock_qty" id="edit_stock_qty" class="finput" value="1" min="0">
          </div>
          <div class="fgroup" style="grid-column:1/-1;">
            <label class="flabel">Category</label>
            <select name="category_id" id="edit_category_id" class="finput">
              <option value="">— No category —</option>
            </select>
          </div>
          <div class="fgroup" style="grid-column:1/-1;">
            <label class="flabel">Item Photo</label>
            <input type="file" name="item_photo" class="finput" accept="image/jpeg,image/png,image/webp" style="padding:8px;">
            <div id="edit_current_photo" style="margin-top:8px;"></div>
          </div>
          <div class="fgroup">
            <label class="flabel" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" name="is_shop_visible" id="edit_is_visible" value="1" style="width:16px;height:16px;accent-color:#059669;">
              Visible in Shop
            </label>
          </div>
          <div class="fgroup">
            <label class="flabel" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" name="is_featured" id="edit_is_featured" value="1" style="width:16px;height:16px;accent-color:#f59e0b;">
              Featured Item
            </label>
          </div>
        </div>
        <div style="background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.15);border-radius:10px;padding:10px 13px;font-size:.75rem;color:rgba(110,231,183,.7);margin-bottom:14px;">
          💡 Set a Display Price and mark as Visible for it to appear in the mobile shop.
        </div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('shopEditModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ADD/EDIT CATEGORY MODAL -->
<div class="modal-overlay" id="addCatModal">
  <div class="modal" style="width:420px;">
    <div class="mhdr">
      <div class="mtitle" id="cat_modal_title">Add Category</div>
      <button class="mclose" onclick="document.getElementById('addCatModal').classList.remove('open')">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="save_category">
        <input type="hidden" name="cat_id" id="cat_id_field" value="0">
        <div class="fgroup">
          <label class="flabel">Category Name *</label>
          <input type="text" name="cat_name" id="cat_name_field" class="finput" placeholder="e.g. Jewelry, Electronics" required>
        </div>
        <div class="fgroup">
          <label class="flabel">Icon Name</label>
          <select name="cat_icon" id="cat_icon_field" class="finput">
            <option value="category">category (default)</option>
            <option value="diamond">diamond (Jewelry/Gold)</option>
            <option value="smartphone">smartphone (Phone)</option>
            <option value="laptop">laptop (Laptop/PC)</option>
            <option value="watch">watch (Watch)</option>
            <option value="photo_camera">photo_camera (Camera)</option>
            <option value="shopping_bag">shopping_bag (Bag)</option>
            <option value="checkroom">checkroom (Clothing)</option>
            <option value="videogame_asset">videogame_asset (Gaming)</option>
            <option value="headphones">headphones (Audio)</option>
            <option value="tv">tv (Appliance)</option>
          </select>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('addCatModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openShopEdit(item, categories) {
  document.getElementById('edit_item_id').value = item.id;
  document.getElementById('edit_item_name').value = item.item_name || item.ticket_no || '';
  document.getElementById('edit_display_price').value = item.display_price > 0 ? item.display_price : '';
  document.getElementById('edit_stock_qty').value = item.stock_qty || 1;
  document.getElementById('edit_is_visible').checked = parseInt(item.is_shop_visible) === 1;
  document.getElementById('edit_is_featured').checked = parseInt(item.is_featured) === 1;

  // Populate categories
  const sel = document.getElementById('edit_category_id');
  sel.innerHTML = '<option value="">— No category —</option>';
  categories.forEach(c => {
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name;
    if (parseInt(item.category_id) === parseInt(c.id)) opt.selected = true;
    sel.appendChild(opt);
  });

  // Show current photo
  const photoDiv = document.getElementById('edit_current_photo');
  if (item.item_photo_path) {
    photoDiv.innerHTML = '<img src="' + item.item_photo_path + '" style="height:60px;border-radius:8px;border:1px solid rgba(255,255,255,.1);" onerror="this.style.display=\'none\'">';
  } else {
    photoDiv.innerHTML = '<span style="font-size:.72rem;color:rgba(255,255,255,.25);">No photo uploaded yet.</span>';
  }

  document.getElementById('shopEditModal').classList.add('open');
}

function openAddCat() {
  document.getElementById('cat_modal_title').textContent = 'Add Category';
  document.getElementById('cat_id_field').value = '0';
  document.getElementById('cat_name_field').value = '';
  const sel = document.getElementById('cat_icon_field');
  sel.value = 'category';
  document.getElementById('addCatModal').classList.add('open');
}

function openEditCat(id, name, icon) {
  document.getElementById('cat_modal_title').textContent = 'Edit Category';
  document.getElementById('cat_id_field').value = id;
  document.getElementById('cat_name_field').value = name;
  const sel = document.getElementById('cat_icon_field');
  sel.value = icon || 'category';
  document.getElementById('addCatModal').classList.add('open');
}

// Delegate edit-cat button clicks (safe with any characters in name/icon)
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.btn-edit-cat');
  if (btn) {
    openEditCat(btn.dataset.id, btn.dataset.name, btn.dataset.icon);
  }
});

document.getElementById('shopEditModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});
document.getElementById('addCatModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});
</script>

<script>
function showCatSuggestions() {
  document.getElementById('cat_suggestions').style.display = 'block';
}
function filterCatSuggestions(val) {
  const opts = document.querySelectorAll('.cat-opt');
  const q = val.toLowerCase();
  let anyVisible = false;
  opts.forEach(o => {
    const match = o.dataset.name.toLowerCase().includes(q);
    o.style.display = match ? 'flex' : 'none';
    if (match) anyVisible = true;
  });
  document.getElementById('cat_suggestions').style.display = anyVisible || val === '' ? 'block' : 'none';
}
function selectCat(name, id) {
  document.getElementById('cat_input').value = name;
  document.getElementById('cat_id_hidden').value = id;
  document.getElementById('cat_suggestions').style.display = 'none';
}
</script>
<script>
function previewPhoto(input) {
  const preview = document.getElementById('photo_preview');
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      preview.src = e.target.result;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
  }
}
</script>

<!-- PROMO / ANNOUNCEMENT ADD-EDIT MODAL -->
<div class="modal-overlay" id="promoModal">
  <div class="modal" style="width:560px;max-height:92vh;overflow-y:auto;">
    <div class="mhdr" style="position:sticky;top:0;background:#070d0a;z-index:10;padding-bottom:14px;border-bottom:1px solid rgba(255,255,255,.07);">
      <div class="mtitle" id="promoModalTitle">New Promo / Announcement</div>
      <button class="mclose" onclick="closePromoModal()">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="mbody">
      <form method="POST" id="promoForm">
        <input type="hidden" name="action" value="save_promo">
        <input type="hidden" name="promo_id" id="promo_id_field" value="0">

        <div class="fgroup">
          <label class="flabel">Title *</label>
          <input type="text" name="promo_title" id="promo_title_field" class="finput" placeholder="e.g. Special Interest Rate This Month!" required maxlength="255">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="fgroup">
            <label class="flabel">Type</label>
            <select name="promo_type" id="promo_type_field" class="finput" onchange="onPromoTypeChange(this.value)">
              <option value="announcement">📢 Announcement</option>
              <option value="promo">🏷️ Promo</option>
              <option value="sale">🔖 Sale</option>
              <option value="warning">⚠️ Notice / Warning</option>
            </select>
          </div>
          <div class="fgroup" id="discount_pct_group" style="display:none;">
            <label class="flabel">Discount % <span style="color:rgba(255,255,255,.25);font-weight:400;">(optional)</span></label>
            <div style="position:relative;">
              <input type="number" name="discount_pct" id="promo_discount_field" class="finput" placeholder="e.g. 20" min="0" max="100" step="0.01" style="padding-right:36px;">
              <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:.8rem;color:rgba(255,255,255,.3);font-weight:700;">%</span>
            </div>
          </div>
        </div>

        <!-- Linked Item Picker -->
        <div class="fgroup" id="linked_item_group" style="display:none;">
          <label class="flabel">Link to Shop Item <span style="color:rgba(255,255,255,.25);font-weight:400;">(optional — shows item photo on promo card)</span></label>
          <input type="hidden" name="linked_item_id" id="linked_item_id_field" value="">
          <div style="position:relative;">
            <input type="text" id="item_search_input" class="finput" placeholder="Search item name…" autocomplete="off"
              oninput="filterItemDropdown(this.value)" onfocus="showItemDropdown()" style="padding-right:36px;">
            <span class="material-symbols-outlined" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:18px;color:rgba(255,255,255,.2);">search</span>
          </div>
          <!-- Item preview -->
          <div id="linked_item_preview" style="display:none;margin-top:8px;background:rgba(255,255,255,.04);border:1px solid rgba(5,150,105,.25);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;">
            <img id="linked_item_preview_img" src="" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover;border:1px solid rgba(255,255,255,.08);">
            <div style="flex:1;min-width:0;">
              <div id="linked_item_preview_name" style="font-size:.82rem;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
              <div id="linked_item_preview_price" style="font-size:.73rem;color:#6ee7b7;margin-top:2px;"></div>
            </div>
            <button type="button" onclick="clearLinkedItem()" style="background:none;border:none;cursor:pointer;color:rgba(255,255,255,.3);padding:4px;">
              <span class="material-symbols-outlined" style="font-size:16px;">close</span>
            </button>
          </div>
          <!-- Dropdown list -->
          <div id="item_dropdown" style="display:none;position:absolute;z-index:999;width:100%;max-width:460px;background:#0d1f14;border:1px solid rgba(5,150,105,.25);border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.6);overflow:hidden;max-height:220px;overflow-y:auto;margin-top:4px;">
            <?php foreach($shop_items as $si):
              if(!(int)$si['is_shop_visible']) continue; ?>
            <div class="item-opt" data-id="<?=(int)$si['id']?>"
              data-name="<?=htmlspecialchars($si['item_name']??'Item',ENT_QUOTES)?>"
              data-photo="<?=htmlspecialchars($si['item_photo_path']??'',ENT_QUOTES)?>"
              data-price="<?=number_format((float)$si['display_price'],2)?>"
              onclick="selectLinkedItem(this)"
              style="display:flex;align-items:center;gap:10px;padding:9px 14px;cursor:pointer;transition:background .15s;border-bottom:1px solid rgba(255,255,255,.04);">
              <?php if(!empty($si['item_photo_path'])): ?>
              <img src="<?=htmlspecialchars($si['item_photo_path'])?>" alt="" style="width:36px;height:36px;border-radius:8px;object-fit:cover;flex-shrink:0;">
              <?php else: ?>
              <div style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><span class="material-symbols-outlined" style="font-size:17px;color:rgba(255,255,255,.2);">diamond</span></div>
              <?php endif; ?>
              <div style="flex:1;min-width:0;">
                <div style="font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($si['item_name']??'Item')?></div>
                <div style="font-size:.71rem;color:rgba(255,255,255,.35);">₱<?=number_format((float)$si['display_price'],2)?><?php if($si['cat_name']): ?> · <?=htmlspecialchars($si['cat_name'])?><?php endif;?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <!-- Discount preview -->
          <div id="discount_preview" style="display:none;margin-top:6px;font-size:.75rem;color:#fcd34d;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:7px 11px;"></div>
        </div>

        <div class="fgroup">
          <label class="flabel">Body / Details</label>
          <textarea name="promo_body" id="promo_body_field" class="finput" rows="3" placeholder="Describe the promo, dates, conditions, etc." style="resize:vertical;"></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="fgroup">
            <label class="flabel">Start Date</label>
            <input type="date" name="start_date" id="promo_start_field" class="finput">
          </div>
          <div class="fgroup">
            <label class="flabel">End Date</label>
            <input type="date" name="end_date" id="promo_end_field" class="finput">
          </div>
        </div>

        <div class="fgroup">
          <label class="flabel">Banner Image URL <span style="color:rgba(255,255,255,.25);font-weight:400;">(optional — overrides item photo)</span></label>
          <input type="url" name="image_url" id="promo_img_field" class="finput" placeholder="https://...">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
          <label class="flabel" style="display:flex;align-items:center;gap:9px;cursor:pointer;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);border-radius:10px;padding:10px 13px;">
            <input type="checkbox" name="is_active" id="promo_active_field" value="1" checked style="width:16px;height:16px;accent-color:#059669;flex-shrink:0;">
            <span><span style="color:#fff;font-size:.82rem;font-weight:600;">Active</span><br><span style="font-size:.68rem;color:rgba(255,255,255,.3);font-weight:400;">Visible to customers</span></span>
          </label>
          <label class="flabel" style="display:flex;align-items:center;gap:9px;cursor:pointer;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);border-radius:10px;padding:10px 13px;">
            <input type="checkbox" name="is_pinned" id="promo_pinned_field" value="1" style="width:16px;height:16px;accent-color:#f59e0b;flex-shrink:0;">
            <span><span style="color:#fff;font-size:.82rem;font-weight:600;">📌 Pin to Top</span><br><span style="font-size:.68rem;color:rgba(255,255,255,.3);font-weight:400;">Show first on the page</span></span>
          </label>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="closePromoModal()">Cancel</button>
          <button type="submit" class="btn-sm btn-primary" id="promoSubmitBtn">
            <span class="material-symbols-outlined" style="font-size:15px;">save</span>Save
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ── Promo Modal ──────────────────────────────────────────────
function onPromoTypeChange(val) {
  const needsItem = (val === 'promo' || val === 'sale');
  document.getElementById('linked_item_group').style.display = needsItem ? 'block' : 'none';
  document.getElementById('discount_pct_group').style.display = needsItem ? 'block' : 'none';
}

function openPromoModal(promo) {
  const modal = document.getElementById('promoModal');
  const title = document.getElementById('promoModalTitle');

  if (promo) {
    title.textContent = 'Edit Promo / Announcement';
    document.getElementById('promo_id_field').value      = promo.id || 0;
    document.getElementById('promo_title_field').value   = promo.title || '';
    document.getElementById('promo_type_field').value    = promo.type || 'announcement';
    document.getElementById('promo_body_field').value    = promo.body || '';
    document.getElementById('promo_start_field').value   = (promo.start_date || '').substring(0,10);
    document.getElementById('promo_end_field').value     = (promo.end_date   || '').substring(0,10);
    document.getElementById('promo_img_field').value     = promo.image_url || '';
    document.getElementById('promo_active_field').checked = parseInt(promo.is_active) === 1;
    document.getElementById('promo_pinned_field').checked = parseInt(promo.is_pinned) === 1;
    document.getElementById('promo_discount_field').value = promo.discount_pct > 0 ? promo.discount_pct : '';
    // Restore linked item
    if (promo.linked_item_id) {
      document.getElementById('linked_item_id_field').value = promo.linked_item_id;
      document.getElementById('item_search_input').value = promo.linked_item_name || '';
      showLinkedItemPreview(
        promo.linked_item_photo || '',
        promo.linked_item_name  || 'Item',
        promo.linked_item_price  || '',
        promo.linked_item_orig_price || ''
      );
    } else {
      clearLinkedItem();
    }
    onPromoTypeChange(promo.type || 'announcement');
  } else {
    title.textContent = 'New Promo / Announcement';
    document.getElementById('promoForm').reset();
    document.getElementById('promo_id_field').value = 0;
    document.getElementById('promo_active_field').checked = true;
    clearLinkedItem();
    onPromoTypeChange('announcement');
  }

  document.getElementById('item_dropdown').style.display = 'none';
  modal.classList.add('open');
}

function closePromoModal() {
  document.getElementById('promoModal').classList.remove('open');
  document.getElementById('item_dropdown').style.display = 'none';
}

document.getElementById('promoModal').addEventListener('click', function(e) {
  if (e.target === this) closePromoModal();
});

// ── Item search dropdown ──────────────────────────────────────
function showItemDropdown() {
  filterItemDropdown(document.getElementById('item_search_input').value);
}

function filterItemDropdown(q) {
  const dd = document.getElementById('item_dropdown');
  const opts = dd.querySelectorAll('.item-opt');
  const search = q.toLowerCase().trim();
  let any = false;
  opts.forEach(o => {
    const name = o.dataset.name.toLowerCase();
    const show = !search || name.includes(search);
    o.style.display = show ? 'flex' : 'none';
    if (show) any = true;
  });
  dd.style.display = any ? 'block' : 'none';
}

function showLinkedItemPreview(photo, name, price, origPrice) {
  const wrap = document.getElementById('linked_item_preview');
  document.getElementById('linked_item_preview_img').src = photo || '';
  document.getElementById('linked_item_preview_img').style.display = photo ? 'block' : 'none';
  document.getElementById('linked_item_preview_name').textContent = name;
  document.getElementById('linked_item_preview_price').textContent = price ? '₱' + price : '';
  wrap.style.display = 'flex';
  updateDiscountPreview();
}

function selectLinkedItem(el) {
  document.getElementById('linked_item_id_field').value = el.dataset.id;
  document.getElementById('item_search_input').value    = el.dataset.name;
  document.getElementById('item_dropdown').style.display = 'none';
  showLinkedItemPreview(el.dataset.photo, el.dataset.name, el.dataset.price, '');
}

function clearLinkedItem() {
  document.getElementById('linked_item_id_field').value = '';
  document.getElementById('item_search_input').value    = '';
  document.getElementById('linked_item_preview').style.display = 'none';
  document.getElementById('discount_preview').style.display    = 'none';
  document.getElementById('item_dropdown').style.display       = 'none';
}

function updateDiscountPreview() {
  const pct   = parseFloat(document.getElementById('promo_discount_field').value) || 0;
  const priceEl = document.getElementById('linked_item_preview_price');
  const preview = document.getElementById('discount_preview');
  if (!pct || pct <= 0 || pct > 100) { preview.style.display = 'none'; return; }
  const priceText = priceEl.textContent.replace('₱','').replace(/,/g,'');
  const orig = parseFloat(priceText);
  if (!orig) { preview.style.display = 'none'; return; }
  const disc = (orig * (1 - pct/100)).toFixed(2);
  preview.textContent = `💸 Sale price: ₱${parseFloat(disc).toLocaleString('en-PH',{minimumFractionDigits:2})} (${pct}% off ₱${orig.toLocaleString('en-PH',{minimumFractionDigits:2})})`;
  preview.style.display = 'block';
}

document.getElementById('promo_discount_field').addEventListener('input', updateDiscountPreview);

// Close dropdown on outside click
document.addEventListener('click', function(e) {
  if (!e.target.closest('#linked_item_group')) {
    document.getElementById('item_dropdown').style.display = 'none';
  }
});
// Hover effect for item options
document.getElementById('item_dropdown').addEventListener('mouseover', e => {
  const opt = e.target.closest('.item-opt');
  if (opt) opt.style.background = 'rgba(5,150,105,.12)';
});
document.getElementById('item_dropdown').addEventListener('mouseout', e => {
  const opt = e.target.closest('.item-opt');
  if (opt) opt.style.background = '';
});
</script>

<!-- LOGOUT CONFIRMATION MODAL -->
<div id="logoutModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:16px;">
  <div style="background:#1a1d26;border:1px solid rgba(255,255,255,.1);border-radius:20px;width:100%;max-width:380px;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.6);animation:logoutIn .22s ease both;">
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
<style>
@keyframes logoutIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.sb-logout{background:none;border:none;cursor:pointer;font-family:inherit;width:100%;text-align:left;}
</style>
<script>
function toggleNotifPanel(e){
  e.stopPropagation();
  document.getElementById('notifPanel').classList.toggle('open');
}
document.addEventListener('click',function(){
  document.getElementById('notifPanel')?.classList.remove('open');
});
function showLogoutModal(url){
  document.getElementById('logoutConfirmBtn').href=url;
  document.getElementById('logoutModal').style.display='flex';
}
function hideLogoutModal(){
  document.getElementById('logoutModal').style.display='none';
}
document.getElementById('logoutModal').addEventListener('click',function(e){if(e.target===this)hideLogoutModal();});
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