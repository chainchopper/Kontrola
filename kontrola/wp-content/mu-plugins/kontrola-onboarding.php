<?php
/**
 * Kontrola Onboarding Wizard
 *
 * REST endpoints and WordPress admin UI for configuring vector databases, caching, and storage.
 * This file is included by kontrola-core.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kontrola_Onboarding {
    const OPTION_PREFIX = 'kontrola_vector_';
    const OPTION_WIZARD_COMPLETE = 'kontrola_onboarding_complete';
    const OPTION_VECTOR_BACKEND = 'kontrola_vector_backend';
    const OPTION_CACHE_ENABLED = 'kontrola_cache_enabled';
    const OPTION_OBJECT_STORAGE = 'kontrola_object_storage';

    public static function bootstrap(): void {
        add_action('rest_api_init', [__CLASS__, 'register_onboarding_routes']);
        add_action('admin_menu', [__CLASS__, 'add_onboarding_submenu']);
        add_action('wp_ajax_kontrola_test_vector_connection', [__CLASS__, 'ajax_test_vector_connection']);
        add_action('wp_ajax_kontrola_save_onboarding', [__CLASS__, 'ajax_save_onboarding']);
    }

    public static function add_onboarding_submenu(): void {
        // Only show onboarding if not complete
        $is_complete = get_option(self::OPTION_WIZARD_COMPLETE, false);

        if (!$is_complete) {
            add_submenu_page(
                'kontrola',
                'Vector DB Setup',
                'Setup Wizard',
                'manage_options',
                'kontrola-onboarding',
                [__CLASS__, 'render_onboarding_page']
            );
        }

        // Always show services status page
        add_submenu_page(
            'kontrola',
            'Services Status',
            'Services Status',
            'manage_options',
            'kontrola-services',
            [__CLASS__, 'render_services_page']
        );
    }

    public static function render_onboarding_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        wp_enqueue_script('kontrola-onboarding', plugins_url('assets/onboarding.js', __FILE__), ['jquery'], '1.0');
        wp_localize_script('kontrola-onboarding', 'kontrolaOnboarding', [
            'nonce' => wp_create_nonce('kontrola_onboarding'),
            'restUrl' => esc_url_raw(rest_url('kontrola/v1/onboarding/')),
        ]);
        wp_enqueue_style('kontrola-onboarding', plugins_url('assets/onboarding.css', __FILE__), [], '1.0');

        echo '<div class="wrap kontrola-onboarding-wrap">';
        echo '<h1>Kontrola Vector Database Setup</h1>';
        echo '<p class="description">Configure AI capabilities for your WordPress site. This wizard will guide you through selecting services.</p>';

        echo '<div id="kontrola-wizard" class="kontrola-wizard">';
        echo '  <div class="step active" data-step="1">';
        echo '    <h2>Step 1: Welcome</h2>';
        echo '    <p>Kontrola integrates advanced AI features into WordPress:</p>';
        echo '    <ul>';
        echo '      <li><strong>Vector Search:</strong> Semantic search across posts, plugins, and metadata</li>';
        echo '      <li><strong>RAG (Retrieval-Augmented Generation):</strong> AI-enhanced recommendations using your data</li>';
        echo '      <li><strong>Caching:</strong> Fast access to frequently queried data</li>';
        echo '      <li><strong>Object Storage:</strong> Safe storage for models and large files</li>';
        echo '    </ul>';
        echo '    <button class="next-step button button-primary">Continue →</button>';
        echo '  </div>';

        // Step 2: Service Selection
        echo '  <div class="step" data-step="2">';
        echo '    <h2>Step 2: Choose Services</h2>';
        echo '    <p>Select which services to enable:</p>';

        echo '    <label class="service-option">';
        echo '      <input type="checkbox" name="cache_enabled" value="1" checked />';
        echo '      <span class="service-label">Redis Caching <span class="badge badge-recommended">Recommended</span></span>';
        echo '      <span class="service-desc">Fast in-memory caching. Reduces MySQL load, speeds up queries.</span>';
        echo '    </label>';

        echo '    <label class="service-option">';
        echo '      <input type="checkbox" name="object_storage" value="1" />';
        echo '      <span class="service-label">MinIO Object Storage</span>';
        echo '      <span class="service-desc">S3-compatible storage for models, backups, and large files.</span>';
        echo '    </label>';

        echo '    <div class="service-option group">';
        echo '      <span class="service-label">Vector Database <span class="badge badge-required">Required</span></span>';
        echo '      <span class="service-desc">Where embeddings and semantic search vectors are stored.</span>';
        echo '      <div class="vector-db-options">';

        $vector_dbs = [
            'lancedb' => [
                'label' => 'LanceDB (Default)',
                'desc' => 'Embedded, zero-config, GPU-accelerated. Best for getting started.',
                'recommended' => true,
            ],
            'milvus' => [
                'label' => 'Milvus',
                'desc' => 'Production-grade, billions of vectors, GPU support. Requires more resources.',
            ],
            'chroma' => [
                'label' => 'Chroma',
                'desc' => 'Simple, built-in embeddings. Good for small to medium datasets.',
            ],
            'qdrant' => [
                'label' => 'Qdrant',
                'desc' => 'Production-ready with web UI, excellent filtering. Good balance of features.',
            ],
            'pgvector' => [
                'label' => 'PGVector',
                'desc' => 'PostgreSQL extension. SQL-based vector search, co-locate with relational data.',
            ],
            'pinecone' => [
                'label' => 'Pinecone',
                'desc' => 'Managed cloud service. Zero-ops, auto-scaling. Requires API key.',
            ],
        ];

        foreach ($vector_dbs as $key => $db) {
            $checked = ($key === 'lancedb') ? 'checked' : '';
            $recommended = isset($db['recommended']) ? ' <span class="badge badge-recommended">Recommended</span>' : '';
            echo "      <label class='vector-db-option'>";
            echo "        <input type='radio' name='vector_db' value='{$key}' {$checked} />";
            echo "        <span class='vector-db-label'>{$db['label']}{$recommended}</span>";
            echo "        <span class='vector-db-desc'>{$db['desc']}</span>";
            echo "      </label>";
        }

        echo '      </div>';
        echo '    </div>';

        echo '    <div class="form-navigation">';
        echo '      <button class="prev-step button">← Back</button>';
        echo '      <button class="next-step button button-primary">Continue →</button>';
        echo '    </div>';
        echo '  </div>';

        // Step 3: Connection Testing
        echo '  <div class="step" data-step="3">';
        echo '    <h2>Step 3: Test Connections</h2>';
        echo '    <p>Testing connections to selected services...</p>';
        echo '    <div id="connection-tests"></div>';
        echo '    <div class="form-navigation">';
        echo '      <button class="prev-step button">← Back</button>';
        echo '      <button class="next-step button button-primary" id="continue-if-ok" disabled>Continue →</button>';
        echo '    </div>';
        echo '  </div>';

        // Step 4: Configuration
        echo '  <div class="step" data-step="4">';
        echo '    <h2>Step 4: Advanced Configuration</h2>';
        echo '    <p>Optional: Configure service credentials (if not set in .env)</p>';
        echo '    <div id="config-form"></div>';
        echo '    <div class="form-navigation">';
        echo '      <button class="prev-step button">← Back</button>';
        echo '      <button class="save-onboarding button button-primary">Save & Complete Setup</button>';
        echo '    </div>';
        echo '  </div>';

        // Step 5: Success
        echo '  <div class="step" data-step="5">';
        echo '    <h2>Setup Complete!</h2>';
        echo '    <div class="success-message">';
        echo '      <p>✓ Your Kontrola AI platform is now configured.</p>';
        echo '      <p>Next steps:</p>';
        echo '      <ul>';
        echo '        <li>Visit the <a href="' . admin_url('admin.php?page=kontrola-services') . '">Services Status</a> page to monitor your services</li>';
        echo '        <li>Enable RAG features to index your content</li>';
        echo '        <li>Explore AI-powered search and recommendations</li>';
        echo '      </ul>';
        echo '    </div>';
        echo '  </div>';

        echo '</div>';
        echo '</div>';
    }

    public static function render_services_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        echo '<div class="wrap kontrola-services-wrap">';
        echo '<h1>Kontrola Services Status</h1>';

        $vector_backend = get_option(self::OPTION_VECTOR_BACKEND, 'lancedb');
        $cache_enabled = get_option(self::OPTION_CACHE_ENABLED, false);
        $object_storage = get_option(self::OPTION_OBJECT_STORAGE, false);

        echo '<div class="services-grid">';

        // WordPress/MySQL
        echo '<div class="service-card">';
        echo '  <h3>WordPress & MySQL</h3>';
        echo '  <p class="status status-ok">✓ Connected</p>';
        echo '  <p>Local WordPress installation with MySQL database.</p>';
        echo '</div>';

        // Vector Database
        echo '<div class="service-card">';
        echo '  <h3>Vector Database</h3>';
        $vector_status = self::check_vector_health($vector_backend);
        $status_class = $vector_status['ok'] ? 'status-ok' : 'status-error';
        $status_text = $vector_status['ok'] ? '✓ Connected' : '✗ Not Available';
        echo "  <p class='status {$status_class}'>{$status_text}</p>";
        echo "  <p><strong>Backend:</strong> " . esc_html($vector_backend) . "</p>";
        echo '  <p>' . esc_html($vector_status['message'] ?? '') . '</p>';
        echo '</div>';

        // Redis Cache
        if ($cache_enabled) {
            echo '<div class="service-card">';
            echo '  <h3>Redis Cache</h3>';
            $cache_status = self::check_cache_health();
            $status_class = $cache_status['ok'] ? 'status-ok' : 'status-error';
            $status_text = $cache_status['ok'] ? '✓ Connected' : '✗ Not Available';
            echo "  <p class='status {$status_class}'>{$status_text}</p>";
            if ($cache_status['ok']) {
                echo '<p><strong>Hit Rate:</strong> ' . esc_html($cache_status['hit_rate'] ?? 'N/A') . '%</p>';
            }
            echo '</div>';
        }

        // Object Storage
        if ($object_storage) {
            echo '<div class="service-card">';
            echo '  <h3>MinIO Object Storage</h3>';
            echo '  <p class="status status-info">ℹ Configured</p>';
            echo '  <p>S3-compatible storage for models and large files.</p>';
            echo '  <p><a href="http://localhost:9001" target="_blank">Open Console</a></p>';
            echo '</div>';
        }

        // Re-run wizard
        echo '<div class="service-card full-width">';
        echo '  <h3>Re-configure</h3>';
        echo '  <p><a href="' . admin_url('admin.php?page=kontrola&reset=1') . '" class="button button-secondary">Re-run Setup Wizard</a></p>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    private static function check_vector_health($backend) {
        $agent_url = getenv('KONTROLA_AGENT_URL') ?: get_option('kontrola_agent_url', '');
        if (!$agent_url) {
            return ['ok' => false, 'message' => 'Agent URL not configured'];
        }

        $url = trailingslashit($agent_url) . 'vector/health';
        $secret = getenv('KONTROLA_AGENT_SHARED_SECRET') ?: get_option('kontrola_agent_shared_secret', '');

        $args = ['timeout' => 5, 'headers' => []];
        if ($secret) {
            $args['headers']['X-Kontrola-Secret'] = $secret;
        }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => 'Agent unreachable'];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return [
            'ok' => $body['ok'] ?? false,
            'message' => $body['backend'] ?? $backend,
        ];
    }

    private static function check_cache_health() {
        $agent_url = getenv('KONTROLA_AGENT_URL') ?: get_option('kontrola_agent_url', '');
        if (!$agent_url) {
            return ['ok' => false];
        }

        $url = trailingslashit($agent_url) . 'cache/status';
        $secret = getenv('KONTROLA_AGENT_SHARED_SECRET') ?: get_option('kontrola_agent_shared_secret', '');

        $args = ['timeout' => 5, 'headers' => []];
        if ($secret) {
            $args['headers']['X-Kontrola-Secret'] = $secret;
        }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return ['ok' => false];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $hits = intval($body['keyspace_hits'] ?? 0);
        $misses = intval($body['keyspace_misses'] ?? 0);
        $total = $hits + $misses;
        $hit_rate = $total > 0 ? round(($hits / $total) * 100) : 0;

        return [
            'ok' => $body['ok'] ?? false,
            'hit_rate' => $hit_rate,
        ];
    }

    public static function register_onboarding_routes(): void {
        register_rest_route('kontrola/v1', '/onboarding/test-connection', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'rest_test_connection'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('manage_options');
            },
            'args' => [
                'service' => ['required' => true, 'type' => 'string'],
                'backend' => ['required' => false, 'type' => 'string'],
            ],
        ]);

        register_rest_route('kontrola/v1', '/onboarding/save', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'rest_save_onboarding'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('manage_options');
            },
            'args' => [
                'vector_db' => ['required' => true, 'type' => 'string'],
                'cache_enabled' => ['required' => false, 'type' => 'boolean'],
                'object_storage' => ['required' => false, 'type' => 'boolean'],
                'config' => ['required' => false, 'type' => 'object'],
            ],
        ]);
    }

    public static function rest_test_connection(WP_REST_Request $req) {
        $service = $req->get_param('service');
        $backend = $req->get_param('backend');

        $agent_url = getenv('KONTROLA_AGENT_URL') ?: get_option('kontrola_agent_url', '');
        if (!$agent_url) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Agent URL not configured'], 503);
        }

        $endpoints = [
            'vector' => 'vector/health',
            'cache' => 'cache/status',
        ];

        if (!isset($endpoints[$service])) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Unknown service'], 400);
        }

        $url = trailingslashit($agent_url) . $endpoints[$service];
        $secret = getenv('KONTROLA_AGENT_SHARED_SECRET') ?: get_option('kontrola_agent_shared_secret', '');

        $args = ['timeout' => 10, 'headers' => []];
        if ($secret) {
            $args['headers']['X-Kontrola-Secret'] = $secret;
        }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Connection failed'], 503);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        return new WP_REST_Response([
            'ok' => $body['ok'] ?? false,
            'message' => $body['error'] ?? 'Connected',
        ]);
    }

    public static function rest_save_onboarding(WP_REST_Request $req) {
        $vector_db = sanitize_text_field($req->get_param('vector_db'));
        $cache_enabled = (bool) $req->get_param('cache_enabled');
        $object_storage = (bool) $req->get_param('object_storage');
        $config = (array) $req->get_param('config');

        update_option(self::OPTION_VECTOR_BACKEND, $vector_db);
        update_option(self::OPTION_CACHE_ENABLED, $cache_enabled);
        update_option(self::OPTION_OBJECT_STORAGE, $object_storage);

        if (!empty($config)) {
            foreach ($config as $key => $value) {
                update_option(self::OPTION_PREFIX . $key, sanitize_text_field($value));
            }
        }

        update_option(self::OPTION_WIZARD_COMPLETE, true);

        return new WP_REST_Response([
            'ok' => true,
            'message' => 'Configuration saved successfully',
        ]);
    }

    public static function ajax_test_vector_connection() {
        check_ajax_referer('kontrola_onboarding');

        $backend = isset($_POST['backend']) ? sanitize_text_field($_POST['backend']) : '';
        $req = new WP_REST_Request('POST', '/kontrola/v1/onboarding/test-connection');
        $req->set_param('service', 'vector');
        $req->set_param('backend', $backend);

        $res = self::rest_test_connection($req);
        wp_send_json($res->get_data());
    }

    public static function ajax_save_onboarding() {
        check_ajax_referer('kontrola_onboarding');

        $vector_db = isset($_POST['vector_db']) ? sanitize_text_field($_POST['vector_db']) : 'lancedb';
        $cache_enabled = isset($_POST['cache_enabled']) ? (bool) $_POST['cache_enabled'] : false;

        $req = new WP_REST_Request('POST', '/kontrola/v1/onboarding/save');
        $req->set_param('vector_db', $vector_db);
        $req->set_param('cache_enabled', $cache_enabled);

        $res = self::rest_save_onboarding($req);
        wp_send_json($res->get_data());
    }
}

Kontrola_Onboarding::bootstrap();
