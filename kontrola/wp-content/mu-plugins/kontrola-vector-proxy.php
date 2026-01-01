<?php
/**
 * Kontrola Vector Store & Cache Proxy Endpoints
 *
 * Proxies vector store and cache operations from WordPress to the kontrola-agent service.
 * This file is included by kontrola-core.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proxy vector/cache requests to the agent service.
 *
 * @param string $endpoint The agent endpoint path (e.g., 'vector/health', 'cache/status')
 * @param string $method   HTTP method (GET, POST, DELETE)
 * @param array  $body     Optional request body
 * @return array|WP_Error Response or error
 */
function kontrola_proxy_agent_request($endpoint, $method = 'GET', $body = null) {
    $agent_url = getenv('KONTROLA_AGENT_URL');
    if (!$agent_url) {
        $agent_url = get_option('kontrola_agent_url', '');
    }

    if (!$agent_url) {
        return new WP_Error('agent_not_configured', 'Kontrola agent URL is not configured.');
    }

    $url = trailingslashit($agent_url) . $endpoint;
    $secret = getenv('KONTROLA_AGENT_SHARED_SECRET');
    if (!$secret) {
        $secret = get_option('kontrola_agent_shared_secret', '');
    }

    $args = [
        'method' => $method,
        'timeout' => 10,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
    ];

    if ($secret) {
        $args['headers']['X-Kontrola-Secret'] = $secret;
    }

    if ($body) {
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        return new WP_Error('agent_error', 'Failed to reach Kontrola agent: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code >= 400) {
        $error_data = json_decode($body, true);
        return new WP_Error('agent_error', $error_data['detail'] ?? 'Agent returned error ' . $code);
    }

    return json_decode($body, true);
}

/**
 * Register vector store proxy endpoints.
 */
function kontrola_register_vector_routes() {
    // Vector store health check
    register_rest_route('kontrola/v1', '/vector/health', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => function () {
            $res = kontrola_proxy_agent_request('vector/health');
            if (is_wp_error($res)) {
                return new WP_REST_Response(['ok' => false, 'error' => $res->get_error_message()], 503);
            }
            return $res;
        },
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('edit_posts');
        },
    ]);

    // Vector insert (requires editor+)
    register_rest_route('kontrola/v1', '/vector/insert', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => function (WP_REST_Request $req) {
            $body = $req->get_json_params();
            $res = kontrola_proxy_agent_request('vector/insert', 'POST', $body);
            if (is_wp_error($res)) {
                return new WP_REST_Response(['ok' => false, 'error' => $res->get_error_message()], 503);
            }
            return $res;
        },
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('edit_posts');
        },
        'args' => [
            'collection' => ['required' => true, 'type' => 'string'],
            'vectors' => ['required' => true, 'type' => 'array'],
            'metadata' => ['required' => true, 'type' => 'array'],
            'ids' => ['required' => false, 'type' => 'array'],
        ],
    ]);

    // Vector search (requires editor+)
    register_rest_route('kontrola/v1', '/vector/search', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => function (WP_REST_Request $req) {
            $body = $req->get_json_params();
            $res = kontrola_proxy_agent_request('vector/search', 'POST', $body);
            if (is_wp_error($res)) {
                return new WP_REST_Response(['ok' => false, 'error' => $res->get_error_message()], 503);
            }
            return $res;
        },
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('edit_posts');
        },
        'args' => [
            'collection' => ['required' => true, 'type' => 'string'],
            'query_vector' => ['required' => true, 'type' => 'array'],
            'top_k' => ['required' => false, 'type' => 'integer', 'default' => 10],
            'filter' => ['required' => false, 'type' => 'object'],
        ],
    ]);
}

/**
 * Register cache proxy endpoints.
 */
function kontrola_register_cache_routes() {
    // Cache status (check connection + stats)
    register_rest_route('kontrola/v1', '/cache/status', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => function () {
            $res = kontrola_proxy_agent_request('cache/status');
            if (is_wp_error($res)) {
                return new WP_REST_Response(['ok' => false, 'error' => $res->get_error_message()], 503);
            }
            return $res;
        },
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('manage_options');
        },
    ]);

    // Get cached value
    register_rest_route('kontrola/v1', '/cache/(?P<key>.+)', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => function (WP_REST_Request $req) {
            $key = $req->get_param('key');
            $res = kontrola_proxy_agent_request("cache/get/{$key}");
            if (is_wp_error($res)) {
                return new WP_REST_Response(['ok' => false, 'error' => $res->get_error_message()], 503);
            }
            return $res;
        },
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('manage_options');
        },
    ]);

    // Set cached value
    register_rest_route('kontrola/v1', '/cache/set', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => function (WP_REST_Request $req) {
            $key = $req->get_param('key');
            $value = $req->get_param('value');
            $ttl = $req->get_param('ttl');

            $query_string = "key={$key}";
            if ($ttl) {
                $query_string .= "&ttl={$ttl}";
            }

            $res = kontrola_proxy_agent_request("cache/set/{$query_string}", 'POST', ['value' => $value]);
            if (is_wp_error($res)) {
                return new WP_REST_Response(['ok' => false, 'error' => $res->get_error_message()], 503);
            }
            return $res;
        },
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('manage_options');
        },
        'args' => [
            'key' => ['required' => true, 'type' => 'string'],
            'value' => ['required' => true, 'type' => 'string'],
            'ttl' => ['required' => false, 'type' => 'integer'],
        ],
    ]);

    // Delete cached value
    register_rest_route('kontrola/v1', '/cache/delete', [
        'methods' => WP_REST_Server::DELETABLE,
        'callback' => function (WP_REST_Request $req) {
            $key = $req->get_param('key');
            $res = kontrola_proxy_agent_request("cache/delete/{$key}", 'DELETE');
            if (is_wp_error($res)) {
                return new WP_REST_Response(['ok' => false, 'error' => $res->get_error_message()], 503);
            }
            return $res;
        },
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('manage_options');
        },
        'args' => [
            'key' => ['required' => true, 'type' => 'string'],
        ],
    ]);
}

// Hook into REST API init
add_action('rest_api_init', 'kontrola_register_vector_routes');
add_action('rest_api_init', 'kontrola_register_cache_routes');
