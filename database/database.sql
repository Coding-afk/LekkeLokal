CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone_number VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_security (
    user_id INT PRIMARY KEY,
    last_mfa_verified_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_security_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mfa_challenges (
    mfa_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    is_used BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME NULL,
    INDEX idx_mfa_user_created (user_id, created_at),
    INDEX idx_mfa_expires (expires_at),
    CONSTRAINT fk_mfa_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_challenges (
    reset_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    is_used BOOLEAN NOT NULL DEFAULT FALSE,
    request_ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME NULL,
    INDEX idx_password_reset_user_created (user_id, created_at),
    INDEX idx_password_reset_expires (expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    succeeded BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_email_ip_time (email, ip_address, attempted_at),
    INDEX idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS buyer (
    user_id INT PRIMARY KEY,
    default_shipping_address VARCHAR(255) NULL,
    CONSTRAINT fk_buyer_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seller (
    user_id INT PRIMARY KEY,
    store_name VARCHAR(100) NOT NULL,
    bio TEXT,
    trust_badge VARCHAR(50) DEFAULT 'New seller',
    verification_status VARCHAR(20) DEFAULT 'pending',
    INDEX idx_seller_verification_status (verification_status),
    CONSTRAINT fk_seller_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin (
    user_id INT PRIMARY KEY,
    admin_level VARCHAR(20) DEFAULT 'standard',
    CONSTRAINT fk_admin_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seller_verifications (
    verification_id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    verification_type VARCHAR(20) NOT NULL DEFAULT 'initial',
    document_type VARCHAR(30) NULL,
    document_path VARCHAR(255) NULL,
    selfie_path VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    admin_notes TEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    expires_at DATE NULL,
    INDEX idx_seller_verification_seller (seller_id),
    INDEX idx_seller_verification_status (status),
    INDEX idx_seller_verification_expires (expires_at),
    CONSTRAINT fk_seller_verification_seller FOREIGN KEY (seller_id) REFERENCES seller(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_seller_verification_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS language_preferences (
    pref_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    language_code VARCHAR(10) NOT NULL DEFAULT 'en',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_language_user (user_id),
    CONSTRAINT fk_language_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    parent_category_id INT NULL,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    CONSTRAINT fk_category_parent FOREIGN KEY (parent_category_id) REFERENCES categories(category_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS listings (
    listing_id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    category_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    `condition` VARCHAR(30) NOT NULL,
    location VARCHAR(100) NOT NULL,
    allow_delivery BOOLEAN NOT NULL DEFAULT TRUE,
    allow_pickup BOOLEAN NOT NULL DEFAULT TRUE,
    pickup_address VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_listing_seller FOREIGN KEY (seller_id) REFERENCES seller(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_listing_category FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE RESTRICT,
    INDEX idx_listing_search (title, location, status),
    INDEX idx_listing_category (category_id),
    INDEX idx_listing_seller (seller_id),
    INDEX idx_listing_dashboard (status, seller_id, category_id, location, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS listing_images (
    image_id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_listing_image_listing FOREIGN KEY (listing_id) REFERENCES listings(listing_id) ON DELETE CASCADE,
    INDEX idx_listing_image_listing (listing_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wishlists (
    wishlist_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    listing_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wishlist_user_listing (user_id, listing_id),
    INDEX idx_wishlist_user (user_id),
    INDEX idx_wishlist_listing (listing_id),
    CONSTRAINT fk_wishlist_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_listing FOREIGN KEY (listing_id) REFERENCES listings(listing_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chats (
    chat_id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chat_listing_buyer_seller (listing_id, buyer_id, seller_id),
    CONSTRAINT fk_chat_listing FOREIGN KEY (listing_id) REFERENCES listings(listing_id) ON DELETE SET NULL,
    CONSTRAINT fk_chat_buyer FOREIGN KEY (buyer_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_seller FOREIGN KEY (seller_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_messages (
    chat_message_id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id INT NOT NULL,
    sender_id INT NOT NULL,
    message_text TEXT NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    INDEX idx_chat_messages_chat_time (chat_id, sent_at),
    INDEX idx_chat_messages_unread (chat_id, sender_id, is_read),
    CONSTRAINT fk_chat_message_chat FOREIGN KEY (chat_id) REFERENCES chats(chat_id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_message_sender FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT NOT NULL,
    order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_method VARCHAR(20) NOT NULL,
    delivery_address VARCHAR(255),
    INDEX idx_orders_buyer_date (buyer_id, order_date),
    INDEX idx_orders_status_date (status, order_date),
    CONSTRAINT fk_order_buyer FOREIGN KEY (buyer_id) REFERENCES buyer(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
    order_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    listing_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    INDEX idx_order_items_order (order_id),
    INDEX idx_order_items_listing (listing_id),
    CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    CONSTRAINT fk_order_item_listing FOREIGN KEY (listing_id) REFERENCES listings(listing_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reviews (
    review_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    order_item_id INT NOT NULL,
    listing_id INT NOT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    rating TINYINT NOT NULL,
    review_text TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review_order_item_buyer (order_item_id, buyer_id),
    INDEX idx_review_listing (listing_id),
    INDEX idx_review_seller (seller_id),
    INDEX idx_reviews_created (created_at),
    CONSTRAINT fk_review_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    CONSTRAINT fk_review_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(order_item_id) ON DELETE CASCADE,
    CONSTRAINT fk_review_listing FOREIGN KEY (listing_id) REFERENCES listings(listing_id) ON DELETE CASCADE,
    CONSTRAINT fk_review_buyer FOREIGN KEY (buyer_id) REFERENCES buyer(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_review_seller FOREIGN KEY (seller_id) REFERENCES seller(user_id) ON DELETE CASCADE,
    CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaints (
    complaint_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    complaint_type VARCHAR(80) NOT NULL,
    complaint_text TEXT NOT NULL,
    resolution_text TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handled_at DATETIME NULL,
    handled_by INT NULL,
    INDEX idx_complaint_status (status),
    INDEX idx_complaint_order (order_id),
    INDEX idx_complaint_buyer (buyer_id),
    INDEX idx_complaint_seller (seller_id),
    INDEX idx_complaint_order_buyer_type_status (order_id, buyer_id, complaint_type, status),
    INDEX idx_complaints_created (created_at),
    CONSTRAINT fk_complaint_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    CONSTRAINT fk_complaint_buyer FOREIGN KEY (buyer_id) REFERENCES buyer(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_complaint_seller FOREIGN KEY (seller_id) REFERENCES seller(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_complaint_handled_by FOREIGN KEY (handled_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO categories (category_name, description) VALUES
('Clothing', 'Clothing, shoes, and accessories'),
('Electronics', 'Phones, laptops, gadgets, and accessories'),
('Home & Furniture', 'Furniture, homeware, and household items'),
('Books & Stationery', 'Books, textbooks, school supplies, and stationery'),
('Sports & Outdoors', 'Sports gear and outdoor equipment'),
('Beauty & Personal Care', 'Beauty and care items'),
('Toys & Games', 'Toys, games, and entertainment items'),
('Other', 'Other marketplace listings')
ON DUPLICATE KEY UPDATE
description = VALUES(description);

INSERT INTO users
(first_name, last_name, email, phone_number, password_hash, status, role)
VALUES
('Thabo', 'Mokoena', 'thabo@example.com', '0712345678',
 '$2y$12$vzYkrw2O6UWVkC3lmAEQnuG.gZ07dhvwepCTzu5Cj/ciGfWWYPtvO', 'active', 'user'),

('Ayesha', 'Khan', 'ayesha@example.com', '0723456789',
 '$2y$12$vzYkrw2O6UWVkC3lmAEQnuG.gZ07dhvwepCTzu5Cj/ciGfWWYPtvO', 'active', 'user'),

('Liam', 'Jacobs', 'liam@example.com', '0734567890',
 '$2y$12$vzYkrw2O6UWVkC3lmAEQnuG.gZ07dhvwepCTzu5Cj/ciGfWWYPtvO', 'active', 'user'),

('Nomsa', 'Dlamini', 'nomsa@example.com', '0745678901',
 '$2y$12$vzYkrw2O6UWVkC3lmAEQnuG.gZ07dhvwepCTzu5Cj/ciGfWWYPtvO', 'active', 'user'),

('Pieter', 'Botha', 'pieter@example.com', '0756789012',
 '$2y$12$vzYkrw2O6UWVkC3lmAEQnuG.gZ07dhvwepCTzu5Cj/ciGfWWYPtvO', 'active', 'user')
ON DUPLICATE KEY UPDATE
first_name = VALUES(first_name),
last_name = VALUES(last_name),
phone_number = VALUES(phone_number),
password_hash = VALUES(password_hash),
status = VALUES(status),
role = VALUES(role);


-- =====================================================
-- Default Admin User
-- Email: heinrich.lategan.1234@gmail.com
-- Password: set securely during deployment
-- =====================================================
INSERT INTO users (first_name, last_name, email, phone_number, password_hash, status, role)
VALUES (
    'Heinrich',
    'Lategan',
    'heinrich.lategan.1234@gmail.com',
    '0123456789',
    '$2y$10$bFxJTzFdaSlc5lMhZYVOPuVRGOTgWISxve1dGTpYFFHaZDChZ2st2',
    'active',
    'admin'
)
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    phone_number = VALUES(phone_number),
    password_hash = VALUES(password_hash),
    status = VALUES(status),
    role = VALUES(role);

INSERT INTO admin (user_id, admin_level)
SELECT user_id, 'super'
FROM users
WHERE email = 'heinrich.lategan.1234@gmail.com'
ON DUPLICATE KEY UPDATE
    admin_level = VALUES(admin_level);


INSERT IGNORE INTO language_preferences (user_id, language_code)
SELECT user_id, 'en'
FROM users;


INSERT IGNORE INTO user_security (user_id)
SELECT user_id
FROM users;
