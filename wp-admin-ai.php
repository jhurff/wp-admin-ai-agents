<?php
/**
 * Plugin Name: WP Admin for AI Agents
 * Plugin URI: https://fastbytes.io/plugins/wp-admin-ai-agents
 * Description: AI agent-optimized WordPress admin plugin with MCP-compatible REST API. Manage posts, pages, CPT, and meta fields via API key auth. MCP tool-ready for AI agents like Claude, ChatGPT, and autonomous agents.
 * Version: 2.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: FastBytes
 * Author URI: https://FastBytes.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-admin-ai-agents
 * Domain Path: /languages
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

/**
 * Prevent direct access to this file
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * =============================================================================
 * API KEY AUTHENTICATION SYSTEM
 * =============================================================================
 * 
 * AI Agent-friendly authentication using API keys.
 * Keys are generated in WordPress admin and used in requests:
 *   X-API-Key: mm_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 * 
 * No Application Passwords needed. Stateless authentication.
 */

/**
 * Generate a new API key
 * 
 * @param string $name Friendly name for this key
 * @param array $scopes Optional permissions scope
 * @return string|false The raw API key (only shown once) or false on failure
 */
function meta_manager_generate_api_key($name, $scopes = array('read', 'write')) {
    global $wpdb;
    
    // Generate key parts
    $prefix = 'mm_live_';
    $random = bin2hex(random_bytes(32)); // 64 char hex string
    $raw_key = $prefix . $random;
    
    // Hash the key for storage (never store raw)
    $hashed_key = hash('sha256', $raw_key);
    
    // Create key metadata
    $key_data = array(
        'name' => sanitize_text_field($name),
        'key_prefix' => $prefix . substr($random, 0, 8), // First 8 chars for identification
        'hashed_key' => $hashed_key,
        'scopes' => maybe_serialize($scopes),
        'created' => current_time('mysql'),
        'last_used' => null,
        'use_count' => 0
    );
    
    // Store in options table (simple approach)
    $keys = get_option('wp_admin_ai_api_keys', array());
    $key_id = wp_generate_uuid4();
    $keys[$key_id] = $key_data;
    
    if (update_option('wp_admin_ai_api_keys', $keys)) {
        return $raw_key; // Return ONLY once, never stored raw
    }
    
    return false;
}

/**
 * Validate an API key
 * 
 * @param string $raw_key The raw API key from request
 * @return array ['valid' => bool, 'key_id' => string|null, 'scopes' => array]
 */
function meta_manager_validate_api_key($raw_key) {
    if (empty($raw_key) || !is_string($raw_key)) {
        return array('valid' => false, 'key_id' => null, 'scopes' => array());
    }
    
    $keys = get_option('wp_admin_ai_api_keys', array());
    $hashed_key = hash('sha256', $raw_key);
    
    foreach ($keys as $key_id => $key_data) {
        if ($key_data['hashed_key'] === $hashed_key) {
            // Update last used timestamp
            $keys[$key_id]['last_used'] = current_time('mysql');
            $keys[$key_id]['use_count'] = intval($key_data['use_count']) + 1;
            update_option('wp_admin_ai_api_keys', $keys);
            
            return array(
                'valid' => true,
                'key_id' => $key_id,
                'key_name' => $key_data['name'],
                'scopes' => maybe_unserialize($key_data['scopes'])
            );
        }
    }
    
    return array('valid' => false, 'key_id' => null, 'scopes' => array());
}

/**
 * Delete an API key
 * 
 * @param string $key_id The key ID to delete
 * @return bool Success
 */
function meta_manager_delete_api_key($key_id) {
    $keys = get_option('wp_admin_ai_api_keys', array());
    
    if (isset($keys[$key_id])) {
        unset($keys[$key_id]);
        return update_option('wp_admin_ai_api_keys', $keys);
    }
    
    return false;
}

/**
 * Get all API keys (metadata only, never raw keys)
 * 
 * @return array Array of key metadata
 */
function meta_manager_get_all_keys() {
    $keys = get_option('wp_admin_ai_api_keys', array());
    $safe_keys = array();
    
    foreach ($keys as $key_id => $key_data) {
        $safe_keys[$key_id] = array(
            'name' => $key_data['name'],
            'key_prefix' => $key_data['key_prefix'],
            'scopes' => maybe_unserialize($key_data['scopes']),
            'created' => $key_data['created'],
            'last_used' => $key_data['last_used'],
            'use_count' => intval($key_data['use_count'])
        );
    }
    
    return $safe_keys;
}

/**
 * Authentication callback - accepts either WordPress auth OR API Key
 * 
 * @param WP_REST_Request $request Full request data
 * @return bool|WP_Error True if permitted, WP_Error if not
 */
function meta_manager_auth_callback($request) {
    // First, try WordPress cookie auth (for admin UI usage)
    if (current_user_can('edit_posts')) {
        return true;
    }
    
    // Try API Key auth
    $api_key = $request->get_header('X-API-Key');
    
    if (empty($api_key)) {
        // Check Authorization header as fallback (Bearer token style)
        $auth_header = $request->get_header('Authorization');
        if ($auth_header && strpos($auth_header, 'X-API-Key ') === 0) {
            $api_key = trim(substr($auth_header, 10));
        }
    }
    
    if (empty($api_key)) {
        return new WP_Error(
            'rest_forbidden',
            __('Authentication required. Provide X-API-Key header or log in to WordPress.', 'wp-admin-ai-agents'),
            array('status' => 401)
        );
    }
    
    $validation = meta_manager_validate_api_key($api_key);
    
    if (!$validation['valid']) {
        return new WP_Error(
            'rest_forbidden',
            __('Invalid API key.', 'wp-admin-ai-agents'),
            array('status' => 401)
        );
    }
    
    // Check scopes
    $required_scope = ($request->get_method() === 'GET') ? 'read' : 'write';
    if (!in_array($required_scope, $validation['scopes']) && !in_array('admin', $validation['scopes'])) {
        return new WP_Error(
            'rest_forbidden',
            sprintf(__('API key does not have %s permission.', 'wp-admin-ai-agents'), $required_scope),
            array('status' => 403)
        );
    }
    
    return true;
}

/**
 * Authentication callback for health check (no auth required)
 */
function meta_manager_auth_callback_health($request) {
    return true;
}

/**
 * Current plugin version
 */
define('META_MANAGER_VERSION', '2.0.0');

/**
 * Plugin basename
 */
define('META_MANAGER_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Add REST API endpoints for meta management
 */
add_action('rest_api_init', 'meta_manager_register_routes');

/**
 * Register REST API routes
 */
function meta_manager_register_routes() {
    
    /**
     * Health check endpoint
     * 
     * GET /wp-json/wp-ai/v1/health
     * 
     * No authentication required.
     */
    register_rest_route('wp-ai/v1', '/health', array(
        'methods'             => 'GET',
        'callback'           => 'meta_manager_health_check',
        'permission_callback' => 'meta_manager_auth_callback_health',
        'show_in_index'      => false,
    ));
    
    /**
     * Generate new API key (admin only)
     * 
     * POST /wp-json/wp-ai/v1/keys
     * 
     * Body:
     * {
     *   "name": "FastBytes Agent",
     *   "scopes": ["read", "write"]
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "key_id": "uuid",
     *   "api_key": "mm_live_xxxxxxxxxx...",  // SHOW ONLY ONCE
     *   "name": "FastBytes Agent"
     * }
     */
    register_rest_route('wp-ai/v1', '/keys', array(
        'methods'             => 'POST',
        'callback'           => 'meta_manager_create_key',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
    ));
    
    /**
     * List API keys (admin only)
     * 
     * GET /wp-json/wp-ai/v1/keys
     * 
     * Returns key metadata (never raw keys)
     */
    register_rest_route('wp-ai/v1', '/keys', array(
        'methods'             => 'GET',
        'callback'           => 'meta_manager_list_keys',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
    ));
    
    /**
     * Delete API key (admin only)
     * 
     * DELETE /wp-json/wp-ai/v1/keys/{key_id}
     */
    register_rest_route('wp-ai/v1', '/keys/(?P<key_id>[a-z0-9-]+)', array(
        'methods'             => 'DELETE',
        'callback'           => 'meta_manager_delete_key',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'args'               => array(
            'key_id' => array(
                'required' => true,
            ),
        ),
    ));
    
    /**
     * Get all meta for a post
     * 
     * GET /wp-json/wp-ai/v1/get/{post_id}
     * 
     * Authentication: X-API-Key header OR WordPress login
     * 
     * Response:
     * {
     *   "post_id": 363,
     *   "meta": {
     *     "_ws_last_price": "5.60",
     *     "_ws_last_updated": "2026-03-31T18:00:00"
     *   }
     * }
     */
    register_rest_route('wp-ai/v1', '/get/(?P<post_id>\d+)', array(
        'methods'             => 'GET',
        'callback'           => 'meta_manager_get_meta',
        'permission_callback' => 'meta_manager_auth_callback',
        'args'               => array(
            'post_id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
    
    /**
     * Update or create a single meta field
     * 
     * POST /wp-json/wp-ai/v1/update
     * 
     * Authentication: X-API-Key header OR WordPress login
     * 
     * Body:
     * {
     *   "post_id": 363,
     *   "meta_key": "_ws_last_price",
     *   "meta_value": "5.60"
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "post_id": 363,
     *   "meta_key": "_ws_last_price",
     *   "meta_value": "5.60",
     *   "result": false
     * }
     */
    register_rest_route('wp-ai/v1', '/update', array(
        'methods'             => 'POST',
        'callback'           => 'meta_manager_update_meta',
        'permission_callback' => 'meta_manager_auth_callback',
        'args'               => array(
            'post_id' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ),
            'meta_key' => array(
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'meta_value' => array(
                'required' => false,
            ),
        ),
    ));
    
    /**
     * Bulk update multiple meta fields at once
     * 
     * POST /wp-json/wp-ai/v1/bulk-update
     * 
     * Authentication: X-API-Key header OR WordPress login
     * 
     * Body:
     * {
     *   "post_id": 363,
     *   "meta": {
     *     "_ws_last_price": "5.60",
     *     "_ws_last_updated": "2026-03-31T18:00:00",
     *     "_ws_price_history": "[...]"
     *   }
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "post_id": 363,
     *   "updated": {
     *     "_ws_last_price": "5.60",
     *     "_ws_last_updated": "2026-03-31T18:00:00"
     *   },
     *   "errors": {}
     * }
     */
    register_rest_route('wp-ai/v1', '/bulk-update', array(
        'methods'             => 'POST',
        'callback'           => 'meta_manager_bulk_update',
        'permission_callback' => 'meta_manager_auth_callback',
        'args'               => array(
            'post_id' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ),
            'meta' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_array($param);
                }
            ),
        ),
    ));
    
    /**
     * Update price history entry
     * 
     * POST /wp-json/wp-ai/v1/update-history
     * 
     * Authentication: X-API-Key header OR WordPress login
     * 
     * Body:
     * {
     *   "post_id": 363,
     *   "price": 5.60,
     *   "change": 0.15,
     *   "change_pct": 2.71,
     *   "date": "2026-03-31"
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "post_id": 363,
     *   "price": 5.60,
     *   "change": 0.15,
     *   "change_pct": 2.71,
     *   "date": "2026-03-31",
     *   "history_count": 6
     * }
     */
    register_rest_route('wp-ai/v1', '/update-history', array(
        'methods'             => 'POST',
        'callback'           => 'meta_manager_update_history',
        'permission_callback' => 'meta_manager_auth_callback',
        'args'               => array(
            'post_id' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ),
            'price' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ),
            'change' => array(
                'required' => false,
                'default' => 0,
            ),
            'change_pct' => array(
                'required' => false,
                'default' => 0,
            ),
            'date' => array(
                'required' => false,
                'default' => current_time('Y-m-d'),
            ),
        ),
    ));
    
    /**
     * Update post content (title, body, status)
     * 
     * POST /wp-json/wp-ai/v1/update-post
     * 
     * Authentication: X-API-Key header OR WordPress login
     * 
     * Body:
     * {
     *   "post_id": 363,
     *   "title": "New Title",
     *   "content": "New content...",
     *   "status": "publish"  // publish, draft, private
     * }
     */
    register_rest_route('wp-ai/v1', '/update-post', array(
        'methods'             => 'POST',
        'callback'           => 'meta_manager_update_post',
        'permission_callback' => 'meta_manager_auth_callback',
        'args'               => array(
            'post_id' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
}

/**
 * Permission callback - DEPRECATED, use meta_manager_auth_callback instead
 * Kept for backwards compatibility
 */
function meta_manager_can_edit_post($request) {
    return meta_manager_auth_callback($request);
}

/**
 * Health check callback
 *
 * @param WP_REST_Request $request Full request data
 * @return array Health status
 */
function meta_manager_health_check($request) {
    return array(
        'status'    => 'ok',
        'plugin'    => 'WP Admin for AI Agents',
        'version'   => META_MANAGER_VERSION,
        'timestamp' => current_time('Y-m-d\TH:i:s'),
        'auth'      => 'X-API-Key header required for write operations',
        'endpoints' => array(
            'GET' => '/wp-json/wp-ai/v1/get/{post_id}',
            'POST' => '/wp-json/wp-ai/v1/update',
            'POST' => '/wp-json/wp-ai/v1/bulk-update',
            'POST' => '/wp-json/wp-ai/v1/update-history',
            'POST' => '/wp-json/wp-ai/v1/update-post',
        )
    );
}

/**
 * Create new API key (admin only)
 */
function meta_manager_create_key($request) {
    $name = $request->get_param('name') ?: 'Unnamed Key';
    $scopes = $request->get_param('scopes') ?: array('read', 'write');
    
    $raw_key = meta_manager_generate_api_key($name, $scopes);
    
    if (!$raw_key) {
        return new WP_Error(
            'keyCreationFailed',
            __('Failed to create API key.'),
            array('status' => 500)
        );
    }
    
    // Return full response including raw key (only time it's shown)
    return array(
        'success' => true,
        'message' => __('Store this key securely - it will not be shown again.', 'wp-admin-ai-agents'),
        'key_id' => wp_generate_uuid4(),
        'api_key' => $raw_key,
        'name' => $name,
        'scopes' => $scopes,
        'example' => "curl -H 'X-API-Key: {$raw_key}' " . rest_url('wp-ai/v1/get/363')
    );
}

/**
 * List all API keys (admin only)
 */
function meta_manager_list_keys($request) {
    $keys = meta_manager_get_all_keys();
    
    return array(
        'success' => true,
        'keys' => $keys,
        'count' => count($keys)
    );
}

/**
 * Delete API key (admin only)
 */
function meta_manager_delete_key($request) {
    $key_id = $request->get_param('key_id');
    
    $deleted = meta_manager_delete_api_key($key_id);
    
    if (!$deleted) {
        return new WP_Error(
            'keyNotFound',
            __('API key not found.', 'wp-admin-ai-agents'),
            array('status' => 404)
        );
    }
    
    return array(
        'success' => true,
        'message' => __('API key deleted.', 'wp-admin-ai-agents')
    );
}

/**
 * Get all meta for a post
 *
 * @param WP_REST_Request $request Full request data
 * @return array Post meta data
 */
function meta_manager_get_meta($request) {
    $post_id = intval($request->get_param('post_id'));
    $meta = get_post_meta($post_id);
    
    // Flatten single-value arrays for cleaner output
    $flat_meta = array();
    foreach ($meta as $key => $values) {
        $flat_meta[$key] = $values[0];
    }
    
    return array(
        'post_id' => $post_id,
        'meta'    => $flat_meta,
    );
}

/**
 * Update or create a single meta field
 *
 * @param WP_REST_Request $request Full request data
 * @return array Update result
 */
function meta_manager_update_meta($request) {
    $post_id = intval($request->get_param('post_id'));
    $meta_key = $request->get_param('meta_key');
    $meta_value = $request->get_param('meta_value');
    
    // Use update_post_meta - WordPress handles existing vs new automatically
    // Returns: 
    //   - false if value didn't change
    //   - meta_id if new meta was created
    //   - true if existing meta was updated
    $result = update_post_meta($post_id, $meta_key, $meta_value);
    
    return array(
        'success'    => true,
        'post_id'    => $post_id,
        'meta_key'   => $meta_key,
        'meta_value' => $meta_value,
        'result'     => $result,
    );
}

/**
 * Bulk update multiple meta fields
 *
 * @param WP_REST_Request $request Full request data
 * @return array Bulk update results
 */
function meta_manager_bulk_update($request) {
    $post_id = intval($request->get_param('post_id'));
    $meta_fields = $request->get_param('meta');
    
    $updated = array();
    $errors = array();
    
    foreach ($meta_fields as $key => $value) {
        $result = update_post_meta($post_id, $key, $value);
        if ($result !== false) {
            $updated[$key] = $value;
        } else {
            $errors[$key] = __('Failed to update meta value');
        }
    }
    
    return array(
        'success' => count($errors) === 0,
        'post_id'  => $post_id,
        'updated'  => $updated,
        'errors'   => $errors,
    );
}

/**
 * Update price history entry
 *
 * Convenience endpoint for stock price tracking applications.
 * Appends to _ws_price_history array and updates _ws_last_price/_ws_last_updated
 *
 * @param WP_REST_Request $request Full request data
 * @return array Update result with history count
 */
function meta_manager_update_history($request) {
    $post_id = intval($request->get_param('post_id'));
    $price = floatval($request->get_param('price'));
    $change = floatval($request->get_param('change'));
    $change_pct = floatval($request->get_param('change_pct'));
    $date = $request->get_param('date') ?: current_time('Y-m-d');
    
    // Get existing history
    $history_json = get_post_meta($post_id, '_ws_price_history', true);
    $history = $history_json ? json_decode($history_json, true) : array();
    
    if (!is_array($history)) {
        $history = array();
    }
    
    // Add new entry
    $history[] = array(
        'date'       => $date,
        'price'      => $price,
        'change'     => round($change, 2),
        'change_pct' => round($change_pct, 2),
    );
    
    // Keep last 100 entries
    if (count($history) > 100) {
        $history = array_slice($history, -100);
    }
    
    // Update all meta fields
    update_post_meta($post_id, '_ws_last_price', number_format($price, 2, '.', ''));
    update_post_meta($post_id, '_ws_last_updated', current_time('Y-m-d\TH:i:s'));
    update_post_meta($post_id, '_ws_price_history', json_encode($history));
    
    return array(
        'success'       => true,
        'post_id'       => $post_id,
        'price'         => $price,
        'change'        => round($change, 2),
        'change_pct'    => round($change_pct, 2),
        'date'          => $date,
        'history_count' => count($history),
    );
}

/**
 * Update post content, title, or status
 *
 * @param WP_REST_Request $request Full request data
 * @return array Update result
 */
function meta_manager_update_post($request) {
    $post_id = intval($request->get_param('post_id'));
    
    // Verify post exists
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error(
            'postNotFound',
            __('Post not found.'),
            array('status' => 404)
        );
    }
    
    $update_data = array();
    $errors = array();
    
    // Title
    $title = $request->get_param('title');
    if ($title !== null) {
        $update_data['post_title'] = sanitize_text_field($title);
    }
    
    // Content
    $content = $request->get_param('content');
    if ($content !== null) {
        $update_data['post_content'] = wp_kses_post($content);
    }
    
    // Status
    $status = $request->get_param('status');
    if ($status !== null) {
        if (in_array($status, array('publish', 'draft', 'private', 'pending', 'future'))) {
            $update_data['post_status'] = $status;
        } else {
            $errors['status'] = 'Invalid status';
        }
    }
    
    if (empty($update_data)) {
        return new WP_Error(
            'noData',
            __('No valid update data provided.'),
            array('status' => 400)
        );
    }
    
    $update_data['ID'] = $post_id;
    $result = wp_update_post($update_data, true);
    
    if (is_wp_error($result)) {
        return new WP_Error(
            'updateFailed',
            $result->get_error_message(),
            array('status' => 500)
        );
    }
    
    return array(
        'success' => true,
        'post_id' => $post_id,
        'updated' => array_keys($update_data),
        'errors' => $errors
    );
}

/**
 * Plugin activation hook
 */
function meta_manager_activate() {
    // Flush rewrite rules for REST API routes
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'meta_manager_activate');

/**
 * Plugin deactivation hook
 */
function meta_manager_deactivate() {
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'meta_manager_deactivate');

/**
 * =============================================================================
 * ADMIN UI - Meta Manager Panel
 * =============================================================================
 */

/**
 * Add Meta Manager admin menu and styles
 */
add_action('admin_menu', 'meta_manager_add_admin_menu');
add_action('admin_enqueue_scripts', 'meta_manager_enqueue_styles');

/**
 * Add WP Admin for AI Agents submenu under Tools
 */
function meta_manager_add_admin_menu() {
    add_submenu_page(
        'tools.php',
        __('WP Admin for AI Agents', 'wp-admin-ai-agents'),
        __('WP Admin for AI Agents', 'wp-admin-ai-agents'),
        'manage_options',
        'meta-manager',
        'meta_manager_admin_page'
    );
}

/**
 * Enqueue admin styles
 */
function meta_manager_enqueue_styles($hook) {
    // Only load on specific pages
    if ($hook !== 'post.php' && $hook !== 'post-new.php' && $hook !== 'tools_page_meta-manager') {
        return;
    }
    
    wp_enqueue_style(
        'meta-manager-admin',
        plugin_dir_url(__FILE__) . 'assets/admin.css',
        array(),
        META_MANAGER_VERSION
    );
}

/**
 * Add Meta Manager meta box to post editor
 */
add_action('add_meta_boxes', 'meta_manager_add_meta_box');

function meta_manager_add_meta_box($post_type) {
    // Add meta box to all post types
    add_meta_box(
        'meta-manager-box',
        __('WP Admin for AI Agents', 'wp-admin-ai-agents'),
        'meta_manager_render_meta_box',
        $post_type,
        'side', // position (side, normal, advanced)
        'low'   // priority
    );
}

/**
 * Render the Meta Manager meta box content
 */
function meta_manager_render_meta_box($post) {
    // Get all meta for this post
    $all_meta = get_post_meta($post->ID);
    
    // Separate internal (starting with _) from visible meta
    $internal_meta = array();
    $visible_meta = array();
    
    foreach ($all_meta as $key => $values) {
        if (is_array($values) && count($values) === 1) {
            $values = $values[0];
        }
        
        if (substr($key, 0, 1) === '_') {
            $internal_meta[$key] = $values;
        } else {
            $visible_meta[$key] = $values;
        }
    }
    
    // Nonce for security
    wp_nonce_field('meta_manager_save_meta', 'meta_manager_nonce');
    
    ?>
    <div class="meta-manager-box">
        <p class="meta-manager-header">
            <span class="dashicons dashicons-database"></span>
            <?php _e('WP Admin for AI Agents', 'wp-admin-ai-agents'); ?>
            <button type="button" class="meta-manager-toggle-all button-link" aria-expanded="false">
                <?php _e('Show All', 'wp-admin-ai-agents'); ?>
            </button>
        </p>
        
        <?php if (empty($visible_meta) && empty($internal_meta)): ?>
            <p class="meta-manager-empty"><?php _e('No meta fields found for this post.', 'wp-admin-ai-agents'); ?></p>
        <?php else: ?>
        
            <!-- Visible Meta Fields -->
            <div class="meta-manager-section">
                <h4 class="meta-manager-section-title">
                    <?php _e('Custom Fields', 'wp-admin-ai-agents'); ?>
                    <span class="meta-manager-count">(<?php echo count($visible_meta); ?>)</span>
                </h4>
                
                <?php if (!empty($visible_meta)): ?>
                    <table class="meta-manager-table">
                        <tbody>
                            <?php foreach ($visible_meta as $key => $value): ?>
                                <tr class="meta-manager-row" data-key="<?php echo esc_attr($key); ?>">
                                    <td class="meta-manager-key">
                                        <code><?php echo esc_html($key); ?></code>
                                    </td>
                                    <td class="meta-manager-value">
                                        <?php 
                                        $display_value = is_array($value) ? implode(', ', $value) : $value;
                                        $display_value = strlen($display_value) > 100 
                                            ? esc_html(substr($display_value, 0, 100)) . '...' 
                                            : esc_html($display_value);
                                        ?>
                                        <span class="meta-manager-value-display" title="<?php echo esc_attr(is_array($value) ? implode(', ', $value) : $value); ?>">
                                            <?php echo $display_value; ?>
                                        </span>
                                        <button type="button" class="meta-manager-edit-btn button-link" title="<?php _e('Edit', 'wp-admin-ai-agents'); ?>">
                                            <span class="dashicons dashicons-edit"></span>
                                        </button>
                                        <div class="meta-manager-edit-form" style="display:none;">
                                            <textarea class="meta-manager-edit-textarea" rows="2"><?php echo esc_textarea(is_array($value) ? json_encode($value) : $value); ?></textarea>
                                            <div class="meta-manager-edit-actions">
                                                <button type="button" class="meta-manager-save-btn button button-primary button-small"><?php _e('Save', 'wp-admin-ai-agents'); ?></button>
                                                <button type="button" class="meta-manager-cancel-btn button button-small"><?php _e('Cancel', 'wp-admin-ai-agents'); ?></button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="meta-manager-empty"><?php _e('No visible custom fields.', 'wp-admin-ai-agents'); ?></p>
                <?php endif; ?>
            </div>
            
            <!-- Internal Meta Fields (Hidden by default) -->
            <div class="meta-manager-section meta-manager-internal">
                <h4 class="meta-manager-section-title meta-manager-toggle-internal">
                    <?php _e('Internal Fields', 'wp-admin-ai-agents'); ?>
                    <span class="meta-manager-count">(<?php echo count($internal_meta); ?>)</span>
                    <span class="dashicons dashicons-arrow-down"></span>
                </h4>
                
                <?php if (!empty($internal_meta)): ?>
                    <table class="meta-manager-table meta-manager-table-internal" style="display:none;">
                        <tbody>
                            <?php foreach ($internal_meta as $key => $value): ?>
                                <tr class="meta-manager-row" data-key="<?php echo esc_attr($key); ?>">
                                    <td class="meta-manager-key">
                                        <code><?php echo esc_html($key); ?></code>
                                    </td>
                                    <td class="meta-manager-value">
                                        <?php 
                                        $display_value = is_array($value) ? implode(', ', $value) : $value;
                                        // Try to decode JSON for readability
                                        if (is_string($display_value) && strlen($display_value) > 0) {
                                            $decoded = json_decode($display_value, true);
                                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                $display_value = json_encode($decoded, JSON_PRETTY_PRINT);
                                            }
                                        }
                                        $display_value = strlen($display_value) > 150 
                                            ? esc_html(substr($display_value, 0, 150)) . '...' 
                                            : esc_html($display_value);
                                        ?>
                                        <span class="meta-manager-value-display" title="<?php echo esc_attr(is_array($value) ? json_encode($value) : $value); ?>">
                                            <?php echo '<pre>' . $display_value . '</pre>'; ?>
                                        </span>
                                        <button type="button" class="meta-manager-edit-btn button-link" title="<?php _e('Edit', 'wp-admin-ai-agents'); ?>">
                                            <span class="dashicons dashicons-edit"></span>
                                        </button>
                                        <div class="meta-manager-edit-form" style="display:none;">
                                            <textarea class="meta-manager-edit-textarea" rows="3"><?php echo esc_textarea(is_array($value) ? json_encode($value) : $value); ?></textarea>
                                            <div class="meta-manager-edit-actions">
                                                <button type="button" class="meta-manager-save-btn button button-primary button-small"><?php _e('Save', 'wp-admin-ai-agents'); ?></button>
                                                <button type="button" class="meta-manager-cancel-btn button button-small"><?php _e('Cancel', 'wp-admin-ai-agents'); ?></button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="meta-manager-empty"><?php _e('No internal fields.', 'wp-admin-ai-agents'); ?></p>
                <?php endif; ?>
            </div>
            
        <?php endif; ?>
        
        <!-- AJAX Save Handler -->
        <script>
        jQuery(document).ready(function($) {
            // Toggle internal fields
            $('.meta-manager-toggle-internal').on('click', function() {
                $(this).find('.dashicons').toggleClass('dashicons-arrow-up dashicons-arrow-down');
                $(this).next('.meta-manager-table-internal').toggle();
            });
            
            // Toggle all fields
            $('.meta-manager-toggle-all').on('click', function() {
                var $box = $(this).closest('.meta-manager-box');
                $box.find('.meta-manager-internal').toggle();
                $box.find('.meta-manager-table-internal').toggle();
                $(this).text($box.find('.meta-manager-table-internal').is(':visible') ? 'Hide All' : 'Show All');
            });
            
            // Edit button click
            $('.meta-manager-edit-btn').on('click', function() {
                $(this).siblings('.meta-manager-value-display').hide();
                $(this).siblings('.meta-manager-edit-form').show();
                $(this).hide();
            });
            
            // Cancel button
            $('.meta-manager-cancel-btn').on('click', function() {
                var $form = $(this).closest('.meta-manager-edit-form');
                $form.hide();
                $form.siblings('.meta-manager-value-display').show();
                $form.siblings('.meta-manager-edit-btn').show();
            });
            
            // Save button
            $('.meta-manager-save-btn').on('click', function() {
                var $row = $(this).closest('.meta-manager-row');
                var $form = $(this).closest('.meta-manager-edit-form');
                var key = $row.data('key');
                var value = $form.find('.meta-manager-edit-textarea').val();
                var $display = $row.find('.meta-manager-value-display');
                var $editBtn = $row.find('.meta-manager-edit-btn');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'meta_manager_ajax_update',
                        nonce: $('#meta_manager_nonce').val(),
                        post_id: <?php echo $post->ID; ?>,
                        meta_key: key,
                        meta_value: value
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update display
                            var displayValue = value.length > 100 ? value.substring(0, 100) + '...' : value;
                            $display.text(displayValue).attr('title', value);
                            $form.hide();
                            $display.show();
                            $editBtn.show();
                            
                            // Show success indicator
                            $row.addClass('meta-manager-updated');
                            setTimeout(function() { $row.removeClass('meta-manager-updated'); }, 2000);
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('AJAX error');
                    }
                });
            });
        });
        </script>
    </div>
    <?php
}

/**
 * AJAX handler for updating meta via admin UI
 */
add_action('wp_ajax_meta_manager_ajax_update', 'meta_manager_ajax_update');

function meta_manager_ajax_update() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'meta_manager_save_meta')) {
        wp_send_json_error('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('edit_post', $_POST['post_id'])) {
        wp_send_json_error('Permission denied');
    }
    
    $post_id = intval($_POST['post_id']);
    $key = sanitize_text_field($_POST['meta_key']);
    $value = $_POST['meta_value'];
    
    // Try to parse as JSON
    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $value = $decoded;
    }
    
    // Update meta
    $result = update_post_meta($post_id, $key, $value);
    
    if ($result !== false) {
        wp_send_json_success(array(
            'key' => $key,
            'value' => $value
        ));
    } else {
        wp_send_json_error('Failed to update meta');
    }
}

/**
 * WP Admin for AI Agents Admin Page (under Tools)
 */
function meta_manager_admin_page() {
    // Get all posts with meta for browsing
    $post_types = get_post_types(array('public' => true), 'objects');
    
    ?>
    <div class="wrap meta-manager-admin-page">
        <h1><?php _e('WP Admin for AI Agents', 'wp-admin-ai-agents'); ?></h1>
        <p><?php _e('View and manage meta fields for any post type.', 'wp-admin-ai-agents'); ?></p>
        
        <hr>
        
        <h2><?php _e('Quick Stats', 'wp-admin-ai-agents'); ?></h2>
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php _e('Post Type', 'wp-admin-ai-agents'); ?></th>
                    <th><?php _e('Total Posts', 'wp-admin-ai-agents'); ?></th>
                    <th><?php _e('Actions', 'wp-admin-ai-agents'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($post_types as $post_type): ?>
                    <?php 
                    $count = wp_count_posts($post_type->name);
                    if ($count && $count->publish > 0):
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($post_type->labels->name); ?></strong></td>
                        <td><?php echo intval($count->publish); ?></td>
                        <td>
                            <a href="<?php echo admin_url('edit.php?post_type=' . $post_type->name); ?>" class="button">
                                <?php _e('Manage Posts', 'wp-admin-ai-agents'); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <hr>
        
        <h2><?php _e('API Endpoints', 'wp-admin-ai-agents'); ?></h2>
        <p><?php _e('Use these REST API endpoints to update meta from external applications:', 'wp-admin-ai-agents'); ?></p>
        
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php _e('Method', 'wp-admin-ai-agents'); ?></th>
                    <th><?php _e('Endpoint', 'wp-admin-ai-agents'); ?></th>
                    <th><?php _e('Description', 'wp-admin-ai-agents'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="dashicons dashicons-yes-alt" style="color:green;"></span> GET</td>
                    <td><code>/wp-json/wp-ai/v1/health</code></td>
                    <td><?php _e('Health check (no auth required)', 'wp-admin-ai-agents'); ?></td>
                </tr>
                <tr>
                    <td><span class="dashicons dashicons-yes-alt" style="color:green;"></span> GET</td>
                    <td><code>/wp-json/wp-ai/v1/get/{post_id}</code></td>
                    <td><?php _e('Get all meta for a post', 'wp-admin-ai-agents'); ?></td>
                </tr>
                <tr>
                    <td><span class="dashicons dashicons-yes-alt" style="color:green;"></span> POST</td>
                    <td><code>/wp-json/wp-ai/v1/update</code></td>
                    <td><?php _e('Update single meta field', 'wp-admin-ai-agents'); ?></td>
                </tr>
                <tr>
                    <td><span class="dashicons dashicons-yes-alt" style="color:green;"></span> POST</td>
                    <td><code>/wp-json/wp-ai/v1/bulk-update</code></td>
                    <td><?php _e('Update multiple meta fields', 'wp-admin-ai-agents'); ?></td>
                </tr>
                <tr>
                    <td><span class="dashicons dashicons-yes-alt" style="color:green;"></span> POST</td>
                    <td><code>/wp-json/wp-ai/v1/update-history</code></td>
                    <td><?php _e('Add price history entry (convenience endpoint)', 'wp-admin-ai-agents'); ?></td>
                </tr>
            </tbody>
        </table>
        
        <hr>
        
        <h2><?php _e('Authentication', 'wp-admin-ai-agents'); ?></h2>
        <p><?php _e('All endpoints (except health) require WordPress authentication. Use Basic Auth with your WordPress username and password, or Application Passwords.', 'wp-admin-ai-agents'); ?></p>
        <pre style="background:#f0f0f0;padding:15px;border-radius:5px;">
# Example: Update meta via curl
curl -X POST https://yoursite.com/wp-json/wp-ai/v1/update \
  -u "username:password" \
  -H "Content-Type: application/json" \
  -d '{"post_id": 363, "meta_key": "_ws_last_price", "meta_value": "5.60"}'</pre>
    </div>
    <?php
}
