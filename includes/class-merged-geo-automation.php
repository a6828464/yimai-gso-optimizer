<?php
/**
 * GEO automation module for the merged plugin.
 *
 * This file is intentionally self-contained and uses WordPress APIs only.
 * It does not include or execute code from the untrusted standalone GEO app.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_MERGED_GEO_AUTOMATION {
    const CRON_HOOK = 'yimai_gso_merged_geo_process_jobs';
    const NONCE     = 'yimai_gso_merged_geo_nonce';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
        add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
        add_action( self::CRON_HOOK, array( $this, 'process_jobs' ) );
    }

    public function register_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['yimai_gso_merged_geo_every_five'] ) ) {
            $schedules['yimai_gso_merged_geo_every_five'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => 'GEO 自动化每 5 分钟',
            );
        }
        return $schedules;
    }

    public static function activate() {
        self::get_instance();
        self::create_tables();
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'yimai_gso_merged_geo_every_five', self::CRON_HOOK );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE {$wpdb->prefix}yimai_gso_geo_keywords (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(220) NOT NULL,
            category VARCHAR(100) NOT NULL DEFAULT '',
            source VARCHAR(30) NOT NULL DEFAULT 'manual',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY source (source),
            KEY active (active),
            KEY keyword (keyword(120))
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}yimai_gso_geo_brand_scans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            brand_name VARCHAR(220) NOT NULL DEFAULT '',
            total_keywords INT UNSIGNED NOT NULL DEFAULT 0,
            mentioned_keywords INT UNSIGNED NOT NULL DEFAULT 0,
            doubao_count INT UNSIGNED NOT NULL DEFAULT 0,
            deepseek_count INT UNSIGNED NOT NULL DEFAULT 0,
            scan_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
            raw_response LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}yimai_gso_geo_brand_scan_details (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scan_id BIGINT UNSIGNED NOT NULL,
            keyword VARCHAR(220) NOT NULL,
            platform VARCHAR(50) NOT NULL DEFAULT '',
            mentioned TINYINT(1) NOT NULL DEFAULT 0,
            rank_position INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY scan_id (scan_id),
            KEY platform (platform),
            KEY mentioned (mentioned)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}yimai_gso_geo_jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(40) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            payload LONGTEXT NULL,
            result LONGTEXT NULL,
            error TEXT NULL,
            created_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY type (type),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}yimai_gso_geo_job_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY level (level)
        ) $charset;" );
    }

    public function register_menu() {
        add_submenu_page(
            'yimai-gso-center',
            'GEO 自动化',
            'GEO 自动化',
            'manage_options',
            'yimai-gso-merged-geo',
            array( $this, 'render_page' )
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '权限不足', 'yimai-gso-optimizer' ) );
        }
        self::create_tables();
        $message = $this->handle_post();
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
        $tabs = array(
            'dashboard' => '总览',
            'settings'  => '品牌/API',
            'keywords'  => '关键词库',
            'scan'      => '品牌扫描',
            'beautify'  => '文章美化',
            'jobs'      => '任务日志',
        );
        echo '<div class="wrap yimai-gso-merged-geo"><h1>GEO 自动化</h1>';
        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }
        echo '<nav class="nav-tab-wrapper">';
        foreach ( $tabs as $key => $label ) {
            $url = admin_url( 'admin.php?page=yimai-gso-merged-geo&tab=' . $key );
            echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav><div style="margin-top:18px;">';
        if ( $tab === 'settings' ) $this->render_settings();
        elseif ( $tab === 'keywords' ) $this->render_keywords();
        elseif ( $tab === 'scan' ) $this->render_scan();
        elseif ( $tab === 'beautify' ) $this->render_beautify();
        elseif ( $tab === 'jobs' ) $this->render_jobs();
        else $this->render_dashboard();
        echo '</div></div>';
    }

    private function handle_post() {
        if ( empty( $_POST['yimai_gso_merged_geo_action'] ) ) return '';
        check_admin_referer( self::NONCE );
        $action = sanitize_key( $_POST['yimai_gso_merged_geo_action'] );
        if ( 'save_settings' === $action ) {
            update_option( 'yimai_gso_geo_brand_profile', array(
                'brand_name' => sanitize_text_field( $_POST['brand_name'] ?? '' ),
                'brand_abbr' => sanitize_text_field( $_POST['brand_abbr'] ?? '' ),
                'industry'   => sanitize_text_field( $_POST['industry'] ?? '' ),
                'region'     => sanitize_text_field( $_POST['region'] ?? '' ),
                'products'   => sanitize_textarea_field( $_POST['products'] ?? '' ),
                'highlights' => sanitize_textarea_field( $_POST['highlights'] ?? '' ),
                'pain_points'=> sanitize_textarea_field( $_POST['pain_points'] ?? '' ),
                'cases'      => sanitize_textarea_field( $_POST['cases'] ?? '' ),
            ), false );
            update_option( 'yimai_gso_geo_api_providers', array(
                'doubao' => array(
                    'api_key'  => sanitize_text_field( $_POST['doubao_api_key'] ?? '' ),
                    'endpoint' => esc_url_raw( $_POST['doubao_endpoint'] ?? '' ),
                    'model'    => sanitize_text_field( $_POST['doubao_model'] ?? '' ),
                ),
                'deepseek' => array(
                    'api_key'  => sanitize_text_field( $_POST['deepseek_api_key'] ?? '' ),
                    'endpoint' => esc_url_raw( $_POST['deepseek_endpoint'] ?? '' ),
                    'model'    => sanitize_text_field( $_POST['deepseek_model'] ?? '' ),
                ),
            ), false );
            return '设置已保存。';
        }
        if ( 'add_keywords' === $action ) {
            $this->insert_keywords_from_text( wp_unslash( $_POST['keywords'] ?? '' ), 'manual' );
            return '关键词已导入。';
        }
        if ( 'clear_keywords' === $action ) {
            global $wpdb;
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}yimai_gso_geo_keywords" );
            return '关键词库已清空。';
        }
        if ( 'dedupe_keywords' === $action ) {
            $this->dedupe_keywords();
            return '关键词已去重。';
        }
        if ( 'queue_distill' === $action ) {
            $this->create_job( 'keyword_distill', array( 'mode' => sanitize_key( $_POST['mode'] ?? 'append' ) ) );
            $this->spawn_cron();
            return '关键词蒸馏任务已加入队列。';
        }
        if ( 'queue_scan' === $action ) {
            $this->create_job( 'brand_scan', array() );
            $this->spawn_cron();
            return '品牌扫描任务已加入队列。';
        }
        if ( 'queue_beautify' === $action ) {
            $this->create_job( 'article_beautify', array(
                'title'   => sanitize_text_field( $_POST['title'] ?? '' ),
                'brand'   => sanitize_text_field( $_POST['brand'] ?? '' ),
                'content' => wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) ),
            ) );
            $this->spawn_cron();
            return '文章美化任务已加入队列。';
        }
        if ( 'run_jobs' === $action ) {
            $this->process_jobs( 2 );
            return '已尝试执行队列任务。';
        }
        return '';
    }

    private function render_dashboard() {
        global $wpdb;
        $kw_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}yimai_gso_geo_keywords" );
        $scan = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}yimai_gso_geo_brand_scans ORDER BY id DESC LIMIT 1", ARRAY_A );
        $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}yimai_gso_geo_jobs WHERE status IN ('pending','running')" );
        echo '<div style="display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:14px;">';
        $this->card( '关键词总数', $kw_count );
        $this->card( '待处理任务', $pending );
        $this->card( '最新命中词', $scan ? (int) $scan['mentioned_keywords'] : 0 );
        $this->card( '最新命中率', $scan ? $scan['scan_percent'] . '%' : '0%' );
        echo '</div><p style="margin-top:16px;color:#64748b;">SEO 功能保留在原 GSO 双擎优化页面，本页用于新增 GEO 自动化任务。</p>';
    }

    private function card( $label, $value ) {
        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;"><div style="color:#64748b;font-size:13px;">' . esc_html( $label ) . '</div><div style="font-size:28px;font-weight:700;margin-top:8px;">' . esc_html( (string) $value ) . '</div></div>';
    }

    private function render_settings() {
        $profile = $this->get_brand_profile();
        $providers = wp_parse_args( get_option( 'yimai_gso_geo_api_providers', array() ), self::default_providers() );
        echo '<form method="post">';
        wp_nonce_field( self::NONCE );
        echo '<input type="hidden" name="yimai_gso_merged_geo_action" value="save_settings">';
        echo '<h2>品牌信息（兼容视图）</h2><p class="description">品牌主体请优先在“双擎工作台 &gt; 基础设置 &gt; GEO实体”维护；这里仅保留旧 GEO 自动化字段兼容。</p><table class="form-table"><tbody>';
        $this->input_row( '品牌名称', 'brand_name', $profile['brand_name'] );
        $this->input_row( '品牌简称', 'brand_abbr', $profile['brand_abbr'] );
        $this->input_row( '行业', 'industry', $profile['industry'] );
        $this->input_row( '地区', 'region', $profile['region'] );
        $this->textarea_row( '产品/服务', 'products', $profile['products'] );
        $this->textarea_row( '产品亮点', 'highlights', $profile['highlights'] );
        $this->textarea_row( '用户痛点', 'pain_points', $profile['pain_points'] );
        $this->textarea_row( '客户案例', 'cases', $profile['cases'] );
        echo '</tbody></table><h2>AI 平台</h2><table class="form-table"><tbody>';
        foreach ( array( 'doubao' => '豆包', 'deepseek' => 'DeepSeek' ) as $key => $label ) {
            $cfg = wp_parse_args( $providers[ $key ] ?? array(), self::default_providers()[ $key ] );
            $this->input_row( $label . ' API Key', $key . '_api_key', $cfg['api_key'], 'password' );
            $this->input_row( $label . ' Endpoint', $key . '_endpoint', $cfg['endpoint'] );
            $this->input_row( $label . ' Model', $key . '_model', $cfg['model'] );
        }
        echo '</tbody></table>';
        submit_button( '保存设置' );
        echo '</form>';
    }

    private function render_keywords() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}yimai_gso_geo_keywords ORDER BY id DESC LIMIT 200", ARRAY_A );
        echo '<form method="post" style="background:#fff;border:1px solid #e5e7eb;padding:16px;border-radius:8px;">';
        wp_nonce_field( self::NONCE );
        echo '<input type="hidden" name="yimai_gso_merged_geo_action" value="add_keywords"><h2>导入关键词</h2><textarea name="keywords" rows="6" style="width:100%;" placeholder="每行一个关键词，支持 关键词|分类"></textarea>';
        submit_button( '导入关键词', 'primary', '', false );
        echo '</form><div style="margin-top:12px;display:flex;gap:8px;">';
        $this->small_form( 'queue_distill', 'AI 蒸馏 200 个关键词', array( 'mode' => 'append' ) );
        $this->small_form( 'dedupe_keywords', '关键词去重' );
        $this->small_form( 'clear_keywords', '清空关键词库', array(), 'delete' );
        echo '</div><h2>最近关键词</h2><div style="display:flex;flex-wrap:wrap;gap:8px;">';
        foreach ( $rows as $row ) {
            echo '<span style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:18px;padding:5px 12px;">' . esc_html( $row['keyword'] ) . ( $row['category'] ? ' <em style="color:#64748b;">' . esc_html( $row['category'] ) . '</em>' : '' ) . '</span>';
        }
        echo '</div>';
    }

    private function render_scan() {
        global $wpdb;
        $scans = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}yimai_gso_geo_brand_scans ORDER BY id DESC LIMIT 20", ARRAY_A );
        $this->small_form( 'queue_scan', '开始品牌可见度扫描' );
        echo '<h2>扫描记录</h2><table class="widefat striped"><thead><tr><th>时间</th><th>品牌</th><th>总词数</th><th>命中</th><th>豆包</th><th>DeepSeek</th><th>命中率</th></tr></thead><tbody>';
        foreach ( $scans as $scan ) {
            echo '<tr><td>' . esc_html( $scan['created_at'] ) . '</td><td>' . esc_html( $scan['brand_name'] ) . '</td><td>' . esc_html( $scan['total_keywords'] ) . '</td><td>' . esc_html( $scan['mentioned_keywords'] ) . '</td><td>' . esc_html( $scan['doubao_count'] ) . '</td><td>' . esc_html( $scan['deepseek_count'] ) . '</td><td>' . esc_html( $scan['scan_percent'] ) . '%</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function render_beautify() {
        echo '<form method="post" style="background:#fff;border:1px solid #e5e7eb;padding:16px;border-radius:8px;max-width:960px;">';
        wp_nonce_field( self::NONCE );
        echo '<input type="hidden" name="yimai_gso_merged_geo_action" value="queue_beautify"><h2>文章美化</h2>';
        echo '<p><input class="regular-text" name="title" placeholder="文章标题" style="width:100%;max-width:720px;"></p>';
        echo '<p><input class="regular-text" name="brand" placeholder="品牌名称，可留空使用品牌设置" style="width:100%;max-width:720px;"></p>';
        echo '<textarea name="content" rows="14" style="width:100%;" placeholder="粘贴需要美化的文章草稿"></textarea>';
        submit_button( '加入美化队列' );
        echo '</form>';
    }

    private function render_jobs() {
        global $wpdb;
        echo '<div style="margin-bottom:12px;">';
        $this->small_form( 'run_jobs', '立即执行队列' );
        echo '</div>';
        $jobs = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}yimai_gso_geo_jobs ORDER BY id DESC LIMIT 50", ARRAY_A );
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>类型</th><th>状态</th><th>错误/结果</th><th>创建时间</th><th>完成时间</th></tr></thead><tbody>';
        foreach ( $jobs as $job ) {
            $text = $job['error'] ?: wp_trim_words( wp_strip_all_tags( (string) $job['result'] ), 32 );
            echo '<tr><td>' . esc_html( $job['id'] ) . '</td><td>' . esc_html( $job['type'] ) . '</td><td>' . esc_html( $job['status'] ) . '</td><td>' . esc_html( $text ) . '</td><td>' . esc_html( $job['created_at'] ) . '</td><td>' . esc_html( $job['completed_at'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function input_row( $label, $name, $value, $type = 'text' ) {
        echo '<tr><th scope="row"><label>' . esc_html( $label ) . '</label></th><td><input type="' . esc_attr( $type ) . '" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></td></tr>';
    }

    private function textarea_row( $label, $name, $value ) {
        echo '<tr><th scope="row"><label>' . esc_html( $label ) . '</label></th><td><textarea class="large-text" rows="4" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea></td></tr>';
    }

    private function small_form( $action, $label, $hidden = array(), $class = 'secondary' ) {
        echo '<form method="post" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field( self::NONCE );
        echo '<input type="hidden" name="yimai_gso_merged_geo_action" value="' . esc_attr( $action ) . '">';
        foreach ( $hidden as $k => $v ) echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
        submit_button( $label, $class, '', false );
        echo '</form>';
    }

    private static function default_providers() {
        return array(
            'doubao' => array( 'api_key' => '', 'endpoint' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions', 'model' => 'doubao-seed-evolving' ),
            'deepseek' => array( 'api_key' => '', 'endpoint' => 'https://api.deepseek.com/chat/completions', 'model' => 'deepseek-chat' ),
        );
    }

    /**
     * Read the canonical GEO entity first and keep the legacy profile as a fallback.
     */
    private function get_brand_profile() {
        $keys    = array( 'brand_name', 'brand_abbr', 'industry', 'region', 'products', 'highlights', 'pain_points', 'cases' );
        $profile = wp_parse_args( get_option( 'yimai_gso_geo_brand_profile', array() ), array_fill_keys( $keys, '' ) );

        if ( class_exists( 'YIMAI_GSO_GEO_Entity' ) ) {
            $entity = YIMAI_GSO_GEO_Entity::get_entity_data();
            $profile['brand_name'] = $profile['brand_name'] ?: (string) ( $entity['entity_name'] ?? '' );
            $profile['brand_abbr']  = $profile['brand_abbr'] ?: '一麦';
            $profile['industry']    = $profile['industry'] ?: '瑜伽与普拉提';
            $profile['region']      = $profile['region'] ?: '宁波';
            $profile['products']    = $profile['products'] ?: (string) ( $entity['entity_description'] ?? '' );
        }

        return $profile;
    }

    private function create_job( $type, $payload ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'yimai_gso_geo_jobs', array(
            'type'       => sanitize_key( $type ),
            'status'     => 'pending',
            'payload'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
            'created_at' => current_time( 'mysql' ),
        ), array( '%s', '%s', '%s', '%s' ) );
        return (int) $wpdb->insert_id;
    }

    public function process_jobs( $limit = 1 ) {
        global $wpdb;
        $limit = max( 1, min( 5, absint( $limit ) ) );
        $jobs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}yimai_gso_geo_jobs WHERE status='pending' ORDER BY id ASC LIMIT %d", $limit ), ARRAY_A );
        foreach ( $jobs as $job ) {
            $wpdb->update( $wpdb->prefix . 'yimai_gso_geo_jobs', array( 'status' => 'running', 'started_at' => current_time( 'mysql' ) ), array( 'id' => $job['id'] ) );
            $payload = json_decode( (string) $job['payload'], true );
            try {
                if ( $job['type'] === 'keyword_distill' ) $result = $this->run_keyword_distill( (array) $payload );
                elseif ( $job['type'] === 'brand_scan' ) $result = $this->run_brand_scan();
                elseif ( $job['type'] === 'article_beautify' ) $result = $this->run_article_beautify( (array) $payload );
                else $result = new WP_Error( 'unknown_job', '未知任务类型' );
                if ( is_wp_error( $result ) ) {
                    $wpdb->update( $wpdb->prefix . 'yimai_gso_geo_jobs', array( 'status' => 'failed', 'error' => $result->get_error_message(), 'completed_at' => current_time( 'mysql' ) ), array( 'id' => $job['id'] ) );
                } else {
                    $wpdb->update( $wpdb->prefix . 'yimai_gso_geo_jobs', array( 'status' => 'completed', 'result' => is_string( $result ) ? $result : wp_json_encode( $result, JSON_UNESCAPED_UNICODE ), 'completed_at' => current_time( 'mysql' ) ), array( 'id' => $job['id'] ) );
                }
            } catch ( Exception $e ) {
                $wpdb->update( $wpdb->prefix . 'yimai_gso_geo_jobs', array( 'status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => current_time( 'mysql' ) ), array( 'id' => $job['id'] ) );
            }
        }
    }

    private function run_keyword_distill( $payload ) {
        $profile = $this->get_brand_profile();
        if ( empty( $profile['brand_name'] ) ) return new WP_Error( 'missing_brand', '请先填写品牌名称。' );
        $prompt = "你是一个研究中国用户AI搜索习惯的GEO优化专家。请基于以下企业信息生成200个真实用户会搜索的GEO长尾关键词。\n\n";
        foreach ( array( 'brand_name'=>'公司名称', 'brand_abbr'=>'简称', 'region'=>'地区', 'industry'=>'行业', 'products'=>'产品服务', 'highlights'=>'产品亮点', 'pain_points'=>'用户痛点', 'cases'=>'客户案例' ) as $key => $label ) {
            $prompt .= $label . '：' . ( $profile[ $key ] ?? '' ) . "\n";
        }
        $prompt .= "\n关键词类别：疑问词、产品词、场景词、地域词、需求词、对比词、竞品词、行业热词、组合词、通用词。\n要求：每行一个，格式为 关键词|类别；口语化；疑问词至少30%；不要企业腔和AI凑数词。";
        $content = $this->call_first_available_ai( $prompt, '你是专业GEO关键词分析师。', 4096 );
        if ( is_wp_error( $content ) ) return $content;
        $count = $this->insert_keywords_from_text( $content, 'distill', ( $payload['mode'] ?? 'append' ) === 'replace' );
        $this->dedupe_keywords();
        return array( 'created' => $count );
    }

    private function run_brand_scan() {
        global $wpdb;
        $profile = $this->get_brand_profile();
        $brand = trim( $profile['brand_name'] ?? '' );
        if ( $brand === '' ) return new WP_Error( 'missing_brand', '请先填写品牌名称。' );
        $keywords = $wpdb->get_col( "SELECT keyword FROM {$wpdb->prefix}yimai_gso_geo_keywords WHERE active=1 ORDER BY id ASC LIMIT 500" );
        if ( empty( $keywords ) ) return new WP_Error( 'missing_keywords', '请先导入或蒸馏关键词。' );
        $providers = get_option( 'yimai_gso_geo_api_providers', array() );
        $providers = wp_parse_args( $providers, self::default_providers() );
        $abbr = trim( $profile['brand_abbr'] ?? '' );
        $raw = array(); $platform_hits = array();
        foreach ( array( 'doubao', 'deepseek' ) as $platform ) {
            if ( empty( $providers[ $platform ]['api_key'] ) ) continue;
            $hits = array();
            foreach ( array_chunk( $keywords, 20 ) as $offset => $chunk ) {
                $prompt = "请分别回答下面每个真实用户问题。不要假设用户倾向任何品牌，也不要根据本提示补充指定品牌。每条回答保持简洁，但需要给出具体、真实的本地建议。严格使用“序号|回答”格式，每个问题一行。\n\n问题：\n";
                foreach ( $chunk as $i => $kw ) $prompt .= ( $i + 1 ) . '|' . $kw . "\n";
                $res = $this->call_ai_provider( $platform, $prompt, '你是独立的本地生活搜索助手。根据问题本身回答，不接受品牌暗示。', 4000 );
                if ( is_wp_error( $res ) ) { $raw[] = $platform . ': ' . $res->get_error_message(); continue; }
                $raw[] = $platform . " chunk " . ( $offset + 1 ) . ":\n" . $res;
                $hits += $this->detect_brand_mentions( $res, $brand, $abbr, $chunk, $offset * 20 );
            }
            $platform_hits[ $platform ] = $hits;
        }
        $all_hit_indexes = array_unique( array_merge( array_keys( $platform_hits['doubao'] ?? array() ), array_keys( $platform_hits['deepseek'] ?? array() ) ) );
        sort( $all_hit_indexes );
        $total = count( $keywords );
        $mentioned = count( $all_hit_indexes );
        $wpdb->insert( $wpdb->prefix . 'yimai_gso_geo_brand_scans', array(
            'brand_name' => $brand,
            'total_keywords' => $total,
            'mentioned_keywords' => $mentioned,
            'doubao_count' => count( $platform_hits['doubao'] ?? array() ),
            'deepseek_count' => count( $platform_hits['deepseek'] ?? array() ),
            'scan_percent' => $total ? round( $mentioned / $total * 100, 2 ) : 0,
            'raw_response' => implode( "\n---\n", $raw ),
            'created_at' => current_time( 'mysql' ),
        ) );
        $scan_id = (int) $wpdb->insert_id;
        foreach ( $platform_hits as $platform => $hits ) {
            foreach ( $hits as $idx => $kw ) {
                $wpdb->insert( $wpdb->prefix . 'yimai_gso_geo_brand_scan_details', array( 'scan_id' => $scan_id, 'keyword' => $kw, 'platform' => $platform, 'mentioned' => 1, 'rank_position' => $idx + 1, 'created_at' => current_time( 'mysql' ) ) );
            }
        }
        return array( 'scan_id' => $scan_id, 'mentioned' => $mentioned, 'total' => $total );
    }

    private function run_article_beautify( $payload ) {
        $profile = $this->get_brand_profile();
        $brand = trim( $payload['brand'] ?? '' ) ?: ( $profile['brand_name'] ?? '' );
        $title = trim( $payload['title'] ?? '' );
        $content = trim( $payload['content'] ?? '' );
        if ( $title === '' || $content === '' ) return new WP_Error( 'missing_article', '请填写标题和正文。' );
        $prompt = "请根据以下要求，为我优化重写一篇自媒体热门文章。\n文章标题：{$title}\n品牌名称：{$brand}\n\n要求：内容要有具体案例或实操建议；分点论述；自然融入品牌，不要硬广；禁止AI腔；不要输出网址电话；不要虚构数据；不要违反广告法；字数1000到2000字。\n\n原文：\n{$content}\n\n输出格式：第一行输出新标题，空一行后输出正文。";
        if ( class_exists( 'YIMAI_GSO_Knowledge_Center' ) ) {
            $prompt = YIMAI_GSO_Knowledge_Center::augment_prompt( $prompt, $title );
        }
        $result = $this->call_first_available_ai( $prompt, '你是一位资深自媒体文章创作专家，擅长写适合GEO收录的高质量中文内容。', 4096 );
        if ( is_wp_error( $result ) ) return $result;
        $lines = preg_split( "/\r\n|\r|\n/", trim( $result ) );
        $new_title = trim( array_shift( $lines ) );
        while ( $lines && trim( $lines[0] ) === '' ) array_shift( $lines );
        $new_content = trim( implode( "\n", $lines ) );
        $post_id = wp_insert_post( array( 'post_title' => $new_title ?: $title, 'post_content' => $new_content ?: $result, 'post_status' => 'draft', 'post_type' => 'post' ), true );
        if ( is_wp_error( $post_id ) ) return $post_id;
        update_post_meta( $post_id, 'yimai_gso_geo_beautified', '1' );
        return array( 'post_id' => $post_id, 'message' => '已保存为草稿。' );
    }

    private function call_first_available_ai( $prompt, $system, $max_tokens ) {
        foreach ( array( 'doubao', 'deepseek' ) as $provider ) {
            $res = $this->call_ai_provider( $provider, $prompt, $system, $max_tokens );
            if ( ! is_wp_error( $res ) ) return $res;
        }
        return new WP_Error( 'no_ai_provider', '请先配置豆包或 DeepSeek API。' );
    }

    private function call_ai_provider( $provider, $prompt, $system, $max_tokens = 2048 ) {
        $providers = wp_parse_args( get_option( 'yimai_gso_geo_api_providers', array() ), self::default_providers() );
        $cfg = wp_parse_args( $providers[ $provider ] ?? array(), self::default_providers()[ $provider ] ?? array() );

        // GEO 自动化默认复用基础设置中的 AI 配置，避免用户重复填写 API。
        if ( empty( $cfg['api_key'] ) && class_exists( 'YIMAI_GSO_Settings' ) ) {
            $cfg['api_key']  = YIMAI_GSO_Settings::get( 'ai_api_key', '' );
            $cfg['endpoint'] = YIMAI_GSO_Settings::get( 'ai_api_url', '' ) ?: $cfg['endpoint'];
            $cfg['model']    = YIMAI_GSO_Settings::get( 'ai_model', '' ) ?: $cfg['model'];
        }
        if ( empty( $cfg['api_key'] ) || empty( $cfg['endpoint'] ) || empty( $cfg['model'] ) ) return new WP_Error( 'missing_api', $provider . ' API 未配置' );
        $body = array( 'model' => $cfg['model'], 'messages' => array( array( 'role' => 'system', 'content' => $system ), array( 'role' => 'user', 'content' => $prompt ) ), 'max_tokens' => $max_tokens );
        $response = wp_remote_post( $cfg['endpoint'], array( 'timeout' => 120, 'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $cfg['api_key'] ), 'body' => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ) ) );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        $raw = wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) return new WP_Error( 'api_http_error', $provider . ' HTTP ' . $code . ': ' . wp_trim_words( $raw, 20 ) );
        $data = json_decode( $raw, true );
        $content = $data['choices'][0]['message']['content'] ?? $data['output'][0]['content'][0]['text'] ?? $data['output'][0]['content'] ?? $data['content'] ?? '';
        return $content !== '' ? $content : new WP_Error( 'empty_ai_response', $provider . ' 返回内容为空' );
    }

    private function insert_keywords_from_text( $text, $source = 'manual', $replace = false ) {
        global $wpdb;
        if ( $replace ) $wpdb->delete( $wpdb->prefix . 'yimai_gso_geo_keywords', array( 'source' => 'distill' ) );
        $count = 0;
        foreach ( preg_split( "/\r\n|\r|\n/", (string) $text ) as $line ) {
            $line = trim( wp_strip_all_tags( $line ) );
            if ( $line === '' ) continue;
            $line = preg_replace( '/^\d+[\.\]\s、-]+/u', '', $line );
            $parts = array_map( 'trim', explode( '|', $line, 2 ) );
            $keyword = sanitize_text_field( $parts[0] ?? '' );
            $category = sanitize_text_field( $parts[1] ?? '' );
            if ( function_exists( 'mb_strlen' ) ) {
                $len = mb_strlen( $keyword, 'UTF-8' );
                if ( $len < 2 || $len > 80 ) continue;
            } elseif ( strlen( $keyword ) < 2 || strlen( $keyword ) > 160 ) continue;
            $wpdb->insert( $wpdb->prefix . 'yimai_gso_geo_keywords', array( 'keyword' => $keyword, 'category' => $category, 'source' => sanitize_key( $source ), 'active' => 1, 'created_at' => current_time( 'mysql' ) ) );
            $count++;
        }
        return $count;
    }

    private function dedupe_keywords() {
        global $wpdb;
        $table = $wpdb->prefix . 'yimai_gso_geo_keywords';
        $wpdb->query( "DELETE k1 FROM {$table} k1 INNER JOIN {$table} k2 WHERE k1.id > k2.id AND k1.keyword = k2.keyword" );
    }

    private function parse_line_numbers( $text ) {
        preg_match_all( '/\b\d+\b/', (string) $text, $m );
        $nums = array_map( 'absint', $m[0] ?? array() );
        return array_values( array_filter( array_unique( $nums ) ) );
    }

    private function detect_brand_mentions( $response, $brand, $abbr, $chunk, $base_index ) {
        $hits = array();
        foreach ( preg_split( "/\r\n|\r|\n/", (string) $response ) as $line ) {
            if ( ! preg_match( '/^\s*(\d+)\s*[|｜:：.-]\s*(.+)$/u', trim( $line ), $match ) ) {
                continue;
            }
            $local_index = absint( $match[1] ) - 1;
            $answer      = (string) $match[2];
            $mentioned   = false !== stripos( $answer, $brand );
            if ( ! $mentioned && $abbr !== '' ) {
                $mentioned = false !== stripos( $answer, $abbr );
            }
            if ( $mentioned && isset( $chunk[ $local_index ] ) ) {
                $global_index          = $base_index + $local_index;
                $hits[ $global_index ] = $chunk[ $local_index ];
            }
        }
        return $hits;
    }

    private function spawn_cron() {
        if ( function_exists( 'spawn_cron' ) ) spawn_cron();
    }
}
