<?php
/**
 * Plugin Name: Kontrola Core (MU)
 * Description: Minimal Kontrola wiring: health + task queue REST endpoints and optional delegation to an external agent service.
 * Version: 0.1.0
 * Author: Kontrola
 * License: MIT
 *
 * Notes:
 * - This is a MUST-USE plugin (mu-plugin). It loads automatically if mounted into wp-content/mu-plugins.
 * - Keep this file dependency-free and WordPress-native (hooks, REST API, $wpdb, WP-Cron).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Kontrola_Core_Mu {
    const DB_VERSION = '2';
    const TABLE_TASKS_SUFFIX = 'kontrola_ai_tasks';
    const TABLE_SOCIAL_QUEUE_SUFFIX = 'kontrola_social_queue';
    const OPTION_AGENT_URL = 'kontrola_agent_url';
    const OPTION_AGENT_SHARED_SECRET = 'kontrola_agent_shared_secret';
    const OPTION_MOBILE_API_KEY = 'kontrola_mobile_api_key';
    const OPTION_MOBILE_TOKEN_TTL = 'kontrola_mobile_token_ttl_seconds';
    const USERMETA_MOBILE_TOKEN_HASH = '_kontrola_mobile_token_hash';
    const USERMETA_MOBILE_TOKEN_EXPIRES = '_kontrola_mobile_token_expires';
    const CRON_HOOK_PROCESS_TASKS = 'kontrola_process_tasks';
    const CRON_HOOK_PROCESS_SOCIAL_QUEUE = 'kontrola_process_social_queue';

    public static function bootstrap(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('init', [__CLASS__, 'maybe_install_schema']);
        add_action('init', [__CLASS__, 'maybe_schedule_processing']);
        add_action(self::CRON_HOOK_PROCESS_TASKS, [__CLASS__, 'process_tasks']);
        add_action(self::CRON_HOOK_PROCESS_SOCIAL_QUEUE, [__CLASS__, 'process_social_queue']);

        // Lightweight admin UI for local testing/bootstrapping.
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_menu', [__CLASS__, 'register_admin_pages']);

        // Add a 60s cron schedule so we can poll frequently in dev.
        add_filter('cron_schedules', [__CLASS__, 'add_cron_schedules']);

        // Optional WP-CLI commands for local ops.
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('kontrola tasks', [__CLASS__, 'wp_cli_tasks']);
        }
    }

    public static function add_cron_schedules(array $schedules): array {
        if (!isset($schedules['kontrola_minute'])) {
            $schedules['kontrola_minute'] = [
                'interval' => 60,
                'display' => 'Kontrola: every minute',
            ];
        }
        return $schedules;
    }

    public static function register_settings(): void {
        register_setting('kontrola', self::OPTION_AGENT_URL, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);

        register_setting('kontrola', self::OPTION_AGENT_SHARED_SECRET, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('kontrola', self::OPTION_MOBILE_API_KEY, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('kontrola', self::OPTION_MOBILE_TOKEN_TTL, [
            'type' => 'integer',
            'sanitize_callback' => function ($value) {
                $v = (int) $value;
                if ($v <= 0) {
                    $v = 60 * 60 * 24 * 7; // 7 days
                }
                return $v;
            },
            'default' => 60 * 60 * 24 * 7,
        ]);
    }

    public static function register_admin_pages(): void {
        add_management_page(
            'Kontrola',
            'Kontrola',
            'manage_options',
            'kontrola',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $did_test = false;
        $test_result = null;

        if (isset($_POST['kontrola_test_generate']) && check_admin_referer('kontrola_test_generate')) {
            $did_test = true;
            $prompt = isset($_POST['kontrola_prompt']) ? sanitize_text_field(wp_unslash($_POST['kontrola_prompt'])) : '';

            $req = new WP_REST_Request('POST', '/kontrola/v1/ai/generate');
            $req->set_param('prompt', $prompt);
            $res = self::ai_generate($req);

            if (is_wp_error($res)) {
                $test_result = $res->get_error_message();
            } else {
                $test_result = wp_json_encode(rest_ensure_response($res)->get_data());
            }
        }

        echo '<div class="wrap">';
        echo '<h1>Kontrola</h1>';
        echo '<p>Early wiring for the Kontrola platform: REST endpoints, task queue, and an optional external agent service.</p>';

        echo '<h2>Agent configuration</h2>';
        echo '<p><strong>Tip:</strong> environment variables (from Docker Compose) override these settings.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('kontrola');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="' . esc_attr(self::OPTION_AGENT_URL) . '">Agent URL</label></th>';
        echo '<td><input name="' . esc_attr(self::OPTION_AGENT_URL) . '" id="' . esc_attr(self::OPTION_AGENT_URL) . '" type="url" class="regular-text" value="' . esc_attr(get_option(self::OPTION_AGENT_URL, '')) . '" placeholder="http://kontrola-agent:8000" /></td></tr>';
        echo '<tr><th scope="row"><label for="' . esc_attr(self::OPTION_AGENT_SHARED_SECRET) . '">Shared secret</label></th>';
        echo '<td><input name="' . esc_attr(self::OPTION_AGENT_SHARED_SECRET) . '" id="' . esc_attr(self::OPTION_AGENT_SHARED_SECRET) . '" type="password" class="regular-text" value="' . esc_attr(get_option(self::OPTION_AGENT_SHARED_SECRET, '')) . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="' . esc_attr(self::OPTION_MOBILE_API_KEY) . '">Mobile API key</label></th>';
        echo '<td><input name="' . esc_attr(self::OPTION_MOBILE_API_KEY) . '" id="' . esc_attr(self::OPTION_MOBILE_API_KEY) . '" type="text" class="regular-text" value="' . esc_attr(get_option(self::OPTION_MOBILE_API_KEY, '')) . '" placeholder="(set a secret key for mobile clients)" /></td></tr>';
        echo '<tr><th scope="row"><label for="' . esc_attr(self::OPTION_MOBILE_TOKEN_TTL) . '">Mobile token TTL (seconds)</label></th>';
        echo '<td><input name="' . esc_attr(self::OPTION_MOBILE_TOKEN_TTL) . '" id="' . esc_attr(self::OPTION_MOBILE_TOKEN_TTL) . '" type="number" class="small-text" value="' . esc_attr((string) get_option(self::OPTION_MOBILE_TOKEN_TTL, 60 * 60 * 24 * 7)) . '" min="60" step="60" /> <span class="description">Default: 7 days</span></td></tr>';
        echo '</table>';
        submit_button('Save settings');
        echo '</form>';

        echo '<h2>Test generation</h2>';
        echo '<form method="post">';
        wp_nonce_field('kontrola_test_generate');
        echo '<p><label for="kontrola_prompt">Prompt</label></p>';
        echo '<p><input type="text" class="large-text" id="kontrola_prompt" name="kontrola_prompt" value="' . esc_attr(isset($_POST['kontrola_prompt']) ? wp_unslash($_POST['kontrola_prompt']) : 'Write 3 social post ideas for a WordPress agency.') . '" /></p>';
        submit_button('Run /ai/generate', 'primary', 'kontrola_test_generate');
        echo '</form>';

        if ($did_test) {
            echo '<h3>Result</h3>';
            echo '<pre style="white-space: pre-wrap;">' . esc_html((string) $test_result) . '</pre>';
        }

        echo '</div>';
    }

    private static function get_agent_url(): string {
        $agent_url = (string) getenv('KONTROLA_AGENT_URL');
        if (!$agent_url) {
            $agent_url = (string) get_option(self::OPTION_AGENT_URL, '');
        }
        return (string) $agent_url;
    }

    private static function get_agent_secret(): string {
        $secret = (string) getenv('KONTROLA_AGENT_SHARED_SECRET');
        if (!$secret) {
            $secret = (string) get_option(self::OPTION_AGENT_SHARED_SECRET, '');
        }
        return (string) $secret;
    }

    public static function maybe_schedule_processing(): void {
        // If WP Cron is disabled, this won't run; in that case call `wp cron event run` via wp-cli.
        if (!wp_next_scheduled(self::CRON_HOOK_PROCESS_TASKS)) {
            wp_schedule_event(time() + 60, 'kontrola_minute', self::CRON_HOOK_PROCESS_TASKS);
        }

        if (!wp_next_scheduled(self::CRON_HOOK_PROCESS_SOCIAL_QUEUE)) {
            wp_schedule_event(time() + 120, 'kontrola_minute', self::CRON_HOOK_PROCESS_SOCIAL_QUEUE);
        }
    }

    public static function register_routes(): void {
        register_rest_route('kontrola/v1', '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'health'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('kontrola/v1', '/tasks', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'list_tasks'],
            // In early wiring we require an authenticated user who can manage options.
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('manage_options');
            },
        ]);

        register_rest_route('kontrola/v1', '/tasks', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'create_task'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('manage_options');
            },
            'args' => [
                'type' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'payload' => [
                    'required' => false,
                ],
                'priority' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 0,
                ],
            ],
        ]);

        register_rest_route('kontrola/v1', '/ai/generate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'ai_generate'],
            'permission_callback' => function () {
                // For now: editors and above can generate.
                return is_user_logged_in() && current_user_can('edit_posts');
            },
            'args' => [
                'prompt' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        // TrendRadar proxy endpoints (served by the optional kontrola-agent service).
        register_rest_route('kontrola/v1', '/trends/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'trends_status'],
            'permission_callback' => function () {
                // Editors and above can query trend status.
                return is_user_logged_in() && current_user_can('edit_posts');
            },
        ]);

        register_rest_route('kontrola/v1', '/trends/available-dates', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'trends_available_dates'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('edit_posts');
            },
        ]);

        register_rest_route('kontrola/v1', '/trends/latest', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'trends_latest'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('edit_posts');
            },
            'args' => [
                'kind' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'news',
                ],
                'date' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                ],
            ],
        ]);

        // Mobile endpoints (WordPress-native, token stored in usermeta).
        register_rest_route('kontrola/v1', '/mobile/auth', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'mobile_auth'],
            'permission_callback' => '__return_true',
            'args' => [
                'username' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'password' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route('kontrola/v1', '/mobile/sync/posts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'mobile_sync_posts'],
            'permission_callback' => [__CLASS__, 'permission_mobile_token'],
            'args' => [
                'modified_after' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 0,
                ],
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                ],
            ],
        ]);

        register_rest_route('kontrola/v1', '/mobile/sync/products', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'mobile_sync_products'],
            'permission_callback' => [__CLASS__, 'permission_mobile_token'],
            'args' => [
                'modified_after' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 0,
                ],
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                ],
            ],
        ]);

        register_rest_route('kontrola/v1', '/mobile/sync/tasks', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'mobile_sync_tasks'],
            'permission_callback' => [__CLASS__, 'permission_mobile_token'],
            'args' => [
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                ],
            ],
        ]);

        register_rest_route('kontrola/v1', '/mobile/sync/analytics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'mobile_sync_analytics'],
            'permission_callback' => [__CLASS__, 'permission_mobile_token'],
        ]);

        register_rest_route('kontrola/v1', '/mobile/posts', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'mobile_create_post'],
            'permission_callback' => [__CLASS__, 'permission_mobile_token'],
            'args' => [
                'title' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'content' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'status' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'draft',
                ],
                'type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'post',
                ],
            ],
        ]);

        // Social queue endpoints (enqueue + optional listing).
        register_rest_route('kontrola/v1', '/social/post', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'social_enqueue_post'],
            'permission_callback' => [__CLASS__, 'permission_mobile_token'],
            'args' => [
                'platform' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'content' => [
                    'required' => true,
                ],
                'scheduled_for' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'priority' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 0,
                ],
                'post_id' => [
                    'required' => false,
                    'type' => 'integer',
                ],
                'media' => [
                    'required' => false,
                ],
            ],
        ]);

        register_rest_route('kontrola/v1', '/social/queue', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'social_list_queue'],
            'permission_callback' => function () {
                // Keep queue listing admin-only for now.
                return is_user_logged_in() && current_user_can('manage_options');
            },
            'args' => [
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                ],
                'status' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ]);
    }

    public static function health(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'ok' => true,
            'plugin' => 'kontrola-core-mu',
            'version' => '0.1.0',
            'time' => current_time('mysql'),
        ], 200);
    }

    private static function tasks_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_TASKS_SUFFIX;
    }

    private static function social_queue_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SOCIAL_QUEUE_SUFFIX;
    }

    public static function maybe_install_schema(): void {
        // Keep this lightweight; only run when needed.
        $installed = get_option('kontrola_db_version');

        global $wpdb;
        $tasks_table = self::tasks_table_name();
        $social_table = self::social_queue_table_name();

        $tasks_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tasks_table)) === $tasks_table;
        $social_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $social_table)) === $social_table;

        if ($installed === self::DB_VERSION && $tasks_exists && $social_exists) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql_tasks = "CREATE TABLE {$tasks_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(128) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            priority INT(11) NOT NULL DEFAULT 0,
            payload LONGTEXT NULL,
            result LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY priority (priority),
            KEY type (type)
        ) {$charset_collate};";

        $sql_social = "CREATE TABLE {$social_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NULL,
            platform VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            priority INT(11) NOT NULL DEFAULT 0,
            content LONGTEXT NOT NULL,
            media LONGTEXT NULL,
            scheduled_for DATETIME NULL,
            posted_at DATETIME NULL,
            retry_count INT(11) NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            result LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY scheduled_for (scheduled_for),
            KEY platform (platform),
            KEY priority (priority),
            KEY post_id (post_id)
        ) {$charset_collate};";

        dbDelta($sql_tasks);
        dbDelta($sql_social);

        update_option('kontrola_db_version', self::DB_VERSION, true);
    }

    public static function list_tasks(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = self::tasks_table_name();

        $limit = (int) $request->get_param('limit');
        if ($limit <= 0 || $limit > 200) {
            $limit = 50;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, type, status, priority, created_at, updated_at FROM {$table} ORDER BY id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return new WP_REST_Response([
            'items' => $rows ?: [],
        ], 200);
    }

    public static function create_task(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = self::tasks_table_name();

        $type = (string) $request->get_param('type');
        $priority = (int) $request->get_param('priority');
        $payload = $request->get_param('payload');

        $now = current_time('mysql');

        $wpdb->insert(
            $table,
            [
                'type' => $type,
                'status' => 'pending',
                'priority' => $priority,
                'payload' => is_null($payload) ? null : wp_json_encode($payload),
                'result' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        // Kick the worker quickly for interactive workflows.
        wp_schedule_single_event(time() + 5, self::CRON_HOOK_PROCESS_TASKS);

        return new WP_REST_Response([
            'id' => (int) $wpdb->insert_id,
            'status' => 'pending',
        ], 201);
    }

    public static function ai_generate(WP_REST_Request $request) {
        $prompt = (string) $request->get_param('prompt');

        $agent_url = self::get_agent_url();

        if (!$agent_url) {
            return new WP_Error('kontrola_no_agent', 'Kontrola agent service is not configured (missing KONTROLA_AGENT_URL).', ['status' => 501]);
        }

        $shared_secret = self::get_agent_secret();

        $response = wp_remote_post(rtrim($agent_url, '/') . '/generate', [
            'timeout' => 30,
            'headers' => array_filter([
                'Content-Type' => 'application/json',
                'X-Kontrola-Secret' => $shared_secret ?: null,
            ]),
            'body' => wp_json_encode([
                'prompt' => $prompt,
                'site' => home_url('/'),
                'user' => get_current_user_id(),
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            return new WP_Error('kontrola_agent_error', 'Kontrola agent service returned an error.', [
                'status' => 502,
                'agent_status' => $status,
                'agent_body' => $body,
            ]);
        }

        return rest_ensure_response([
            'text' => is_array($json) && isset($json['text']) ? $json['text'] : $body,
        ]);
    }

    private static function agent_get_json(string $path, array $query = []) {
        $agent_url = self::get_agent_url();
        if (!$agent_url) {
            return new WP_Error('kontrola_no_agent', 'Kontrola agent service is not configured (missing KONTROLA_AGENT_URL).', ['status' => 501]);
        }

        $shared_secret = self::get_agent_secret();
        $url = rtrim($agent_url, '/') . '/' . ltrim($path, '/');

        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => array_filter([
                'Accept' => 'application/json',
                'X-Kontrola-Secret' => $shared_secret ?: null,
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            return new WP_Error('kontrola_agent_error', 'Kontrola agent service returned an error.', [
                'status' => 502,
                'agent_status' => $status,
                'agent_body' => $body,
            ]);
        }

        if (!is_array($json)) {
            return new WP_Error('kontrola_agent_invalid_json', 'Kontrola agent service returned invalid JSON.', [
                'status' => 502,
                'agent_body' => $body,
            ]);
        }

        return rest_ensure_response($json);
    }

    public static function trends_status(WP_REST_Request $request) {
        return self::agent_get_json('/trends/status');
    }

    public static function trends_available_dates(WP_REST_Request $request) {
        return self::agent_get_json('/trends/available-dates');
    }

    public static function trends_latest(WP_REST_Request $request) {
        $kind_raw = (string) $request->get_param('kind');
        $kind = sanitize_key($kind_raw ?: 'news');
        if (!in_array($kind, ['news', 'rss'], true)) {
            return new WP_Error('kontrola_trends_kind_invalid', 'Invalid kind. Expected news or rss.', ['status' => 400]);
        }

        $date = (string) $request->get_param('date');
        $date = $date ? sanitize_text_field($date) : '';
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('kontrola_trends_date_invalid', 'Invalid date. Expected YYYY-MM-DD.', ['status' => 400]);
        }

        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $query = [
            'kind' => $kind,
            'limit' => $limit,
        ];
        if ($date !== '') {
            $query['date'] = $date;
        }

        return self::agent_get_json('/trends/latest', $query);
    }

    public static function process_tasks(): void {
        global $wpdb;
        $table = self::tasks_table_name();

        // Pick one pending task at a time.
        $task = $wpdb->get_row(
            "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY priority DESC, id ASC LIMIT 1",
            ARRAY_A
        );

        if (!$task) {
            return;
        }

        $now = current_time('mysql');
        $wpdb->update(
            $table,
            ['status' => 'processing', 'updated_at' => $now],
            ['id' => (int) $task['id']],
            ['%s', '%s'],
            ['%d']
        );

        $result = null;
        $status = 'done';

        try {
            if ($task['type'] === 'ai.generate' || $task['type'] === 'ai.generate_text') {
                $payload = $task['payload'] ? json_decode($task['payload'], true) : [];
                $prompt = is_array($payload) && isset($payload['prompt']) ? (string) $payload['prompt'] : '';
                if (!$prompt) {
                    throw new Exception('Task payload missing prompt.');
                }

                $req = new WP_REST_Request('POST', '/kontrola/v1/ai/generate');
                $req->set_param('prompt', $prompt);
                $res = self::ai_generate($req);

                if (is_wp_error($res)) {
                    throw new Exception($res->get_error_message());
                }

                $result = rest_ensure_response($res)->get_data();
            } else {
                // Unknown task type: keep it simple for now.
                $result = ['message' => 'No processor for task type.', 'type' => $task['type']];
                $status = 'skipped';
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $result = ['error' => $e->getMessage()];
        }

        $wpdb->update(
            $table,
            [
                'status' => $status,
                'result' => $result === null ? null : wp_json_encode($result),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => (int) $task['id']],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * WP-CLI: `wp kontrola tasks list`.
     *
     * @param array $args
     * @param array $assoc_args
     */
    public static function wp_cli_tasks(array $args, array $assoc_args): void {
        $sub = $args[0] ?? 'list';
        if ($sub !== 'list') {
            WP_CLI::error('Unknown subcommand. Try: wp kontrola tasks list');
        }

        global $wpdb;
        $table = self::tasks_table_name();
        $rows = $wpdb->get_results("SELECT id, type, status, priority, created_at, updated_at FROM {$table} ORDER BY id DESC LIMIT 20", ARRAY_A);
        WP_CLI::print_value($rows, ['format' => 'json']);
    }

    private static function get_mobile_api_key(): string {
        $env_key = (string) getenv('MOBILE_API_KEY');
        if ($env_key) {
            return $env_key;
        }
        return (string) get_option(self::OPTION_MOBILE_API_KEY, '');
    }

    private static function get_mobile_token_ttl_seconds(): int {
        $ttl = (int) get_option(self::OPTION_MOBILE_TOKEN_TTL, 60 * 60 * 24 * 7);
        if ($ttl <= 0) {
            $ttl = 60 * 60 * 24 * 7;
        }
        return $ttl;
    }

    /**
     * Mobile auth helper: generate a token and store a hash + expiry in user meta.
     *
     * Token format: "{user_id}.{random_hex}". Only the random part is hashed in storage.
     */
    private static function issue_mobile_token(int $user_id): array {
        $random = '';
        try {
            $random = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $random = wp_generate_password(64, false, false);
        }

        $token = $user_id . '.' . $random;
        $expires = time() + self::get_mobile_token_ttl_seconds();

        update_user_meta($user_id, self::USERMETA_MOBILE_TOKEN_HASH, hash('sha256', $random));
        update_user_meta($user_id, self::USERMETA_MOBILE_TOKEN_EXPIRES, (string) $expires);

        return ['token' => $token, 'expires' => $expires];
    }

    private static function verify_mobile_token_from_request(WP_REST_Request $request) {
        $api_key = (string) $request->get_header('X-Mobile-API-Key');
        $expected_api_key = self::get_mobile_api_key();
        if (!$expected_api_key) {
            return new WP_Error('kontrola_mobile_api_key_missing', 'Mobile API key is not configured on this site.', ['status' => 501]);
        }
        if (!$api_key || !hash_equals($expected_api_key, $api_key)) {
            return new WP_Error('kontrola_mobile_api_key_invalid', 'Invalid mobile API key.', ['status' => 401]);
        }

        $auth = (string) $request->get_header('Authorization');
        if (!$auth || stripos($auth, 'Bearer ') !== 0) {
            return new WP_Error('kontrola_mobile_auth_missing', 'Missing Authorization: Bearer token.', ['status' => 401]);
        }
        $token = trim(substr($auth, 7));
        if ($token === '') {
            return new WP_Error('kontrola_mobile_auth_invalid', 'Invalid bearer token.', ['status' => 401]);
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return new WP_Error('kontrola_mobile_auth_invalid', 'Invalid token format.', ['status' => 401]);
        }
        $user_id = (int) $parts[0];
        $random = (string) $parts[1];
        if ($user_id <= 0 || $random === '') {
            return new WP_Error('kontrola_mobile_auth_invalid', 'Invalid token format.', ['status' => 401]);
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('kontrola_mobile_auth_invalid', 'Unknown user.', ['status' => 401]);
        }

        $stored_hash = (string) get_user_meta($user_id, self::USERMETA_MOBILE_TOKEN_HASH, true);
        $stored_expires = (int) get_user_meta($user_id, self::USERMETA_MOBILE_TOKEN_EXPIRES, true);

        if (!$stored_hash || !$stored_expires) {
            return new WP_Error('kontrola_mobile_auth_invalid', 'Token not found.', ['status' => 401]);
        }
        if (time() > $stored_expires) {
            return new WP_Error('kontrola_mobile_auth_expired', 'Token expired.', ['status' => 401]);
        }

        $candidate_hash = hash('sha256', $random);
        if (!hash_equals($stored_hash, $candidate_hash)) {
            return new WP_Error('kontrola_mobile_auth_invalid', 'Invalid token.', ['status' => 401]);
        }

        // Establish the current user for capability checks downstream.
        wp_set_current_user($user_id);

        if (!current_user_can('edit_posts')) {
            return new WP_Error('kontrola_mobile_auth_forbidden', 'User is not allowed to access mobile endpoints.', ['status' => 403]);
        }

        return $user_id;
    }

    public static function permission_mobile_token(WP_REST_Request $request): bool {
        $verified = self::verify_mobile_token_from_request($request);
        if (is_wp_error($verified)) {
            return $verified;
        }
        return true;
    }

    public static function mobile_auth(WP_REST_Request $request) {
        $api_key = (string) $request->get_header('X-Mobile-API-Key');
        $expected_api_key = self::get_mobile_api_key();
        if (!$expected_api_key) {
            return new WP_Error('kontrola_mobile_api_key_missing', 'Mobile API key is not configured on this site.', ['status' => 501]);
        }
        if (!$api_key || !hash_equals($expected_api_key, $api_key)) {
            return new WP_Error('kontrola_mobile_api_key_invalid', 'Invalid mobile API key.', ['status' => 401]);
        }

        $username = (string) $request->get_param('username');
        $password = (string) $request->get_param('password');

        $user = wp_authenticate($username, $password);
        if (is_wp_error($user)) {
            return new WP_Error('kontrola_mobile_auth_failed', 'Authentication failed.', ['status' => 401]);
        }

        if (!user_can($user, 'edit_posts')) {
            return new WP_Error('kontrola_mobile_auth_forbidden', 'User is not allowed to use mobile endpoints.', ['status' => 403]);
        }

        $issued = self::issue_mobile_token((int) $user->ID);

        return rest_ensure_response([
            'success' => true,
            'user' => [
                'id' => (int) $user->ID,
                'username' => (string) $user->user_login,
                'display_name' => (string) $user->display_name,
            ],
            'token' => $issued['token'],
            'expires' => $issued['expires'],
            'server_time' => time(),
        ]);
    }

    public static function mobile_sync_posts(WP_REST_Request $request): WP_REST_Response {
        $modified_after = (int) $request->get_param('modified_after');
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0 || $limit > 200) {
            $limit = 50;
        }

        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if ($modified_after > 0) {
            $args['date_query'] = [[
                'column' => 'post_modified_gmt',
                'after' => gmdate('Y-m-d H:i:s', $modified_after),
                'inclusive' => false,
            ]];
        }

        $q = new WP_Query($args);
        $items = [];
        foreach ($q->posts as $post) {
            $items[] = [
                'id' => (int) $post->ID,
                'type' => (string) $post->post_type,
                'status' => (string) $post->post_status,
                'title' => (string) get_the_title($post),
                'excerpt' => (string) get_the_excerpt($post),
                'content' => (string) apply_filters('the_content', $post->post_content),
                'date' => (string) $post->post_date,
                'modified' => (string) $post->post_modified,
                'featured_image' => get_the_post_thumbnail_url($post, 'medium') ?: null,
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $items,
            'count' => count($items),
            'timestamp' => time(),
        ], 200);
    }

    public static function mobile_sync_products(WP_REST_Request $request) {
        if (!function_exists('wc_get_products')) {
            return new WP_Error('kontrola_woocommerce_missing', 'WooCommerce is not installed/active on this site.', ['status' => 501]);
        }

        $limit = (int) $request->get_param('limit');
        if ($limit <= 0 || $limit > 200) {
            $limit = 50;
        }

        $products = wc_get_products([
            'limit' => $limit,
            'orderby' => 'date_modified',
            'order' => 'DESC',
            'status' => ['publish', 'draft', 'private'],
        ]);

        $items = [];
        foreach ($products as $p) {
            $items[] = [
                'id' => (int) $p->get_id(),
                'name' => (string) $p->get_name(),
                'status' => (string) $p->get_status(),
                'price' => $p->get_price(),
                'sku' => (string) $p->get_sku(),
                'type' => (string) $p->get_type(),
                'modified' => $p->get_date_modified() ? $p->get_date_modified()->date('c') : null,
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $items,
            'count' => count($items),
            'timestamp' => time(),
        ]);
    }

    public static function mobile_sync_tasks(WP_REST_Request $request): WP_REST_Response {
        // Reuse the existing list endpoint logic.
        return self::list_tasks($request);
    }

    public static function mobile_sync_analytics(WP_REST_Request $request): WP_REST_Response {
        // Placeholder for future analytics integration.
        return new WP_REST_Response([
            'success' => true,
            'data' => [],
            'count' => 0,
            'timestamp' => time(),
        ], 200);
    }

    public static function mobile_create_post(WP_REST_Request $request) {
        $title = (string) $request->get_param('title');
        $content = (string) $request->get_param('content');
        $status = (string) $request->get_param('status');
        $type = (string) $request->get_param('type');

        $status_allowed = ['draft', 'publish', 'private'];
        if (!in_array($status, $status_allowed, true)) {
            $status = 'draft';
        }

        $post_id = wp_insert_post([
            'post_type' => $type ?: 'post',
            'post_status' => $status,
            'post_title' => wp_strip_all_tags($title),
            'post_content' => $content,
            'post_author' => get_current_user_id(),
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        return rest_ensure_response([
            'success' => true,
            'id' => (int) $post_id,
            'edit_link' => get_edit_post_link((int) $post_id, 'raw'),
        ]);
    }

    public static function social_enqueue_post(WP_REST_Request $request) {
        global $wpdb;
        $table = self::social_queue_table_name();

        $platform = sanitize_key((string) $request->get_param('platform'));
        $content = $request->get_param('content');
        $media = $request->get_param('media');
        $priority = (int) $request->get_param('priority');
        $post_id = $request->get_param('post_id');
        $scheduled_for_raw = (string) $request->get_param('scheduled_for');

        $scheduled_for = null;
        if ($scheduled_for_raw) {
            $ts = strtotime($scheduled_for_raw);
            if ($ts !== false) {
                $scheduled_for = wp_date('Y-m-d H:i:s', $ts);
            }
        }

        $now = current_time('mysql');
        $wpdb->insert(
            $table,
            [
                'post_id' => is_null($post_id) ? null : (int) $post_id,
                'platform' => $platform,
                'status' => 'pending',
                'priority' => $priority,
                'content' => is_string($content) ? $content : wp_json_encode($content),
                'media' => is_null($media) ? null : (is_string($media) ? $media : wp_json_encode($media)),
                'scheduled_for' => $scheduled_for,
                'posted_at' => null,
                'retry_count' => 0,
                'last_error' => null,
                'result' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $id = (int) $wpdb->insert_id;

        // Kick processing quickly.
        wp_schedule_single_event(time() + 10, self::CRON_HOOK_PROCESS_SOCIAL_QUEUE);

        return rest_ensure_response([
            'success' => true,
            'id' => $id,
            'status' => 'pending',
        ]);
    }

    public static function social_list_queue(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = self::social_queue_table_name();

        $limit = (int) $request->get_param('limit');
        if ($limit <= 0 || $limit > 200) {
            $limit = 50;
        }

        $status = (string) $request->get_param('status');
        $where = '1=1';
        $params = [];

        if ($status !== '') {
            $where = 'status = %s';
            $params[] = $status;
        }

        $sql = "SELECT id, post_id, platform, status, priority, scheduled_for, posted_at, retry_count, last_error, created_at, updated_at FROM {$table} WHERE {$where} ORDER BY priority DESC, id DESC LIMIT %d";
        $params[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        return new WP_REST_Response([
            'items' => $rows ?: [],
        ], 200);
    }

    public static function process_social_queue(): void {
        global $wpdb;
        $table = self::social_queue_table_name();

        $now_mysql = current_time('mysql');

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'pending' AND (scheduled_for IS NULL OR scheduled_for <= %s) ORDER BY priority DESC, id ASC LIMIT 1",
                $now_mysql
            ),
            ARRAY_A
        );

        if (!$row) {
            return;
        }

        $wpdb->update(
            $table,
            ['status' => 'processing', 'updated_at' => $now_mysql],
            ['id' => (int) $row['id']],
            ['%s', '%s'],
            ['%d']
        );

        $status = 'posted';
        $result = null;
        $error = null;

        try {
            $content = $row['content'];
            $media = $row['media'] ? json_decode($row['media'], true) : null;

            /**
             * Filter: provide an integration that actually posts to a platform.
             *
             * Expected return (array): ['success' => bool, 'message' => string, 'data' => mixed]
             */
            $integration_result = apply_filters('kontrola_social_post', null, $row['platform'], $content, $media, $row);

            if (!is_array($integration_result) || !isset($integration_result['success'])) {
                throw new Exception('No social integration handler registered. Add a filter for kontrola_social_post.');
            }

            if (!$integration_result['success']) {
                throw new Exception(isset($integration_result['message']) ? (string) $integration_result['message'] : 'Social post failed.');
            }

            $result = $integration_result;
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            $result = ['error' => $error];
        }

        $update = [];
        $update_formats = [];

        $update['status'] = $status;
        $update_formats[] = '%s';

        $update['updated_at'] = current_time('mysql');
        $update_formats[] = '%s';

        $update['result'] = $result === null ? null : wp_json_encode($result);
        $update_formats[] = '%s';

        if ($status === 'posted') {
            $update['posted_at'] = current_time('mysql');
            $update_formats[] = '%s';

            $update['last_error'] = null;
            $update_formats[] = '%s';
        } else {
            $update['retry_count'] = ((int) $row['retry_count']) + 1;
            $update_formats[] = '%d';

            $update['last_error'] = $error;
            $update_formats[] = '%s';
        }

        $wpdb->update(
            $table,
            $update,
            ['id' => (int) $row['id']],
            $update_formats,
            ['%d']
        );
    }
}

Kontrola_Core_Mu::bootstrap();
