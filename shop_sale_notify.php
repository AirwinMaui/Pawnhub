<?php
/**
 * shop_sale_notify.php
 * ─────────────────────────────────────────────────────────────
 * Helper: Send in-app notifications to Tenant (admin) and
 * Manager when a shop item is sold / stock is reduced.
 *
 * HOW TO USE — call this right after you deduct stock / insert
 * a shop_orders row in your shop sale handler:
 *
 *   require_once __DIR__ . '/shop_sale_notify.php';
 *   notify_shop_sale($pdo, $tenant_id, $item_id, $item_name, $qty_sold, $new_stock);
 *
 * The function is safe to call even if the tenant_notifications
 * table doesn't exist yet — it wraps everything in try/catch.
 * ─────────────────────────────────────────────────────────────
 */

/**
 * Insert a shop-sale notification for the tenant admin and all
 * managers of that tenant.
 *
 * @param PDO    $pdo         Active DB connection
 * @param int    $tenant_id   Tenant ID
 * @param int    $item_id     item_inventory.id
 * @param string $item_name   Human-readable item name
 * @param int    $qty_sold    How many units were just sold (usually 1)
 * @param int    $new_stock   Remaining stock_qty after the sale
 */
function notify_shop_sale(
    PDO    $pdo,
    int    $tenant_id,
    int    $item_id,
    string $item_name,
    int    $qty_sold    = 1,
    int    $new_stock   = 0
): void {

    try {
        // ── Build notification message ────────────────────────
        $title   = "Shop Sale: {$item_name}";
        $message = "{$qty_sold} unit" . ($qty_sold > 1 ? 's' : '') . " of \"{$item_name}\" sold.";

        if ($new_stock === 0) {
            $message .= " ⚠️ Item is now OUT OF STOCK.";
            $type     = 'out_of_stock';
            $icon     = 'remove_shopping_cart';
        } elseif ($new_stock <= 2) {
            $message .= " Low stock remaining: {$new_stock} unit" . ($new_stock > 1 ? 's' : '') . ".";
            $type     = 'low_stock';
            $icon     = 'inventory_2';
        } else {
            $message .= " Stock remaining: {$new_stock}.";
            $type     = 'sale';
            $icon     = 'storefront';
        }

        // ── Fetch recipient user IDs (admin + managers) ───────
        $stmt = $pdo->prepare("
            SELECT id FROM users
            WHERE tenant_id = ?
              AND role IN ('admin', 'manager')
              AND status = 'approved'
              AND is_suspended = 0
        ");
        $stmt->execute([$tenant_id]);
        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($recipients)) {
            return;
        }

        // ── Insert one notification row per recipient ─────────
        // Assumes a tenant_notifications table with these columns:
        //   id, tenant_id, user_id, type, icon, title, message,
        //   entity_type, entity_id, is_read, created_at
        $insert = $pdo->prepare("
            INSERT INTO tenant_notifications
                (tenant_id, user_id, type, icon, title, message,
                 entity_type, entity_id, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'shop_item', ?, 0, NOW())
        ");

        foreach ($recipients as $uid) {
            $insert->execute([
                $tenant_id,
                $uid,
                $type,
                $icon,
                $title,
                $message,
                $item_id,
            ]);
        }

        // ── Optional: Audit log ───────────────────────────────
        try {
            $pdo->prepare("
                INSERT INTO audit_logs
                    (tenant_id, actor_user_id, actor_username, actor_role,
                     action, entity_type, entity_id, message, ip_address, created_at)
                VALUES (?, NULL, 'system', 'system',
                        'SHOP_ITEM_SOLD', 'shop_item', ?, ?, '::shop', NOW())
            ")->execute([
                $tenant_id,
                $item_id,
                "Sold {$qty_sold}x \"{$item_name}\". Remaining stock: {$new_stock}.",
            ]);
        } catch (Throwable $e) {
            // Audit log failure is non-fatal
        }

    } catch (Throwable $e) {
        // Notifications are non-fatal — log and continue
        error_log("[shop_sale_notify] Failed for tenant_id={$tenant_id}, item_id={$item_id}: " . $e->getMessage());
    }
}


/**
 * notify_shop_sale_from_order()
 * ─────────────────────────────────────────────────────────────
 * Convenience wrapper — call this right after a shop_orders row
 * is inserted. Looks up the item name and new stock automatically.
 *
 * @param PDO    $pdo       Active DB connection
 * @param int    $tenant_id Tenant ID
 * @param int    $item_id   item_inventory.id
 * @param int    $qty_sold  Quantity just purchased
 */
function notify_shop_sale_from_order(
    PDO $pdo,
    int $tenant_id,
    int $item_id,
    int $qty_sold = 1
): void {
    try {
        $stmt = $pdo->prepare("SELECT item_name, stock_qty FROM item_inventory WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$item_id, $tenant_id]);
        $item = $stmt->fetch();

        if (!$item) return;

        $new_stock = max(0, (int)$item['stock_qty']); // stock already deducted before calling this
        notify_shop_sale($pdo, $tenant_id, $item_id, $item['item_name'], $qty_sold, $new_stock);

    } catch (Throwable $e) {
        error_log("[shop_sale_notify] Lookup failed for item_id={$item_id}: " . $e->getMessage());
    }
}