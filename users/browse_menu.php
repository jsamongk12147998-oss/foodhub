<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { 
    header("Location: ../login/Studlogin.php"); 
    exit(); 
}

// Include database connection - updated path to match your structure
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

// Function to generate star rating display
function generateStarRating($rating, $reviewCount) {
    $stars = '';
    $fullStars = floor($rating);
    $hasHalfStar = ($rating - $fullStars) >= 0.5;
    $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);
    
    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $stars .= '<i class="fas fa-star text-yellow-400"></i>';
    }
    
    // Half star
    if ($hasHalfStar) {
        $stars .= '<i class="fas fa-star-half-alt text-yellow-400"></i>';
    }
    
    // Empty stars
    for ($i = 0; $i < $emptyStars; $i++) {
        $stars .= '<i class="far fa-star text-yellow-400"></i>';
    }
    
    // Add review count
    $stars .= '<span class="text-gray-500 text-xs ml-1">(' . $reviewCount . ')</span>';
    
    return $stars;
}

// Function to format store name for URLs
function formatStoreName($storeName) {
    return preg_replace('/[^a-zA-Z0-9]/', '_', $storeName);
}

// Function to get proper image URL for displaying (adds ../ prefix)
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

// Function to get proper store image URL for displaying (adds ../ prefix)
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

// Function to normalize image path for database storage (removes ../ prefix)
function normalizeImagePath($imagePath) {
    if (empty($imagePath)) {
        return null;
    }
    
    // Remove ../ prefix if present
    if (strpos($imagePath, '../') === 0) {
        return substr($imagePath, 3);
    }
    
    return $imagePath;
}

// Function to get product ratings and review counts
function getProductRatings($conn, $productIds = []) {
    $ratings = [];
    
    if (empty($productIds)) {
        return $ratings;
    }
    
    $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
    $query = "SELECT product_id, 
                     AVG(rating) as avg_rating, 
                     COUNT(*) as review_count 
              FROM product_reviews 
              WHERE product_id IN ($placeholders) 
              GROUP BY product_id";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param(str_repeat('i', count($productIds)), ...$productIds);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $ratings[$row['product_id']] = [
            'avg_rating' => round($row['avg_rating'], 1),
            'review_count' => $row['review_count']
        ];
    }
    
    $stmt->close();
    return $ratings;
}

// Function to get store ratings and review counts
function getStoreRatings($conn, $storeIds = []) {
    $ratings = [];
    
    if (empty($storeIds)) {
        return $ratings;
    }
    
    $placeholders = str_repeat('?,', count($storeIds) - 1) . '?';
    $query = "SELECT p.restaurant_id, 
                     AVG(pr.rating) as avg_rating, 
                     COUNT(*) as review_count 
              FROM product_reviews pr
              JOIN products p ON pr.product_id = p.id
              WHERE p.restaurant_id IN ($placeholders) 
              GROUP BY p.restaurant_id";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param(str_repeat('i', count($storeIds)), ...$storeIds);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $ratings[$row['restaurant_id']] = [
            'avg_rating' => round($row['avg_rating'], 1),
            'review_count' => $row['review_count']
        ];
    }
    
    $stmt->close();
    return $ratings;
}

// Function to get product reviews with user information
function getProductReviews($conn, $product_id, $limit = 10, $offset = 0, $sort_by = 'newest') {
    $reviews = [];
    
    // Define sort order
    $sort_columns = [
        'newest' => 'pr.created_at DESC',
        'highest' => 'pr.rating DESC, pr.created_at DESC',
        'lowest' => 'pr.rating ASC, pr.created_at DESC',
        'most_helpful' => 'pr.created_at DESC' // Placeholder - add helpful_count column if needed
    ];
    
    $sort_order = $sort_columns[$sort_by] ?? 'pr.created_at DESC';
    
    $query = "SELECT pr.*, u.name as user_name, u.profile_image 
              FROM product_reviews pr 
              JOIN users u ON pr.user_id = u.id 
              WHERE pr.product_id = ? 
              ORDER BY $sort_order 
              LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iii", $product_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    
    $stmt->close();
    return $reviews;
}

// Function to get product rating statistics
function getProductRatingStats($conn, $product_id) {
    $stats = [
        'average_rating' => 0,
        'total_reviews' => 0,
        'rating_distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]
    ];
    
    $query = "SELECT 
                COUNT(*) as total_reviews,
                AVG(rating) as average_rating,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as rating_1,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as rating_2,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as rating_3,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as rating_4,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as rating_5
              FROM product_reviews 
              WHERE product_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $stats['total_reviews'] = $row['total_reviews'];
        $stats['average_rating'] = round($row['average_rating'] ?? 0, 1);
        $stats['rating_distribution'][1] = $row['rating_1'];
        $stats['rating_distribution'][2] = $row['rating_2'];
        $stats['rating_distribution'][3] = $row['rating_3'];
        $stats['rating_distribution'][4] = $row['rating_4'];
        $stats['rating_distribution'][5] = $row['rating_5'];
    }
    
    $stmt->close();
    return $stats;
}

// Function to get search suggestions
function getSearchSuggestions($conn, $query) {
    $suggestions = [];
    
    if (strlen($query) < 2) {
        return $suggestions;
    }
    
    // Search products
    $products_query = "SELECT p.name, p.category, r.name as restaurant_name 
                      FROM products p 
                      JOIN restaurants r ON p.restaurant_id = r.id 
                      WHERE p.is_available = 1 
                      AND (p.name LIKE ? OR p.category LIKE ?)
                      LIMIT 5";
    $products_stmt = $conn->prepare($products_query);
    $search_param = "%$query%";
    $products_stmt->bind_param("ss", $search_param, $search_param);
    $products_stmt->execute();
    $products_result = $products_stmt->get_result();
    
    while ($row = $products_result->fetch_assoc()) {
        $suggestions[] = [
            'type' => 'food',
            'name' => $row['name'],
            'category' => $row['category'],
            'restaurant' => $row['restaurant_name']
        ];
    }
    $products_stmt->close();
    
    // Search stores
    $stores_query = "SELECT name, category FROM restaurants 
                    WHERE is_active = 1 
                    AND (name LIKE ? OR category LIKE ?)
                    LIMIT 5";
    $stores_stmt = $conn->prepare($stores_query);
    $stores_stmt->bind_param("ss", $search_param, $search_param);
    $stores_stmt->execute();
    $stores_result = $stores_stmt->get_result();
    
    while ($row = $stores_result->fetch_assoc()) {
        $suggestions[] = [
            'type' => 'store',
            'name' => $row['name'],
            'category' => $row['category']
        ];
    }
    $stores_stmt->close();
    
    return $suggestions;
}

// Get user details from database using session user_id
$userName = 'User';
$firstLetter = 'U';
$user_id = $_SESSION['user_id'];

// Fetch user details from users table
$user_query = "SELECT name, email, role, profile_image FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();

if ($user_result->num_rows > 0) {
    $user_data = $user_result->fetch_assoc();
    $userName = $user_data['name'];
    $firstLetter = strtoupper(substr($userName, 0, 1));
} else {
    // If user not found in database, log them out
    session_destroy();
    header("Location: ../login/Studlogin.php");
    exit();
}
$user_stmt->close();

// Fetch user favorites from the new tables
$user_favorites = [];

// Fetch favorite items
$favorite_items_query = "SELECT product_id, restaurant_id FROM favorite_items WHERE user_id = ?";
$favorite_items_stmt = $conn->prepare($favorite_items_query);
$favorite_items_stmt->bind_param("i", $user_id);
$favorite_items_stmt->execute();
$favorite_items_result = $favorite_items_stmt->get_result();

if ($favorite_items_result) {
    while ($row = $favorite_items_result->fetch_assoc()) {
        $user_favorites['item_' . $row['product_id']] = true;
    }
}
$favorite_items_stmt->close();

// Fetch favorite stores
$favorite_stores_query = "SELECT restaurant_id FROM favorite_stores WHERE user_id = ?";
$favorite_stores_stmt = $conn->prepare($favorite_stores_query);
$favorite_stores_stmt->bind_param("i", $user_id);
$favorite_stores_stmt->execute();
$favorite_stores_result = $favorite_stores_stmt->get_result();

if ($favorite_stores_result) {
    while ($row = $favorite_stores_result->fetch_assoc()) {
        $user_favorites['store_' . $row['restaurant_id']] = true;
    }
}
$favorite_stores_stmt->close();

// Fetch cart items for the current user
$cart_items = [];
$cart_total = 0;
$cart_count = 0;
$cart_item_count = 0; // Count distinct items

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
        $cart_item_count++; // Count each distinct item
    }
}
$stmt->close();

// Handle search functionality - REDIRECT TO SEARCH.PHP
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
    $search_query = trim($_GET['search']);
    $search_category = $_GET['category'] ?? 'all';
    
    if (!empty($search_query)) {
        // Redirect to search.php with search parameters
        $redirect_url = "search.php?q=" . urlencode($search_query) . "&type=" . urlencode($search_category);
        header("Location: " . $redirect_url);
        exit();
    }
}

// Fetch all stores with their products (only if not searching)
$stores_with_products = [];
$all_product_ids = [];
$all_store_ids = [];

$stores_query = "SELECT r.*, 
                COUNT(p.id) as product_count,
                AVG(p.price) as avg_price
                FROM restaurants r 
                LEFT JOIN products p ON r.id = p.restaurant_id AND p.is_available = 1
                WHERE r.is_active = 1 
                GROUP BY r.id 
                ORDER BY r.name";
$stores_result = $conn->query($stores_query);

if ($stores_result) {
    while ($store = $stores_result->fetch_assoc()) {
        // Fix store image URLs
        if (!empty($store['restaurant_image_url'])) {
            $store['restaurant_image_url'] = getStoreImageUrl($store['restaurant_image_url'], $store['name']);
        }
        
        // Fetch products for this store
        $products_query = "SELECT * FROM products 
                          WHERE restaurant_id = ? AND is_available = 1 
                          ORDER BY category, name";
        $products_stmt = $conn->prepare($products_query);
        $products_stmt->bind_param("i", $store['id']);
        $products_stmt->execute();
        $products_result = $products_stmt->get_result();
        
        $products = [];
        $categories = [];
        
        while ($product = $products_result->fetch_assoc()) {
            // Fix product image URLs
            if (!empty($product['product_image_url'])) {
                $product['product_image_url'] = getProductImageUrl($product['product_image_url'], $store['name']);
            }
            $products[] = $product;
            $all_product_ids[] = $product['id'];
            $category = $product['category'] ?? 'Uncategorized';
            if (!in_array($category, $categories)) {
                $categories[] = $category;
            }
        }
        
        $all_store_ids[] = $store['id'];
        $stores_with_products[] = [
            'store' => $store,
            'products' => $products,
            'categories' => $categories
        ];
        
        $products_stmt->close();
    }
}

// Get ratings for all products and stores
$product_ratings = getProductRatings($conn, $all_product_ids);
$store_ratings = getStoreRatings($conn, $all_store_ids);

// Get unique categories across all stores for filtering
$all_categories_query = "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' AND is_available = 1 ORDER BY category";
$all_categories_result = $conn->query($all_categories_query);
$all_categories = [];
while ($row = $all_categories_result->fetch_assoc()) {
    $all_categories[] = $row['category'];
}

// Handle AJAX requests for cart operations and reviews
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

        case 'toggle_favorite':
            if (isset($_POST['product_id'], $_POST['product_name'], $_POST['product_price'], $_POST['product_category'], $_POST['store_name'], $_POST['product_image'], $_POST['product_description'], $_POST['restaurant_id'])) {
                $product_id = intval($_POST['product_id']);
                $product_name = $_POST['product_name'];
                $product_price = floatval($_POST['product_price']);
                $product_category = $_POST['product_category'];
                $store_name = $_POST['store_name'];
                $product_image = $_POST['product_image'];
                $product_description = $_POST['product_description'];
                $restaurant_id = intval($_POST['restaurant_id']);
                
                // Normalize image path - remove ../ prefix if present
                $product_image_normalized = normalizeImagePath($product_image);
                
                // Check if already favorited in favorite_items table
                $check_query = "SELECT id FROM favorite_items WHERE user_id = ? AND product_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $user_id, $product_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    // Remove from favorites
                    $delete_query = "DELETE FROM favorite_items WHERE user_id = ? AND product_id = ?";
                    $delete_stmt = $conn->prepare($delete_query);
                    $delete_stmt->bind_param("ii", $user_id, $product_id);
                    $delete_stmt->execute();
                    $response['success'] = $delete_stmt->affected_rows > 0;
                    $response['is_favorite'] = false;
                    $response['message'] = $response['success'] ? 'Removed from favorites' : 'Failed to remove from favorites';
                    $delete_stmt->close();
                } else {
                    // Add to favorites - use normalized path
                    $insert_query = "INSERT INTO favorite_items (user_id, product_id, restaurant_id, product_name, product_price, product_category, store_name, product_image_url, product_description, restaurant_image_url) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, (SELECT restaurant_image_url FROM restaurants WHERE id = ?))";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bind_param("iiisddsssi", $user_id, $product_id, $restaurant_id, $product_name, $product_price, $product_category, $store_name, $product_image_normalized, $product_description, $restaurant_id);
                    $insert_stmt->execute();
                    $response['success'] = $insert_stmt->affected_rows > 0;
                    $response['is_favorite'] = true;
                    $response['message'] = $response['success'] ? 'Added to favorites' : 'Failed to add to favorites';
                    $insert_stmt->close();
                }
                
                $check_stmt->close();
            }
            break;

        case 'toggle_store_favorite':
            if (isset($_POST['restaurant_id'], $_POST['restaurant_name'], $_POST['description'])) {
                $restaurant_id = intval($_POST['restaurant_id']);
                $restaurant_name = $_POST['restaurant_name'];
                $description = $_POST['description'];
                
                // Check if already favorited in favorite_stores table
                $check_query = "SELECT id FROM favorite_stores WHERE user_id = ? AND restaurant_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $user_id, $restaurant_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    // Remove from favorites
                    $delete_query = "DELETE FROM favorite_stores WHERE user_id = ? AND restaurant_id = ?";
                    $delete_stmt = $conn->prepare($delete_query);
                    $delete_stmt->bind_param("ii", $user_id, $restaurant_id);
                    $delete_stmt->execute();
                    $response['success'] = $delete_stmt->affected_rows > 0;
                    $response['is_favorite'] = false;
                    $response['message'] = $response['success'] ? 'Store removed from favorites' : 'Failed to remove store from favorites';
                    $delete_stmt->close();
                } else {
                    // Add to favorites with restaurant image
                    $insert_query = "INSERT INTO favorite_stores (user_id, restaurant_id, restaurant_name, description, restaurant_image_url) 
                                    VALUES (?, ?, ?, ?, (SELECT restaurant_image_url FROM restaurants WHERE id = ?))";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bind_param("iissi", $user_id, $restaurant_id, $restaurant_name, $description, $restaurant_id);
                    $insert_stmt->execute();
                    $response['success'] = $insert_stmt->affected_rows > 0;
                    $response['is_favorite'] = true;
                    $response['message'] = $response['success'] ? 'Store added to favorites' : 'Failed to add store to favorites';
                    $insert_stmt->close();
                }
                
                $check_stmt->close();
            }
            break;

        case 'get_reviews':
            if (isset($_POST['product_id'])) {
                $product_id = intval($_POST['product_id']);
                $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
                $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 5;
                $sort_by = $_POST['sort_by'] ?? 'newest';
                $offset = ($page - 1) * $per_page;
                
                $reviews = getProductReviews($conn, $product_id, $per_page, $offset, $sort_by);
                $rating_stats = getProductRatingStats($conn, $product_id);
                
                // Get total reviews count for pagination
                $total_query = "SELECT COUNT(*) as total FROM product_reviews WHERE product_id = ?";
                $total_stmt = $conn->prepare($total_query);
                $total_stmt->bind_param("i", $product_id);
                $total_stmt->execute();
                $total_result = $total_stmt->get_result();
                $total_data = $total_result->fetch_assoc();
                $total_reviews = $total_data['total'];
                $total_pages = ceil($total_reviews / $per_page);
                
                $response['success'] = true;
                $response['reviews'] = $reviews;
                $response['rating_stats'] = $rating_stats;
                $response['pagination'] = [
                    'current_page' => $page,
                    'total_pages' => $total_pages,
                    'total_reviews' => $total_reviews,
                    'per_page' => $per_page
                ];
                
                $total_stmt->close();
            }
            break;
            
        case 'search_suggestions':
            if (isset($_POST['query'])) {
                $query = $conn->real_escape_string($_POST['query']);
                $response['success'] = true;
                $response['suggestions'] = getSearchSuggestions($conn, $query);
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
        'item_count' => 0 // Count distinct items
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
            $cart_data['item_count']++; // Count distinct items
        }
    }
    $stmt->close();
    
    return $cart_data;
}

// Prepare products data for JavaScript
$products_js = [];
foreach ($stores_with_products as $store_data) {
    foreach ($store_data['products'] as $product) {
        $rating_data = $product_ratings[$product['id']] ?? ['avg_rating' => 0.0, 'review_count' => 0];
        $products_js[$product['id']] = [
            'name' => $product['name'],
            'description' => $product['description'],
            'price' => $product['price'],
            'image' => $product['product_image_url'] ?? null,
            'category' => $product['category'] ?? 'General',
            'restaurant_id' => $product['restaurant_id'],
            'restaurant_name' => $store_data['store']['name'],
            'restaurant_image_url' => $store_data['store']['restaurant_image_url'] ?? null,
            'avg_rating' => $rating_data['avg_rating'],
            'review_count' => $rating_data['review_count']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Menu - UMAK Foodhub</title>
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
        
        .store-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.15);
        }
        
        .food-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
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

        /* Menu Product Grid Styles */
        .menu-product-grid {
            display: grid;
            gap: 1.5rem;
        }

        .menu-product-grid .food-card {
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
        }

        .menu-product-grid .food-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            border-color: #fbbf24;
        }

        /* Line clamp utility for description */
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Store Section Styles */
        .store-section {
            margin-bottom: 3rem;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
        }

        .store-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3730a3 100%);
            color: white;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .store-header:hover {
            background: linear-gradient(135deg, #3730a3 0%, #1e3a8a 100%);
        }

        .store-content {
            background: white;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }

        .store-content.expanded {
            max-height: 5000px;
        }

        .category-section {
            margin-bottom: 2rem;
        }

        .category-header {
            font-size: 1.25rem;
            font-weight: bold;
            color: #1e3a8a;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #fbbf24;
        }

        /* Filter Styles */
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .filter-tag {
            display: inline-flex;
            align-items: center;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            padding: 0.5rem 1rem;
            margin: 0.25rem;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .filter-tag:hover {
            background: #e5e7eb;
        }

        .filter-tag.active {
            background: #fbbf24;
            color: #1e3a8a;
            border-color: #fbbf24;
        }

        .filter-tag .remove {
            margin-left: 0.5rem;
            cursor: pointer;
            opacity: 0.7;
        }

        .filter-tag .remove:hover {
            opacity: 1;
        }

        /* Favorite Button Styles */
        .favorite-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 10;
        }

        .favorite-btn:hover {
            background: white;
            transform: scale(1.1);
        }

        .favorite-btn.active {
            background: #fbbf24;
        }

        .favorite-btn.active i {
            color: #dc2626;
        }

        .favorite-btn i {
            color: #6b7280;
            transition: color 0.3s ease;
        }

        /* Store Favorite Button */
        .store-favorite-btn {
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .store-favorite-btn:hover {
            background: white;
            transform: scale(1.1);
        }

        .store-favorite-btn.active {
            background: #fbbf24;
        }

        .store-favorite-btn.active i {
            color: #dc2626;
        }

        .store-favorite-btn i {
            color: #6b7280;
            transition: color 0.3s ease;
            font-size: 1.1rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        /* Cart image styling */
        .cart-item-image {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 8px;
        }

        .cart-item-image-fallback {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        /* Modal Styles - FIXED CENTERING */
        .modal-overlay {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-content {
            position: relative;
            margin: auto;
            max-width: 500px;
            width: 100%;
        }

        /* Enhanced Review Styles */
        .review-card {
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .review-card:hover {
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .review-user {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .review-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
        }

        .review-user-info h4 {
            font-weight: 600;
            margin-bottom: 4px;
            color: #1f2937;
        }

        .review-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #6b7280;
        }

        .review-verified {
            display: flex;
            align-items: center;
            gap: 4px;
            color: #10b981;
            font-size: 12px;
            font-weight: 500;
        }

        .review-content {
            margin-top: 16px;
        }

        .review-text {
            line-height: 1.6;
            color: #4b5563;
            margin-bottom: 12px;
        }

        .review-images {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .review-image {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            object-fit: cover;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .review-image:hover {
            transform: scale(1.05);
        }

        .review-helpful {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid #f3f4f6;
        }

        .helpful-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 14px;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
        }

        .helpful-btn:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
        }

        .helpful-btn.active {
            background: #eff6ff;
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .review-reply {
            margin-top: 16px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
            border-left: 3px solid #e5e7eb;
        }

        .review-reply-header {
            font-weight: 600;
            margin-bottom: 6px;
            color: #374151;
        }

        .review-reply-text {
            color: #6b7280;
            line-height: 1.5;
        }

        .review-sort {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .sort-select {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: white;
            font-size: 14px;
            color: #374151;
        }

        .review-summary-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .rating-breakdown {
            margin-top: 16px;
        }

        .rating-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }

        .rating-label {
            width: 60px;
            font-size: 14px;
            color: #6b7280;
        }

        .rating-bar-bg {
            flex: 1;
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }

        .rating-bar-fill {
            height: 100%;
            background: #fbbf24;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .rating-count {
            width: 40px;
            font-size: 14px;
            color: #6b7280;
            text-align: right;
        }

        .review-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
        }

        .pagination-btn {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: white;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
        }

        .pagination-btn:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        .pagination-btn.active {
            background: #1e3a8a;
            border-color: #1e3a8a;
            color: white;
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .review-loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: #6b7280;
        }

        .review-loading .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #e5e7eb;
            border-top: 2px solid #1e3a8a;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .review-placeholder {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
        }

        .review-placeholder-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Star Rating Styles */
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

        /* Remove interactive styles for review stars */
        .star-rating .star.interactive {
            cursor: default;
        }
        
        .star-rating .star.interactive:hover {
            color: inherit;
        }

        /* Search Suggestions Dropdown */
        .search-suggestions-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .search-suggestion-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s;
        }

        .search-suggestion-item:hover {
            background-color: #f9fafb;
        }

        .search-suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-type {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .suggestion-name {
            font-weight: 500;
            color: #1f2937;
            margin-bottom: 2px;
        }

        .suggestion-details {
            font-size: 0.875rem;
            color: #6b7280;
        }

        .search-container {
            position: relative;
        }

        .search-container:focus-within .search-suggestions-dropdown {
            display: block;
        }

        /* Responsive grid adjustments */
        @media (max-width: 640px) {
            .menu-product-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        @media (min-width: 641px) and (max-width: 768px) {
            .menu-product-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .menu-product-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (min-width: 1025px) {
            .menu-product-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-full">
<!-- Overlay -->
<div id="overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden overlay"></div>

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

            <!-- Center: Search Bar - UPDATED TO REDIRECT TO SEARCH.PHP WITH SUGGESTIONS -->
            <div class="hidden md:flex items-center flex-1 max-w-2xl mx-8">
                <div class="search-container w-full">
                    <form method="GET" action="search.php" class="flex items-center w-full bg-white rounded-lg shadow-sm border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-yellow-400 focus-within:border-yellow-400 transition-all duration-200">
                        <select name="type" class="px-4 py-3 bg-white border-none text-sm focus:outline-none focus:ring-0 appearance-none cursor-pointer">
                            <option value="all">All</option>
                            <option value="foods">Foods</option>
                            <option value="shops">Stores</option>
                        </select>
                        <div class="w-px h-6 bg-gray-300"></div>
                        <input type="text" name="q" placeholder="What are you craving today?" 
                            class="flex-1 px-4 py-3 border-none focus:outline-none focus:ring-0 text-sm" id="search-input" autocomplete="off">
                        <button type="submit" class="px-6 py-3 umak-yellow umak-blue-text hover:bg-yellow-300 transition-all duration-300 font-medium border-l border-yellow-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </button>
                    </form>
                    <div class="search-suggestions-dropdown" id="search-suggestions"></div>
                </div>
            </div>

            <!-- Right: Cart and Profile -->
            <div class="flex items-center space-x-4">
                <button id="cart-btn" class="relative p-2 text-white hover:text-yellow-300 hover:bg-blue-800 rounded-lg transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0V19a2 2 0 002 2h7a2 2 0 002-2v-4"></path>
                    </svg>
                    <span class="cart-badge"><?php echo $cart_item_count; ?></span>
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
            <li><a href="browse_menu.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg bg-yellow-50 umak-blue-text font-medium border-l-4 umak-yellow-border">
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
            <li><a href="my_orders.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
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
                                 class="cart-item-image"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <?php endif; ?>
                        <div class="cart-item-image-fallback <?php echo !empty($item['product_image_url']) ? 'hidden' : ''; ?>">
                            <span>🍽️</span>
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
        <!-- Mobile Search - UPDATED TO REDIRECT TO SEARCH.PHP WITH SUGGESTIONS -->
        <div class="md:hidden mb-6">
            <div class="search-container">
                <form method="GET" action="search.php" class="flex items-center bg-white rounded-lg shadow-sm border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-yellow-400 focus-within:border-yellow-400 transition-all duration-200">
                    <select name="type" class="px-3 py-3 bg-white border-none text-sm focus:outline-none focus:ring-0 appearance-none cursor-pointer flex-shrink-0">
                        <option value="all">All</option>
                        <option value="foods">Foods</option>
                        <option value="shops">Stores</option>
                    </select>
                    <div class="w-px h-6 bg-gray-300 flex-shrink-0"></div>
                    <input type="text" name="q" placeholder="Search food or stores..." 
                        class="flex-1 px-3 py-3 border-none focus:outline-none focus:ring-0 text-sm" id="mobile-search-input">
                    <button type="submit" class="px-4 py-3 umak-yellow umak-blue-text hover:bg-yellow-300 transition-all duration-300 font-medium border-l border-yellow-400 flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </form>
                <div class="search-suggestions-dropdown" id="mobile-search-suggestions"></div>
            </div>
        </div>

        <!-- Page Header -->
        <section class="mb-8">
            <div class="umak-blue rounded-2xl p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-bold mb-2">Browse Menu 👨‍🍳</h1>
                        <p class="text-lg opacity-90">Discover all our delicious offerings from different stores</p>
                    </div>
                    <div class="hidden md:block">
                        <div class="w-16 h-16 umak-yellow rounded-full flex items-center justify-center">
                            <span class="text-3xl">📚</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Filters Section -->
        <section class="filter-section mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-4">
                <h2 class="text-xl font-bold text-gray-900">Filter Options</h2>
                <div class="flex flex-wrap gap-2">
                    <button id="clearFilters" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 transition-colors">
                        Clear All Filters
                    </button>
                </div>
            </div>
            
            <!-- Category Filters -->
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-gray-800 mb-3">Categories</h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($all_categories as $category): ?>
                    <span class="filter-tag category-filter" data-category="<?php echo htmlspecialchars($category); ?>">
                        <?php echo htmlspecialchars($category); ?>
                        <span class="remove hidden">×</span>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Store Filters -->
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-gray-800 mb-3">Stores</h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($stores_with_products as $store_data): ?>
                    <span class="filter-tag store-filter" data-store="<?php echo htmlspecialchars($store_data['store']['id']); ?>">
                        <?php echo htmlspecialchars($store_data['store']['name']); ?>
                        <span class="remove hidden">×</span>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Active Filters -->
            <div id="activeFilters" class="hidden">
                <h3 class="text-lg font-semibold text-gray-800 mb-3">Active Filters</h3>
                <div id="activeFiltersList" class="flex flex-wrap gap-2"></div>
            </div>
        </section>

        <!-- Stores and Products Section -->
        <section id="storesSection">
            <?php if (empty($stores_with_products)): ?>
            <div class="empty-state">
                <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M12 12h.01M12 2a10 10 0 100 20 10 10 0 000-20z"></path>
                </svg>
                <h3 class="text-lg font-semibold text-gray-600 mb-2">No stores available</h3>
                <p class="text-gray-500">Check back later for new stores and menu items.</p>
            </div>
            <?php else: ?>
                <?php foreach ($stores_with_products as $index => $store_data): 
                    $isStoreFavorite = isset($user_favorites['store_' . $store_data['store']['id']]);
                    $store_rating_data = $store_ratings[$store_data['store']['id']] ?? ['avg_rating' => 0.0, 'review_count' => 0];
                ?>
                <div class="store-section" data-store-id="<?php echo $store_data['store']['id']; ?>" data-store-categories="<?php echo htmlspecialchars(implode(',', $store_data['categories'])); ?>">
                    <!-- Store Header -->
                    <div class="store-header" onclick="toggleStoreSection(<?php echo $index; ?>)">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center space-x-4">
                                <div class="w-12 h-12 umak-yellow rounded-full flex items-center justify-center">
                                    <span class="umak-blue-text font-bold">🏪</span>
                                </div>
                                <div>
                                    <h2 class="text-xl font-bold"><?php echo htmlspecialchars($store_data['store']['name']); ?></h2>
                                    <div class="flex items-center space-x-4 mt-1 text-sm opacity-90">
                                        <span class="flex items-center space-x-1">
                                            <i class="fas fa-utensils"></i>
                                            <span><?php echo count($store_data['products']); ?> items</span>
                                        </span>
                                        <span class="flex items-center space-x-1">
                                            <i class="fas fa-tags"></i>
                                            <span><?php echo count($store_data['categories']); ?> categories</span>
                                        </span>
                                        <span class="flex items-center space-x-1">
                                            <?php echo generateStarRating($store_rating_data['avg_rating'], $store_rating_data['review_count']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center space-x-3">
                                <button class="store-favorite-btn <?php echo $isStoreFavorite ? 'active' : ''; ?>" 
                                        onclick="event.stopPropagation(); toggleStoreFavorite(<?php echo $store_data['store']['id']; ?>, '<?php echo htmlspecialchars($store_data['store']['name'], ENT_QUOTES); ?>', this)"
                                        title="<?php echo $isStoreFavorite ? 'Remove from favorites' : 'Add to favorites'; ?>">
                                    <i class="fas fa-heart"></i>
                                </button>
                                <span class="text-sm bg-white umak-blue-text px-3 py-1 rounded-full font-medium">
                                    Browse Menu
                                </span>
                                <i class="fas fa-chevron-down transition-transform duration-300" id="store-arrow-<?php echo $index; ?>"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Store Content -->
                    <div class="store-content" id="store-content-<?php echo $index; ?>">
                        <div class="p-6">
                            <?php if (empty($store_data['products'])): ?>
                            <div class="empty-state py-8">
                                <p class="text-gray-500">No menu items available for this store.</p>
                            </div>
                            <?php else: ?>
                                <!-- Group products by category -->
                                <?php 
                                $products_by_category = [];
                                foreach ($store_data['products'] as $product) {
                                    $category = $product['category'] ?? 'Uncategorized';
                                    if (!isset($products_by_category[$category])) {
                                        $products_by_category[$category] = [];
                                    }
                                    $products_by_category[$category][] = $product;
                                }
                                ?>

                                <?php foreach ($products_by_category as $category => $category_products): ?>
                                <div class="category-section" data-category="<?php echo htmlspecialchars($category); ?>">
                                    <h3 class="category-header"><?php echo htmlspecialchars($category); ?></h3>
                                    <div class="menu-product-grid">
                                        <?php foreach ($category_products as $product): 
                                            $isFavorite = isset($user_favorites['item_' . $product['id']]);
                                            $rating_data = $product_ratings[$product['id']] ?? ['avg_rating' => 0.0, 'review_count' => 0];
                                        ?>
                                        <div class="food-card bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300 cursor-pointer relative" 
                                             onclick="openProductPopup(<?php echo $product['id']; ?>)"
                                             data-product-category="<?php echo htmlspecialchars($category); ?>">
                                            
                                            <!-- Favorite Button -->
                                            <button class="favorite-btn <?php echo $isFavorite ? 'active' : ''; ?>" 
                                                    onclick="event.stopPropagation(); toggleFavorite(<?php echo $product['id']; ?>, this)">
                                                <i class="fas fa-heart <?php echo $isFavorite ? 'text-red-600' : 'text-gray-500'; ?>"></i>
                                            </button>
                                            
                                            <!-- Product Image -->
                                            <div class="h-48 relative overflow-hidden">
                                                <?php if (!empty($product['product_image_url'])): ?>
                                                    <img src="<?php echo htmlspecialchars($product['product_image_url']); ?>" 
                                                         alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                                         class="w-full h-full object-cover transition-transform duration-300 hover:scale-105"
                                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <?php endif; ?>
                                                <div class="w-full h-full umak-blue flex items-center justify-center <?php echo !empty($product['product_image_url']) ? 'hidden' : ''; ?>">
                                                    <span class="text-white text-4xl font-bold">🍽️</span>
                                                </div>
                                                
                                                <!-- Category Badge -->
                                                <div class="absolute top-3 left-3 umak-yellow umak-blue-text px-2 py-1 rounded-full text-xs font-bold z-10">
                                                    <?php echo htmlspecialchars($category); ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Product Info -->
                                            <div class="p-4">
                                                <h3 class="font-bold text-lg text-gray-900 mb-1 truncate"><?php echo htmlspecialchars($product['name']); ?></h3>
                                                <p class="text-sm text-gray-600 mb-2"><?php echo htmlspecialchars($store_data['store']['name']); ?></p>
                                                <p class="text-xs text-gray-500 mb-3 line-clamp-2"><?php echo htmlspecialchars($product['description'] ?? 'Delicious dish from our restaurant'); ?></p>
                                                
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center space-x-2 text-sm">
                                                        <?php echo generateStarRating($rating_data['avg_rating'], $rating_data['review_count']); ?>
                                                    </div>
                                                    <span class="font-bold umak-blue-text text-lg">₱<?php echo number_format($product['price'], 2); ?></span>
                                                </div>
                                                
                                                <!-- Availability -->
                                                <div class="flex items-center text-xs mt-2 text-green-600">
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewbox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                    </svg>
                                                    <span>Available</span>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</main>

<!-- Enhanced Product Popup Modal with Reviews -->
<div id="productPopup" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-gray-900" id="popupProductName">Product Name</h3>
                <button onclick="closeProductPopup()" class="text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Product Image and Basic Info -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <div id="popupProductImageContainer" class="w-full h-64 bg-gray-100 rounded-lg mb-4 flex items-center justify-center overflow-hidden">
                        <img id="popupProductImage" src="" alt="" class="w-full h-full object-cover hidden">
                        <div id="popupProductImagePlaceholder" class="text-gray-400 text-4xl">🍽️</div>
                    </div>
                </div>
                <div>
                    <p id="popupProductDescription" class="text-gray-600 mb-4"></p>
                    
                    <!-- Rating Summary -->
                    <div id="popupRatingSummary" class="mb-4">
                        <div class="flex items-center mb-2">
                            <div class="text-3xl font-bold umak-blue-text mr-3" id="popupAverageRating">0.0</div>
                            <div>
                                <div class="star-rating text-lg mb-1" id="popupStarRating">
                                    <!-- Stars will be generated here -->
                                </div>
                                <div class="text-sm text-gray-600" id="popupReviewCount">0 reviews</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Price and Quantity -->
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-2xl font-bold umak-blue-text" id="popupProductPrice">₱0.00</span>
                        <div class="flex items-center space-x-3 bg-gray-100 rounded-full px-3 py-1">
                            <button onclick="updatePopupQuantity(-1)" class="w-8 h-8 rounded-full umak-blue text-white flex items-center justify-center hover:bg-blue-800 transition-colors">-</button>
                            <span id="popupQuantity" class="font-semibold text-lg min-w-8 text-center">1</span>
                            <button onclick="updatePopupQuantity(1)" class="w-8 h-8 rounded-full umak-blue text-white flex items-center justify-center hover:bg-blue-800 transition-colors">+</button>
                        </div>
                    </div>
                    
                    <button onclick="addToCartFromPopup()" class="w-full umak-yellow umak-blue-text py-3 rounded-lg font-semibold hover:bg-yellow-300 transition-all duration-300 font-bold text-lg mb-4">
                        Add to Cart
                    </button>
                </div>
            </div>
            
            <!-- Enhanced Reviews Section (READ-ONLY) -->
            <div class="border-t border-gray-200 pt-6">
                <h4 class="text-lg font-bold text-gray-900 mb-4">Ratings & Reviews</h4>
                
                <!-- Review Summary Card -->
                <div class="review-summary-card">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="text-center">
                            <div class="text-4xl font-bold umak-blue-text mb-2" id="summaryAverageRating">0.0</div>
                            <div class="star-rating text-lg mb-2" id="summaryStarRating"></div>
                            <div class="text-sm text-gray-600" id="summaryReviewCount">0 reviews</div>
                        </div>
                        <div class="md:col-span-2">
                            <div class="rating-breakdown">
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                <div class="rating-row">
                                    <div class="rating-label"><?php echo $i; ?> ★</div>
                                    <div class="rating-bar-bg">
                                        <div class="rating-bar-fill" id="ratingBar<?php echo $i; ?>" style="width: 0%"></div>
                                    </div>
                                    <div class="rating-count" id="ratingCount<?php echo $i; ?>">0</div>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Reviews Filter and Sort -->
                <div class="review-sort">
                    <span class="text-sm text-gray-600">Sort by:</span>
                    <select id="reviewSort" class="sort-select" onchange="loadReviews(currentProductId)">
                        <option value="newest">Newest First</option>
                        <option value="highest">Highest Rating</option>
                        <option value="lowest">Lowest Rating</option>
                        <option value="most_helpful">Most Helpful</option>
                    </select>
                </div>
                
                <!-- Reviews List -->
                <div id="reviewsContainer">
                    <div class="review-loading">
                        <div class="spinner"></div>
                        <span>Loading reviews...</span>
                    </div>
                </div>
                
                <!-- Pagination -->
                <div class="review-pagination" id="reviewPagination">
                    <!-- Pagination will be generated here -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Product data from PHP
const products = <?php echo json_encode($products_js); ?>;
// Store data from PHP
const stores = <?php echo json_encode(array_column($stores_with_products, 'store')); ?>;
let currentProductId = null;
let currentStoreId = null;
let popupQuantity = 1;
let currentReviewPage = 1;
let reviewsPerPage = 5;
let totalReviewPages = 1;
let autoRefreshInterval = null;

// UI Elements
const hamburger = document.getElementById('hamburger');
const sidebar = document.getElementById('sidebar');
const closeSidebar = document.getElementById('close-sidebar');
const cartBtn = document.getElementById('cart-btn');
const cartSidebar = document.getElementById('cart-sidebar');
const closeCart = document.getElementById('close-cart');
const overlay = document.getElementById('overlay');

// Search elements
const searchInput = document.getElementById('search-input');
const mobileSearchInput = document.getElementById('mobile-search-input');
const searchSuggestions = document.getElementById('search-suggestions');
const mobileSearchSuggestions = document.getElementById('mobile-search-suggestions');

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

// Store section toggle functionality
function toggleStoreSection(index) {
    const content = document.getElementById(`store-content-${index}`);
    const arrow = document.getElementById(`store-arrow-${index}`);
    
    content.classList.toggle('expanded');
    arrow.classList.toggle('rotate-180');
}

// Search suggestions functionality
function setupSearchSuggestions(inputElement, suggestionsElement) {
    let timeoutId;
    
    inputElement.addEventListener('input', function() {
        clearTimeout(timeoutId);
        const query = this.value.trim();
        
        if (query.length < 2) {
            suggestionsElement.style.display = 'none';
            return;
        }
        
        timeoutId = setTimeout(() => {
            fetchSearchSuggestions(query, suggestionsElement);
        }, 300);
    });
    
    inputElement.addEventListener('focus', function() {
        const query = this.value.trim();
        if (query.length >= 2) {
            fetchSearchSuggestions(query, suggestionsElement);
        }
    });
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!inputElement.contains(e.target) && !suggestionsElement.contains(e.target)) {
            suggestionsElement.style.display = 'none';
        }
    });
}

function fetchSearchSuggestions(query, suggestionsElement) {
    const formData = new FormData();
    formData.append('action', 'search_suggestions');
    formData.append('query', query);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.suggestions.length > 0) {
            displaySearchSuggestions(data.suggestions, suggestionsElement);
        } else {
            suggestionsElement.style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Error fetching search suggestions:', error);
        suggestionsElement.style.display = 'none';
    });
}

function displaySearchSuggestions(suggestions, suggestionsElement) {
    let html = '';
    
    suggestions.forEach(suggestion => {
        const typeIcon = suggestion.type === 'food' ? '🍽️' : '🏪';
        const typeText = suggestion.type === 'food' ? 'Food' : 'Store';
        
        html += `
            <div class="search-suggestion-item" onclick="selectSearchSuggestion('${suggestion.name}')">
                <div class="suggestion-type">${typeIcon} ${typeText}</div>
                <div class="suggestion-name">${suggestion.name}</div>
                <div class="suggestion-details">
                    ${suggestion.type === 'food' 
                        ? `${suggestion.category} • ${suggestion.restaurant}`
                        : suggestion.category
                    }
                </div>
            </div>
        `;
    });
    
    suggestionsElement.innerHTML = html;
    suggestionsElement.style.display = 'block';
}

function selectSearchSuggestion(suggestion) {
    // Set the search input value and submit the form
    if (searchInput) {
        searchInput.value = suggestion;
        searchInput.closest('form').submit();
    }
    if (mobileSearchInput) {
        mobileSearchInput.value = suggestion;
        mobileSearchInput.closest('form').submit();
    }
}

// Toggle favorite for products
function toggleFavorite(productId, button) {
    const product = products[productId];
    if (!product) return;

    const formData = new FormData();
    formData.append('action', 'toggle_favorite');
    formData.append('product_id', productId);
    formData.append('product_name', product.name);
    formData.append('product_price', product.price);
    formData.append('product_category', product.category);
    formData.append('store_name', product.restaurant_name);
    formData.append('product_image', product.image);
    formData.append('product_description', product.description);
    formData.append('restaurant_id', product.restaurant_id);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            button.classList.toggle('active');
            const icon = button.querySelector('i');
            if (data.is_favorite) {
                icon.classList.remove('text-gray-500');
                icon.classList.add('text-red-600');
                showNotification('Added to favorites!');
            } else {
                icon.classList.remove('text-red-600');
                icon.classList.add('text-gray-500');
                showNotification('Removed from favorites');
            }
        } else {
            showNotification(data.message || 'Failed to update favorites', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

// Toggle favorite for stores
function toggleStoreFavorite(storeId, storeName, button) {
    const formData = new FormData();
    formData.append('action', 'toggle_store_favorite');
    formData.append('restaurant_id', storeId);
    formData.append('restaurant_name', storeName);
    formData.append('description', storeName + ' Restaurant');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            button.classList.toggle('active');
            const icon = button.querySelector('i');
            if (data.is_favorite) {
                icon.classList.remove('text-gray-500');
                icon.classList.add('text-red-600');
                button.style.background = '#fbbf24';
                showNotification('Store added to favorites!');
            } else {
                icon.classList.remove('text-red-600');
                icon.classList.add('text-gray-500');
                button.style.background = 'rgba(255, 255, 255, 0.9)';
                showNotification('Store removed from favorites');
            }
        } else {
            showNotification(data.message || 'Failed to update favorites', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

// Enhanced Open product popup with reviews (READ-ONLY)
function openProductPopup(productId) {
    const product = products[productId];
    if (!product) return;
    
    currentProductId = productId;
    popupQuantity = 1;
    currentReviewPage = 1;
    
    document.getElementById('popupProductName').textContent = product.name;
    document.getElementById('popupProductDescription').textContent = product.description;
    document.getElementById('popupProductPrice').textContent = `₱${parseFloat(product.price).toFixed(2)}`;
    document.getElementById('popupQuantity').textContent = popupQuantity;
    
    // Update rating display
    document.getElementById('popupAverageRating').textContent = product.avg_rating.toFixed(1);
    document.getElementById('popupReviewCount').textContent = `${product.review_count} ${product.review_count === 1 ? 'review' : 'reviews'}`;
    
    // Update summary rating
    document.getElementById('summaryAverageRating').textContent = product.avg_rating.toFixed(1);
    document.getElementById('summaryReviewCount').textContent = `${product.review_count} ${product.review_count === 1 ? 'review' : 'reviews'}`;
    
    // Generate star rating
    const starRatingContainer = document.getElementById('popupStarRating');
    const summaryStarRating = document.getElementById('summaryStarRating');
    starRatingContainer.innerHTML = generateStarRating(product.avg_rating, false);
    summaryStarRating.innerHTML = generateStarRating(product.avg_rating, false);
    
    const imageContainer = document.getElementById('popupProductImageContainer');
    const productImage = document.getElementById('popupProductImage');
    const imagePlaceholder = document.getElementById('popupProductImagePlaceholder');
    
    if (product.image) {
        const img = new Image();
        img.onload = function() {
            productImage.src = product.image;
            productImage.alt = product.name;
            productImage.classList.remove('hidden');
            imagePlaceholder.classList.add('hidden');
            imageContainer.classList.remove('umak-blue');
        };
        img.onerror = function() {
            productImage.classList.add('hidden');
            imagePlaceholder.classList.remove('hidden');
            imageContainer.classList.add('umak-blue');
            imagePlaceholder.classList.add('text-white', 'text-6xl');
        };
        img.src = product.image;
    } else {
        productImage.classList.add('hidden');
        imagePlaceholder.classList.remove('hidden');
        imageContainer.classList.add('umak-blue');
        imagePlaceholder.classList.add('text-white', 'text-6xl');
    }
    
    // Load reviews
    loadReviews(productId);
    
    // Start auto-refresh for reviews
    startAutoRefresh();
    
    document.getElementById('productPopup').classList.remove('hidden');
    document.getElementById('productPopup').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// Start auto-refresh for reviews
function startAutoRefresh() {
    // Clear any existing interval
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    
    // Refresh reviews every 30 seconds
    autoRefreshInterval = setInterval(() => {
        if (currentProductId) {
            loadReviews(currentProductId, true); // silent refresh
        }
    }, 30000);
}

// Stop auto-refresh
function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

// Generate star rating HTML
function generateStarRating(rating, interactive = false) {
    let stars = '';
    const fullStars = Math.floor(rating);
    const hasHalfStar = rating - fullStars >= 0.5;
    
    for (let i = 1; i <= 5; i++) {
        if (i <= fullStars) {
            stars += `<span class="star active" data-rating="${i}">★</span>`;
        } else if (i === fullStars + 1 && hasHalfStar) {
            stars += `<span class="star active" data-rating="${i}">★</span>`;
        } else {
            stars += `<span class="star" data-rating="${i}">★</span>`;
        }
    }
    
    return stars;
}

// Load reviews for a product
function loadReviews(productId, silent = false) {
    if (!silent) {
        document.getElementById('reviewsContainer').innerHTML = `
            <div class="review-loading">
                <div class="spinner"></div>
                <span>Loading reviews...</span>
            </div>
        `;
    }
    
    const sortBy = document.getElementById('reviewSort').value;
    const formData = new FormData();
    formData.append('action', 'get_reviews');
    formData.append('product_id', productId);
    formData.append('page', currentReviewPage);
    formData.append('per_page', reviewsPerPage);
    formData.append('sort_by', sortBy);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayReviews(data.reviews, data.rating_stats);
            updateRatingDistribution(data.rating_stats);
            updatePagination(data.pagination.total_pages || 1);
            
            // Update product data with new rating stats
            if (products[productId]) {
                products[productId].avg_rating = data.rating_stats.average_rating;
                products[productId].review_count = data.rating_stats.total_reviews;
                
                // Update the rating display in popup
                document.getElementById('popupAverageRating').textContent = data.rating_stats.average_rating.toFixed(1);
                document.getElementById('popupReviewCount').textContent = `${data.rating_stats.total_reviews} ${data.rating_stats.total_reviews === 1 ? 'review' : 'reviews'}`;
                document.getElementById('summaryAverageRating').textContent = data.rating_stats.average_rating.toFixed(1);
                document.getElementById('summaryReviewCount').textContent = `${data.rating_stats.total_reviews} ${data.rating_stats.total_reviews === 1 ? 'review' : 'reviews'}`;
                document.getElementById('popupStarRating').innerHTML = generateStarRating(data.rating_stats.average_rating, false);
                document.getElementById('summaryStarRating').innerHTML = generateStarRating(data.rating_stats.average_rating, false);
            }
        }
    })
    .catch(error => {
        console.error('Error loading reviews:', error);
        if (!silent) {
            document.getElementById('reviewsContainer').innerHTML = `
                <div class="review-placeholder">
                    <div class="review-placeholder-icon">⚠️</div>
                    <p>Failed to load reviews. Please try again.</p>
                </div>
            `;
        }
    });
}

// Update rating distribution bars
function updateRatingDistribution(ratingStats) {
    const totalReviews = ratingStats.total_reviews;
    
    for (let i = 1; i <= 5; i++) {
        const count = ratingStats.rating_distribution[i];
        const percentage = totalReviews > 0 ? (count / totalReviews) * 100 : 0;
        
        const bar = document.getElementById(`ratingBar${i}`);
        const countElement = document.getElementById(`ratingCount${i}`);
        
        if (bar) bar.style.width = `${percentage}%`;
        if (countElement) countElement.textContent = count;
    }
}

// Update pagination
function updatePagination(totalPages) {
    totalReviewPages = totalPages;
    const paginationContainer = document.getElementById('reviewPagination');
    
    if (totalPages <= 1) {
        paginationContainer.innerHTML = '';
        return;
    }
    
    let paginationHTML = '';
    
    // Previous button
    paginationHTML += `
        <button class="pagination-btn ${currentReviewPage === 1 ? 'disabled' : ''}" 
                onclick="changeReviewPage(${currentReviewPage - 1})" 
                ${currentReviewPage === 1 ? 'disabled' : ''}>
            <i class="fas fa-chevron-left"></i>
        </button>
    `;
    
    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentReviewPage - 1 && i <= currentReviewPage + 1)) {
            paginationHTML += `
                <button class="pagination-btn ${i === currentReviewPage ? 'active' : ''}" 
                        onclick="changeReviewPage(${i})">
                    ${i}
                </button>
            `;
        } else if (i === currentReviewPage - 2 || i === currentReviewPage + 2) {
            paginationHTML += `<span class="pagination-btn disabled">...</span>`;
        }
    }
    
    // Next button
    paginationHTML += `
        <button class="pagination-btn ${currentReviewPage === totalPages ? 'disabled' : ''}" 
                onclick="changeReviewPage(${currentReviewPage + 1})" 
                ${currentReviewPage === totalPages ? 'disabled' : ''}>
            <i class="fas fa-chevron-right"></i>
        </button>
    `;
    
    paginationContainer.innerHTML = paginationHTML;
}

// Change review page
function changeReviewPage(page) {
    if (page < 1 || page > totalReviewPages || page === currentReviewPage) return;
    
    currentReviewPage = page;
    loadReviews(currentProductId);
    
    // Scroll to reviews section
    document.getElementById('reviewsContainer').scrollIntoView({ behavior: 'smooth' });
}

// Display reviews in the popup
function displayReviews(reviews, ratingStats) {
    const reviewsContainer = document.getElementById('reviewsContainer');
    
    if (reviews.length === 0) {
        reviewsContainer.innerHTML = `
            <div class="review-placeholder">
                <div class="review-placeholder-icon">💬</div>
                <p>No reviews yet.</p>
            </div>
        `;
        return;
    }
    
    let reviewsHTML = '';
    
    reviews.forEach(review => {
        const userInitial = review.user_name ? review.user_name.charAt(0).toUpperCase() : 'U';
        const reviewDate = new Date(review.created_at).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        
        // Check if user has purchased this product (for verified purchase badge)
        const isVerified = Math.random(); // Simulate verified purchases
        
        reviewsHTML += `
            <div class="review-card bg-white p-4 mb-4">
                <div class="review-header">
                    <div class="review-user">
                        <div class="review-avatar">
                            ${userInitial}
                        </div>
                        <div class="review-user-info">
                            <h4>${review.user_name}</h4>
                            <div class="review-meta">
                                <div class="star-rating">
                                    ${generateStarRating(review.rating, false)}
                                </div>
                                <span>•</span>
                                <span>${reviewDate}</span>
                                ${isVerified ? `
                                    <div class="review-verified">
                                        <i class="fas fa-check-circle"></i>
                                        <span>Verified Purchase</span>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="review-content">
                    <p class="review-text">${review.review_text}</p>
                    
                    <!-- Review images would go here -->
                    <!-- <div class="review-images">
                        <img src="review-image.jpg" alt="Review image" class="review-image">
                    </div> -->
                </div>
                
                <!-- Restaurant reply (if any) -->
                ${review.reply_text ? `
                    <div class="review-reply">
                        <div class="review-reply-header">Restaurant Response</div>
                        <p class="review-reply-text">${review.reply_text}</p>
                    </div>
                ` : ''}
            </div>
        `;
    });
    
    reviewsContainer.innerHTML = reviewsHTML;
}

// Update quantity in popup
function updatePopupQuantity(change) {
    popupQuantity += change;
    if (popupQuantity < 1) popupQuantity = 1;
    if (popupQuantity > 20) popupQuantity = 20;
    document.getElementById('popupQuantity').textContent = popupQuantity;
    
    if (currentProductId) {
        const product = products[currentProductId];
        const totalPrice = (product.price * popupQuantity).toFixed(2);
        document.getElementById('popupProductPrice').textContent = `₱${totalPrice}`;
    }
}

// Close popup
function closeProductPopup() {
    document.getElementById('productPopup').classList.add('hidden');
    document.getElementById('productPopup').style.display = 'none';
    document.body.style.overflow = 'auto';
    currentProductId = null;
    
    // Stop auto-refresh
    stopAutoRefresh();
    
    const imageContainer = document.getElementById('popupProductImageContainer');
    const productImage = document.getElementById('popupProductImage');
    const imagePlaceholder = document.getElementById('popupProductImagePlaceholder');
    
    imageContainer.classList.remove('umak-blue');
    productImage.classList.add('hidden');
    imagePlaceholder.classList.remove('hidden', 'text-white', 'text-6xl');
    imagePlaceholder.classList.add('text-gray-400', 'text-4xl');
}

// Add to cart from popup
function addToCartFromPopup() {
    if (!currentProductId) {
        showNotification('No product selected', 'error');
        return;
    }
    
    addToCart(currentProductId, popupQuantity);
    closeProductPopup();
}

// Add item to cart via AJAX
function addToCart(productId, quantity = 1) {
    const formData = new FormData();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);
    formData.append('quantity', quantity);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Item added to cart!');
            updateCartCount();
            if (data.cart_data) {
                updateCartDisplay(data.cart_data);
            }
        } else {
            showNotification(data.message || 'Failed to add item to cart', 'error');
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
        cartItemsContainer.classList.add('hidden');
        cartEmpty.classList.remove('hidden');
        proceedToCheckout.classList.add('opacity-50', 'pointer-events-none');
    } else {
        cartEmpty.classList.add('hidden');
        cartItemsContainer.classList.remove('hidden');
        proceedToCheckout.classList.remove('opacity-50', 'pointer-events-none');
        
        cartItemsContainer.innerHTML = '';
        
        cartData.items.forEach(item => {
            const cartItemHTML = `
                <div class="cart-item border-b border-gray-200 pb-4 mb-4" data-cart-id="${item.id}">
                    <div class="flex items-center space-x-3">
                        <div class="flex-shrink-0">
                            ${item.product_image_url ? 
                                `<img src="${item.product_image_url}" alt="${item.product_name}" class="cart-item-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">` : 
                                `<div class="cart-item-image-fallback"><span>🍽️</span></div>`
                            }
                            <div class="cart-item-image-fallback ${item.product_image_url ? 'hidden' : ''}">
                                <span>🍽️</span>
                            </div>
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
    
    cartTotal.textContent = '₱' + parseFloat(cartData.total).toFixed(2);
}

// Update cart quantity via AJAX
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

// Remove from cart via AJAX
function removeFromCart(cartId) {
    if (!confirm('Remove this item from cart?')) return;

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
            const cartItem = document.querySelector(`[data-cart-id="${cartId}"]`);
            if (cartItem) {
                cartItem.remove();
            }
            updateCartCount();
            if (data.cart_data) {
                updateCartDisplay(data.cart_data);
            } else {
                updateCartTotal();
            }
            showNotification('Item removed from cart');
        } else {
            showNotification(data.message || 'Failed to remove item', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

// Update cart total
function updateCartTotal() {
    let total = 0;
    document.querySelectorAll('.cart-item').forEach(item => {
        const price = parseFloat(item.querySelector('.text-gray-600').textContent.replace('₱', ''));
        const quantity = parseInt(item.querySelector('.cart-quantity').textContent);
        total += price * quantity;
    });

    document.getElementById('cart-total').textContent = '₱' + total.toFixed(2);
    
    const cartEmpty = document.getElementById('cart-empty');
    const cartItemsContainer = document.getElementById('cart-items-container');
    const proceedToCheckout = document.getElementById('proceed-to-checkout');
    
    if (document.querySelectorAll('.cart-item').length === 0) {
        cartItemsContainer.classList.add('hidden');
        cartEmpty.classList.remove('hidden');
        proceedToCheckout.classList.add('opacity-50', 'pointer-events-none');
    } else {
        cartEmpty.classList.add('hidden');
        cartItemsContainer.classList.remove('hidden');
        proceedToCheckout.classList.remove('opacity-50', 'pointer-events-none');
    }
}

// Update cart count badge
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
            const badge = document.querySelector('.cart-badge');
            if (data.count > 0) {
                badge.textContent = data.count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }
    })
    .catch(error => {
        console.error('Error updating cart count:', error);
    });
}

// Show notification
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = 'notification';
    notification.style.background = type === 'success' ? '#4CAF50' : '#f44336';
    notification.textContent = message;
    document.body.appendChild(notification);

    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Filter functionality
let activeFilters = {
    categories: [],
    stores: []
};

document.addEventListener('DOMContentLoaded', function() {
    // Category filter click
    document.querySelectorAll('.category-filter').forEach(filter => {
        filter.addEventListener('click', function() {
            const category = this.getAttribute('data-category');
            toggleCategoryFilter(category, this);
        });
    });

    // Store filter click
    document.querySelectorAll('.store-filter').forEach(filter => {
        filter.addEventListener('click', function() {
            const storeId = this.getAttribute('data-store');
            toggleStoreFilter(storeId, this);
        });
    });

    // Clear all filters
    document.getElementById('clearFilters').addEventListener('click', clearAllFilters);
    
    // Update cart badge display
    const cartBadge = document.querySelector('.cart-badge');
    const cartCount = parseInt(cartBadge.textContent);
    if (cartCount > 0) {
        cartBadge.style.display = 'flex';
    }
    
    // Setup search suggestions
    if (searchInput) {
        setupSearchSuggestions(searchInput, searchSuggestions);
    }
    if (mobileSearchInput) {
        setupSearchSuggestions(mobileSearchInput, mobileSearchSuggestions);
    }
});

function toggleCategoryFilter(category, element) {
    const index = activeFilters.categories.indexOf(category);
    
    if (index === -1) {
        // Add filter
        activeFilters.categories.push(category);
        element.classList.add('active');
        element.querySelector('.remove').classList.remove('hidden');
    } else {
        // Remove filter
        activeFilters.categories.splice(index, 1);
        element.classList.remove('active');
        element.querySelector('.remove').classList.add('hidden');
    }
    
    updateActiveFilters();
    applyFilters();
}

function toggleStoreFilter(storeId, element) {
    const index = activeFilters.stores.indexOf(storeId);
    
    if (index === -1) {
        // Add filter
        activeFilters.stores.push(storeId);
        element.classList.add('active');
        element.querySelector('.remove').classList.remove('hidden');
    } else {
        // Remove filter
        activeFilters.stores.splice(index, 1);
        element.classList.remove('active');
        element.querySelector('.remove').classList.add('hidden');
    }
    
    updateActiveFilters();
    applyFilters();
}

function updateActiveFilters() {
    const activeFiltersContainer = document.getElementById('activeFilters');
    const activeFiltersList = document.getElementById('activeFiltersList');
    
    activeFiltersList.innerHTML = '';
    
    let hasActiveFilters = false;
    
    // Add category filters
    activeFilters.categories.forEach(category => {
        hasActiveFilters = true;
        const filterTag = document.createElement('span');
        filterTag.className = 'filter-tag active';
        filterTag.innerHTML = `${category} <span class="remove" onclick="removeCategoryFilter('${category}')">×</span>`;
        activeFiltersList.appendChild(filterTag);
    });
    
    // Add store filters
    activeFilters.stores.forEach(storeId => {
        hasActiveFilters = true;
        const storeName = document.querySelector(`.store-filter[data-store="${storeId}"]`).textContent;
        const filterTag = document.createElement('span');
        filterTag.className = 'filter-tag active';
        filterTag.innerHTML = `${storeName} <span class="remove" onclick="removeStoreFilter('${storeId}')">×</span>`;
        activeFiltersList.appendChild(filterTag);
    });
    
    if (hasActiveFilters) {
        activeFiltersContainer.classList.remove('hidden');
    } else {
        activeFiltersContainer.classList.add('hidden');
    }
}

function removeCategoryFilter(category) {
    activeFilters.categories = activeFilters.categories.filter(c => c !== category);
    const filterElement = document.querySelector(`.category-filter[data-category="${category}"]`);
    if (filterElement) {
        filterElement.classList.remove('active');
        filterElement.querySelector('.remove').classList.add('hidden');
    }
    updateActiveFilters();
    applyFilters();
}

function removeStoreFilter(storeId) {
    activeFilters.stores = activeFilters.stores.filter(s => s !== storeId);
    const filterElement = document.querySelector(`.store-filter[data-store="${storeId}"]`);
    if (filterElement) {
        filterElement.classList.remove('active');
        filterElement.querySelector('.remove').classList.add('hidden');
    }
    updateActiveFilters();
    applyFilters();
}

function clearAllFilters() {
    activeFilters.categories = [];
    activeFilters.stores = [];
    
    document.querySelectorAll('.filter-tag').forEach(tag => {
        tag.classList.remove('active');
        tag.querySelector('.remove').classList.add('hidden');
    });
    
    updateActiveFilters();
    applyFilters();
}

function applyFilters() {
    const storeSections = document.querySelectorAll('.store-section');
    let anyStoreVisible = false;
    
    storeSections.forEach(section => {
        const storeId = section.getAttribute('data-store-id');
        const storeCategories = section.getAttribute('data-store-categories').split(',');
        
        let shouldShow = true;
        
        // Apply store filters
        if (activeFilters.stores.length > 0 && !activeFilters.stores.includes(storeId)) {
            shouldShow = false;
        }
        
        // Apply category filters
        if (activeFilters.categories.length > 0) {
            const hasMatchingCategory = activeFilters.categories.some(category => 
                storeCategories.includes(category)
            );
            if (!hasMatchingCategory) {
                shouldShow = false;
            }
        }
        
        if (shouldShow) {
            section.style.display = 'block';
            anyStoreVisible = true;
            
            // Also filter products within the store by category
            if (activeFilters.categories.length > 0) {
                section.querySelectorAll('.category-section').forEach(categorySection => {
                    const category = categorySection.getAttribute('data-category');
                    if (activeFilters.categories.includes(category)) {
                        categorySection.style.display = 'block';
                    } else {
                        categorySection.style.display = 'none';
                    }
                });
            } else {
                section.querySelectorAll('.category-section').forEach(categorySection => {
                    categorySection.style.display = 'block';
                });
            }
        } else {
            section.style.display = 'none';
        }
    });
    
    // Show empty state if no stores match filters
    const emptyState = document.querySelector('.empty-state');
    if (!anyStoreVisible && storeSections.length > 0) {
        if (!emptyState) {
            const storesSection = document.getElementById('storesSection');
            const emptyStateHtml = `
                <div class="empty-state">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M12 12h.01M12 2a10 10 0 100 20 10 10 0 000-20z"></path>
                    </svg>
                    <h3 class="text-lg font-semibold text-gray-600 mb-2">No stores match your filters</h3>
                    <p class="text-gray-500">Try adjusting your filters to see more results.</p>
                </div>
            `;
            storesSection.innerHTML = emptyStateHtml;
        }
    } else if (emptyState && anyStoreVisible) {
        emptyState.remove();
    }
}

// Close popup on escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeProductPopup();
    }
});

// Close popup when clicking outside
document.getElementById('productPopup').addEventListener('click', function(event) {
    if (event.target === this) {
        closeProductPopup();
    }
});
</script>
</body>
</html>