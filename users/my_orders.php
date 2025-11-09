<?php
session_start();

// Include database connection - updated path to match your structure
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

// Check if user is logged in and has 'user' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') { 
    header("Location: Studlogin.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];
$success = $error = '';

// Get user data
$user_sql = "SELECT name, email, role, profile_image FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();

if ($user_result->num_rows > 0) {
    $user = $user_result->fetch_assoc();
    $userName = $user['name'];
    $firstLetter = strtoupper(substr($userName, 0, 1));
} else {
    // If user not found in database, log them out
    session_destroy();
    header("Location: Studlogin.php");
    exit();
}
$stmt->close();

// Function to format store name for URLs
function formatStoreName($storeName) {
    return preg_replace('/[^a-zA-Z0-9]/', '_', $storeName);
}

// Function to get proper image URL
function getProductImageUrl($productImage, $storeName) {
    if (empty($productImage)) {
        return null;
    }
    
    // If it's already a full path, return as is
    if (strpos($productImage, 'uploads/') === 0) {
        return '../' . $productImage;
    }
    
    // Otherwise construct the path
    $formattedStoreName = formatStoreName($storeName);
    return '../uploads/menus/' . $formattedStoreName . '/' . $productImage;
}

// Function to get proper store image URL
function getStoreImageUrl($storeImage, $storeName) {
    if (empty($storeImage)) {
        return null;
    }
    
    // If it's already a full path, return as is
    if (strpos($storeImage, 'uploads/') === 0) {
        return '../' . $storeImage;
    }
    
    // Otherwise construct the path
    $formattedStoreName = formatStoreName($storeName);
    return '../uploads/stores/' . $formattedStoreName . '.jpg';
}

// Function to get database-safe image URL (without ../ prefix)
function getDbSafeProductImageUrl($productImage, $storeName) {
    if (empty($productImage)) {
        return null;
    }
    
    // Remove ../ prefix if present
    $productImage = str_replace('../', '', $productImage);
    
    // If it's already a full path with uploads/, return as is
    if (strpos($productImage, 'uploads/') === 0) {
        return $productImage;
    }
    
    // Otherwise construct the path
    $formattedStoreName = formatStoreName($storeName);
    return 'uploads/menus/' . $formattedStoreName . '/' . $productImage;
}

// Fetch cart items for the current user with restaurant information
$cart_items = [];
$cart_total = 0;
$cart_count = 0;
$cart_item_count = 0;

$cart_query = "SELECT c.*, r.name as restaurant_name 
               FROM cart c 
               JOIN restaurants r ON c.restaurant_id = r.id 
               WHERE c.user_id = ?";
$stmt = $conn->prepare($cart_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$cart_result = $stmt->get_result();

if ($cart_result) {
    while ($row = $cart_result->fetch_assoc()) {
        // Fix image URLs for cart items
        if (!empty($row['product_image_url'])) {
            $row['product_image_url'] = getProductImageUrl($row['product_image_url'], $row['restaurant_name']);
        }
        $cart_items[] = $row;
        $cart_total += $row['price'] * $row['quantity'];
        $cart_count += $row['quantity'];
        $cart_item_count++;
    }
}
$stmt->close();

// Get active tab from URL parameter or default to 'preparing'
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'preparing';
$valid_tabs = ['preparing', 'ready', 'completed', 'cancelled', 'failed', 'refunded'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'preparing';
}

// Get user orders from database grouped by status
$orders_by_status = [
    'preparing' => [],
    'ready' => [],
    'completed' => [],
    'cancelled' => [],
    'failed' => [],
    'refunded' => []
];

$orders_sql = "SELECT o.*, r.name as restaurant_name, p.payment_method, p.payment_status, p.transaction_id
               FROM orders o 
               JOIN restaurants r ON o.restaurant_id = r.id 
               LEFT JOIN payments p ON o.payment_id = p.payment_id
               WHERE o.user_id = ? 
               ORDER BY o.created_at DESC";
$stmt = $conn->prepare($orders_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$orders_result = $stmt->get_result();

while ($row = $orders_result->fetch_assoc()) {
    // Get order items for each order
    $order_items_sql = "SELECT oi.* 
                        FROM order_items oi 
                        WHERE oi.order_id = ?";
    $items_stmt = $conn->prepare($order_items_sql);
    $items_stmt->bind_param("i", $row['id']);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    $order_items = [];
    while ($item = $items_result->fetch_assoc()) {
        // Fix image URLs for order items
        if (!empty($item['product_image_url'])) {
            $item['product_image_url'] = getProductImageUrl($item['product_image_url'], $row['restaurant_name']);
        }
        
        // Check if user has already reviewed this product in this order
        $review_check_sql = "SELECT id, rating, review_text FROM product_reviews 
                            WHERE user_id = ? AND product_id = ? AND order_id = ?";
        $review_stmt = $conn->prepare($review_check_sql);
        $review_stmt->bind_param("iii", $user_id, $item['product_id'], $row['id']);
        $review_stmt->execute();
        $review_result = $review_stmt->get_result();
        $item['user_review'] = $review_result->fetch_assoc();
        $review_stmt->close();
        
        $order_items[] = $item;
    }
    $items_stmt->close();
    
    $row['order_items'] = $order_items;
    
    // Group by status
    $status = strtolower($row['status']);
    if (array_key_exists($status, $orders_by_status)) {
        $orders_by_status[$status][] = $row;
    }
}
$stmt->close();

// Count orders for each status for badge notifications
$preparing_count = count($orders_by_status['preparing']);
$ready_count = count($orders_by_status['ready']);
$completed_count = count($orders_by_status['completed']);
$cancelled_count = count($orders_by_status['cancelled']);
$failed_count = count($orders_by_status['failed']);
$refunded_count = count($orders_by_status['refunded']);

// Handle AJAX requests for cart operations, order status updates, reviews, and cancellations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $response = ['success' => false, 'message' => ''];
    
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'User not logged in';
        echo json_encode($response);
        exit();
    }
    
    $user_id = $_SESSION['user_id'];
    
    switch ($_POST['action']) {
        case 'add_to_cart':
            if (isset($_POST['product_id'], $_POST['quantity'])) {
                $product_id = intval($_POST['product_id']);
                $quantity = intval($_POST['quantity']);
                
                // Get product details with restaurant information
                $product_query = "SELECT p.*, r.id as restaurant_id, r.name as restaurant_name 
                                 FROM products p 
                                 JOIN restaurants r ON p.restaurant_id = r.id 
                                 WHERE p.id = ?";
                $stmt = $conn->prepare($product_query);
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $product_result = $stmt->get_result();
                
                if ($product_result->num_rows > 0) {
                    $product = $product_result->fetch_assoc();
                    
                    // Store original image URL without modification for database
                    $product_image = $product['product_image_url'];
                    
                    // Check if item already exists in cart
                    $check_query = "SELECT * FROM cart WHERE user_id = ? AND product_id = ?";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param("ii", $user_id, $product_id);
                    $check_stmt->execute();
                    $existing_item = $check_stmt->get_result();
                    
                    if ($existing_item->num_rows > 0) {
                        // Update quantity
                        $update_query = "UPDATE cart SET quantity = quantity + ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND product_id = ?";
                        $update_stmt = $conn->prepare($update_query);
                        $update_stmt->bind_param("iii", $quantity, $user_id, $product_id);
                        $update_stmt->execute();
                        $response['success'] = $update_stmt->affected_rows > 0;
                        $response['message'] = $response['success'] ? 'Quantity updated in cart' : 'Failed to update cart';
                        $update_stmt->close();
                    } else {
                        // Insert new item with all required fields
                        $insert_query = "INSERT INTO cart (user_id, product_id, product_name, product_image_url, price, quantity, restaurant_id, restaurant_name) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        $insert_stmt = $conn->prepare($insert_query);
                        
                        if (!$insert_stmt) {
                            $response['message'] = 'Database error: ' . $conn->error;
                            $response['success'] = false;
                        } else {
                            $insert_stmt->bind_param("iissdiss", 
                                $user_id, 
                                $product_id, 
                                $product['name'], 
                                $product_image,
                                $product['price'], 
                                $quantity, 
                                $product['restaurant_id'],
                                $product['restaurant_name']
                            );
                            
                            if ($insert_stmt->execute()) {
                                $response['success'] = true;
                                $response['message'] = 'Item added to cart';
                            } else {
                                $response['success'] = false;
                                $response['message'] = 'Failed to add item: ' . $insert_stmt->error;
                            }
                            $insert_stmt->close();
                        }
                    }
                    
                    $check_stmt->close();
                    
                    // Return updated cart data
                    if ($response['success']) {
                        $response['cart_data'] = getCartData($conn, $user_id);
                    }
                } else {
                    $response['message'] = 'Product not found';
                    $response['success'] = false;
                }
                $stmt->close();
            } else {
                $response['message'] = 'Missing required parameters';
                $response['success'] = false;
            }
            break;
            
        case 'update_cart_quantity':
            if (isset($_POST['cart_id'], $_POST['quantity'])) {
                $cart_id = intval($_POST['cart_id']);
                $quantity = intval($_POST['quantity']);
                
                if ($quantity <= 0) {
                    // Remove item if quantity is 0 or less
                    $delete_query = "DELETE FROM cart WHERE id = ? AND user_id = ?";
                    $delete_stmt = $conn->prepare($delete_query);
                    $delete_stmt->bind_param("ii", $cart_id, $user_id);
                    $delete_stmt->execute();
                    $response['success'] = $delete_stmt->affected_rows > 0;
                    $delete_stmt->close();
                    $response['message'] = $response['success'] ? 'Item removed from cart' : 'Failed to remove item';
                } else {
                    // Update quantity
                    $update_query = "UPDATE cart SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("iii", $quantity, $cart_id, $user_id);
                    $update_stmt->execute();
                    $response['success'] = $update_stmt->affected_rows > 0;
                    $update_stmt->close();
                    $response['message'] = $response['success'] ? 'Cart updated' : 'Failed to update cart';
                }
                
                // Return updated cart data
                if ($response['success']) {
                    $response['cart_data'] = getCartData($conn, $user_id);
                }
            }
            break;
            
        case 'remove_from_cart':
            if (isset($_POST['cart_id'])) {
                $cart_id = intval($_POST['cart_id']);
                $delete_query = "DELETE FROM cart WHERE id = ? AND user_id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("ii", $cart_id, $user_id);
                $delete_stmt->execute();
                $response['success'] = $delete_stmt->affected_rows > 0;
                $delete_stmt->close();
                $response['message'] = $response['success'] ? 'Item removed from cart' : 'Failed to remove item';
                
                // Return updated cart data
                if ($response['success']) {
                    $response['cart_data'] = getCartData($conn, $user_id);
                }
            }
            break;
            
        case 'get_cart_count':
            // Count distinct items (not quantities)
            $count_query = "SELECT COUNT(*) as item_count FROM cart WHERE user_id = ?";
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->bind_param("i", $user_id);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $count_data = $count_result->fetch_assoc();
            $response['success'] = true;
            $response['count'] = $count_data['item_count'] ?? 0;
            $count_stmt->close();
            break;
            
        case 'get_cart_data':
            $response['success'] = true;
            $response['cart_data'] = getCartData($conn, $user_id);
            break;
            
        case 'get_order_counts':
            // Get updated order counts for badges
            $counts_query = "SELECT status, COUNT(*) as count FROM orders WHERE user_id = ? AND status IN ('preparing', 'ready', 'completed', 'cancelled', 'failed', 'refunded') GROUP BY status";
            $counts_stmt = $conn->prepare($counts_query);
            $counts_stmt->bind_param("i", $user_id);
            $counts_stmt->execute();
            $counts_result = $counts_stmt->get_result();
            
            $order_counts = [
                'preparing' => 0,
                'ready' => 0,
                'completed' => 0,
                'cancelled' => 0,
                'failed' => 0,
                'refunded' => 0
            ];
            
            while ($row = $counts_result->fetch_assoc()) {
                $order_counts[$row['status']] = $row['count'];
            }
            
            $response['success'] = true;
            $response['counts'] = $order_counts;
            $counts_stmt->close();
            break;
            
        case 'get_orders_by_status':
            // Get orders for a specific status
            if (isset($_POST['status'])) {
                $status = $_POST['status'];
                $valid_statuses = ['preparing', 'ready', 'completed', 'cancelled', 'failed', 'refunded'];
                
                if (in_array($status, $valid_statuses)) {
                    $orders_sql = "SELECT o.*, r.name as restaurant_name, p.payment_method, p.payment_status, p.transaction_id
                                   FROM orders o 
                                   JOIN restaurants r ON o.restaurant_id = r.id 
                                   LEFT JOIN payments p ON o.payment_id = p.payment_id
                                   WHERE o.user_id = ? AND o.status = ?
                                   ORDER BY o.created_at DESC";
                    $stmt = $conn->prepare($orders_sql);
                    $stmt->bind_param("is", $user_id, $status);
                    $stmt->execute();
                    $orders_result = $stmt->get_result();
                    
                    $orders = [];
                    while ($row = $orders_result->fetch_assoc()) {
                        // Get order items for each order
                        $order_items_sql = "SELECT oi.* 
                                            FROM order_items oi 
                                            WHERE oi.order_id = ?";
                        $items_stmt = $conn->prepare($order_items_sql);
                        $items_stmt->bind_param("i", $row['id']);
                        $items_stmt->execute();
                        $items_result = $items_stmt->get_result();
                        
                        $order_items = [];
                        while ($item = $items_result->fetch_assoc()) {
                            // Fix image URLs for order items
                            if (!empty($item['product_image_url'])) {
                                $item['product_image_url'] = getProductImageUrl($item['product_image_url'], $row['restaurant_name']);
                            }
                            
                            // Check if user has already reviewed this product in this order
                            $review_check_sql = "SELECT id, rating, review_text FROM product_reviews 
                                                WHERE user_id = ? AND product_id = ? AND order_id = ?";
                            $review_stmt = $conn->prepare($review_check_sql);
                            $review_stmt->bind_param("iii", $user_id, $item['product_id'], $row['id']);
                            $review_stmt->execute();
                            $review_result = $review_stmt->get_result();
                            $item['user_review'] = $review_result->fetch_assoc();
                            $review_stmt->close();
                            
                            $order_items[] = $item;
                        }
                        $items_stmt->close();
                        
                        $row['order_items'] = $order_items;
                        $orders[] = $row;
                    }
                    $stmt->close();
                    
                    $response['success'] = true;
                    $response['orders'] = $orders;
                    $response['status'] = $status;
                } else {
                    $response['message'] = 'Invalid status';
                }
            }
            break;
            
        case 'get_order_details':
            // Get complete order details including items for modal
            if (isset($_POST['order_id'])) {
                $order_id = intval($_POST['order_id']);
                
                $order_sql = "SELECT o.*, r.name as restaurant_name, p.payment_method, p.payment_status, p.transaction_id, p.amount as payment_amount
                              FROM orders o 
                              JOIN restaurants r ON o.restaurant_id = r.id 
                              LEFT JOIN payments p ON o.payment_id = p.payment_id
                              WHERE o.id = ? AND o.user_id = ?";
                $stmt = $conn->prepare($order_sql);
                $stmt->bind_param("ii", $order_id, $user_id);
                $stmt->execute();
                $order_result = $stmt->get_result();
                
                if ($order_result->num_rows > 0) {
                    $order = $order_result->fetch_assoc();
                    
                    // Get order items
                    $items_sql = "SELECT oi.* 
                                  FROM order_items oi 
                                  WHERE oi.order_id = ?";
                    $items_stmt = $conn->prepare($items_sql);
                    $items_stmt->bind_param("i", $order_id);
                    $items_stmt->execute();
                    $items_result = $items_stmt->get_result();
                    
                    $order_items = [];
                    while ($item = $items_result->fetch_assoc()) {
                        // Fix image URLs for order items
                        if (!empty($item['product_image_url'])) {
                            $item['product_image_url'] = getProductImageUrl($item['product_image_url'], $order['restaurant_name']);
                        }
                        
                        // Check if user has already reviewed this product in this order
                        $review_check_sql = "SELECT id, rating, review_text FROM product_reviews 
                                            WHERE user_id = ? AND product_id = ? AND order_id = ?";
                        $review_stmt = $conn->prepare($review_check_sql);
                        $review_stmt->bind_param("iii", $user_id, $item['product_id'], $order_id);
                        $review_stmt->execute();
                        $review_result = $review_stmt->get_result();
                        $item['user_review'] = $review_result->fetch_assoc();
                        $review_stmt->close();
                        
                        $order_items[] = $item;
                    }
                    $items_stmt->close();
                    
                    $order['order_items'] = $order_items;
                    
                    $response['success'] = true;
                    $response['order'] = $order;
                
                }
                $stmt->close();
            }
            break;
            
        case 'refresh_all_orders':
            // Get all orders grouped by status (full refresh)
            $orders_by_status = [
                'preparing' => [],
                'ready' => [],
                'completed' => [],
                'cancelled' => [],
                'failed' => [],
                'refunded' => []
            ];

            $orders_sql = "SELECT o.*, r.name as restaurant_name, p.payment_method, p.payment_status, p.transaction_id
                           FROM orders o 
                           JOIN restaurants r ON o.restaurant_id = r.id 
                           LEFT JOIN payments p ON o.payment_id = p.payment_id
                           WHERE o.user_id = ? 
                           ORDER BY o.created_at DESC";
            $stmt = $conn->prepare($orders_sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $orders_result = $stmt->get_result();

            while ($row = $orders_result->fetch_assoc()) {
                // Get order items for each order
                $order_items_sql = "SELECT oi.* 
                                    FROM order_items oi 
                                    WHERE oi.order_id = ?";
                $items_stmt = $conn->prepare($order_items_sql);
                $items_stmt->bind_param("i", $row['id']);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                $order_items = [];
                while ($item = $items_result->fetch_assoc()) {
                    // Fix image URLs for order items
                    if (!empty($item['product_image_url'])) {
                        $item['product_image_url'] = getProductImageUrl($item['product_image_url'], $row['restaurant_name']);
                    }
                    
                    // Check if user has already reviewed this product in this order
                    $review_check_sql = "SELECT id, rating, review_text FROM product_reviews 
                                        WHERE user_id = ? AND product_id = ? AND order_id = ?";
                    $review_stmt = $conn->prepare($review_check_sql);
                    $review_stmt->bind_param("iii", $user_id, $item['product_id'], $row['id']);
                    $review_stmt->execute();
                    $review_result = $review_stmt->get_result();
                    $item['user_review'] = $review_result->fetch_assoc();
                    $review_stmt->close();
                    
                    $order_items[] = $item;
                }
                $items_stmt->close();
                
                $row['order_items'] = $order_items;
                
                // Group by status
                $status = strtolower($row['status']);
                if (array_key_exists($status, $orders_by_status)) {
                    $orders_by_status[$status][] = $row;
                }
            }
            $stmt->close();
            
            $response['success'] = true;
            $response['orders_by_status'] = $orders_by_status;
            break;
            
        case 'submit_review':
            if (isset($_POST['order_id'], $_POST['product_id'], $_POST['rating'], $_POST['review_text'])) {
                $order_id = intval($_POST['order_id']);
                $product_id = intval($_POST['product_id']);
                $rating = intval($_POST['rating']);
                $review_text = trim($_POST['review_text']);
                
                // Validate rating
                if ($rating < 1 || $rating > 5) {
                    $response['message'] = 'Rating must be between 1 and 5 stars';
                    break;
                }
                
                // Validate review text
                if (empty($review_text)) {
                    $response['message'] = 'Review text cannot be empty';
                    break;
                }
                
                if (strlen($review_text) < 10) {
                    $response['message'] = 'Review must be at least 10 characters long';
                    break;
                }
                
                // Check if order exists, belongs to user, and is completed
                $order_check_query = "SELECT o.id, o.status 
                                     FROM orders o 
                                     WHERE o.id = ? AND o.user_id = ? AND o.status = 'completed'";
                $order_check_stmt = $conn->prepare($order_check_query);
                $order_check_stmt->bind_param("ii", $order_id, $user_id);
                $order_check_stmt->execute();
                $order_check_result = $order_check_stmt->get_result();
                
                if ($order_check_result->num_rows === 0) {
                    $response['message'] = 'Order not found, does not belong to you, or is not completed';
                    $order_check_stmt->close();
                    break;
                }
                $order_check_stmt->close();
                
                // Check if product exists in this order
                $product_check_query = "SELECT oi.id 
                                       FROM order_items oi 
                                       WHERE oi.order_id = ? AND oi.product_id = ?";
                $product_check_stmt = $conn->prepare($product_check_query);
                $product_check_stmt->bind_param("ii", $order_id, $product_id);
                $product_check_stmt->execute();
                $product_check_result = $product_check_stmt->get_result();
                
                if ($product_check_result->num_rows === 0) {
                    $response['message'] = 'Product not found in this order';
                    $product_check_stmt->close();
                    break;
                }
                $product_check_stmt->close();
                
                // Check if user has already reviewed this product in this order
                $check_query = "SELECT id FROM product_reviews WHERE user_id = ? AND product_id = ? AND order_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("iii", $user_id, $product_id, $order_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    // Update existing review
                    $update_query = "UPDATE product_reviews SET rating = ?, review_text = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND product_id = ? AND order_id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("isiii", $rating, $review_text, $user_id, $product_id, $order_id);
                    if ($update_stmt->execute()) {
                        $response['success'] = $update_stmt->affected_rows > 0;
                        $response['message'] = $response['success'] ? 'Review updated successfully' : 'Failed to update review';
                    } else {
                        $response['success'] = false;
                        $response['message'] = 'Database error: ' . $update_stmt->error;
                    }
                    $update_stmt->close();
                } else {
                    // Insert new review
                    $insert_query = "INSERT INTO product_reviews (user_id, product_id, order_id, rating, review_text) VALUES (?, ?, ?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bind_param("iiiss", $user_id, $product_id, $order_id, $rating, $review_text);
                    if ($insert_stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'Review submitted successfully';
                    } else {
                        $response['success'] = false;
                        $response['message'] = 'Database error: ' . $insert_stmt->error;
                    }
                    $insert_stmt->close();
                }
                
                $check_stmt->close();
            } else {
                $response['message'] = 'Missing required parameters';
                $response['success'] = false;
            }
            break;
            
        case 'cancel_order':
            if (isset($_POST['order_id'], $_POST['cancellation_reason'])) {
                $order_id = intval($_POST['order_id']);
                $cancellation_reason = trim($_POST['cancellation_reason']);
                
                // Validate cancellation reason
                if (empty($cancellation_reason)) {
                    $response['message'] = 'Please provide a cancellation reason';
                    break;
                }
                
                // Start transaction for atomic operation
                $conn->begin_transaction();
                
                try {
                    // Check if order exists and belongs to user and is in preparing status
                    $check_query = "SELECT id, status, payment_id FROM orders WHERE id = ? AND user_id = ? AND status = 'preparing'";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param("ii", $order_id, $user_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $order = $check_result->fetch_assoc();
                        $payment_id = $order['payment_id'];
                        
                        // Update order status to cancelled and add cancellation reason
                        $update_order_query = "UPDATE orders SET status = 'cancelled', cancellation_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                        $update_order_stmt = $conn->prepare($update_order_query);
                        $update_order_stmt->bind_param("si", $cancellation_reason, $order_id);
                        $update_order_stmt->execute();
                        
                        if ($update_order_stmt->affected_rows > 0) {
                            // Update payment status to Cancelled if payment exists
                            if ($payment_id) {
                                $update_payment_query = "UPDATE payments SET payment_status = 'Cancelled', updated_at = CURRENT_TIMESTAMP WHERE payment_id = ?";
                                $update_payment_stmt = $conn->prepare($update_payment_query);
                                $update_payment_stmt->bind_param("i", $payment_id);
                                $update_payment_stmt->execute();
                                $update_payment_stmt->close();
                            }
                            
                            $conn->commit();
                            $response['success'] = true;
                            $response['message'] = 'Order cancelled successfully';
                        } else {
                            $conn->rollback();
                            $response['message'] = 'Failed to cancel order';
                        }
                        $update_order_stmt->close();
                    } else {
                        $conn->rollback();
                        $response['message'] = 'Order not found or cannot be cancelled';
                    }
                    $check_stmt->close();
                } catch (Exception $e) {
                    $conn->rollback();
                    $response['message'] = 'Error cancelling order: ' . $e->getMessage();
                }
            }
            break;
    }
    
    echo json_encode($response);
    exit();
}

// Function to get cart data
function getCartData($conn, $user_id) {
    $cart_data = [
        'items' => [],
        'total' => 0,
        'count' => 0,
        'item_count' => 0
    ];
    
    $cart_query = "SELECT c.*, r.name as restaurant_name 
                   FROM cart c 
                   JOIN restaurants r ON c.restaurant_id = r.id 
                   WHERE c.user_id = ?";
    $stmt = $conn->prepare($cart_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $cart_result = $stmt->get_result();
    
    if ($cart_result) {
        while ($row = $cart_result->fetch_assoc()) {
            // Fix image URLs for cart items
            if (!empty($row['product_image_url'])) {
                $row['product_image_url'] = getProductImageUrl($row['product_image_url'], $row['restaurant_name']);
            }
            
            $cart_data['items'][] = $row;
            $cart_data['total'] += $row['price'] * $row['quantity'];
            $cart_data['count'] += $row['quantity'];
            $cart_data['item_count']++;
        }
    }
    $stmt->close();
    
    return $cart_data;
}

// Helper function to render order card
function renderOrderCard($order) {
    $statusClass = getStatusClass($order['status']);
    $statusLabel = ucfirst($order['status']);
    if ($order['status'] === 'ready') {
        $statusLabel = 'Ready for Pickup';
    }
    
    $orderDate = date('m/d/y • H:i', strtotime($order['created_at'] ?? $order['order_date']));
    
    ob_start();
    ?>
    <div class="order-card bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6" data-order-id="<?php echo $order['id']; ?>">
        <div class="p-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 umak-yellow rounded-lg flex items-center justify-center">
                        <span class="umak-blue-text font-bold text-sm"><?php echo substr($order['restaurant_name'] ?? 'OR', 0, 2); ?></span>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900">Order #<?php echo htmlspecialchars($order['order_number'] ?? $order['id']); ?></h3>
                        <p class="text-sm text-gray-600">Ordered: <?php echo $orderDate; ?></p>
                    </div>
                </div>
                <span class="<?php echo $statusClass; ?> px-3 py-1 rounded-full text-xs font-medium"><?php echo $statusLabel; ?></span>
            </div>
        </div>
        
        <div class="p-4">
            <?php if (!empty($order['order_items'])): ?>
            <div class="space-y-4">
                <?php foreach ($order['order_items'] as $item): ?>
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                            <?php if (!empty($item['product_image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($item['product_image_url']); ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>" class="w-10 h-10 object-cover rounded">
                            <?php else: ?>
                                <i class="fas fa-utensils text-gray-400"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></h4>
                            <p class="text-sm text-gray-600">Quantity: <?php echo $item['quantity']; ?></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="font-semibold text-gray-900">₱<?php echo number_format($item['unit_price'], 2); ?></p>
                        <p class="text-sm text-gray-600">Subtotal: ₱<?php echo number_format($item['total_price'], 2); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-200">
                <div class="font-bold text-lg umak-blue-text">Total: ₱<?php echo number_format($order['total_amount'], 2); ?></div>
                <div class="flex space-x-2">
                    <?php if ($order['status'] === 'preparing'): ?>
                        <button class="cancel-order-btn px-4 py-2 bg-red-600 text-white rounded-lg font-semibold hover:bg-red-700 transition-all duration-300" data-order-id="<?php echo $order['id']; ?>">
                            Cancel Order
                        </button>
                    <?php endif; ?>
                    <button class="view-order-btn px-4 py-2 umak-blue text-white rounded-lg font-semibold hover:bg-blue-800 transition-all duration-300" data-order-id="<?php echo $order['id']; ?>">
                        View Details
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function getStatusClass($status) {
    $statusMap = [
        'preparing' => 'status-preparing',
        'ready' => 'status-ready',
        'completed' => 'status-completed',
        'cancelled' => 'status-cancelled',
        'failed' => 'status-failed',
        'refunded' => 'status-refunded'
    ];
    return $statusMap[strtolower($status)] ?? 'status-pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - UMAK Foodhub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        body {
            box-sizing: border-box;
        }
        
        .sidebar-transition {
            transition: transform 0.3s ease-in-out;
        }
        
        .cart-transition {
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
        }
        
        .overlay {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
        }
        
        .umak-blue {
            background-color: #1e3a8a;
        }
        
        .umak-yellow {
            background-color: #fbbf24;
        }
        
        .umak-blue-text {
            color: #1e3a8a;
        }
        
        .umak-yellow-text {
            color: #fbbf24;
        }
        
        .umak-blue-border {
            border-color: #1e3a8a;
        }
        
        .umak-yellow-border {
            border-color: #fbbf24;
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 100px;
            right: 20px;
            background: #4CAF50;
            color: white;
            padding: 15px 20px;
            border-radius: 5px;
            z-index: 1000;
            animation: slideIn 0.3s ease-out;
        }

        .notification.error {
            background: #f44336;
        }

        .notification.info {
            background: #2196F3;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }

        /* Cart Badge */
        .cart-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #fbbf24;
            color: #1e3a8a;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            font-weight: bold;
            display: none;
            align-items: center;
            justify-content: center;
        }

        /* Order Styles */
        .order-card {
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
        }

        .order-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-confirmed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-preparing {
            background: #fef3c7;
            color: #92400e;
        }

        .status-ready {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-failed {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #f59e0b;
        }

        .status-refunded {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .search-dropdown {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }

        .search-input {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            border-left: none;
        }

        .tab {
            transition: all 0.3s ease;
            position: relative;
        }

        .tab.active {
            background: #fbbf24;
            color: #1e3a8a;
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #cbd5e0;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1f2937;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6b7280;
        }

        .modal-body {
            padding: 20px;
        }

        /* Tab Badge Styles */
        .tab-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            background: #ef4444;
            color: white;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 6px;
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #1e3a8a;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Auto-refresh indicator */
        .auto-refresh-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1e3a8a;
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .order-item-list {
            max-height: 300px;
            overflow-y: auto;
        }

        /* Review Stars */
        .star-rating {
            display: inline-flex;
            gap: 2px;
        }
        
        .star-rating .star {
            color: #d1d5db;
            cursor: pointer;
            transition: color 0.2s;
            font-size: 1.5rem;
        }
        
        .star-rating .star.active {
            color: #fbbf24;
        }
        
        .star-rating .star.hover {
            color: #fbbf24;
        }
        
        .star-rating .star.interactive:hover {
            color: #fbbf24;
        }

        /* Review Form Styles */
        .review-form {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
            margin-top: 12px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #374151;
        }

        .form-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            resize: vertical;
            font-family: inherit;
            transition: border-color 0.2s;
        }

        .form-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .char-count {
            text-align: right;
            font-size: 12px;
            color: #9ca3af;
            margin-top: 4px;
        }

        .submit-review-btn {
            background: #1e3a8a;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }

        .submit-review-btn:hover {
            background: #1e40af;
        }

        .submit-review-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        /* Cancellation Modal */
        .cancellation-reason {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            resize: vertical;
            font-family: inherit;
        }

        .cancellation-reason:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .cancel-order-btn {
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }

        .cancel-order-btn:hover {
            background: #b91c1c;
        }

        .review-badge {
            background: #10b981;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin-left: 8px;
        }

        /* Payment Status Styles */
        .payment-status-completed {
            color: #065f46;
            background: #d1fae5;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 500;
        }

        .payment-status-pending {
            color: #92400e;
            background: #fef3c7;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 500;
        }

        .payment-status-cancelled {
            color: #991b1b;
            background: #fee2e2;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 500;
        }

        .payment-status-failed {
            color: #92400e;
            background: #fef3c7;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 500;
        }

        .payment-status-refunded {
            color: #6b21a8;
            background: #f3e8ff;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 500;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-full">
<!-- Overlay -->
<div id="overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden overlay"></div>

<!-- Auto-refresh Indicator -->
<div id="autoRefreshIndicator" class="auto-refresh-indicator">
    <i class="fas fa-sync-alt fa-spin"></i>
    <span>Auto-refresh active</span>
</div>

<!-- Top Navigation -->
<nav class="umak-blue shadow-lg fixed top-0 left-0 right-0 z-30">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <!-- Left: Logo and Hamburger -->
            <div class="flex items-center space-x-4">
                <button id="hamburger" class="p-2 rounded-md text-white hover:text-yellow-300 hover:bg-blue-800 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                <div class="flex items-center space-x-2">
                    <div class="w-20 h-15 rounded-lg overflow-hidden">
                        <img src="FoodHub_notext.png" alt="FoodHub Logo" class="w-full h-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="w-8 h-8 umak-yellow rounded-lg flex items-center justify-center" style="display: none;">
                            <span class="umak-blue-text font-bold text-sm">F</span>
                        </div>
                    </div>
                    <div class="flex items-center">
                        <span class="text-xl font-bold text-white">UMAK</span>
                        <span class="text-xl font-bold umak-yellow-text ml-1">Foodhub</span>
                    </div>
                </div>
            </div>

            <!-- Center: Search Bar -->
            <div class="hidden md:flex items-center flex-1 max-w-2xl mx-8">
                <div class="flex items-center w-full bg-white rounded-lg shadow-sm border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-yellow-400 focus-within:border-yellow-400 transition-all duration-200">
                    <select class="px-4 py-3 bg-transparent border-none text-sm focus:outline-none focus:ring-0 appearance-none cursor-pointer">
                        <option value="all">All</option>
                        <option value="foods">Foods</option>
                        <option value="shops">Stores</option>
                    </select>
                    <div class="w-px h-6 bg-gray-300"></div>
                    <input type="text" placeholder="What are you craving today?" class="flex-1 px-4 py-3 border-none focus:outline-none focus:ring-0 text-sm">
                    <button class="px-6 py-3 umak-yellow umak-blue-text hover:bg-yellow-300 transition-all duration-300 font-medium border-l border-yellow-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Right: Cart and Profile -->
            <div class="flex items-center space-x-4">
                <button id="cart-btn" class="relative p-2 text-white hover:text-yellow-300 hover:bg-blue-800 rounded-lg transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0V19a2 2 0 002 2h7a2 2 0 002-2v-4"></path>
                    </svg>
                    <span class="cart-badge" style="<?php echo $cart_item_count > 0 ? 'display: flex;' : ''; ?>"><?php echo $cart_item_count; ?></span>
                </button>
                <div class="flex items-center space-x-2">
                    <div class="w-8 h-8 umak-yellow rounded-full flex items-center justify-center">
                        <span class="umak-blue-text font-bold text-sm"><?php echo $firstLetter; ?></span>
                    </div>
                    <span id="user-name" class="hidden sm:block text-sm font-medium text-white"><?php echo htmlspecialchars($userName); ?></span>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Sidebar -->
<aside id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-white shadow-lg z-50 transform -translate-x-full sidebar-transition">
    <div class="p-4 border-b border-gray-200">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <div class="w-15 h-12 rounded-lg overflow-hidden">
                    <img src="FoodHub_notext.png" alt="FoodHub Logo" class="w-full h-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="w-8 h-8 umak-yellow rounded-lg flex items-center justify-center" style="display: none;">
                        <span class="umak-blue-text font-bold text-sm">F</span>
                    </div>
                </div>
                <div class="flex items-center">
                    <span class="text-xl font-bold umak-blue-text">UMAK</span>
                    <span class="text-xl font-bold umak-yellow-text ml-1">Foodhub</span>
                </div>
            </div>
            <button id="close-sidebar" class="p-1 text-gray-500 hover:text-gray-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
    <nav class="p-4">
        <ul class="space-y-2">
            <li><a href="UserInt.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="currentColor" viewbox="0 0 20 20">
                    <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"></path>
                </svg>
                <span>Home</span>
            </a></li>
            <li><a href="browse_menu.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253z"></path>
                </svg>
                <span>Browse Menu</span>
            </a></li>
            <li><a href="favorites.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                </svg>
                <span>Favorites</span>
            </a></li>
            <li><a href="my_orders.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg bg-yellow-50 umak-blue-text font-medium border-l-4 umak-yellow-border">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                </svg>
                <span>My Orders</span>
            </a></li>
            <li><a href="profile.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                <span>Profile</span>
            </a></li>
            <li><a href="../users/logout.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-red-600 hover:bg-red-50 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                <span>Logout</span>
            </a></li>
        </ul>
    </nav>
</aside>

<!-- Cart Sidebar -->
<div id="cart-sidebar" class="fixed right-0 top-0 h-full w-80 bg-white shadow-lg z-50 transform translate-x-full cart-transition flex flex-col">
    <div class="p-4 border-b border-gray-200 flex-shrink-0">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Your Cart</h2>
            <button id="close-cart" class="p-1 text-gray-500 hover:text-gray-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
    
    <!-- Scrollable cart items area -->
    <div class="flex-1 overflow-y-auto">
        <div id="cart-empty" class="text-center py-12 <?php echo !empty($cart_items) ? 'hidden' : ''; ?>">
            <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0V19a2 2 0 002 2h7a2 2 0 002-2v-4"></path>
            </svg>
            <p class="text-gray-500 mb-2">Your cart is empty</p>
            <p class="text-sm text-gray-400">Add some delicious items to get started!</p>
        </div>
        <div id="cart-items-container" class="p-4 <?php echo empty($cart_items) ? 'hidden' : ''; ?>">
            <?php foreach ($cart_items as $item): ?>
            <div class="cart-item border-b border-gray-200 pb-4 mb-4" data-cart-id="<?php echo $item['id']; ?>">
                <div class="flex items-center space-x-3">
                    <div class="flex-shrink-0">
                        <?php if (!empty($item['product_image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($item['product_image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($item['product_name']); ?>" 
                                 class="w-16 h-16 object-cover rounded-lg"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <?php endif; ?>
                        <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center <?php echo !empty($item['product_image_url']) ? 'hidden' : ''; ?>">
                            <span class="text-gray-400 text-2xl">🍽️</span>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-semibold text-gray-900"><?php echo htmlspecialchars($item['product_name']); ?></h4>
                        <p class="text-sm text-gray-600">₱<?php echo number_format($item['price'], 2); ?></p>
                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($item['restaurant_name']); ?></p>
                        <div class="flex items-center justify-between mt-2">
                            <div class="flex items-center space-x-2">
                                <button onclick="updateCartQuantity(<?php echo $item['id']; ?>, -1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-sm hover:bg-gray-300 transition-colors">-</button>
                                <span class="font-medium cart-quantity"><?php echo $item['quantity']; ?></span>
                                <button onclick="updateCartQuantity(<?php echo $item['id']; ?>, 1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-sm hover:bg-gray-300 transition-colors">+</button>
                            </div>
                            <button onclick="removeFromCart(<?php echo $item['id']; ?>)" class="text-red-500 hover:text-red-700 text-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Fixed footer that stays at bottom -->
    <div class="border-t border-gray-200 p-4 bg-white flex-shrink-0">
        <div class="flex justify-between items-center mb-4">
            <span class="font-semibold text-gray-900">Total:</span>
            <span id="cart-total" class="font-bold text-lg umak-blue-text">₱<?php echo number_format($cart_total, 2); ?></span>
        </div>
        <a href="checkout.php" id="proceed-to-checkout" class="w-full umak-yellow umak-blue-text py-3 rounded-lg font-semibold hover:bg-yellow-300 transition-all duration-300 text-center block <?php echo empty($cart_items) ? 'opacity-50 pointer-events-none' : ''; ?>">
            Proceed to Checkout
        </a>
    </div>
</div>

<!-- Main Content -->
<main class="pt-16">
    <div class="p-6">
        <!-- Mobile Search -->
        <div class="md:hidden mb-6">
            <div class="flex items-center bg-white rounded-lg shadow-sm border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-yellow-400 focus-within:border-yellow-400 transition-all duration-200">
                <select class="px-3 py-3 bg-transparent border-none text-sm focus:outline-none focus:ring-0 appearance-none cursor-pointer flex-shrink-0">
                    <option value="all">All</option>
                    <option value="foods">Foods</option>
                    <option value="shops">Stores</option>
                </select>
                <div class="w-px h-6 bg-gray-300 flex-shrink-0"></div>
                <input type="text" placeholder="Search food or stores..." class="flex-1 px-3 py-3 border-none focus:outline-none focus:ring-0 text-sm">
                <button class="px-4 py-3 umak-yellow umak-blue-text hover:bg-yellow-300 transition-all duration-300 font-medium border-l border-yellow-400 flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Personalized Greeting Section -->
        <section class="mb-8">
            <div class="umak-blue rounded-2xl p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-bold mb-2">Hi, <span id="greeting-name"><?php echo htmlspecialchars($userName); ?></span>! 👋</h1>
                        <p class="text-lg opacity-90">Track your orders and meal journey</p>
                    </div>
                    <div class="hidden md:block">
                        <div class="w-16 h-16 umak-yellow rounded-full flex items-center justify-center">
                            <span class="text-3xl">📦</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Orders Content -->
        <section class="mb-8">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900">My Orders</h2>
                    <p class="text-gray-600 mt-1">Track your orders and meal journey</p>
                </div>
                <div class="text-sm text-gray-500">
                    <i class="fas fa-sync-alt fa-spin mr-1"></i>
                    Auto-refresh enabled
                </div>
            </div>
            
            <!-- Orders Tabs -->
            <div class="flex flex-wrap gap-2 mb-6">
                <button class="tab <?php echo $active_tab === 'preparing' ? 'active' : ''; ?> px-4 py-2 rounded-full text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors" data-tab="preparing">
                    Preparing
                    <?php if ($preparing_count > 0): ?>
                        <span class="tab-badge" id="preparing-badge"><?php echo $preparing_count; ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab <?php echo $active_tab === 'ready' ? 'active' : ''; ?> px-4 py-2 rounded-full text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors" data-tab="ready">
                    Ready for Pickup
                    <?php if ($ready_count > 0): ?>
                        <span class="tab-badge" id="ready-badge"><?php echo $ready_count; ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab <?php echo $active_tab === 'completed' ? 'active' : ''; ?> px-4 py-2 rounded-full text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors" data-tab="completed">
                    Completed
                    <?php if ($completed_count > 0): ?>
                        <span class="tab-badge" id="completed-badge"><?php echo $completed_count; ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab <?php echo $active_tab === 'cancelled' ? 'active' : ''; ?> px-4 py-2 rounded-full text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors" data-tab="cancelled">
                    Cancelled
                    <?php if ($cancelled_count > 0): ?>
                        <span class="tab-badge" id="cancelled-badge"><?php echo $cancelled_count; ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab <?php echo $active_tab === 'failed' ? 'active' : ''; ?> px-4 py-2 rounded-full text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors" data-tab="failed">
                    Failed
                    <?php if ($failed_count > 0): ?>
                        <span class="tab-badge" id="failed-badge"><?php echo $failed_count; ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab <?php echo $active_tab === 'refunded' ? 'active' : ''; ?> px-4 py-2 rounded-full text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors" data-tab="refunded">
                    Refunded
                    <?php if ($refunded_count > 0): ?>
                        <span class="tab-badge" id="refunded-badge"><?php echo $refunded_count; ?></span>
                    <?php endif; ?>
                </button>
            </div>
            
            <!-- Preparing Orders Tab -->
            <div class="tab-content <?php echo $active_tab === 'preparing' ? 'active' : 'hidden'; ?>" id="preparing-tab">
                <?php if (!empty($orders_by_status['preparing'])): ?>
                    <?php foreach ($orders_by_status['preparing'] as $order): ?>
                        <?php echo renderOrderCard($order); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state bg-white rounded-xl p-8 text-center">
                        <i class="fas fa-clock text-gray-300 mb-4" style="font-size: 4rem;"></i>
                        <h3 class="text-xl font-semibold text-gray-700 mb-2">No preparing orders</h3>
                        <p class="text-gray-500">Your orders will appear here when they're being prepared.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Ready Orders Tab -->
            <div class="tab-content <?php echo $active_tab === 'ready' ? 'active' : 'hidden'; ?>" id="ready-tab">
                <?php if (!empty($orders_by_status['ready'])): ?>
                    <?php foreach ($orders_by_status['ready'] as $order): ?>
                        <?php echo renderOrderCard($order); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state bg-white rounded-xl p-8 text-center">
                        <i class="fas fa-check-circle text-gray-300 mb-4" style="font-size: 4rem;"></i>
                        <h3 class="text-xl font-semibold text-gray-700 mb-2">No ready orders</h3>
                        <p class="text-gray-500">Your orders will appear here when they're ready for pickup.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Completed Orders Tab -->
            <div class="tab-content <?php echo $active_tab === 'completed' ? 'active' : 'hidden'; ?>" id="completed-tab">
                <?php if (!empty($orders_by_status['completed'])): ?>
                    <?php foreach ($orders_by_status['completed'] as $order): ?>
                        <?php echo renderOrderCard($order); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state bg-white rounded-xl p-8 text-center">
                        <i class="fas fa-history text-gray-300 mb-4" style="font-size: 4rem;"></i>
                        <h3 class="text-xl font-semibold text-gray-700 mb-2">No completed orders</h3>
                        <p class="text-gray-500">Your completed orders will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Cancelled Orders Tab -->
            <div class="tab-content <?php echo $active_tab === 'cancelled' ? 'active' : 'hidden'; ?>" id="cancelled-tab">
                <?php if (!empty($orders_by_status['cancelled'])): ?>
                    <?php foreach ($orders_by_status['cancelled'] as $order): ?>
                        <?php echo renderOrderCard($order); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state bg-white rounded-xl p-8 text-center">
                        <i class="fas fa-times-circle text-gray-300 mb-4" style="font-size: 4rem;"></i>
                        <h3 class="text-xl font-semibold text-gray-700 mb-2">No cancelled orders</h3>
                        <p class="text-gray-500">Your cancelled orders will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Failed Orders Tab -->
            <div class="tab-content <?php echo $active_tab === 'failed' ? 'active' : 'hidden'; ?>" id="failed-tab">
                <?php if (!empty($orders_by_status['failed'])): ?>
                    <?php foreach ($orders_by_status['failed'] as $order): ?>
                        <?php echo renderOrderCard($order); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state bg-white rounded-xl p-8 text-center">
                        <i class="fas fa-exclamation-triangle text-gray-300 mb-4" style="font-size: 4rem;"></i>
                        <h3 class="text-xl font-semibold text-gray-700 mb-2">No failed orders</h3>
                        <p class="text-gray-500">Your failed orders will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Refunded Orders Tab -->
            <div class="tab-content <?php echo $active_tab === 'refunded' ? 'active' : 'hidden'; ?>" id="refunded-tab">
                <?php if (!empty($orders_by_status['refunded'])): ?>
                    <?php foreach ($orders_by_status['refunded'] as $order): ?>
                        <?php echo renderOrderCard($order); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state bg-white rounded-xl p-8 text-center">
                        <i class="fas fa-undo text-gray-300 mb-4" style="font-size: 4rem;"></i>
                        <h3 class="text-xl font-semibold text-gray-700 mb-2">No refunded orders</h3>
                        <p class="text-gray-500">Your refunded orders will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<!-- Order Details Modal -->
<div class="modal" id="orderReferenceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Order Details</h2>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="order-confirmation mb-6">
                <h3 class="text-xl font-semibold text-gray-900 mb-2" id="modal-order-title">Order Details</h3>
                <p class="text-gray-600 mb-4" id="modal-order-status">Order information</p>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <span class="text-gray-600">Order ID:</span>
                            <p class="font-semibold umak-blue-text" id="modal-order-id">#</p>
                        </div>
                        <div>
                            <span class="text-gray-600">Status:</span>
                            <p class="font-semibold" id="modal-status"></p>
                        </div>
                        <div>
                            <span class="text-gray-600">Restaurant:</span>
                            <p class="font-semibold" id="modal-restaurant"></p>
                        </div>
                        <div>
                            <span class="text-gray-600">Order Date:</span>
                            <p class="font-semibold" id="modal-order-date"></p>
                        </div>
                        <div>
                            <span class="text-gray-600">Payment Method:</span>
                            <p class="font-semibold" id="modal-payment-method"></p>
                        </div>
                        <div>
                            <span class="text-gray-600">Payment Status:</span>
                            <p class="font-semibold" id="modal-payment-status"></p>
                        </div>
                        <div id="modal-transaction-id-container" class="col-span-2 hidden">
                            <span class="text-gray-600">Transaction ID:</span>
                            <p class="font-semibold text-sm" id="modal-transaction-id"></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mb-6">
                <h4 class="font-semibold text-gray-900 mb-3">Order Items</h4>
                <div class="order-item-list space-y-3" id="modal-order-items">
                    <!-- Order items will be inserted here -->
                </div>
            </div>

            <div class="border-t pt-4">
                <div class="flex justify-between items-center">
                    <span class="text-lg font-semibold text-gray-700">Total Amount:</span>
                    <span class="text-2xl font-bold umak-blue-text" id="modal-total">₱0.00</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cancellation Modal -->
<div class="modal" id="cancellationModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Cancel Order</h2>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="mb-4">
                <p class="text-gray-700 mb-3">Please tell us why you want to cancel this order:</p>
                <textarea id="cancellationReason" class="cancellation-reason" rows="4" placeholder="Please provide your reason for cancellation..."></textarea>
                <p class="text-sm text-gray-500 mt-1">Your feedback helps us improve our service.</p>
            </div>
            <div class="flex justify-end space-x-3">
                <button class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors" onclick="closeCancellationModal()">
                    Keep Order
                </button>
                <button class="cancel-order-btn px-4 py-2" onclick="confirmCancellation()">
                    Confirm Cancellation
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Review Modal -->
<div class="modal" id="reviewModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Review Product</h2>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <div id="review-product-info" class="mb-4 p-4 bg-gray-50 rounded-lg">
                <!-- Product info will be inserted here -->
            </div>
            <div class="mb-4">
                <label class="form-label">Your Rating</label>
                <div class="star-rating text-2xl mb-3" id="reviewStarRating">
                    <span class="star interactive" data-rating="1">★</span>
                    <span class="star interactive" data-rating="2">★</span>
                    <span class="star interactive" data-rating="3">★</span>
                    <span class="star interactive" data-rating="4">★</span>
                    <span class="star interactive" data-rating="5">★</span>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">Your Review</label>
                <textarea id="reviewText" placeholder="Share your experience with this product..." class="form-textarea" rows="4" maxlength="500"></textarea>
                <div class="char-count"><span id="charCount">0</span>/500</div>
            </div>
            <div class="flex justify-end">
                <button id="submitReviewBtn" class="submit-review-btn" onclick="submitReview()">
                    Submit Review
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// UI Elements
const hamburger = document.getElementById('hamburger');
const sidebar = document.getElementById('sidebar');
const closeSidebar = document.getElementById('close-sidebar');
const cartBtn = document.getElementById('cart-btn');
const cartSidebar = document.getElementById('cart-sidebar');
const closeCart = document.getElementById('close-cart');
const overlay = document.getElementById('overlay');
const tabs = document.querySelectorAll('.tab');
const modal = document.getElementById('orderReferenceModal');
const closeModal = document.querySelector('.close-modal');
const cancellationModal = document.getElementById('cancellationModal');
const reviewModal = document.getElementById('reviewModal');

// State
let autoRefreshInterval = null;
let isAutoRefreshEnabled = true;
let currentOrderId = null;
let currentProductId = null;
let selectedRating = 0;
let currentReviewOrderId = null;

// Sidebar functionality
hamburger.addEventListener('click', () => {
    sidebar.classList.remove('-translate-x-full');
    overlay.classList.remove('hidden');
});

closeSidebar.addEventListener('click', () => {
    sidebar.classList.add('-translate-x-full');
    overlay.classList.add('hidden');
});

// Cart functionality
cartBtn.addEventListener('click', () => {
    cartSidebar.classList.remove('translate-x-full');
    overlay.classList.remove('hidden');
});

closeCart.addEventListener('click', () => {
    cartSidebar.classList.add('translate-x-full');
    overlay.classList.add('hidden');
});

// Overlay click to close
overlay.addEventListener('click', () => {
    sidebar.classList.add('-translate-x-full');
    cartSidebar.classList.add('translate-x-full');
    overlay.classList.add('hidden');
});

// Enhanced tab functionality
tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        const tabId = tab.getAttribute('data-tab');
        switchTab(tabId);
        
        // Update URL without page reload
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.pushState({}, '', url);
    });
});

// Enhanced function to switch tabs
function switchTab(tabId) {
    // Remove active class from all tabs and contents
    tabs.forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));
    
    // Add active class to clicked tab and corresponding content
    const activeTab = document.querySelector(`[data-tab="${tabId}"]`);
    const activeContent = document.getElementById(`${tabId}-tab`);
    
    if (activeTab && activeContent) {
        activeTab.classList.add('active');
        activeContent.classList.remove('hidden');
        
        // Load orders for this tab if it's empty or needs refresh
        if (activeContent.querySelector('.empty-state') || 
            activeContent.querySelector('.order-card') || 
            activeContent.innerHTML.trim() === '') {
            loadOrdersForTab(tabId);
        }
    }
}

// Handle browser back/forward buttons
window.addEventListener('popstate', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab') || 'preparing';
    switchTab(tab);
});

// Function to load orders for a specific tab
function loadOrdersForTab(status) {
    const tabContent = document.getElementById(`${status}-tab`);
    
    // Show loading state
    tabContent.innerHTML = `
        <div class="empty-state bg-white rounded-xl p-8 text-center">
            <div class="loading mx-auto mb-4"></div>
            <p class="text-gray-500">Loading orders...</p>
        </div>
    `;

    const formData = new FormData();
    formData.append('action', 'get_orders_by_status');
    formData.append('status', status);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.orders) {
            renderOrdersForTab(status, data.orders);
        } else {
            showEmptyState(status);
        }
    })
    .catch(error => {
        console.error('Error loading orders:', error);
        showEmptyState(status);
        showNotification('Failed to load orders', 'error');
    });
}

// Function to render orders for a specific tab
function renderOrdersForTab(status, orders) {
    const tabContent = document.getElementById(`${status}-tab`);
    
    if (orders.length === 0) {
        showEmptyState(status);
        return;
    }

    let ordersHTML = '';
    
    orders.forEach(order => {
        const statusClass = getStatusClass(order.status);
        const statusLabel = getStatusLabel(order.status);
        const orderDate = new Date(order.created_at || order.order_date).toLocaleDateString('en-US', {
            month: '2-digit',
            day: '2-digit',
            year: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        }).replace(',', ' •');
        
        let itemsHTML = '';
        if (order.order_items && order.order_items.length > 0) {
            order.order_items.forEach(item => {
                itemsHTML += `
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                                ${item.product_image_url ? 
                                    `<img src="${escapeHtml(item.product_image_url)}" alt="${escapeHtml(item.item_name)}" class="w-10 h-10 object-cover rounded">` : 
                                    `<i class="fas fa-utensils text-gray-400"></i>`
                                }
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-900">${escapeHtml(item.item_name)}</h4>
                                <p class="text-sm text-gray-600">Quantity: ${item.quantity}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold text-gray-900">₱${parseFloat(item.unit_price).toFixed(2)}</p>
                            <p class="text-sm text-gray-600">Subtotal: ₱${parseFloat(item.total_price).toFixed(2)}</p>
                        </div>
                    </div>
                `;
            });
        }

        let actionButtons = '';
        if (order.status === 'preparing') {
            actionButtons = `
                <button class="cancel-order-btn px-4 py-2 bg-red-600 text-white rounded-lg font-semibold hover:bg-red-700 transition-all duration-300" data-order-id="${order.id}">
                    Cancel Order
                </button>
                <button class="view-order-btn px-4 py-2 umak-blue text-white rounded-lg font-semibold hover:bg-blue-800 transition-all duration-300" data-order-id="${order.id}">
                    View Details
                </button>
            `;
        } else if (order.status === 'completed') {
            actionButtons = `
                <button class="view-order-btn px-4 py-2 umak-blue text-white rounded-lg font-semibold hover:bg-blue-800 transition-all duration-300" data-order-id="${order.id}">
                    View Details
                </button>
            `;
        } else {
            actionButtons = `
                <button class="view-order-btn px-4 py-2 umak-blue text-white rounded-lg font-semibold hover:bg-blue-800 transition-all duration-300" data-order-id="${order.id}">
                    View Details
                </button>
            `;
        }

        ordersHTML += `
            <div class="order-card bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6" data-order-id="${order.id}">
                <div class="p-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 umak-yellow rounded-lg flex items-center justify-center">
                                <span class="umak-blue-text font-bold text-sm">${(order.restaurant_name || 'OR').substring(0, 2)}</span>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Order #${escapeHtml(order.order_number || order.id)}</h3>
                                <p class="text-sm text-gray-600">Ordered: ${orderDate}</p>
                            </div>
                        </div>
                        <span class="${statusClass} px-3 py-1 rounded-full text-xs font-medium">${statusLabel}</span>
                    </div>
                </div>
                
                <div class="p-4">
                    ${itemsHTML ? `
                        <div class="space-y-4">
                            ${itemsHTML}
                        </div>
                    ` : ''}
                    
                    <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-200">
                        <div class="font-bold text-lg umak-blue-text">Total: ₱${parseFloat(order.total_amount).toFixed(2)}</div>
                        <div class="flex space-x-2">
                            ${actionButtons}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    tabContent.innerHTML = ordersHTML;
    
    // Re-attach event listeners to the new buttons
    attachOrderDetailListeners();
    attachCancelOrderListeners();
}

// Helper function to get status class
function getStatusClass(status) {
    const statusMap = {
        'preparing': 'status-preparing',
        'ready': 'status-ready',
        'completed': 'status-completed',
        'cancelled': 'status-cancelled',
        'failed': 'status-failed',
        'refunded': 'status-refunded'
    };
    return statusMap[status.toLowerCase()] || 'status-pending';
}

// Helper function to get status label
function getStatusLabel(status) {
    const statusLabels = {
        'preparing': 'Preparing',
        'ready': 'Ready for Pickup',
        'completed': 'Completed',
        'cancelled': 'Cancelled',
        'failed': 'Failed',
        'refunded': 'Refunded'
    };
    return statusLabels[status.toLowerCase()] || status.charAt(0).toUpperCase() + status.slice(1);
}

// Helper function to show empty state
function showEmptyState(status) {
    const tabContent = document.getElementById(`${status}-tab`);
    const statusTitles = {
        'preparing': 'No preparing orders',
        'ready': 'No ready orders',
        'completed': 'No completed orders',
        'cancelled': 'No cancelled orders',
        'failed': 'No failed orders',
        'refunded': 'No refunded orders'
    };
    const statusIcons = {
        'preparing': 'fa-clock',
        'ready': 'fa-check-circle',
        'completed': 'fa-history',
        'cancelled': 'fa-times-circle',
        'failed': 'fa-exclamation-triangle',
        'refunded': 'fa-undo'
    };
    const statusMessages = {
        'preparing': 'Your orders will appear here when they\'re being prepared.',
        'ready': 'Your orders will appear here when they\'re ready for pickup.',
        'completed': 'Your completed orders will appear here.',
        'cancelled': 'Your cancelled orders will appear here.',
        'failed': 'Your failed orders will appear here.',
        'refunded': 'Your refunded orders will appear here.'
    };

    tabContent.innerHTML = `
        <div class="empty-state bg-white rounded-xl p-8 text-center">
            <i class="fas ${statusIcons[status]} text-gray-300 mb-4" style="font-size: 4rem;"></i>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">${statusTitles[status]}</h3>
            <p class="text-gray-500">${statusMessages[status]}</p>
        </div>
    `;
}

// Helper function to escape HTML
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Function to attach event listeners to order detail buttons
function attachOrderDetailListeners() {
    document.querySelectorAll('.view-order-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const orderId = this.getAttribute('data-order-id');
            openOrderModal(orderId);
        });
    });
}

// Function to attach event listeners to cancel order buttons
function attachCancelOrderListeners() {
    document.querySelectorAll('.cancel-order-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const orderId = this.getAttribute('data-order-id');
            openCancellationModal(orderId);
        });
    });
}

// Modal functionality - Fetch order details from server
function openOrderModal(orderId) {
    const formData = new FormData();
    formData.append('action', 'get_order_details');
    formData.append('order_id', orderId);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.order) {
            const order = data.order;
            const statusLabel = getStatusLabel(order.status);
            const orderDate = new Date(order.created_at || order.order_date).toLocaleDateString('en-US', {
                month: '2-digit',
                day: '2-digit',
                year: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }).replace(',', ' •');
            
            // Set modal content
            document.getElementById('modal-order-title').textContent = `Order #${order.order_number || order.id}`;
            document.getElementById('modal-order-status').textContent = `Status: ${statusLabel}`;
            document.getElementById('modal-order-id').textContent = `#${order.order_number || order.id}`;
            document.getElementById('modal-status').textContent = statusLabel;
            document.getElementById('modal-restaurant').textContent = order.restaurant_name || 'N/A';
            document.getElementById('modal-order-date').textContent = orderDate;
            document.getElementById('modal-payment-method').textContent = order.payment_method || 'Cash on Delivery';
            
            // Set payment status with proper styling
            const paymentStatusElement = document.getElementById('modal-payment-status');
            paymentStatusElement.textContent = order.payment_status || 'Pending';
            paymentStatusElement.className = 'font-semibold ' + getPaymentStatusClass(order.payment_status);
            
            document.getElementById('modal-total').textContent = `₱${parseFloat(order.total_amount).toFixed(2)}`;
            
            // Show/hide transaction ID
            const transactionContainer = document.getElementById('modal-transaction-id-container');
            const transactionId = document.getElementById('modal-transaction-id');
            if (order.transaction_id) {
                transactionId.textContent = order.transaction_id;
                transactionContainer.classList.remove('hidden');
            } else {
                transactionContainer.classList.add('hidden');
            }
            
            // Populate order items
            const orderItemsContainer = document.getElementById('modal-order-items');
            orderItemsContainer.innerHTML = '';
            
            if (order.order_items && order.order_items.length > 0) {
                order.order_items.forEach(item => {
                    let reviewButton = '';
                    if (order.status === 'completed') {
                        if (item.user_review) {
                            // User has already reviewed this product
                            reviewButton = `
                                <button class="review-badge" onclick="editReview(${order.id}, ${item.product_id}, ${item.user_review.rating}, '${escapeHtml(item.user_review.review_text)}')">
                                    <i class="fas fa-star mr-1"></i>${item.user_review.rating}/5 • Edit
                                </button>
                            `;
                        } else {
                            // User can review this product
                            reviewButton = `
                                <button class="text-sm umak-blue-text hover:underline font-medium" onclick="openReviewModal(${order.id}, ${item.product_id}, '${escapeHtml(item.item_name)}')">
                                    <i class="fas fa-star mr-1"></i>Add Review
                                </button>
                            `;
                        }
                    }

                    const itemHTML = `
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg mb-2">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-white rounded-lg flex items-center justify-center">
                                    ${item.product_image_url ? 
                                        `<img src="${escapeHtml(item.product_image_url)}" alt="${escapeHtml(item.item_name)}" class="w-10 h-10 object-cover rounded">` : 
                                        `<i class="fas fa-utensils text-gray-400"></i>`
                                    }
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900">${escapeHtml(item.item_name)}</h4>
                                    <p class="text-sm text-gray-600">Qty: ${item.quantity} × ₱${parseFloat(item.unit_price).toFixed(2)}</p>
                                    ${reviewButton}
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold text-gray-900">₱${parseFloat(item.total_price).toFixed(2)}</p>
                            </div>
                        </div>
                    `;
                    orderItemsContainer.innerHTML += itemHTML;
                });
            } else {
                orderItemsContainer.innerHTML = '<p class="text-gray-500 text-center">No items found</p>';
            }
            
            // Show the modal
            modal.classList.add('active');
        
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred while loading order details', 'error');
    });
}

// Helper function for payment status styling
function getPaymentStatusClass(status) {
    const statusMap = {
        'Completed': 'payment-status-completed',
        'Pending': 'payment-status-pending',
        'Cancelled': 'payment-status-cancelled',
        'Failed': 'payment-status-failed',
        'Refunded': 'payment-status-refunded'
    };
    return statusMap[status] || 'payment-status-pending';
}

// Open cancellation modal
function openCancellationModal(orderId) {
    currentOrderId = orderId;
    document.getElementById('cancellationReason').value = '';
    cancellationModal.classList.add('active');
}

// Close cancellation modal
function closeCancellationModal() {
    cancellationModal.classList.remove('active');
    currentOrderId = null;
}

// Confirm order cancellation
function confirmCancellation() {
    const reason = document.getElementById('cancellationReason').value.trim();
    
    if (!reason) {
        showNotification('Please provide a cancellation reason', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'cancel_order');
    formData.append('order_id', currentOrderId);
    formData.append('cancellation_reason', reason);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Order cancelled successfully');
            closeCancellationModal();
            // Refresh the orders
            const currentTab = document.querySelector('.tab.active').getAttribute('data-tab');
            loadOrdersForTab(currentTab);
            updateOrderCounts();
        } else {
            showNotification(data.message || 'Failed to cancel order', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred while cancelling the order', 'error');
    });
}

// Open review modal
function openReviewModal(orderId, productId, productName) {
    currentReviewOrderId = orderId;
    currentProductId = productId;
    selectedRating = 0;
    
    // Set product info
    document.getElementById('review-product-info').innerHTML = `
        <h4 class="font-semibold text-gray-900">${productName}</h4>
        <p class="text-sm text-gray-600">Please share your experience with this product</p>
    `;
    
    // Reset form
    document.getElementById('reviewText').value = '';
    document.getElementById('charCount').textContent = '0';
    resetReviewStars();
    
    reviewModal.classList.add('active');
}

// Edit existing review
function editReview(orderId, productId, rating, reviewText) {
    currentReviewOrderId = orderId;
    currentProductId = productId;
    selectedRating = rating;
    
    // Set product info
    document.getElementById('review-product-info').innerHTML = `
        <h4 class="font-semibold text-gray-900">Edit Your Review</h4>
        <p class="text-sm text-gray-600">Update your feedback for this product</p>
    `;
    
    // Set existing values
    document.getElementById('reviewText').value = reviewText;
    document.getElementById('charCount').textContent = reviewText.length;
    highlightStars(rating);
    
    reviewModal.classList.add('active');
}

// Submit review
function submitReview() {
    if (!currentReviewOrderId || !currentProductId) {
        showNotification('No product selected', 'error');
        return;
    }
    
    if (selectedRating === 0) {
        showNotification('Please select a rating', 'error');
        return;
    }
    
    const reviewText = document.getElementById('reviewText').value.trim();
    if (!reviewText) {
        showNotification('Please write a review', 'error');
        return;
    }
    
    if (reviewText.length < 10) {
        showNotification('Review must be at least 10 characters long', 'error');
        return;
    }

    // Disable submit button
    const submitBtn = document.getElementById('submitReviewBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    const formData = new FormData();
    formData.append('action', 'submit_review');
    formData.append('order_id', currentReviewOrderId);
    formData.append('product_id', currentProductId);
    formData.append('rating', selectedRating);
    formData.append('review_text', reviewText);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Review submitted successfully!');
            closeReviewModal();
            
            // Refresh the order details modal to show the updated review
            if (modal.classList.contains('active')) {
                openOrderModal(currentReviewOrderId);
            }
        } else {
            showNotification(data.message || 'Failed to submit review', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    })
    .finally(() => {
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Review';
    });
}

// Close review modal
function closeReviewModal() {
    reviewModal.classList.remove('active');
    currentReviewOrderId = null;
    currentProductId = null;
}

// Star rating functionality
function initializeStarRating() {
    const reviewStars = document.getElementById('reviewStarRating');
    if (reviewStars) {
        reviewStars.addEventListener('mouseover', function(e) {
            if (e.target.classList.contains('interactive')) {
                const rating = parseInt(e.target.getAttribute('data-rating'));
                highlightStars(rating);
            }
        });
        
        reviewStars.addEventListener('mouseout', function() {
            highlightStars(selectedRating);
        });
        
        reviewStars.addEventListener('click', function(e) {
            if (e.target.classList.contains('interactive')) {
                selectedRating = parseInt(e.target.getAttribute('data-rating'));
                highlightStars(selectedRating);
            }
        });
    }
}

// Highlight stars up to the given rating
function highlightStars(rating) {
    const stars = document.querySelectorAll('#reviewStarRating .star');
    stars.forEach((star, index) => {
        if (index < rating) {
            star.classList.add('active', 'hover');
        } else {
            star.classList.remove('active', 'hover');
        }
    });
}

// Reset review stars
function resetReviewStars() {
    const stars = document.querySelectorAll('#reviewStarRating .star');
    stars.forEach(star => {
        star.classList.remove('active', 'hover');
    });
    selectedRating = 0;
}

// Close modal event listeners
closeModal.addEventListener('click', () => {
    modal.classList.remove('active');
});

// Close all modals when clicking outside
window.addEventListener('click', (e) => {
    if (e.target === modal) {
        modal.classList.remove('active');
    }
    if (e.target === cancellationModal) {
        closeCancellationModal();
    }
    if (e.target === reviewModal) {
        closeReviewModal();
    }
});

// Character count for review text
document.getElementById('reviewText')?.addEventListener('input', function() {
    document.getElementById('charCount').textContent = this.value.length;
});

// Enhanced auto-refresh function - always enabled
function startAutoRefresh() {
    // Refresh current tab and counts every 10 seconds
    autoRefreshInterval = setInterval(() => {
        const currentTab = document.querySelector('.tab.active').getAttribute('data-tab');
        loadOrdersForTab(currentTab);
        updateOrderCounts();
    }, 10000); // Refresh every 10 seconds
}

// Function to update order counts only
function updateOrderCounts() {
    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_order_counts'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateBadgeCount('preparing', data.counts.preparing);
            updateBadgeCount('ready', data.counts.ready);
            updateBadgeCount('completed', data.counts.completed);
            updateBadgeCount('cancelled', data.counts.cancelled);
            updateBadgeCount('failed', data.counts.failed);
            updateBadgeCount('refunded', data.counts.refunded);
        }
    })
    .catch(error => {
        console.error('Error updating order counts:', error);
    });
}

// Update badge count for a specific tab
function updateBadgeCount(tab, count) {
    const badge = document.getElementById(`${tab}-badge`);
    const tabElement = document.querySelector(`[data-tab="${tab}"]`);
    
    if (count > 0) {
        if (badge) {
            badge.textContent = count;
        } else {
            // Create badge if it doesn't exist
            const newBadge = document.createElement('span');
            newBadge.className = 'tab-badge';
            newBadge.id = `${tab}-badge`;
            newBadge.textContent = count;
            tabElement.appendChild(newBadge);
        }
    } else if (badge) {
        // Remove badge if count is 0
        badge.remove();
    }
}

// Cart functionality
function updateCartCount() {
    const formData = new FormData();
    formData.append('action', 'get_cart_count');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const cartBadge = document.querySelector('.cart-badge');
            const count = data.count || 0;
            
            if (count > 0) {
                cartBadge.textContent = count > 99 ? '99+' : count;
                cartBadge.style.display = 'flex';
            } else {
                cartBadge.style.display = 'none';
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// Notification function
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `notification ${type === 'error' ? 'error' : type === 'info' ? 'info' : ''}`;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Set initial active tab based on URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const initialTab = urlParams.get('tab') || 'preparing';
    switchTab(initialTab);
    
    // Load orders for initial tab
    loadOrdersForTab(initialTab);
    
    // Initialize cart display
    updateCartCount();
    
    // Attach event listeners to existing order detail buttons
    attachOrderDetailListeners();
    attachCancelOrderListeners();
    
    // Initialize star rating functionality
    initializeStarRating();
    
    // Start auto-refresh immediately
    startAutoRefresh();
});

// Clean up on page unload
window.addEventListener('beforeunload', () => {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
});

// Cart functions (keep existing cart functionality)
function updateCartQuantity(cartId, change) {
    const cartItem = document.querySelector(`[data-cart-id="${cartId}"]`);
    const quantityElement = cartItem.querySelector('.cart-quantity');
    let currentQuantity = parseInt(quantityElement.textContent);
    let newQuantity = currentQuantity + change;

    if (newQuantity <= 0) {
        removeFromCart(cartId);
        return;
    }

    const formData = new FormData();
    formData.append('action', 'update_cart_quantity');
    formData.append('cart_id', cartId);
    formData.append('quantity', newQuantity);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            quantityElement.textContent = newQuantity;
            updateCartCount();
            if (data.cart_data) {
                updateCartDisplay(data.cart_data);
            } else {
                updateCartTotal();
            }
            showNotification(data.message || 'Cart updated');
        } else {
            showNotification(data.message || 'Failed to update cart', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

// Update cart display with new data
function updateCartDisplay(cartData) {
    const cartItemsContainer = document.getElementById('cart-items-container');
    const cartEmpty = document.getElementById('cart-empty');
    const cartTotal = document.getElementById('cart-total');
    const proceedToCheckout = document.getElementById('proceed-to-checkout');
    
    if (cartData.items.length === 0) {
        // Show empty cart message
        cartItemsContainer.classList.add('hidden');
        cartEmpty.classList.remove('hidden');
        proceedToCheckout.classList.add('opacity-50', 'pointer-events-none');
    } else {
        // Hide empty cart message and show items
        cartEmpty.classList.add('hidden');
        cartItemsContainer.classList.remove('hidden');
        proceedToCheckout.classList.remove('opacity-50', 'pointer-events-none');
        
        // Clear existing items
        cartItemsContainer.innerHTML = '';
        
        // Add new items
        cartData.items.forEach(item => {
            const cartItemHTML = `
                <div class="cart-item border-b border-gray-200 pb-4 mb-4" data-cart-id="${item.id}">
                    <div class="flex items-center space-x-3">
                        <div class="flex-shrink-0">
                            ${item.product_image_url ? 
                                `<img src="${item.product_image_url}" alt="${item.product_name}" class="w-16 h-16 object-cover rounded-lg">` : 
                                `<div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center">
                                    <span class="text-gray-400 text-2xl">🍽️</span>
                                </div>`
                            }
                        </div>
                        <div class="flex-1">
                            <h4 class="font-semibold text-gray-900">${item.product_name}</h4>
                            <p class="text-sm text-gray-600">₱${parseFloat(item.price).toFixed(2)}</p>
                            <p class="text-xs text-gray-500">${item.restaurant_name}</p>
                            <div class="flex items-center justify-between mt-2">
                                <div class="flex items-center space-x-2">
                                    <button onclick="updateCartQuantity(${item.id}, -1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-sm hover:bg-gray-300 transition-colors">-</button>
                                    <span class="font-medium cart-quantity">${item.quantity}</span>
                                    <button onclick="updateCartQuantity(${item.id}, 1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-sm hover:bg-gray-300 transition-colors">+</button>
                                </div>
                                <button onclick="removeFromCart(${item.id})" class="text-red-500 hover:text-red-700 text-sm">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            cartItemsContainer.innerHTML += cartItemHTML;
        });
    }
    
    // Update total
    cartTotal.textContent = '₱' + parseFloat(cartData.total).toFixed(2);
}

function removeFromCart(cartId) {
    const formData = new FormData();
    formData.append('action', 'remove_from_cart');
    formData.append('cart_id', cartId);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Item removed from cart');
            updateCartCount();
            if (data.cart_data) {
                updateCartDisplay(data.cart_data);
            } else {
                const cartItem = document.querySelector(`[data-cart-id="${cartId}"]`);
                if (cartItem) {
                    cartItem.remove();
                }
                updateCartTotal();
                checkEmptyCart();
            }
        } else {
            showNotification(data.message || 'Failed to remove item', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

function updateCartTotal() {
    let total = 0;
    const cartItems = document.querySelectorAll('.cart-item');
    
    cartItems.forEach(item => {
        const priceText = item.querySelector('.text-sm.text-gray-600').textContent;
        const price = parseFloat(priceText.replace('₱', '').replace(',', ''));
        const quantity = parseInt(item.querySelector('.cart-quantity').textContent);
        total += price * quantity;
    });
    
    document.getElementById('cart-total').textContent = '₱' + total.toFixed(2);
    
    // Update checkout button state
    const checkoutBtn = document.getElementById('proceed-to-checkout');
    if (cartItems.length === 0) {
        checkoutBtn.classList.add('opacity-50', 'pointer-events-none');
    } else {
        checkoutBtn.classList.remove('opacity-50', 'pointer-events-none');
    }
}

function checkEmptyCart() {
    const cartItems = document.querySelectorAll('.cart-item');
    const emptyState = document.getElementById('cart-empty');
    const cartItemsContainer = document.getElementById('cart-items-container');
    
    if (cartItems.length === 0) {
        if (!emptyState) {
            cartItemsContainer.innerHTML = `
                <div id="cart-empty" class="text-center py-12">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0V19a2 2 0 002 2h7a2 2 0 002-2v-4"></path>
                    </svg>
                    <p class="text-gray-500 mb-2">Your cart is empty</p>
                    <p class="text-sm text-gray-400">Add some delicious items to get started!</p>
                </div>
            `;
        }
    }
}
</script>
</body>
</html>