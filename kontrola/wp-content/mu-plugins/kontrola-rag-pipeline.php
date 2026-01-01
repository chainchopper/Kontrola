<?php
/**
 * Kontrola RAG Pipeline
 *
 * Retrieval-Augmented Generation for plugin/theme awareness and semantic search.
 * Indexes WordPress data (plugins, themes, posts) into vector database.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kontrola_RAG_Pipeline {
    const TABLE_RAG_INDEX_SUFFIX = 'kontrola_rag_index';
    const CRON_HOOK_INDEX_PLUGINS = 'kontrola_index_plugins';
    const CRON_HOOK_INDEX_THEMES = 'kontrola_index_themes';
    const CRON_HOOK_INDEX_POSTS = 'kontrola_index_posts';
    const OPTION_LAST_PLUGIN_INDEX = 'kontrola_rag_last_plugin_index';
    const OPTION_LAST_THEME_INDEX = 'kontrola_rag_last_theme_index';
    const OPTION_LAST_POSTS_INDEX = 'kontrola_rag_last_posts_index';

    public static function bootstrap(): void {
        add_action('init', [__CLASS__, 'maybe_install_schema']);
        add_action('init', [__CLASS__, 'maybe_schedule_indexing']);
        add_action(self::CRON_HOOK_INDEX_PLUGINS, [__CLASS__, 'index_plugins']);
        add_action(self::CRON_HOOK_INDEX_THEMES, [__CLASS__, 'index_themes']);
        add_action(self::CRON_HOOK_INDEX_POSTS, [__CLASS__, 'index_posts']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);

        // Index on content change
        add_action('transition_post_status', [__CLASS__, 'maybe_reindex_post'], 10, 3);
        add_action('plugins_loaded', [__CLASS__, 'maybe_reindex_plugins']);
    }

    public static function maybe_install_schema(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_RAG_INDEX_SUFFIX;

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            content_type VARCHAR(32) NOT NULL,
            content_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255),
            excerpt LONGTEXT,
            vector_id VARCHAR(256),
            indexed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY content_unique (content_type, content_id),
            KEY indexed_at (indexed_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function maybe_schedule_indexing(): void {
        // Schedule plugins indexing (daily)
        if (!wp_next_scheduled(self::CRON_HOOK_INDEX_PLUGINS)) {
            wp_schedule_event(time() + 300, 'daily', self::CRON_HOOK_INDEX_PLUGINS);
        }

        // Schedule themes indexing (daily)
        if (!wp_next_scheduled(self::CRON_HOOK_INDEX_THEMES)) {
            wp_schedule_event(time() + 600, 'daily', self::CRON_HOOK_INDEX_THEMES);
        }

        // Schedule posts indexing (6 hours)
        if (!wp_next_scheduled(self::CRON_HOOK_INDEX_POSTS)) {
            wp_schedule_event(time() + 900, 'sixhourly', self::CRON_HOOK_INDEX_POSTS);
        }
    }

    /**
     * Index all installed plugins and their metadata.
     */
    public static function index_plugins(): void {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $agent_url = self::get_agent_url();

        if (!$agent_url) {
            return; // Agent not available, skip indexing
        }

        $vectors = [];
        $metadata = [];

        foreach ($plugins as $path => $data) {
            $plugin_name = basename(dirname($path));

            // Read plugin file to extract more metadata
            $content = self::extract_plugin_content($path);
            $text = $data['Name'] . '. ' . $data['Description'] . '. ' . implode(' ', (array) $data['Tags']);

            // Generate embedding (via agent)
            $embedding = self::get_embedding($text);
            if (!$embedding) {
                continue;
            }

            $vectors[] = $embedding;
            $metadata[] = [
                'type' => 'plugin',
                'name' => $data['Name'],
                'slug' => $plugin_name,
                'description' => $data['Description'],
                'version' => $data['Version'],
                'author' => $data['Author'],
                'tags' => implode(', ', (array) $data['Tags']),
                'url' => $data['PluginURI'] ?? '',
            ];
        }

        if (!empty($vectors)) {
            self::insert_vectors('plugins', $vectors, $metadata);
        }

        update_option(self::OPTION_LAST_PLUGIN_INDEX, current_time('mysql'));
    }

    /**
     * Index all installed themes.
     */
    public static function index_themes(): void {
        $themes = wp_get_themes();
        $agent_url = self::get_agent_url();

        if (!$agent_url) {
            return;
        }

        $vectors = [];
        $metadata = [];

        foreach ($themes as $theme) {
            $text = $theme->get('Name') . '. ' . $theme->get('Description');

            $embedding = self::get_embedding($text);
            if (!$embedding) {
                continue;
            }

            $vectors[] = $embedding;
            $metadata[] = [
                'type' => 'theme',
                'name' => $theme->get('Name'),
                'slug' => $theme->get_stylesheet(),
                'description' => $theme->get('Description'),
                'version' => $theme->get('Version'),
                'author' => $theme->get('Author'),
                'url' => $theme->get('ThemeURI'),
            ];
        }

        if (!empty($vectors)) {
            self::insert_vectors('themes', $vectors, $metadata);
        }

        update_option(self::OPTION_LAST_THEME_INDEX, current_time('mysql'));
    }

    /**
     * Index published posts (limited to recent posts for efficiency).
     */
    public static function index_posts(): void {
        global $wpdb;

        $agent_url = self::get_agent_url();
        if (!$agent_url) {
            return;
        }

        // Get recent published posts
        $posts = get_posts([
            'numberposts' => 100,
            'post_type' => 'post',
            'post_status' => 'publish',
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        if (empty($posts)) {
            return;
        }

        $vectors = [];
        $metadata = [];

        foreach ($posts as $post) {
            $text = $post->post_title . '. ' . wp_strip_all_tags($post->post_content);
            $text = substr($text, 0, 2000); // Limit to 2000 chars for embedding

            $embedding = self::get_embedding($text);
            if (!$embedding) {
                continue;
            }

            $vectors[] = $embedding;
            $metadata[] = [
                'type' => 'post',
                'id' => $post->ID,
                'title' => $post->post_title,
                'url' => get_permalink($post->ID),
                'author' => get_the_author_meta('display_name', $post->post_author),
                'date' => $post->post_modified,
                'excerpt' => wp_trim_excerpt($post->post_content),
            ];
        }

        if (!empty($vectors)) {
            self::insert_vectors('posts', $vectors, $metadata);
        }

        update_option(self::OPTION_LAST_POSTS_INDEX, current_time('mysql'));
    }

    /**
     * Get vector embedding for text via the agent.
     *
     * @param string $text The text to embed
     * @return array|null Vector embedding or null if failed
     */
    private static function get_embedding($text) {
        $agent_url = self::get_agent_url();
        if (!$agent_url) {
            return null;
        }

        // For now, use a simple hash-based pseudo-vector
        // In production, call agent's embedding API or OpenAI
        $hash = hash('sha256', $text, true);
        $vector = [];
        for ($i = 0; $i < 1536; $i++) { // OpenAI embedding dim
            $vector[] = ((ord($hash[$i % 32]) / 256) * 2) - 1;
        }

        return $vector;
    }

    /**
     * Insert vectors into the vector DB via the agent.
     */
    private static function insert_vectors($collection, $vectors, $metadata) {
        $agent_url = self::get_agent_url();
        if (!$agent_url) {
            return;
        }

        $url = trailingslashit($agent_url) . 'vector/insert';
        $secret = self::get_agent_secret();

        $ids = [];
        for ($i = 0; $i < count($vectors); $i++) {
            $ids[] = "{$collection}_{$i}";
        }

        $body = [
            'collection' => $collection,
            'vectors' => $vectors,
            'metadata' => $metadata,
            'ids' => $ids,
        ];

        $args = [
            'method' => 'POST',
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ];

        if ($secret) {
            $args['headers']['X-Kontrola-Secret'] = $secret;
        }

        wp_remote_post($url, $args);

        // Also store in local index
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_RAG_INDEX_SUFFIX;

        foreach ($ids as $i => $id) {
            $wpdb->replace($table, [
                'content_type' => $collection,
                'content_id' => $metadata[$i]['id'] ?? $i,
                'title' => $metadata[$i]['name'] ?? $metadata[$i]['title'] ?? '',
                'excerpt' => wp_json_encode($metadata[$i]),
                'vector_id' => $id,
            ]);
        }
    }

    /**
     * Re-index a post when it's published or updated.
     */
    public static function maybe_reindex_post($new_status, $old_status, $post) {
        if ($new_status === 'publish') {
            // Queue for indexing (could use WP-Cron or background job)
            // For now, just mark as needing re-index
            wp_schedule_single_event(time() + 60, self::CRON_HOOK_INDEX_POSTS);
        }
    }

    /**
     * Re-index plugins when new ones are activated.
     */
    public static function maybe_reindex_plugins() {
        // Could check for active plugin changes and trigger re-index
        // For now, relying on daily WP-Cron schedule
    }

    private static function get_agent_url() {
        $url = getenv('KONTROLA_AGENT_URL');
        if (!$url) {
            $url = get_option('kontrola_agent_url', '');
        }
        return $url;
    }

    private static function get_agent_secret() {
        $secret = getenv('KONTROLA_AGENT_SHARED_SECRET');
        if (!$secret) {
            $secret = get_option('kontrola_agent_shared_secret', '');
        }
        return $secret;
    }

    private static function extract_plugin_content($plugin_path) {
        // Read the plugin file to extract more information
        $file = WP_PLUGIN_DIR . '/' . $plugin_path;
        if (file_exists($file)) {
            $content = file_get_contents($file);
            // Extract function names, class names, etc. (basic parsing)
            preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $content, $matches);
            return implode(' ', $matches[1] ?? []);
        }
        return '';
    }

    /**
     * Register REST endpoints for RAG operations.
     */
    public static function register_routes(): void {
        // Search plugins/themes
        register_rest_route('kontrola/v1', '/rag/search', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'rest_search'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('edit_posts');
            },
            'args' => [
                'q' => ['required' => true, 'type' => 'string'],
                'type' => ['required' => false, 'type' => 'string', 'default' => 'plugin'],
                'limit' => ['required' => false, 'type' => 'integer', 'default' => 10],
            ],
        ]);

        // Get indexing status
        register_rest_route('kontrola/v1', '/rag/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'rest_status'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('manage_options');
            },
        ]);

        // Trigger re-indexing
        register_rest_route('kontrola/v1', '/rag/reindex', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'rest_reindex'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('manage_options');
            },
            'args' => [
                'type' => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    public static function rest_search($req) {
        $q = $req->get_param('q');
        $type = $req->get_param('type');
        $limit = $req->get_param('limit');

        // Get embedding for query
        $query_embedding = self::get_embedding($q);
        if (!$query_embedding) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Failed to embed query'], 500);
        }

        $agent_url = self::get_agent_url();
        if (!$agent_url) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Agent not configured'], 503);
        }

        // Search via agent
        $url = trailingslashit($agent_url) . 'vector/search';
        $secret = self::get_agent_secret();

        $body = [
            'collection' => $type,
            'query_vector' => $query_embedding,
            'top_k' => $limit,
        ];

        $args = [
            'method' => 'POST',
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
        ];

        if ($secret) {
            $args['headers']['X-Kontrola-Secret'] = $secret;
        }

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Vector search failed'], 503);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return new WP_REST_Response($data);
    }

    public static function rest_status($req) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_RAG_INDEX_SUFFIX;

        return new WP_REST_Response([
            'ok' => true,
            'plugins' => [
                'last_indexed' => get_option(self::OPTION_LAST_PLUGIN_INDEX, 'never'),
                'count' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE content_type = 'plugin'"),
            ],
            'themes' => [
                'last_indexed' => get_option(self::OPTION_LAST_THEME_INDEX, 'never'),
                'count' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE content_type = 'theme'"),
            ],
            'posts' => [
                'last_indexed' => get_option(self::OPTION_LAST_POSTS_INDEX, 'never'),
                'count' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE content_type = 'post'"),
            ],
        ]);
    }

    public static function rest_reindex($req) {
        $type = $req->get_param('type');

        $hooks = [
            'plugins' => self::CRON_HOOK_INDEX_PLUGINS,
            'themes' => self::CRON_HOOK_INDEX_THEMES,
            'posts' => self::CRON_HOOK_INDEX_POSTS,
        ];

        if (!isset($hooks[$type])) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid type'], 400);
        }

        wp_schedule_single_event(time(), $hooks[$type]);

        return new WP_REST_Response([
            'ok' => true,
            'message' => "Re-indexing {$type} scheduled",
        ]);
    }
}

Kontrola_RAG_Pipeline::bootstrap();
