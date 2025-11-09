<?php
// ==============================
// ✅ Common Database Functions
// ==============================

if (!function_exists('safeQuery')) {
    function safeQuery($conn, $query) {
        try {
            return $conn->query($query);
        } catch (Exception $e) {
            error_log("SQL Error: " . $e->getMessage());
            return false;
        }
    }
}

// -------------------------------
// 🔹 1. Total Users
// -------------------------------
if (!function_exists('getUserCount')) {
    function getUserCount($conn) {
        $sql = "SELECT COUNT(*) AS total FROM users";
        $res = safeQuery($conn, $sql);
        if ($res && $row = $res->fetch_assoc()) return (int)$row['total'];
        return 0;
    }
}

// -------------------------------
// 🔹 2. Total Restaurants
// -------------------------------
if (!function_exists('getRestaurantCount')) {
    function getRestaurantCount($conn) {
        $sql = "SELECT COUNT(*) AS total FROM restaurants";
        $res = safeQuery($conn, $sql);
        if ($res && $row = $res->fetch_assoc()) return (int)$row['total'];
        return 0;
    }
}

// -------------------------------
// 🔹 3. Orders Today
// -------------------------------
if (!function_exists('getTodayOrdersCount')) {
    function getTodayOrdersCount($conn) {
        $sql = "SELECT COUNT(*) AS total FROM orders WHERE DATE(created_at) = CURDATE()";
        $res = safeQuery($conn, $sql);
        if ($res && $row = $res->fetch_assoc()) return (int)$row['total'];
        return 0;
    }
}

// -------------------------------
// 🔹 4. Revenue Today
// -------------------------------
if (!function_exists('getTodayRevenue')) {
    function getTodayRevenue($conn) {
        $sql = "SELECT SUM(total) AS total FROM orders WHERE DATE(created_at) = CURDATE() AND order_status='Delivered'";
        $res = safeQuery($conn, $sql);
        if ($res && $row = $res->fetch_assoc()) return (float)$row['total'];
        return 0;
    }
}

// -------------------------------
// 🔹 5. Get Recent Orders
// -------------------------------
if (!function_exists('getAllOrders')) {
    function getAllOrders($conn, $limit = 5) {
        $orders = [];
        $sql = "
            SELECT o.id,
                   u.name AS customer_name,
                   r.name AS restaurant_name,
                   o.total AS total_amount,
                   o.order_status AS status,
                   o.created_at
            FROM orders o
            LEFT JOIN users u ON o.customer_id = u.id
            LEFT JOIN restaurants r ON o.restaurant_id = r.id
            ORDER BY o.id DESC
            LIMIT $limit
        ";
        $res = safeQuery($conn, $sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $orders[] = $row;
            }
        }
        return $orders;
    }
}
?>