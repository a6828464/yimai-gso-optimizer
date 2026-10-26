<?php
/**
 * Plugin Name: 一麦GSO双擎优化
 * Description: 一麦瑜伽官网专用的 AI 内容、SEO 与 GEO 双引擎优化插件，支持知识库导入、博客自动更新、结构化数据、内链和搜索可见度分析。
 * Version: 2.9.17
 * Author: 一麦瑜伽
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'YIMAI_GSO_LOADED' ) ) {
    return;
}
define( 'YIMAI_GSO_LOADED', true );

defined( 'YIMAI_GSO_VERSION' ) || define( 'YIMAI_GSO_VERSION', '2.9.17' );

/**
 * 统一调试日志（T3 修复）
 *
 * 背景：插件内原有约 100 处 error_log() 调用，其中仅约三成受 WP_DEBUG 控制。
 * 未受控的调用会**无条件**写入服务器错误日志 —— 例如
 * class-ai-generator.php 每次调用都会把 800 字符的 AI 原始返回写入 debug.log，
 * 造成日志无界增长，且可能把业务内容带进日志文件。
 *
 * 本函数统一收敛写入行为：
 *  - 默认仅在 WP_DEBUG 与 WP_DEBUG_LOG 同时开启时写入（本地/测试环境行为不变）；
 *  - 若需要长期诊断，可在 wp-config.php 中定义
 *    define( 'YIMAI_GSO_FORCE_DEBUG_LOG', true );
 *    强制开启，无需改动插件代码。
 *
 * @param string $message 日志内容（不含前缀）
 * @return void
 */
function yimai_gso_debug_log( $message ) {
    $enabled = ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG )
        || ( defined( 'YIMAI_GSO_FORCE_DEBUG_LOG' ) && YIMAI_GSO_FORCE_DEBUG_LOG );

    if ( ! $enabled ) {
        return;
    }

    error_log( '[一麦GSO] ' . (string) $message );
}

/**
 * 全局预览检测函数 - 统一判断是否是预览请求
 * 用于在各模块中快速跳过预览请求，减少内存占用
 *
 * @return bool
 */
function yimai_gso_is_preview_request() {
    // 显式预览参数（WordPress 标准方式）
    if ( isset( $_GET['preview'] ) || isset( $_GET['preview_id'] ) ) {
        return true;
    }

    // WordPress 内部预览标志
    if ( ! empty( $_GET['p'] ) && isset( $_GET['preview'] ) ) {
        return true;
    }

    // 注意：不再用 action 名称判断 AJAX 预览请求
    // 原逻辑 strpos($_POST['action'], 'preview') 会误判插件的 yimai_gso_seo_meta_preview 等 action
    // 导致批量SEO预览功能完全不可用。WordPress 原生预览依赖 GET 参数判断，此处无需重复检测。

    return false;
}

// 内存限制交由宝塔 PHP 配置控制，插件不再强制设置
// 保留 WordPress 标准内存提升（尝试设置到 WP_MAX_MEMORY_LIMIT，默认256M）
if ( function_exists( 'wp_raise_memory_limit' ) ) {
    wp_raise_memory_limit( 'admin' );
}








define( 'YIMAI_GSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YIMAI_GSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'YIMAI_GSO_PLUGIN_FILE', __FILE__ );

// 加载核心文件
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-param-permissions.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-post-type-helper.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-settings.php';

require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-ai-generator.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-image-alt-generator.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-image-alt-admin.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-image-handler.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-ai-image-generator.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-image-library.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-mtphotos-client.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-content-detector.php';

require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-publisher.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-seo-meta-optimizer.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-autopilot-plan.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-autopilot-log.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-autopilot-scheduler.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-autopilot-runner.php';

require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-internal-link.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-seo-pusher.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-sitemap-generator.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-schema-generator.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-seo-scorer.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-heading-analyzer.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-canonical-manager.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-404-optimizer.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-llms-txt-generator.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-geo-entity.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-crawler-tracker.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-author-profile.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-keyword-analyzer.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-crawler-editor.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-content-extractability.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-faq-detector.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-content-freshness.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-citation-helper.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-geo-scorer.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-geo-auditor.php';
// L5 监控与仪表盘
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-referral-tracker.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-alert-engine.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-crawler-dashboard.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-crawler-diagnosis.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-l5-dashboard.php';
// L6 高级联动
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-ai-platform-registry.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-ai-platform-adapter.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-seo-geo-bridge.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-wechat-share.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-admin.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-updater.php';
YIMAI_GSO_Updater::init();
// T2：class-merged-geo-automation.php 已删除（整个模块不可达：钩子只在其构造函数注册，
// 而唯一的 get_instance() 调用点是它自己的 activate()，从不被调用；583 行中 78% 为死代码）。
// 其 5 张 GEO 表不再创建；已有的表保留在卸载清单中，随插件卸载一并清理（不在此处 DROP）。
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-knowledge-center.php';
require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-migration.php';

// 旧前缀数据（options/meta/数据表/cron）一次性迁移到 yimai_gso_ 前缀
YIMAI_GSO_DB_Migration::init();

// 注册定时发布守护进程钩子（每30分钟扫描，异步派发执行）
add_action( 'yimai_gso_future_publish_guardian', array( 'YIMAI_GSO_Publisher', 'guardian_publish' ) );

// 注册定时发布异步执行钩子（由 wp_schedule_single_event 触发）
add_action( 'yimai_gso_guardian_process', array( 'YIMAI_GSO_Publisher', 'handle_guardian_process' ) );

// 注册发布后自动摘要的异步处理钩子（AI 调用不再阻塞 publish() 主流程）
if ( method_exists( 'YIMAI_GSO_Publisher', 'register_async_excerpt_hook' ) ) {
    YIMAI_GSO_Publisher::register_async_excerpt_hook();
}

/**
 * 为 WordPress 核心的 publish_future_post 钩子设置运行时用户上下文
 * 
 * 当 post_status 设为 future 时，WordPress 核心会调度 publish_future_post 事件。
 * 在系统Cron环境（DISABLE_WP_CRON=true）或未登录请求触发Cron时，
 * current_user_can('edit_post') 会因用户ID=0而失败，导致 wp_publish_post() 返回 WP_Error，
 * 文章永远无法从 future 转为 publish。
 * 
 * 本函数在核心回调（priority=10）之前（priority=5）执行，确保用户上下文已就绪。
 * 
 * @param int $post_id
 */
function yimai_gso_bootstrap_cron_user( $post_id ) {
    if ( ! function_exists( 'wp_get_current_user' ) ) {
        return;
    }
    if ( class_exists( 'YIMAI_GSO_Autopilot_Runner' ) ) {
        YIMAI_GSO_Autopilot_Runner::bootstrap_runtime_user( 0 );
    }
}
add_action( 'publish_future_post', 'yimai_gso_bootstrap_cron_user', 5, 1 );

/**
 * 禁用 WordPress 核心对插件生成文章的 publish_future_post 调度
 *
 * 当插件生成 future 文章时，WordPress 核心会自动调度 publish_future_post 事件。
 * 在系统 Cron 环境（DISABLE_WP_CRON=true）下，该事件可能因用户上下文缺失而失败。
 * 此处清除核心调度，让 Guardian 守护进程全权负责插件生成文章的定时发布，
 * 彻底避免双路径竞争导致的间歇性失败。
 *
 * @param string $new_status
 * @param string $old_status
 * @param WP_Post $post
 */
function yimai_gso_disable_core_future_schedule( $new_status, $old_status, $post ) {
    if ( $new_status !== 'future' ) {
        return;
    }
    $is_generated = get_post_meta( $post->ID, 'yimai_gso_generated', true );
    if ( $is_generated === '1' ) {
        wp_clear_scheduled_hook( 'publish_future_post', array( $post->ID ) );
    }
}
add_action( 'transition_post_status', 'yimai_gso_disable_core_future_schedule', 99, 3 );


/**
 * 插件主类
 */
class YIMAI_GSO_Plugin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 如果是文章预览请求，跳过后台模块初始化，降低预览请求的内存占用。
        if ( yimai_gso_is_preview_request() ) {
            return;
        }

        add_action( 'plugins_loaded', array( $this, 'init' ) );
        register_activation_hook( YIMAI_GSO_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( YIMAI_GSO_PLUGIN_FILE, array( $this, 'deactivate' ) );
        register_uninstall_hook( YIMAI_GSO_PLUGIN_FILE, 'yimai_gso_uninstall' );
    }

    public function init() {
        $this->maybe_migrate_to_220();

        // 内存日志：init开始
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'init开始' );
        }

        // 注册图片库 taxonomy（必须在 plugins_loaded 之后）
        YIMAI_GSO_Image_Library::register_taxonomy();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after Image_Library注册' );
        }

        // 初始化各模块
        YIMAI_GSO_Settings::get_instance();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after Settings' );
        }

        // P2 修复：以下类只在后台（含 admin-ajax.php，它也会设置 WP_ADMIN）注册
        // admin_menu / admin_enqueue_scripts / admin_post_ / 后台 AJAX 处理器，
        // 不注册任何前台或 cron 钩子（已逐个核对每个类的完整钩子集合）。
        // 原先它们在**每一次前台请求**上都会被实例化，插件因此每次请求要解析
        // 约 1.82 MB PHP 并构造 34 个单例，其中 class-admin.php 单文件 568 KB（占 31%）。
        // 现在这些纯后台类只在后台请求上实例化，前台访客不再承担这部分开销。
        //
        // 注意：这里必须用 is_admin() 而非再排除 AJAX —— admin-ajax.php 会设置 WP_ADMIN，
        // 后台 AJAX 处理器需要在其中完成注册，不能排除。
        // cron 请求（wp-cron.php）不设置 WP_ADMIN，因此 cron 专属逻辑仍在下方无条件执行。
        if ( is_admin() ) {
            YIMAI_GSO_Admin::get_instance();
            if ( function_exists( 'yimai_gso_log_memory' ) ) {
                yimai_gso_log_memory( 'after Admin' );
            }

            // 纯后台功能模块（后台菜单 / 后台 AJAX / 表单处理）
            // 注意：Knowledge_Center **不在此列出** —— 它的构造函数还注册了 cron 钩子
            // yimai_gso_classify_knowledge（AI 分类队列），而 cron 请求不设置 WP_ADMIN，
            // 且其静态方法 get_topic_seeds()/augment_prompt() 会被自动发布与 AI 生成器
            // 在后台任务中调用。放进本分支会让这两个功能在 cron 场景静默失效。
            // 因此它必须无条件实例化（见下方 is_admin() 块之外）。
            if ( class_exists( 'YIMAI_GSO_Keyword_Analyzer' ) ) {
                YIMAI_GSO_Keyword_Analyzer::get_instance();
            }
            if ( class_exists( 'YIMAI_GSO_Crawler_Dashboard' ) ) {
                YIMAI_GSO_Crawler_Dashboard::get_instance();
            }
            if ( class_exists( 'YIMAI_GSO_L5_Dashboard' ) ) {
                YIMAI_GSO_L5_Dashboard::get_instance();
            }
        }

        // Knowledge_Center：必须无条件实例化（原因见上）。
        YIMAI_GSO_Knowledge_Center::get_instance();

        YIMAI_GSO_Content_Detector::get_instance();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after Content_Detector' );
        }

        YIMAI_GSO_Image_Alt_Generator::get_instance();  // 发布后自动ALT（transition_post_status 钩子，优先级10）
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after Image_Alt_Generator' );
        }

        YIMAI_GSO_SEO_Meta_Optimizer::get_instance();  // 发布后自动SEO元数据（transition_post_status 钩子，优先级15）
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after SEO_Meta_Optimizer' );
        }

        YIMAI_GSO_Internal_Link::get_instance();   // 发布后自动内链（transition_post_status 钩子，优先级25）
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after Internal_Link' );
        }

        YIMAI_GSO_Autopilot_Log::get_instance();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after Autopilot_Log' );
        }

        YIMAI_GSO_Autopilot_Scheduler::get_instance();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after Autopilot_Scheduler' );
        }

        YIMAI_GSO_Autopilot_Runner::get_instance();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after Autopilot_Runner' );
        }

        YIMAI_GSO_SEO_Pusher::get_instance()->init();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after SEO_Pusher' );
        }

        YIMAI_GSO_Sitemap_Generator::get_instance();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after Sitemap_Generator' );
        }

        YIMAI_GSO_Schema_Generator::get_instance();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after Schema_Generator' );
        }

        YIMAI_GSO_Canonical_Manager::init();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after Canonical_Manager' );
        }

        YIMAI_GSO_404_Optimizer::init();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after 404_Optimizer' );
        }

        YIMAI_GSO_LLMs_Txt_Generator::get_instance();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after LLMs_Txt_Generator' );
        }

        // GEO 实体优化 + AI 爬虫追踪 + 作者档案 + 爬虫编辑器
        YIMAI_GSO_GEO_Entity::get_instance();
        YIMAI_GSO_Crawler_Tracker::get_instance();
        YIMAI_GSO_Author_Profile::get_instance();
        // 注意（P2）：Keyword_Analyzer / Crawler_Dashboard / L5_Dashboard 已移至上方
        // is_admin() 分支内实例化（它们只注册后台钩子与后台 AJAX）。
        // 原在此处的无条件调用会绕过门控，故移除。
        YIMAI_GSO_Crawler_Editor::get_instance();
        // L3 内容分析层可提取性 + FAQ + 新鲜度 + 引用溯源
        YIMAI_GSO_Content_Extractability::get_instance();
        YIMAI_GSO_FAQ_Detector::get_instance();
        YIMAI_GSO_Content_Freshness::get_instance();
        YIMAI_GSO_Citation_Helper::get_instance();
        // L4 综合评估层GEO评分 + 双引擎面板 + 22因子审计
        YIMAI_GSO_GEO_Scorer::get_instance();
        YIMAI_GSO_GEO_Auditor::get_instance();

        // ★ 修复（审计 #1/P0 + L5 断供根因）：SEO/GEO 评分原先无任何自动触发，
        //   全靠管理员手动 AJAX——sitemap ai:ready 恒 false、ai:geo_score 全站缺失、
        //   L5 仪表盘分布/散点图数据断供。改为发布管线末尾（优先级 30，内链完成后）
        //   异步评分：transition_post_status → 入队 → 单次 cron 延迟 120s 执行
        //   （等 ALT/SEO meta/内链等前序异步任务先落库，评到的才是最终内容）。
        add_action( 'transition_post_status', array( $this, 'queue_auto_score' ), 30, 3 );
        add_action( 'yimai_gso_process_auto_score_queue', array( $this, 'process_auto_score_queue' ) );

        // L5 监控与仪表盘（仅前台需要的部分）
        YIMAI_GSO_Referral_Tracker::get_instance();
        YIMAI_GSO_Alert_Engine::get_instance();
        YIMAI_GSO_Crawler_Diagnosis::get_instance();
        // L6 高级联动
        YIMAI_GSO_AI_Platform_Adapter::get_instance();
        YIMAI_GSO_SEO_GEO_Bridge::get_instance();
        // 微信分享卡片：JS-SDK 签名 + 抓取元信息加固（前台 wp_head/wp_footer + REST）
        YIMAI_GSO_Wechat_Share::get_instance();
        if ( function_exists( 'yimai_gso_log_memory' ) ) {
            yimai_gso_log_memory( 'after GEO_Entity' );
        }

        // 每小时懒检查一次 Cron 调度是否存在（防止 WP-Cron 意外丢失导致永动发布停转）
        // 使用 transient 限频，避免每次请求都查询 DB
        if ( false === get_transient( 'yimai_gso_cron_schedule_checked' ) ) {
            set_transient( 'yimai_gso_cron_schedule_checked', 1, HOUR_IN_SECONDS );
            if ( class_exists( 'YIMAI_GSO_Autopilot_Scheduler' ) ) {
                YIMAI_GSO_Autopilot_Scheduler::sync_schedule();
                YIMAI_GSO_Autopilot_Scheduler::sync_cleanup_schedule();
            }
        }

        // L5: 注册 6 小时 Cron 间隔
        //
        // 修复（D3）：本行原先位于下方 schedule_crons() **之后**，
        // 导致首次调用 schedule_crons() 时 'six_hours' 尚未注册到 cron_schedules，
        // wp_schedule_event(..., 'six_hours', ...) 因间隔不存在而返回 false，
        // 6 小时 Schema 预警任务静默未被调度（直到下次该分支执行才可能成功）。
        // 过滤器必须先注册，schedule_crons() 才能找到 'six_hours'。
        add_filter( 'cron_schedules', array( 'YIMAI_GSO_Alert_Engine', 'add_cron_schedules' ) );

        // L5: 每小时懒检查预警 Cron 调度
        if ( false === get_transient( 'yimai_gso_alert_cron_checked' ) ) {
            set_transient( 'yimai_gso_alert_cron_checked', 1, HOUR_IN_SECONDS );
            if ( class_exists( 'YIMAI_GSO_Alert_Engine' ) ) {
                YIMAI_GSO_Alert_Engine::schedule_crons();
            }
        }

        // 每小时懒检查定时发布守护进程调度是否存在（防止 WP-Cron 意外丢失）
        if ( false === get_transient( 'yimai_gso_guardian_schedule_checked' ) ) {
            set_transient( 'yimai_gso_guardian_schedule_checked', 1, HOUR_IN_SECONDS );
            if ( ! wp_next_scheduled( 'yimai_gso_future_publish_guardian' ) ) {
                wp_schedule_event( time(), 'every_30_minutes', 'yimai_gso_future_publish_guardian' );
            }
        }

        // 前端文章页显示AI摘要
        add_filter( 'the_content', array( $this, 'frontend_excerpt_display' ), 10 );
        add_action( 'wp_head', array( $this, 'frontend_excerpt_css' ) );

        // 百度站长验证 + 百度统计代码注入
        add_action( 'wp_head', array( $this, 'output_noindex_meta' ), 1 );
        add_action( 'wp_head', array( $this, 'output_baidu_verify_meta' ), 1 );
        add_action( 'wp_footer', array( $this, 'output_baidu_tongji_code' ), 999 );

        // SEO-GEO 双引擎：OG/Twitter Card + AI Meta + 独立 Organization Schema
        add_action( 'wp_head', array( $this, 'output_og_meta' ), 5 );
        add_action( 'wp_head', array( $this, 'output_twitter_card_meta' ), 5 );
        add_action( 'wp_head', array( $this, 'output_ai_meta' ), 5 );
        add_action( 'wp_head', array( $this, 'output_organization_schema' ), 10 );

        // 前端404死链日志记录（仅 SEO 版及以上）
        add_action( 'template_redirect', array( 'YIMAI_GSO_404_Optimizer', 'log_404_hit' ) );

        // 注册 AJAX 处理

        add_action( 'wp_ajax_yimai_gso_generate_article', array( $this, 'ajax_generate_article' ) );
        add_action( 'wp_ajax_yimai_gso_single_generate', array( $this, 'ajax_single_generate' ) );
        add_action( 'wp_ajax_yimai_gso_single_publish', array( $this, 'ajax_single_publish' ) );
        add_action( 'wp_ajax_yimai_gso_publish_article', array( $this, 'ajax_publish_article' ) );
        add_action( 'wp_ajax_yimai_gso_generate_image', array( $this, 'ajax_generate_image' ) );
        add_action( 'wp_ajax_yimai_gso_test_api', array( $this, 'ajax_test_api' ) );
        add_action( 'wp_ajax_yimai_gso_test_ai_image', array( $this, 'ajax_test_ai_image' ) );
        add_action( 'wp_ajax_yimai_gso_upload_image', array( $this, 'ajax_upload_image' ) );
        add_action( 'wp_ajax_yimai_gso_batch_generate_and_publish', array( $this, 'ajax_batch_generate_and_publish' ) );
        add_action( 'wp_ajax_yimai_gso_generate_titles', array( $this, 'ajax_generate_titles' ) );
        add_action( 'wp_ajax_yimai_gso_get_title_history', array( $this, 'ajax_get_title_history' ) );
        add_action( 'wp_ajax_yimai_gso_delete_title_history', array( $this, 'ajax_delete_title_history' ) );
        add_action( 'wp_ajax_yimai_gso_clear_title_history', array( $this, 'ajax_clear_title_history' ) );
        add_action( 'wp_ajax_yimai_gso_dashboard_stats', array( $this, 'ajax_dashboard_stats' ) );
        add_action( 'wp_ajax_yimai_gso_batch_generate_excerpt', array( $this, 'ajax_batch_generate_excerpt' ) );
        add_action( 'wp_ajax_yimai_gso_excerpt_fetch_posts', array( $this, 'ajax_excerpt_fetch_posts' ) );
        add_action( 'wp_ajax_yimai_gso_seo_meta_scan', array( 'YIMAI_GSO_SEO_Meta_Optimizer', 'ajax_scan_posts' ) );
        add_action( 'wp_ajax_yimai_gso_seo_meta_preview', array( 'YIMAI_GSO_SEO_Meta_Optimizer', 'ajax_generate_preview' ) );
        add_action( 'wp_ajax_yimai_gso_seo_meta_apply', array( 'YIMAI_GSO_SEO_Meta_Optimizer', 'ajax_apply_preview' ) );
        add_action( 'wp_ajax_yimai_gso_seo_meta_stats', array( 'YIMAI_GSO_SEO_Meta_Optimizer', 'ajax_get_stats' ) );

        // SEO增强 P0 功能 AJAX
        add_action( 'wp_ajax_yimai_gso_schema_rebuild', array( 'YIMAI_GSO_Schema_Generator', 'ajax_rebuild_schemas' ) );
        add_action( 'wp_ajax_yimai_gso_schema_save_settings', array( 'YIMAI_GSO_Schema_Generator', 'ajax_save_settings' ) );
        add_action( 'wp_ajax_yimai_gso_schema_get_stats', array( 'YIMAI_GSO_Schema_Generator', 'ajax_get_stats' ) );
        add_action( 'wp_ajax_yimai_gso_seo_score_analyze', array( 'YIMAI_GSO_SEO_Scorer', 'ajax_analyze_post' ) );
        add_action( 'wp_ajax_yimai_gso_seo_score_batch_scan', array( 'YIMAI_GSO_SEO_Scorer', 'ajax_batch_scan' ) );
        add_action( 'wp_ajax_yimai_gso_seo_score_batch_analyze', array( 'YIMAI_GSO_SEO_Scorer', 'ajax_batch_analyze' ) );
        add_action( 'wp_ajax_yimai_gso_heading_analyze', array( 'YIMAI_GSO_Heading_Analyzer', 'ajax_analyze' ) );
        add_action( 'wp_ajax_yimai_gso_heading_optimize', array( 'YIMAI_GSO_Heading_Analyzer', 'ajax_optimize' ) );
        add_action( 'wp_ajax_yimai_gso_heading_apply_optimization', array( 'YIMAI_GSO_Heading_Analyzer', 'ajax_apply_optimization' ) );
        add_action( 'wp_ajax_yimai_gso_heading_check_optimized', array( 'YIMAI_GSO_Heading_Analyzer', 'ajax_check_optimized' ) );

        // Canonical URL 管理与 404 优化
        // GEO: 引用数据查询 AJAX
        // ★ 修复（审计 B-7）：ajax_get_citations 一直存在但从未注册，
        //   编辑侧栏未来接入引用反馈时调用将得到 400；补上注册使其成为可用端点。
        add_action( 'wp_ajax_yimai_gso_get_citations', array( 'YIMAI_GSO_Citation_Helper', 'ajax_get_citations' ) );
        // ★ 修复（审计 #8）：canonical 两个 AJAX 原先在主文件与
        //   YIMAI_GSO_Canonical_Manager::init() 双注册，每次请求执行两遍；
        //   类内注册已覆盖，主文件侧删除（canonical_batch_scan/save_custom）。

        add_action( 'wp_ajax_yimai_gso_404_scan', array( 'YIMAI_GSO_404_Optimizer', 'ajax_scan_dead_links' ) );
        add_action( 'wp_ajax_yimai_gso_404_get_detail', array( 'YIMAI_GSO_404_Optimizer', 'ajax_get_link_detail' ) );
        add_action( 'wp_ajax_yimai_gso_404_generate_page', array( 'YIMAI_GSO_404_Optimizer', 'ajax_generate_404_page' ) );
        add_action( 'wp_ajax_yimai_gso_404_save_settings', array( 'YIMAI_GSO_404_Optimizer', 'ajax_save_settings' ) );

        add_action( 'wp_ajax_yimai_gso_history_query', array( $this, 'ajax_history_query' ) );
        add_action( 'wp_ajax_yimai_gso_history_delete', array( $this, 'ajax_history_delete' ) );
        add_action( 'wp_ajax_yimai_gso_history_change_image', array( $this, 'ajax_history_change_image' ) );
        add_action( 'wp_ajax_yimai_gso_history_list_images', array( $this, 'ajax_history_list_images' ) );
        add_action( 'wp_ajax_yimai_gso_history_stats', array( $this, 'ajax_history_stats' ) );
        add_action( 'wp_ajax_yimai_gso_search_posts', array( $this, 'ajax_search_posts' ) );
        
        // 内容改写 AJAX
        add_action( 'wp_ajax_yimai_gso_rewrite_text', array( $this, 'ajax_rewrite_text' ) );
        add_action( 'wp_ajax_yimai_gso_rewrite_post', array( $this, 'ajax_rewrite_post' ) );
        add_action( 'wp_ajax_yimai_gso_batch_rewrite', array( $this, 'ajax_batch_rewrite' ) );
        add_action( 'wp_ajax_yimai_gso_rewrite_save_draft', array( $this, 'ajax_rewrite_save_draft' ) );

        // 永动发布开关 AJAX
        add_action( 'wp_ajax_yimai_gso_autopilot_toggle', array( $this, 'ajax_autopilot_toggle' ) );

        // 自动图片ALT AJAX
        add_action( 'wp_ajax_yimai_gso_save_alt_settings', array( 'YIMAI_GSO_Image_Alt_Admin', 'ajax_save_settings' ) );
        add_action( 'wp_ajax_yimai_gso_batch_alt', array( 'YIMAI_GSO_Image_Alt_Admin', 'ajax_batch_process' ) );

        // 智能内链高级功能 AJAX
        add_action( 'wp_ajax_yimai_gso_start_rebuild_links', array( 'YIMAI_GSO_Internal_Link', 'ajax_start_rebuild_links' ) );
        add_action( 'wp_ajax_yimai_gso_get_rebuild_progress', array( 'YIMAI_GSO_Internal_Link', 'ajax_get_rebuild_progress' ) );
        add_action( 'wp_ajax_yimai_gso_start_broken_link_audit', array( 'YIMAI_GSO_Internal_Link', 'ajax_start_broken_link_audit' ) );
        add_action( 'wp_ajax_yimai_gso_get_broken_link_audit_progress', array( 'YIMAI_GSO_Internal_Link', 'ajax_get_broken_link_audit_progress' ) );
        add_action( 'wp_ajax_yimai_gso_get_broken_link_audit_result', array( 'YIMAI_GSO_Internal_Link', 'ajax_get_broken_link_audit_result' ) );
        add_action( 'wp_ajax_yimai_gso_remove_broken_links', array( 'YIMAI_GSO_Internal_Link', 'ajax_remove_broken_links' ) );

        // GEO: noindex 编辑页 Meta Box
        add_action( 'add_meta_boxes', array( $this, 'add_noindex_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_noindex_meta_box' ) );

        // 注册REST API端点（系统Cron独立触发）
        // GEO automation uses native WP-Cron; no public cron REST endpoint is registered.

        // GEO: HTTPS 检测（后台提示）
        if ( is_admin() && current_user_can( 'manage_options' ) ) {
            add_action( 'admin_notices', array( $this, 'output_https_notice' ) );
        }
    }

    private function maybe_migrate_to_220() {
        if ( '2.2.1' === get_option( 'yimai_gso_migrated_version', '' ) ) {
            return;
        }

        update_option( 'yimai_gso_migrated_version', '2.2.1', false );
        // 2.2.0：提示词链路和前端生成时限已升级，清理旧统计缓存避免后台继续显示旧状态。
        delete_transient( 'yimai_gso_history_stats' );
        delete_transient( 'yimai_gso_dashboard_stats_' . current_time( 'Y-m-d' ) );
        // 清理已退役的「GEO 自动化」模块遗留的 cron 任务。
        // T2：该模块（class-merged-geo-automation.php）整体不可达，已删除；
        // 此处改用字面量 hook 名，确保老站点升级后遗留的调度被清掉。
        wp_clear_scheduled_hook( 'yimai_gso_merged_geo_process_jobs' );

        if ( class_exists( 'YIMAI_GSO_Knowledge_Center' ) ) {
            YIMAI_GSO_Knowledge_Center::create_tables();
        }
    }


    /**
     * 前端文章页显示AI摘要
    * 仅在单篇文章页且文章有摘要时显示，可在设置页开关
    */
    public function frontend_excerpt_display( $content ) {
        // 仅在单篇文章页生效
        if ( ! is_singular( 'post' ) ) {
            return $content;
        }

        // ★ 内存保护：blocks.php + redis-cache 已占用大量内存，剩余不足80MB时跳过摘要显示
        if ( function_exists( 'memory_get_usage' ) ) {
            $mem_limit = wp_convert_hr_to_bytes( @ini_get( 'memory_limit' ) );
            $mem_used  = memory_get_usage( true );
            $mem_left  = $mem_limit - $mem_used;
            if ( $mem_limit > 0 && $mem_left < 80 * 1024 * 1024 ) {
                return $content;
            }
        }

        // 检查是否开启前端摘要显示
        $enabled = YIMAI_GSO_Settings::get( 'frontend_excerpt_enabled', 0 );
        if ( ! $enabled ) {
            return $content;
        }

        // 文章必须有摘要
        // 注意：不用 get_the_excerpt()，因为它在无手动摘要时会触发 wp_trim_excerpt()，
        // 其内部的 preg_match_all 在处理某些批量生成内容时会灾难性回溯（卡死30+秒）
        global $post;
        $excerpt = $post->post_excerpt; // 直接读手动摘要，不触发 wp_trim_excerpt
        if ( empty( $excerpt ) ) {
            // 无手动摘要时，自行安全截断正文（绕过 wp_trim_excerpt 的正则问题）
            $text = wp_strip_all_tags( $post->post_content );
            $text = trim( preg_replace( '/\s+/', ' ', $text ) );
            $char_count = 250; // 约相当于55个英文单词的中文长度
            if ( mb_strlen( $text ) > $char_count ) {
                $excerpt = mb_substr( $text, 0, $char_count ) . '...';
            } else {
                $excerpt = $text;
            }
        }
        if ( empty( $excerpt ) ) {
            return $content;
        }

        // 获取摘要标签文字设置
        $label = YIMAI_GSO_Settings::get( 'frontend_excerpt_label', '文章摘要' );

        $excerpt_html = '<div class="yimai-gso-excerpt-box">'
            . '<div class="yimai-gso-excerpt-label">' . esc_html( $label ) . '</div>'
            . '<div class="yimai-gso-excerpt-text">' . wpautop( $excerpt ) . '</div>'
            . '</div>';

        return $excerpt_html . $content;
    }

    /**
     * 前端摘要框CSS样式（使用 wp_add_inline_style 安全注入）
     */
    public function frontend_excerpt_css() {
        // 仅在单篇文章页且开启了前端摘要时注入样式
        if ( ! is_singular( 'post' ) ) {
            return;
        }
        if ( ! YIMAI_GSO_Settings::get( 'frontend_excerpt_enabled', 0 ) ) {
            return;
        }

        $css = '
        .yimai-gso-excerpt-box {
            background: linear-gradient(135deg, #f0f7ff 0%, #e8f4fd 100%);
            border-left: 4px solid #4a90d9;
            padding: 16px 20px;
            margin-bottom: 24px;
            border-radius: 0 8px 8px 0;
            font-size: 15px;
            line-height: 1.8;
            color: #444;
        }
        .yimai-gso-excerpt-label {
            font-weight: 700;
            font-size: 13px;
            color: #4a90d9;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .yimai-gso-excerpt-label::before {
            content: "\01F4D6\0020\0020";
        }
        .yimai-gso-excerpt-text p {
            margin: 0;
        }
        .yimai-gso-excerpt-text p + p {
            margin-top: 8px;
        }
        ';

        wp_register_style( 'yimai-gso-frontend-excerpt', false, array(), YIMAI_GSO_VERSION );
        wp_enqueue_style( 'yimai-gso-frontend-excerpt' );
        wp_add_inline_style( 'yimai-gso-frontend-excerpt', $css );
    }

    /**
     * 百度站长平台验证 meta 标签注入
     */
    public function output_baidu_verify_meta() {
        $code = trim( YIMAI_GSO_Settings::get( 'seo_push_baidu_verify_code', '' ) );
        if ( ! empty( $code ) ) {
            echo '<meta name="baidu-site-verification" content="' . esc_attr( $code ) . '" />' . "\n";
        }
    }

    /**
     * 百度统计代码注入
     */
    public function output_baidu_tongji_code() {
        $code = trim( YIMAI_GSO_Settings::get( 'seo_push_baidu_tongji_code', '' ) );
        if ( ! empty( $code ) ) {
            // 支持用户直接粘贴完整 <script> 标签或裸 JS 代码，白名单过滤防XSS
            $allowed = array(
                'script' => array( 'src' => array(), 'type' => array(), 'async' => array(), 'defer' => array() ),
            );
            echo wp_kses( $code, $allowed ) . "\n";
        }
    }

    /**
     * Open Graph 标签输出（微信/QQ/Facebook/LinkedIn 等社交平台分享用）
     */
    public function output_og_meta() {
        // 修复：静态首页的 $post 是名为 "Home" 的页面，is_page() 为真会误取页面标题，
        // 导致首页 og:title="Home"、og:type="article"。首页/博客页统一按站点输出。
        $is_site_home = is_front_page() || is_home();

        if ( ! $is_site_home && ( is_singular( 'post' ) || is_page() ) ) {
            $post_id = get_the_ID();
        } else {
            $post_id = 0;
        }

        // ★ 2.9.9：首页 og:title 原样输出站点名「一麦瑜伽」，微信/朋友圈卡片标题单薄。
        //   改为优先读「微信分享卡片 → 首页分享标题」，未配置时仍回落站点名（行为不变）。
        //   归档/分类/搜索页没有单页标题，继续用站点名（与改动前一致）。
        if ( $post_id ) {
            $title = get_the_title( $post_id );
        } elseif ( $is_site_home ) {
            $home_share_title = trim( (string) YIMAI_GSO_Settings::get( 'wechat_share_home_title', '' ) );
            $title = ( '' !== $home_share_title ) ? $home_share_title : get_bloginfo( 'name' );
        } else {
            $title = get_bloginfo( 'name' );
        }
        $url = $post_id ? get_permalink( $post_id ) : home_url( '/' );

        // 描述：优先从 SEO 字段读取（首页用 tagline 兜底而非文章摘要字段）
        $desc = '';
        if ( $post_id ) {
            $desc = get_post_meta( $post_id, 'yimai_gso_excerpt', true );
            if ( empty( $desc ) ) $desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
            if ( empty( $desc ) ) $desc = get_post_meta( $post_id, 'rank_math_description', true );
            if ( empty( $desc ) ) $desc = get_post_meta( $post_id, 'description', true );
            if ( empty( $desc ) ) $desc = get_the_excerpt( $post_id );
        }
        if ( empty( $desc ) ) {
            $desc = get_bloginfo( 'description' );
        }
        $desc = mb_substr( wp_strip_all_tags( $desc ), 0, 200 );

        // 缩略图：统一走分享封面解析器（特色图 → 后台指定封面 → Logo → 站点图标 → 媒体库）
        // ★ 修复（2.9.9）：原实现只处理了 SVG，还漏三件事——
        //   ① WebP 在部分安卓微信分享卡片里不显示；② 图片文件名含中文时微信抓取器取不到图
        //   （首页 og:image 正是「一麦纯logo-绿.png」）；③ 微信/QQ/微博老抓取器只认
        //   <link rel="image_src">，从未输出过。解析器统一做 SVG/WebP 降级、HTTPS 升级与
        //   非 ASCII 字符百分号编码，并被微信 JS-SDK 分享缩略图复用（两处保持一致）。
        $share_img = class_exists( 'YIMAI_GSO_Wechat_Share' )
            ? YIMAI_GSO_Wechat_Share::resolve_share_image( $post_id, $url )
            : array( 'url' => '', 'width' => 0, 'height' => 0, 'type' => '' );

        $image        = ! empty( $share_img['url'] ) ? $share_img['url'] : '';
        $image_width  = ! empty( $share_img['width'] ) ? (int) $share_img['width'] : 0;
        $image_height = ! empty( $share_img['height'] ) ? (int) $share_img['height'] : 0;
        $image_type   = ! empty( $share_img['type'] ) ? (string) $share_img['type'] : '';

        $og  = '<meta property="og:type" content="' . ( $post_id ? 'article' : 'website' ) . '" />' . "\n";
        $og .= '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />' . "\n";
        $og .= '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
        if ( ! empty( $desc ) ) {
            $og .= '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
        }
        $og .= '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
        if ( ! empty( $image ) ) {
            $og .= '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
            $og .= '<meta property="og:image:secure_url" content="' . esc_url( $image ) . '" />' . "\n";
            if ( $image_width > 0 ) {
                $og .= '<meta property="og:image:width" content="' . $image_width . '" />' . "\n";
            }
            if ( $image_height > 0 ) {
                $og .= '<meta property="og:image:height" content="' . $image_height . '" />' . "\n";
            }
            if ( '' !== $image_type ) {
                $og .= '<meta property="og:image:type" content="' . esc_attr( $image_type ) . '" />' . "\n";
            }
            // 微信 / QQ / 微博的老抓取器不解析 og:image，只认 link rel=image_src
            $og .= '<link rel="image_src" href="' . esc_url( $image ) . '" />' . "\n";
        }
        $og .= '<meta property="og:locale" content="zh_CN" />' . "\n";

        echo $og;
    }

    /**
     * Twitter Card 标签输出
     */
    public function output_twitter_card_meta() {
        if ( is_singular( 'post' ) || is_page() ) {
            $post_id = get_the_ID();
        } else {
            return;
        }

        $title  = get_the_title( $post_id );
        $desc   = get_post_meta( $post_id, 'yimai_gso_excerpt', true );
        if ( empty( $desc ) ) $desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        if ( empty( $desc ) ) $desc = get_post_meta( $post_id, 'rank_math_description', true );
        if ( empty( $desc ) ) $desc = get_post_meta( $post_id, 'description', true );
        if ( empty( $desc ) ) $desc = get_the_excerpt( $post_id );
        $desc = mb_substr( wp_strip_all_tags( $desc ), 0, 200 );

        // ★ 2.9.9：与 og:image 共用同一解析器——原先只取特色图，没有特色图的页面
        //   twitter:image 直接缺失，且不拦 SVG / 未编码中文文件名。
        $tw_img  = class_exists( 'YIMAI_GSO_Wechat_Share' )
            ? YIMAI_GSO_Wechat_Share::resolve_share_image( $post_id, get_permalink( $post_id ) )
            : array( 'url' => '' );
        $image   = ! empty( $tw_img['url'] ) ? $tw_img['url'] : '';

        $tw  = '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        $tw .= '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
        if ( ! empty( $desc ) ) {
            $tw .= '<meta name="twitter:description" content="' . esc_attr( $desc ) . '" />' . "\n";
        }
        if ( ! empty( $image ) ) {
            $tw .= '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
        }

        echo $tw;
    }

    /**
     * AI 搜索引擎专属 Meta 标签（GEO 优化核心）
     * 为 ChatGPT/Gemini/Perplexity 等 AI 大模型爬虫提供结构化描述
     */
    public function output_ai_meta() {
        // ★ 修复（审计 B-1）：原先仅 is_singular('post') 生效——页面(page)与受支持的自定义类型
        //   全部不输出 ai-description/ai-keywords（线上首页已验证缺失）。
        //   改为插件全局支持的内容类型白名单（Post_Type_Helper，默认 post+page），
        //   首页/归档保持不输出（避免与 AI Platform Adapter 的站点级 meta 重复）。
        if ( ! is_singular() ) {
            return;
        }
        $post_type = get_post_type();
        $helper_ready = class_exists( 'YIMAI_GSO_Post_Type_Helper' );
        // 兜底：Post_Type_Helper 不存在时维持原行为（仅文章页）
        if ( $helper_ready && ! YIMAI_GSO_Post_Type_Helper::is_supported( $post_type ) ) {
            return;
        }
        if ( ! $helper_ready && ! is_singular( 'post' ) ) {
            return;
        }

        $post_id = get_the_ID();

        // AI 描述：取 SEO 描述的前 160 字符精简版，适合 AI 提取
        $ai_desc = get_post_meta( $post_id, 'yimai_gso_excerpt', true );
        if ( empty( $ai_desc ) ) $ai_desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        if ( empty( $ai_desc ) ) $ai_desc = get_post_meta( $post_id, 'rank_math_description', true );
        if ( empty( $ai_desc ) ) $ai_desc = get_post_meta( $post_id, 'description', true );
        if ( empty( $ai_desc ) ) {
            $post = get_post( $post_id );
            // ★ 修复（GEO 报告 S-7）：原先 wp_trim_words(30)≈60 中文字符提前触底，
            //   meta 描述偏短。改按字符截取 160，让 AI 引擎拿到更完整的首段信息。
            $ai_desc = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 160 );
        }
        $ai_desc = mb_substr( wp_strip_all_tags( $ai_desc ), 0, 160 );

        // AI 关键词
        $ai_kw = get_post_meta( $post_id, 'yimai_gso_keywords', true );
        if ( empty( $ai_kw ) ) $ai_kw = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
        if ( empty( $ai_kw ) ) $ai_kw = get_post_meta( $post_id, 'rank_math_focus_keyword', true );

        if ( ! empty( $ai_desc ) ) {
            echo '<meta name="ai-description" content="' . esc_attr( $ai_desc ) . '" />' . "\n";
        }
        if ( ! empty( $ai_kw ) ) {
            echo '<meta name="ai-keywords" content="' . esc_attr( $ai_kw ) . '" />' . "\n";
        }
    }

    /**
     * 独立 Organization Schema JSON-LD
     * 目前仅依赖挂载在 Article publisher 中输出，此处独立一份便于搜索引擎解析
     */
    public function output_organization_schema() {
        // GEO 实体已配置时，增强版 Schema 会替代此输出，避免重复
        if ( class_exists( 'YIMAI_GSO_GEO_Entity' ) && YIMAI_GSO_GEO_Entity::is_configured() ) {
            return;
        }

        // 修复（L2）：首页的 Organization 已由 class-schema-generator.php
        // 的 output_front_page_schemas() 负责（它同时输出 WebSite + Organization）。
        // 本方法原先没有首页判断，导致首页在「实体未配置」时同时输出本节点与
        // schema-generator 的节点 —— 共 2 个重复 Organization。
        // 首页交给 schema-generator 统一输出，本方法仅负责非首页页面
        // （如文章页的 publisher 信息），从而保证任一页面恰好一个 Organization。
        if ( is_front_page() || is_home() ) {
            return;
        }

        $schema = array(
            '@context'    => 'https://schema.org',
            '@type'       => 'Organization',
            'name'        => get_bloginfo( 'name' ),
            'url'         => home_url( '/' ),
            'description' => get_bloginfo( 'description' ),
        );

        // 站点图标作为 Logo
        $logo_url = '';
        $logo_id  = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
        }
        if ( empty( $logo_url ) && function_exists( 'get_site_icon_url' ) ) {
            $logo_url = get_site_icon_url( 512 );
        }
        if ( ! empty( $logo_url ) ) {
            $schema['logo'] = $logo_url;
        }

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . '</script>' . "\n";
    }

    /**
     * 输出 noindex meta 标签
     * 两层控制: 全局 post_type 开关 + 单页 post_meta 覆盖
     */
    public function output_noindex_meta() {
        if ( is_admin() ) {
            return;
        }

        $noindex = false;

        if ( is_singular() ) {
            $post_id = get_the_ID();

            // 单页覆盖优先
            $post_noindex = get_post_meta( $post_id, 'yimai_gso_noindex', true );
            if ( '1' === $post_noindex ) {
                $noindex = true;
            } elseif ( '0' === $post_noindex ) {
                $noindex = false; // 显式允许
            } else {
                // 全局设置
                $post_type     = get_post_type( $post_id );
                $noindex_types = YIMAI_GSO_Settings::get( 'noindex_post_types', '' );
                $noindex_list  = array_filter( array_map( 'trim', explode( ',', $noindex_types ) ) );
                if ( in_array( $post_type, $noindex_list, true ) ) {
                    $noindex = true;
                }
            }
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $noindex = true; // 分类/标签页默认 noindex
        } elseif ( is_author() || is_date() ) {
            $noindex = true; // 作者/日期归档默认 noindex
        } elseif ( is_404() || is_search() ) {
            $noindex = true; // 404/搜索页必然 noindex
        }

        if ( $noindex ) {
            echo '<meta name="robots" content="noindex, follow">' . "\n";
        }
    }

    /**
     * 发布管线自动评分（审计 #1/P0 修复）：transition_post_status 优先级 30 入队
     * 延迟异步执行 SEO_Scorer::analyze + GEO_Scorer::compute，评分落库后
     * sitemap 的 ai:ready / ai:geo_score 与 L5 仪表盘分布图自动恢复数据。
     */
    public function queue_auto_score( $new_status, $old_status, $post ) {
        if ( $new_status !== 'publish' || ! $post instanceof WP_Post ) {
            return;
        }
        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
            return;
        }
        if ( ! class_exists( 'YIMAI_GSO_Post_Type_Helper' ) || ! YIMAI_GSO_Post_Type_Helper::is_supported( $post->post_type ) ) {
            return;
        }

        $queue = get_option( 'yimai_gso_auto_score_queue', array() );
        if ( ! is_array( $queue ) ) {
            $queue = array();
        }
        if ( ! in_array( $post->ID, $queue, true ) ) {
            $queue[] = (int) $post->ID;
            update_option( 'yimai_gso_auto_score_queue', array_slice( $queue, -50 ), false );
        }

        // 延迟 120s：等 ALT/SEO meta/内链等前序异步任务先落库再评分
        if ( ! wp_next_scheduled( 'yimai_gso_process_auto_score_queue' ) ) {
            wp_schedule_single_event( time() + 120, 'yimai_gso_process_auto_score_queue' );
        }
    }

    /**
     * 自动评分队列处理：逐篇执行 SEO + GEO 评分（cron 回调）
     */
    public function process_auto_score_queue() {
        $queue = get_option( 'yimai_gso_auto_score_queue', array() );
        if ( ! is_array( $queue ) || empty( $queue ) ) {
            delete_option( 'yimai_gso_auto_score_queue' );
            return;
        }

        $batch = array_splice( $queue, 0, 5 );
        foreach ( $batch as $post_id ) {
            $post_id = absint( $post_id );
            if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) ) {
                continue;
            }
            if ( class_exists( 'YIMAI_GSO_SEO_Scorer' ) ) {
                YIMAI_GSO_SEO_Scorer::get_instance()->analyze( $post_id );
            }
            if ( class_exists( 'YIMAI_GSO_GEO_Scorer' ) ) {
                YIMAI_GSO_GEO_Scorer::get_instance()->compute( $post_id );
            }
        }

        if ( ! empty( $queue ) ) {
            update_option( 'yimai_gso_auto_score_queue', $queue, false );
            if ( ! wp_next_scheduled( 'yimai_gso_process_auto_score_queue' ) ) {
                wp_schedule_single_event( time() + 30, 'yimai_gso_process_auto_score_queue' );
            }
        } else {
            delete_option( 'yimai_gso_auto_score_queue' );
        }
    }

    /**
     * 检测 HTTPS 状态并在后台显示提示
     */
    public function output_https_notice() {
        $transient_key = 'yimai_gso_https_check_done';
        if ( get_transient( $transient_key ) ) {
            return; // 每小时只提示一次
        }

        $site_url = site_url();
        $home_url = home_url();

        if ( strpos( $site_url, 'https://' ) !== 0 && strpos( $home_url, 'https://' ) !== 0 ) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>一麦AI SEO 提示</strong>: 您的网站当前未启用 HTTPS (' . esc_html( $site_url ) . ')。</p>';
            echo '<p style="font-size:13px;color:#666">建议启用 SSL 证书并设置站点地址为 https:// 开头，这对 SEO 和 GEO 双引擎优化都十分重要。</p>';
            echo '</div>';
        }

        set_transient( $transient_key, 1, HOUR_IN_SECONDS );
    }

    /**
     * 注册 noindex 编辑页 Meta Box
     */
    public function add_noindex_meta_box() {
        $post_types = YIMAI_GSO_Post_Type_Helper::get_supported();
        foreach ( $post_types as $pt ) {
            add_meta_box(
                'yimai-gso-noindex-meta-box',
                '🔍 索引状态 (noindex)',
                array( $this, 'render_noindex_meta_box' ),
                $pt,
                'side',
                'low'
            );
        }
    }

    /**
     * 渲染 noindex Meta Box
     */
    public function render_noindex_meta_box( $post ) {
        wp_nonce_field( 'yimai_gso_noindex_meta', 'yimai_gso_noindex_nonce' );
        $current = get_post_meta( $post->ID, 'yimai_gso_noindex', true );
        ?>
        <p style="font-size:12px;color:#666;margin-bottom:8px;">控制搜索引擎是否索引此页面</p>
        <select name="yimai_gso_noindex" style="width:100%;margin-bottom:8px;">
            <option value="" <?php selected( $current, '' ); ?>>跟随全局设置</option>
            <option value="0" <?php selected( $current, '0' ); ?>>允许索引 (index)</option>
            <option value="1" <?php selected( $current, '1' ); ?>>禁止索引 (noindex)</option>
        </select>
        <p style="font-size:11px;color:#999;">选择"禁止索引"后，搜索引擎和AI爬虫将不会收录此页面</p>
        <?php
    }

    /**
     * 保存 noindex Meta Box
     */
    public function save_noindex_meta_box( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['yimai_gso_noindex_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['yimai_gso_noindex_nonce'], 'yimai_gso_noindex_meta' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // 仅处理支持的 post_type（避免在全类型 save_post 中无效执行）
        $post_type = get_post_type( $post_id );
        if ( ! in_array( $post_type, YIMAI_GSO_Post_Type_Helper::get_supported(), true ) ) {
            return;
        }

        $value = isset( $_POST['yimai_gso_noindex'] ) ? sanitize_text_field( $_POST['yimai_gso_noindex'] ) : '';

        if ( $value === '0' || $value === '1' ) {
            update_post_meta( $post_id, 'yimai_gso_noindex', $value );
        } else {
            delete_post_meta( $post_id, 'yimai_gso_noindex' ); // 跟随全局
        }
    }

    public function activate() {
        // 注册图片库 taxonomy
        YIMAI_GSO_Image_Library::register_taxonomy();

        // 激活时设置默认选项
        $defaults = array(
            'ai_provider'              => 'openai',
            'ai_api_key'               => '',
            'ai_model'                 => 'gpt-4o',
            'ai_api_url'               => 'https://api.openai.com/v1/chat/completions',
            'image_provider'           => 'pexels',
            'image_api_key'            => '',
            'default_category'         => 1,
            'default_status'           => 'draft',
            'article_language'         => 'zh_CN',
            'word_count'               => 1000,
            'image_count'              => 3,
            'auto_image_alt_enabled'   => 1,
            'auto_image_alt_mode'      => 'rule',
            'internal_link_enabled'    => 1,

            'internal_link_max'        => 3,
            'internal_link_candidate_mode' => 'recent',
            'internal_link_candidate_limit' => 50,
            'internal_link_excluded_category_ids' => array(),
            'internal_link_excluded_tag_ids' => array(),
            'internal_link_keyword_library' => '',
            'internal_link_anchor_policy' => 'natural',
            'detection_enabled'        => 1,
            'seo_push_google_enabled'  => 0,
            'seo_push_google_mode'     => 'sitemap_ping',
            'seo_push_google_service_json' => '',
        );
        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( 'yimai_gso_' . $key ) ) {
                add_option( 'yimai_gso_' . $key, $value );
            }
        }

        if ( false === get_option( 'yimai_gso_detection_logs' ) ) {
            add_option( 'yimai_gso_detection_logs', array(), '', false );
        }

        if ( false === get_option( 'yimai_gso_internal_link_logs' ) ) {
            add_option( 'yimai_gso_internal_link_logs', array(), '', false );
        }

        if ( false === get_option( 'yimai_gso_seo_meta_logs' ) ) {
            add_option( 'yimai_gso_seo_meta_logs', array(), '', false );
        }

        YIMAI_GSO_Autopilot_Log::create_tables();
        update_option( 'yimai_gso_autopilot_schema_version', YIMAI_GSO_Autopilot_Log::SCHEMA_VERSION, false );

        // 激活时同步 cron 调度（永动发布 + 日志清理）
        YIMAI_GSO_Autopilot_Scheduler::sync_schedule();
        YIMAI_GSO_Autopilot_Scheduler::sync_cleanup_schedule();

        // 注册定时发布守护进程（每30分钟执行，修复 WP-Cron 未触发导致定时文章积压）
        if ( ! wp_next_scheduled( 'yimai_gso_future_publish_guardian' ) ) {
            wp_schedule_event( time(), 'every_30_minutes', 'yimai_gso_future_publish_guardian' );
        }

        // GEO: 创建 AI 爬虫日志表
        if ( class_exists( 'YIMAI_GSO_Crawler_Tracker' ) ) {
            YIMAI_GSO_Crawler_Tracker::create_table();
        }

        // L5: 创建引荐流量日志表和预警日志表
        if ( class_exists( 'YIMAI_GSO_Referral_Tracker' ) ) {
            YIMAI_GSO_Referral_Tracker::create_table();
        }
        if ( class_exists( 'YIMAI_GSO_Alert_Engine' ) ) {
            YIMAI_GSO_Alert_Engine::create_table();
            YIMAI_GSO_Alert_Engine::schedule_crons();
        }

        // T2 修复：原先在此调用 YIMAI_GSO_MERGED_GEO_AUTOMATION::create_tables()，
        // 该模块整体不可达（钩子只在其构造函数注册，而唯一的 get_instance() 调用点是
        // 它自己的 activate()，从不被调用），但它通过这里在**每次激活**时 dbDelta 出
        // 5 张永远不会有数据的表（yimai_gso_geo_keywords / _geo_brand_scans /
        // _geo_brand_scan_details / _geo_jobs / _geo_job_logs）。
        // 已移除建表调用；5 张表仍保留在卸载清单中，因此将来卸载插件时会随其它表一并清理。
        // **不在此处 DROP 已存在的表** —— 那是破坏性数据操作，需要单独确认。

        if ( class_exists( 'YIMAI_GSO_Knowledge_Center' ) ) {
            YIMAI_GSO_Knowledge_Center::create_tables();
        }

        flush_rewrite_rules();
    }

    public function deactivate() {
        // 清除 cron 调度
        YIMAI_GSO_Autopilot_Scheduler::clear_schedule();
        YIMAI_GSO_Autopilot_Scheduler::clear_cleanup_schedule();

        // 清除定时发布守护进程
        wp_clear_scheduled_hook( 'yimai_gso_future_publish_guardian' );

        // L5: 清除预警检测 cron
        if ( class_exists( 'YIMAI_GSO_Alert_Engine' ) ) {
            YIMAI_GSO_Alert_Engine::unschedule_crons();
        }

        // 已退役的 GEO 自动化模块遗留任务（T2：模块已删除，此处按字面量清理）
        wp_clear_scheduled_hook( 'yimai_gso_merged_geo_process_jobs' );

        wp_clear_scheduled_hook( 'yimai_gso_classify_knowledge' );

        // T5 修复：补清原先遗漏的 8 个计划任务。
        // 停用后它们仍留在 cron 表中，会在下次到期时触发已失效的回调；
        // 其中 yimai_gso_generate_sitemap 是**每日重复事件**，
        // 不清除会持续被调度（其余为一次性事件，会自行排空）。
        foreach ( array(
            'yimai_gso_process_seo_push_queue',
            'yimai_gso_process_alt_queue',
            'yimai_gso_process_internal_link_queue',
            'yimai_gso_process_rebuild_link_queue',
            'yimai_gso_process_broken_link_audit',
            'yimai_gso_process_seo_meta_queue',
            'yimai_gso_audit_content_batch',
            'yimai_gso_generate_sitemap',       // 每日重复事件，最需要清除
        ) as $cron_hook ) {
            wp_clear_scheduled_hook( $cron_hook );
        }

        // 清理 transient 缓存
        delete_transient( 'yimai_gso_history_stats' );
        delete_transient( 'yimai_gso_guardian_schedule_checked' );
        delete_transient( 'yimai_gso_alert_cron_checked' );
        delete_transient( 'yimai_gso_cron_schedule_checked' );
        delete_transient( 'yimai_gso_autopilot_rescue_checked' );
        delete_transient( 'yimai_gso_autopilot_enabled_check' );

        flush_rewrite_rules();
    }


    /**
     * AJAX: 单篇文章生成
     */
    public function ajax_single_generate() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ), 403 );
        }

        @set_time_limit( 600 );
        $params = array(
            'keyword'    => sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) ),
            'title'      => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
            'length'     => sanitize_key( $_POST['length'] ?? 'medium' ),
            'type'       => sanitize_key( $_POST['type'] ?? 'guide' ),
            'angle'      => sanitize_key( $_POST['angle'] ?? '' ),
            'style'      => sanitize_text_field( wp_unslash( $_POST['style'] ?? '' ) ),
            'with_image' => ! empty( $_POST['add_image'] ),
        );
        if ( $params['keyword'] === '' ) {
            wp_send_json_error( array( 'message' => '请输入关键词或主题。' ), 400 );
        }

        try {
            $result = ( new YIMAI_GSO_AI_Generator() )->generate_single_article( $params );
        } catch ( Throwable $exception ) {
            error_log( '[一麦GSO] 单篇生成异常：' . $exception->getMessage() );
            wp_send_json_error( array( 'message' => '单篇生成发生服务端异常，请查看 PHP 错误日志。', 'code' => 'single_generation_exception' ), 500 );
        }
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 );
        }
        wp_send_json_success( $result );
    }

    /**
     * AJAX: 单篇文章发布/保存
     */
    public function ajax_single_publish() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ), 403 );
        }

        $title   = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );
        if ( class_exists( 'YIMAI_GSO_Publisher' ) ) {
            $content = YIMAI_GSO_Publisher::normalize_table_styles( $content );
        }
        $status  = sanitize_key( $_POST['status'] ?? 'draft' );
        if ( ! in_array( $status, array( 'draft', 'publish', 'pending' ), true ) ) $status = 'draft';
        if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) $status = 'pending';
        if ( $title === '' || $content === '' ) {
            wp_send_json_error( array( 'message' => '标题和正文不能为空。' ), 400 );
        }

        $post_id = wp_insert_post( array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => sanitize_textarea_field( wp_unslash( $_POST['excerpt'] ?? '' ) ),
            'post_status'  => $status,
            'post_type'    => 'post',
        ), true );
        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 400 );
        }
        if ( ! empty( $_POST['tags'] ) ) {
            wp_set_post_tags( $post_id, sanitize_text_field( wp_unslash( $_POST['tags'] ) ), false );
        }
        update_post_meta( $post_id, 'yimai_gso_generated', '1' );
        wp_send_json_success( array(
            'post_id'  => $post_id,
            'post_url' => get_permalink( $post_id ),
            'edit_url' => get_edit_post_link( $post_id, 'raw' ),
            'message'  => '文章已保存。',
        ) );
    }

    /**
     * AJAX: 生成文章
     */
    public function ajax_generate_article() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        // AI 生成 + 图片生成可能需要 3-5 分钟，放宽 PHP 超时限制
        @set_time_limit( 600 );

        $prompt     = sanitize_textarea_field( $_POST['prompt'] ?? '' );
        $word_count = absint( $_POST['word_count'] ?? get_option( 'yimai_gso_word_count', 1000 ) );
        $language   = sanitize_text_field( $_POST['language'] ?? 'zh_CN' );

        if ( empty( $prompt ) ) {
            wp_send_json_error( array( 'message' => '请填写提示词' ) );
        }

        $full_prompt = $prompt;

        $generator = new YIMAI_GSO_AI_Generator();
        $result    = $generator->generate_article( $full_prompt, $word_count, $language );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: 生成配图
     */
    public function ajax_generate_image() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
        $count   = absint( $_POST['count'] ?? 3 );

        $handler = new YIMAI_GSO_Image_Handler();
        $images  = $handler->fetch_images( $keyword, $count );

        if ( is_wp_error( $images ) ) {
            wp_send_json_error( array( 'message' => $images->get_error_message() ) );
        }

        wp_send_json_success( array( 'images' => $images ) );
    }

    /**
     * AJAX: 发布文章
     */
    public function ajax_publish_article() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'publish_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        
        // 增加内存限制 + 放宽 PHP 超时（AI图片下载可能需要较长时间）
        wp_raise_memory_limit( 'admin' );
        @set_time_limit( 600 );

        $image_count = absint( $_POST['image_count'] ?? 0 );

        $data = array(
            'title'        => sanitize_text_field( $_POST['title'] ?? '' ),
            'content'      => wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) ), // HTML净化：保留安全标签，移除恶意脚本
            'excerpt'      => sanitize_textarea_field( $_POST['excerpt'] ?? '' ),
            'category_id'  => absint( $_POST['category_id'] ?? get_option( 'yimai_gso_default_category', 1 ) ),
            'tags'         => sanitize_text_field( $_POST['tags'] ?? '' ),
            'status'       => sanitize_text_field( $_POST['status'] ?? 'draft' ),
            'scheduled_at' => sanitize_text_field( $_POST['scheduled_at'] ?? '' ),
            'thumbnail_id' => absint( $_POST['thumbnail_id'] ?? 0 ),
            'keywords'     => sanitize_text_field( $_POST['keywords'] ?? '' ),
            'prompt'       => sanitize_textarea_field( $_POST['prompt'] ?? '' ),
            'image_count'  => $image_count,
            'source'       => 'manual',
            'pending_image_prompts' => isset( $_POST['pending_image_prompts'] ) ? json_decode( wp_unslash( $_POST['pending_image_prompts'] ), true ) : array(),
        );

        $publisher = new YIMAI_GSO_Publisher();

        $result    = $publisher->publish( $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: 上传外部图片到WordPress媒体库
     */
    public function ajax_upload_image() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );
        $alt_text  = sanitize_text_field( $_POST['alt_text'] ?? '' );

        if ( empty( $image_url ) ) {
            wp_send_json_error( array( 'message' => '图片URL不能为空' ) );
        }

        // SSRF 防护（S3）：本接口把用户提交的 URL 交给 download_url() 发起服务端 GET，
        // 任意持有 upload_files 的账号可借此探测内网（169.254.169.254 云元数据、
        // 127.0.0.1 内部服务、内网面板等），并通过媒体库读回响应。
        // 修复：把校验放在 handler 入口，同时保护所有调用方。
        $ssrf_check = YIMAI_GSO_Image_Handler::validate_remote_image_url( $image_url );
        if ( is_wp_error( $ssrf_check ) ) {
            wp_send_json_error( array( 'message' => $ssrf_check->get_error_message() ) );
        }

        $handler       = new YIMAI_GSO_Image_Handler();
        $attachment_id = $handler->upload_image_to_media( $image_url, '', $alt_text );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => '图片上传失败：' . $attachment_id->get_error_message() ) );
        }

        $image_url_new = wp_get_attachment_url( $attachment_id );

        wp_send_json_success( array(
            'attach_id' => $attachment_id,
            'url'       => $image_url_new,
        ) );
    }

    /**
     * AJAX: 测试 API 连通性
     */
    public function ajax_test_api() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $type         = sanitize_text_field( $_POST['type'] ?? 'ai' );
        $use_saved    = ! empty( $_POST['use_saved'] ) && '1' === $_POST['use_saved'];
        $api_key      = $use_saved ? YIMAI_GSO_Settings::get( 'ai_api_key' ) : sanitize_text_field( $_POST['api_key'] ?? '' );
        $api_url      = esc_url_raw( wp_unslash( $_POST['api_url'] ?? '' ) );
        $provider     = sanitize_key( $_POST['provider'] ?? YIMAI_GSO_Settings::get( 'ai_provider', 'openai' ) );
        $model        = sanitize_text_field( wp_unslash( $_POST['model'] ?? YIMAI_GSO_Settings::get( 'ai_model', 'gpt-4o' ) ) );
        $img_provider = sanitize_text_field( $_POST['img_provider'] ?? '' );

        if ( 'ai' === $type ) {
            if ( empty( $api_key ) ) {
                wp_send_json_error( array( 'message' => '请填写 API Key，或先保存 API Key 后再测试。' ) );
            }
            if ( empty( $api_url ) ) {
                wp_send_json_error( array( 'message' => '请填写 API 接口地址。' ) );
            }
            if ( empty( $model ) ) {
                wp_send_json_error( array( 'message' => '请填写模型名称。' ) );
            }
            $generator = new YIMAI_GSO_AI_Generator( $api_key, $api_url, $model, $provider );
            $result    = $generator->test_connection();
        } else {
            // MT Photos：优先用表单当前值测试（不依赖先保存），空字段回落到已保存设置
            if ( 'mtphotos' === $img_provider && class_exists( 'YIMAI_GSO_MTPhotos_Client' ) ) {
                $posted_host = esc_url_raw( wp_unslash( $_POST['mtphotos_host'] ?? '' ) );
                $posted_key  = sanitize_text_field( wp_unslash( $_POST['mtphotos_api_key'] ?? '' ) );
                $posted_user = sanitize_text_field( wp_unslash( $_POST['mtphotos_username'] ?? '' ) );
                $posted_pwd  = sanitize_text_field( wp_unslash( $_POST['mtphotos_password'] ?? '' ) );

                if ( $posted_host ) { YIMAI_GSO_Settings::set( 'mtphotos_host', $posted_host ); }
                if ( $posted_key )  { YIMAI_GSO_Settings::set( 'mtphotos_api_key', $posted_key ); }
                if ( $posted_user ) { YIMAI_GSO_Settings::set( 'mtphotos_username', $posted_user ); }
                // 密码掩码占位符（****xxx）不覆盖已保存值
                if ( $posted_pwd && ! YIMAI_GSO_Settings::is_masked_value( $posted_pwd ) ) {
                    YIMAI_GSO_Settings::set( 'mtphotos_password', $posted_pwd );
                }

                $client = new YIMAI_GSO_MTPhotos_Client();
                $result = $client->pick_images( '', 1 );
            } else {
                $handler = new YIMAI_GSO_Image_Handler( $api_key, $img_provider );
                $result  = $handler->test_connection();
            }
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => 'API 连接成功！' ) );
    }

    /**
     * AJAX: 测试 AI 生图连接
     */
    public function ajax_test_ai_image() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $provider = sanitize_text_field( $_POST['provider'] ?? 'doubao' );
        $api_key  = sanitize_text_field( $_POST['api_key'] ?? '' );

        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => '请填写 API Key' ) );
        }

        if ( ! class_exists( 'YIMAI_GSO_AI_Image_Generator' ) ) {
            wp_send_json_error( array( 'message' => 'AI 图片生成模块未加载' ) );
        }

        $generator = new YIMAI_GSO_AI_Image_Generator( $provider, $api_key );
        $result = $generator->test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => 'AI 生图连接成功！' ) );
    }

    /**
     * 校验批量文章配置里的发布设置
     * 这类错误属于整批级错误，应在调用 AI 之前直接拦下

     *
     * @param string $status
     * @param string $scheduled_at
     * @return true|array|WP_Error
     */
    private function validate_batch_publish_config( $status, $scheduled_at ) {
        $status = sanitize_text_field( (string) $status );
        if ( ! in_array( $status, array( 'draft', 'publish', 'future' ), true ) ) {
            $status = 'draft';
        }

        if ( 'future' !== $status ) {
            return true;
        }

        if ( empty( $scheduled_at ) ) {
            return new WP_Error( 'invalid_date', '请先在批量文章配置里设置定时发布的起始时间' );
        }

        $publisher = new YIMAI_GSO_Publisher();
        return $publisher->validate_scheduled_at( $scheduled_at );
    }

    /**
     * 返回批量配置级错误（会让前端立即中止整批）
     *
     * @param WP_Error|string $error
     * @return void
     */
    private function send_batch_config_error( $error ) {
        $message      = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
        $source_code  = is_wp_error( $error ) ? $error->get_error_code() : 'batch_config_error';
        $status_code  = ( 'yimai_gso_feature_locked' === $source_code ) ? 403 : 400;

        wp_send_json_error(
            array(
                'message'           => $message,
                'code'              => 'batch_config_error',
                'fatal_batch'       => true,
                'fatal_stage'       => 'schedule',
                'fatal_source_code' => $source_code,
            ),
            $status_code
        );
    }

    /**
     * AJAX: 批量生成文章 + 自动配图 + 自动发布（一次性完成单篇文章的生成和发布）
     * 前端逐个调用此接口，每调用一次生成并发布一篇文章
     */
    public function ajax_batch_generate_and_publish() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        $batch_status = sanitize_key( $_POST['status'] ?? 'draft' );
        // 草稿只需要编辑权限；直接发布/定时发布才需要 publish_posts。
        $required_capability = in_array( $batch_status, array( 'publish', 'future' ), true ) ? 'publish_posts' : 'edit_posts';
        if ( ! current_user_can( $required_capability ) ) {
            wp_send_json_error( array( 'message' => '当前账号没有执行此发布状态所需的权限。', 'code' => 'batch_capability_denied' ), 403 );
        }

        // 设置无时间限制，防止生成过程中被中断
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        ignore_user_abort( true );
        
        // 增加内存限制（AI生成大内容时防止内存溢出）
        wp_raise_memory_limit( 'admin' );

        // 强制单并发（老板要求并发限制为1，无视前端传入值）
        $concurrency_index = 0;
        $lock_source = 'manual_batch_0';

        if ( class_exists( 'YIMAI_GSO_Autopilot_Runner' ) ) {
            // 检查是否有非批量来源的全局锁（如永动发布），批量槽位之间互不阻塞
            $lock_option = get_option( YIMAI_GSO_Autopilot_Runner::GENERATION_LOCK_OPTION, array() );
            $now         = time();

            // 兼容旧版单一锁格式
            if ( isset( $lock_option['source'] ) ) {
                $old_owner = sanitize_key( $lock_option['source'] ?? '' );
                $old_expires = absint( $lock_option['expires_at'] ?? 0 );
                if ( $old_owner && $old_expires > $now && strpos( $old_owner, 'manual_batch' ) !== 0 ) {
                    wp_send_json_error( array(
                        'message' => '当前有其他生成任务正在执行，请稍后再试。',
                        'lock_source' => $old_owner,
                    ), 409 );
                }
            } else {
                // 多槽位格式：检查是否有非批量来源的活跃锁
                foreach ( $lock_option as $key => $lock ) {
                    $expires = absint( $lock['expires_at'] ?? 0 );
                    if ( $expires > $now && strpos( $key, 'manual_batch_' ) !== 0 ) {
                        wp_send_json_error( array(
                            'message' => '当前有其他生成任务正在执行，请稍后再试。',
                            'lock_source' => $key,
                        ), 409 );
                    }
                }
            }

            // 申请本槽位的锁（批量槽位之间互不阻塞）
            $generation_lock = YIMAI_GSO_Autopilot_Runner::acquire_generation_lock( $lock_source, 15 * MINUTE_IN_SECONDS );
            if ( is_wp_error( $generation_lock ) ) {
                wp_send_json_error( array( 'message' => $generation_lock->get_error_message() ), 409 );
            }

            // 注册关闭函数，释放槽位锁
            register_shutdown_function( function() use ( $lock_source ) {
                if ( class_exists( 'YIMAI_GSO_Autopilot_Runner' ) ) {
                    YIMAI_GSO_Autopilot_Runner::release_generation_lock( $lock_source );
                }
            });
        }

        $prompt      = sanitize_textarea_field( $_POST['prompt'] ?? '' );

        $word_count  = absint( $_POST['word_count'] ?? get_option( 'yimai_gso_word_count', 1000 ) );
        $language    = sanitize_text_field( $_POST['language'] ?? 'zh_CN' );
        $image_count = absint( $_POST['image_count'] ?? 3 );
        $category_id = absint( $_POST['category_id'] ?? get_option( 'yimai_gso_default_category', 1 ) );
        $manual_tags = sanitize_text_field( $_POST['tags'] ?? '' );
        $use_ai_tags = ( $_POST['use_ai_tags'] ?? '1' ) === '1';
        $status      = sanitize_text_field( $_POST['status'] ?? 'draft' );
        $scheduled_at = sanitize_text_field( $_POST['scheduled_at'] ?? '' );

        $ai_auto_tags_available = true;

        if ( empty( $prompt ) ) {
            wp_send_json_error( array( 'message' => '提示词不能为空' ) );
        }

        $publish_config = $this->validate_batch_publish_config( $status, $scheduled_at );
        if ( is_wp_error( $publish_config ) ) {
            $this->send_batch_config_error( $publish_config );
        }

        $full_prompt = $prompt;


        // ===== 第1步：AI生成文章 =====
        $generator = new YIMAI_GSO_AI_Generator();
        // AI 调用前主动刷新单篇锁，防止 batch 处理慢时被并发任务挤掉
        if ( class_exists( 'YIMAI_GSO_Autopilot_Runner' ) ) {
            YIMAI_GSO_Autopilot_Runner::refresh_generation_lock( $lock_source, 15 * MINUTE_IN_SECONDS );
        }
        try {
            $article = $generator->generate_article( $full_prompt, $word_count, $language );
        } catch ( Throwable $exception ) {
            error_log( '[一麦GSO] 批量生成异常：' . $exception->getMessage() );
            wp_send_json_error( array( 'message' => '批量生成发生服务端异常，请查看 PHP 错误日志。', 'code' => 'batch_generation_exception' ), 500 );
        }

        if ( is_wp_error( $article ) ) {
            wp_send_json_error( array( 'message' => '文章生成失败：' . $article->get_error_message() ) );
        }


        // ===== 第2步：获取配图并嵌入正文 =====
        $content = $article['content'] ?? '';

        if ( $image_count > 0 ) {
            // 配图阶段前再次刷锁
            if ( class_exists( 'YIMAI_GSO_Autopilot_Runner' ) ) {
                YIMAI_GSO_Autopilot_Runner::refresh_generation_lock( $lock_source, 15 * MINUTE_IN_SECONDS );
            }
            $img_keywords = $article['image_keywords'] ?? $article['keywords'] ?? $prompt;
            $handler = new YIMAI_GSO_Image_Handler();
            try {
                $images = $handler->fetch_images( $img_keywords, $image_count, array(
                    'title'   => $article['title'] ?? $prompt,
                    'keyword' => $article['keywords'] ?? $prompt,
                    'excerpt' => $article['excerpt'] ?? '',
                ) );
            } catch ( Throwable $exception ) {
                // 配图服务异常不应丢弃已经生成的文章，记录后继续发布。
                error_log( '[一麦GSO] 批量配图异常：' . $exception->getMessage() );
                $images = new WP_Error( 'batch_image_exception', $exception->getMessage() );
            }

            if ( ! is_wp_error( $images ) && ! empty( $images ) ) {
                // 把图片外链嵌入正文（Publisher 会统一下载到本地并自动取第一张作为封面）
                try {
                    $content = $this->inject_images_into_content( $content, $images, array(
                        'title'    => $article['title'] ?? $prompt,
                        'keyword'  => $article['keywords'] ?? $prompt,
                        'excerpt'  => $article['excerpt'] ?? '',
                        'language' => $language,
                        'source'   => 'batch',
                    ) );
                } catch ( Throwable $exception ) {
                    error_log( '[一麦GSO] 批量图片写入异常：' . $exception->getMessage() );
                    // 正文已生成，图片写入失败时仍继续发布纯文本文章。
                }
            }

        }

        // ===== 第3步：合并标签 =====

        // 规则：
        //   - 统一标签（$manual_tags）：有填写就使用
        //   - AI 自动标签（$article['tags']）：开关打开时使用
        //   - 两者都有时合并去重
        $ai_tags_str  = $use_ai_tags ? ( $article['tags'] ?? '' ) : '';
        $merged_tags  = $this->merge_tags( $manual_tags, $ai_tags_str, $article['title'] ?? $prompt );

        // ===== 第4步：发布文章 =====
        $ai_model = get_option( 'yimai_gso_ai_model', 'deepseek' );

        $publisher = new YIMAI_GSO_Publisher();
        try {
            $result = $publisher->publish( array(
                'title'          => $article['title'] ?? $prompt,
                'content'        => $content,
                'excerpt'        => $article['excerpt'] ?? '',
                'category_id'    => $category_id,
                'tags'           => $merged_tags,
                'status'         => $status,
                'scheduled_at'   => $scheduled_at,
                'thumbnail_id'   => 0, // 由 Publisher 从正文图片自动提取
                'keywords'       => $article['keywords'] ?? '',
                'prompt'         => $prompt,
                'word_count'     => $word_count,
                'image_count'    => $image_count,
                'ai_model'       => $ai_model,
                'source'         => 'batch',
                'article_index'  => absint( $_POST['batch_index'] ?? 0 ),
                'schema_type'    => $article['schema_type'] ?? 'Article',
            ) );
        } catch ( Throwable $exception ) {
            error_log( '[一麦GSO] 批量发布异常：' . $exception->getMessage() );
            wp_send_json_error( array( 'message' => '批量发布发生服务端异常，请查看 PHP 错误日志。', 'code' => 'batch_publish_exception' ), 500 );
        }

        if ( is_wp_error( $result ) ) {
            if ( 'future' === $status && in_array( $result->get_error_code(), array( 'invalid_date', 'yimai_gso_feature_locked' ), true ) ) {
                $this->send_batch_config_error( $result );
            }

            wp_send_json_error( array( 'message' => '发布失败：' . $result->get_error_message() ) );
        }

        $result['ai_auto_tags_enabled'] = $ai_auto_tags_available;
        $result['ai_auto_tags_applied'] = $use_ai_tags;


        wp_send_json_success( $result );


    }

    /**
     * 合并用户统一标签与 AI 生成标签，去重后返回逗号分隔字符串
     *
     * 增加 $article_title 参数用于过滤标题泄漏为标签（与 build_tags 对齐）
     *
     * @param string $manual_tags   用户填写的统一标签（逗号分隔）
     * @param string $ai_tags       AI 返回的标签（逗号分隔）
     * @param string $article_title 文章标题（用于排除标题泄漏）
     * @return string
     */
    private function merge_tags( $manual_tags, $ai_tags, $article_title = '' ) {
        $all            = array();
        $article_title  = trim( (string) $article_title );

        if ( ! empty( $manual_tags ) ) {
            foreach ( array_map( 'trim', explode( ',', $manual_tags ) ) as $t ) {
                if ( $t !== '' ) $all[] = $t;
            }
        }

        if ( ! empty( $ai_tags ) ) {
            foreach ( array_map( 'trim', explode( ',', $ai_tags ) ) as $t ) {
                $t_len = mb_strlen( $t );
                // 标签长度限制 2~15 字（1 字太泛，15+ 大概率是句子/标题泄漏）
                if ( '' === $t || $t_len < 2 || $t_len > 15 ) {
                    continue;
                }
                if ( in_array( $t, $all, true ) ) {
                    continue;
                }
                // 精确匹配标题
                if ( $article_title && $t === $article_title ) {
                    continue;
                }
                // 模糊匹配：与标题相似度 >70% 则跳过
                if ( $article_title && mb_strlen( $article_title ) > 4 ) {
                    similar_text( $t, $article_title, $sim_pct );
                    if ( $sim_pct > 70 ) {
                        continue;
                    }
                }
                $all[] = $t;
            }
        }

        // 限制最多 5 个标签
        $all = array_slice( $all, 0, 5 );

        return implode( ',', $all );
    }

    /**
     * 将图片嵌入正文HTML（在h2标题后插入）
     * 注意：ALT 生成已改为 transition_post_status 钩子统一处理，此处不生成 ALT
     */
    private function inject_images_into_content( $content, $images, $article_context = array() ) {
        if ( empty( $images ) ) return $content;

        // ALT 处理移至 Publisher::download_and_replace_images() 统一处理
        // 此处只负责将图片插入到正文HTML中，避免重复调 AI 生成 ALT

        $img_index = 0;
        $total = count( $images );


        // 在每个 </h2> 后面插入一张图片
        $content = preg_replace_callback(
            '/<\/h2>/i',
            function( $match ) use ( $images, &$img_index, $total ) {
                if ( $img_index >= $total ) return $match[0];
                $img = $images[ $img_index ];
                $img_index++;
                $img_url = esc_url( $img['url'] ?? '' );
                $img_alt = esc_attr( $img['alt'] ?? '配图' );
                return $match[0] . "\n<p style=\"text-align:center;margin:20px 0;\"><img src=\"{$img_url}\" alt=\"{$img_alt}\" style=\"max-width:100%;border-radius:8px;display:inline-block;\" /></p>\n";
            },
            $content
        );

        // 剩余图片追加到文末
        if ( $img_index < $total ) {
            $content .= "\n<p style=\"text-align:center;color:#999;font-size:14px;margin-top:30px;\">—— 相关配图 ——</p>\n";
            for ( $j = $img_index; $j < $total; $j++ ) {
                $extra = $images[$j];
                $img_url = esc_url( $extra['url'] ?? '' );
                $img_alt = esc_attr( $extra['alt'] ?? '配图' );
                $content .= "<p style=\"text-align:center;margin:20px 0;\"><img src=\"{$img_url}\" alt=\"{$img_alt}\" style=\"max-width:100%;border-radius:8px;display:inline-block;\" /></p>\n";
            }
        }

        return $content;
    }

    /**
     * AJAX: 批量生成热门标题
     */
    public function ajax_generate_titles() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
        $count   = absint( $_POST['count'] ?? 20 );
        $angles  = isset( $_POST['angles'] ) && is_array( $_POST['angles'] ) ? array_map( 'sanitize_text_field', $_POST['angles'] ) : array();

        if ( empty( $keyword ) ) {
            wp_send_json_error( array( 'message' => '请输入行业关键词或主题' ) );
        }

        $generator = new YIMAI_GSO_AI_Generator();
        $titles    = $generator->generate_titles( $keyword, $count, $angles );

        if ( is_wp_error( $titles ) ) {
            wp_send_json_error( array( 'message' => $titles->get_error_message() ) );
        }

        if ( empty( $titles ) || ! is_array( $titles ) ) {
            // 不是网络错误（WP_Error），而是 AI 返回内容无法解析
            error_log( '[一麦GSO-标题生成] AJAX返回空标题，关键词=' . $keyword . ', count=' . $count );
            wp_send_json_error( array( 'message' => 'AI返回内容无法解析，请稍后重试。建议：1) 换个更具体的关键词 2) 检查API Key是否有效 3) 查看PHP错误日志(yimai-gso-optimizer)排查详情' ) );
        }

        // 保存到历史记录
        $this->save_title_to_history( $keyword, $titles );

        wp_send_json_success( array( 'titles' => $titles ) );
    }

    /**
     * 保存标题生成记录
     */
    private function save_title_to_history( $keyword, $titles ) {
        $history = get_option( 'yimai_gso_title_history', array() );
        if ( ! is_array( $history ) ) {
            $history = array();
        }

        // 新增一条记录
        $record = array(
            'keyword' => $keyword,
            'count'   => count( $titles ),
            'titles'  => $titles,
            'created' => current_time( 'mysql' ),
        );

        array_unshift( $history, $record );

        // 只保留最近50条
        $history = array_slice( $history, 0, 50 );

        update_option( 'yimai_gso_title_history', $history, false );
    }

    /**
     * AJAX: 获取标题生成历史记录
     */
    public function ajax_get_title_history() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $history = get_option( 'yimai_gso_title_history', array() );
        if ( ! is_array( $history ) ) {
            $history = array();
        }

        wp_send_json_success( array( 'history' => $history ) );
    }

    /**
     * AJAX: 删除标题生成历史记录
     */
    public function ajax_delete_title_history() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $index = absint( $_POST['index'] ?? -1 );

        if ( $index < 0 ) {
            wp_send_json_error( array( 'message' => '参数错误' ) );
        }

        $history = get_option( 'yimai_gso_title_history', array() );
        if ( ! is_array( $history ) || ! isset( $history[ $index ] ) ) {
            wp_send_json_error( array( 'message' => '记录不存在' ) );
        }

        unset( $history[ $index ] );
        $history = array_values( $history ); // 重新索引

        update_option( 'yimai_gso_title_history', $history, false );

        wp_send_json_success( array( 'message' => '已删除' ) );
    }

    /**
     * AJAX: 清空全部标题历史记录
     */
    public function ajax_clear_title_history() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        delete_option( 'yimai_gso_title_history' );

        wp_send_json_success( array( 'message' => '已清空' ) );
    }

    /**
     * AJAX: 生成记录 - 分页查询
     */
    public function ajax_history_query() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        global $wpdb;
        $page     = max( 1, absint( $_POST['page'] ?? 1 ) );
        $per_page = max( 1, min( 100, absint( $_POST['per_page'] ?? 15 ) ) );
        $search   = sanitize_text_field( $_POST['search'] ?? '' );
        $status   = sanitize_text_field( $_POST['status'] ?? '' );
        $source   = sanitize_text_field( $_POST['source'] ?? '' );
        $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $_POST['date_to'] ?? '' );
        $orderby   = sanitize_text_field( $_POST['orderby'] ?? 'date' );
        $order     = ( $_POST['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

        // 构建 meta query
        $meta_query = array(
            array(
                'key'   => 'yimai_gso_generated',
                'value' => 1,
            ),
        );

        // 构建 args
        $args = array(
            'post_type'      => 'post',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'meta_query'     => $meta_query,
            'orderby'        => $orderby,
            'order'          => $order,
        );

        // 状态筛选
        if ( ! empty( $status ) ) {
            $args['post_status'] = $status;
        } else {
            $args['post_status'] = array( 'publish', 'draft', 'future', 'pending', 'private', 'trash' );
        }

        // 来源筛选
        if ( ! empty( $source ) ) {
            $args['meta_query'][] = array(
                'key'   => 'yimai_gso_source',
                'value' => $source,
            );
        }

        // 搜索（标题）
        if ( ! empty( $search ) ) {
            $args['s'] = $search;
        }

        // 日期范围
        if ( ! empty( $date_from ) || ! empty( $date_to ) ) {
            $date_query = array();
            if ( ! empty( $date_from ) ) {
                $date_query['after']  = $date_from . ' 00:00:00';
            }
            if ( ! empty( $date_to ) ) {
                $date_query['before'] = $date_to . ' 23:59:59';
            }
            $date_query['inclusive'] = true;
            $args['date_query'] = array( $date_query );
        }

        $query = new WP_Query( $args );

        // 批量预加载所有 meta（5个key一次性读入内存，避免循环内N次查询）
        $post_ids = wp_list_pluck( $query->posts, 'ID' );
        if ( ! empty( $post_ids ) ) {
            $meta_keys = array( 'yimai_gso_generated_at', 'yimai_gso_source', 'yimai_gso_prompt', 'yimai_gso_ai_model', 'yimai_gso_word_count', 'yimai_gso_image_count' );
            global $wpdb;
            $id_list = implode( ',', array_map( 'absint', $post_ids ) );
            $key_list = "'" . implode( "','", array_map( 'sanitize_key', $meta_keys ) ) . "'";
            $meta_rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($id_list) AND meta_key IN ($key_list)" );
            // 按文章ID分组缓存
            $meta_cache = array();
            foreach ( $meta_rows as $row ) {
                $meta_cache[ $row->post_id ][ $row->meta_key ] = $row->meta_value;
            }
            unset( $meta_rows );
        }

        // 批量查询分类和标签（避免循环内逐个查）
        $cat_cache = array();
        $tag_cache = array();
        if ( ! empty( $post_ids ) ) {
            // 分类
            $cat_rows = $wpdb->get_results(
                "SELECT tr.object_id, t.name
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                 WHERE tr.object_id IN ($id_list) AND tt.taxonomy = 'category' AND t.term_id > 0"
            );
            foreach ( $cat_rows as $cr ) {
                $cat_cache[ $cr->object_id ][] = $cr->name;
            }
            unset( $cat_rows );
            // 标签
            $tag_rows = $wpdb->get_results(
                "SELECT tr.object_id, t.name
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                 WHERE tr.object_id IN ($id_list) AND tt.taxonomy = 'post_tag'"
            );
            foreach ( $tag_rows as $tr ) {
                $tag_cache[ $tr->object_id ][] = $tr->name;
            }
            unset( $tag_rows );
        }

        // 批量获取缩略图URL（避免逐个调用get_the_post_thumbnail_url）
        $thumb_cache = array();
        if ( ! empty( $post_ids ) ) {
            $thumb_rows = $wpdb->get_results(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id IN ($id_list) AND meta_key = '_thumbnail_id'"
            );
            $thumb_ids = array();
            foreach ( $thumb_rows as $tr ) {
                if ( ! empty( $tr->meta_value ) ) {
                    $thumb_ids[ $tr->post_id ] = absint( $tr->meta_value );
                }
            }
            unset( $thumb_rows );
            if ( ! empty( $thumb_ids ) ) {
                foreach ( $thumb_ids as $pid => $tid ) {
                    $thumb_cache[ $pid ] = wp_get_attachment_image_url( $tid, 'thumbnail' ) ?: wp_get_attachment_url( $tid ) ?: '';
                }
            }
        }

        // 批量生成固定链接（避免逐个调用get_permalink）
        $permalink_cache = array();
        if ( ! empty( $post_ids ) ) {
            $pl_rows = $wpdb->get_results(
                "SELECT ID, post_name, post_type FROM {$wpdb->posts} WHERE ID IN ($id_list)"
            );
            foreach ( $pl_rows as $pr ) {
                $permalink_cache[ $pr->ID ] = get_permalink( $pr );
            }
            unset( $pl_rows );
        }

        $items = array();
        foreach ( $query->posts as $post ) {
            $pid = $post->ID;
            $pm = $meta_cache[ $pid ] ?? array();

            $items[] = array(
                'id'            => $pid,
                'title'         => $post->post_title,
                'status'        => $post->post_status,
                'date'          => $post->post_date,
                'edit_url'      => admin_url( 'post.php?post=' . $pid . '&action=edit' ),
                'view_url'      => $permalink_cache[ $pid ] ?? '',
                'categories'    => $cat_cache[ $pid ] ?? array(),
                'tags'          => $tag_cache[ $pid ] ?? array(),
                'thumbnail'     => $thumb_cache[ $pid ] ?? '',
                'comment_count' => $post->comment_count,
                'generated_at'  => $pm['yimai_gso_generated_at'] ?? '',
                'source'        => $pm['yimai_gso_source'] ?? 'batch',
                'prompt'        => $pm['yimai_gso_prompt'] ?? '',
                'ai_model'      => $pm['yimai_gso_ai_model'] ?? '',
                'word_count'    => $pm['yimai_gso_word_count'] ?? '',
                'image_count'   => $pm['yimai_gso_image_count'] ?? '',
                'scheduled_at'  => $post->post_status === 'future' ? human_time_diff( strtotime( $post->post_date_gmt ), time() ) . '后发布' : '',
            );
        }

        // 直接使用第一次查询的 found_posts，无需第二次查询
        wp_send_json_success( array(
            'items'       => $items,
            'total'       => (int) $query->found_posts,
            'pages'       => (int) $query->max_num_pages,
            'page'        => $page,
            'per_page'    => $per_page,
        ) );
    }

    /**
     * AJAX: 生成记录 - 批量删除
     */
    public function ajax_history_delete() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'delete_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => '请选择要删除的记录' ) );
        }

        // 默认移入回收站（可恢复）；传 force=1 时永久删除
        $force = ! empty( $_POST['force'] );

        $deleted = 0;
        foreach ( $ids as $id ) {
            // 只删除本插件生成的文章
            if ( get_post_meta( $id, 'yimai_gso_generated', true ) == 1 ) {
                if ( wp_delete_post( $id, $force ) ) {
                    $deleted++;
                }
            }
        }

        wp_send_json_success( array(
            'message' => $force ? "已永久删除 {$deleted} 篇文章" : "已移入回收站 {$deleted} 篇文章（可在 文章→回收站 恢复）",
            'deleted' => $deleted,
        ) );
    }

    /**
     * AJAX: 内容管理 - 列出文章正文中的全部配图（换图选择器数据源）
     *
     * 返回每张图的位置序号、当前地址、缩略预览、alt、是否封面，
     * 供前端渲染「选择要更换哪一张」的图片选择弹窗。
     */
    public function ajax_history_list_images() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $post    = $post_id ? get_post( $post_id ) : null;
        if ( ! $post ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }
        if ( get_post_meta( $post_id, 'yimai_gso_generated', true ) != 1 ) {
            wp_send_json_error( array( 'message' => '仅支持插件生成的文章' ) );
        }

        $content = (string) $post->post_content;
        if ( ! preg_match_all( '/<img\s[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER ) ) {
            wp_send_json_success( array( 'items' => array(), 'count' => 0 ) );
        }

        $cover_id  = (int) get_post_thumbnail_id( $post_id );
        $cover_url = $cover_id ? (string) wp_get_attachment_url( $cover_id ) : '';

        $items = array();
        foreach ( $matches as $i => $m ) {
            $url  = html_entity_decode( $m[1], ENT_QUOTES );
            $alt  = '';
            if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $m[0], $am ) ) {
                $alt = $am[1];
            }
            // 本地图优先用中等尺寸缩略图预览，外链图原样返回
            $thumb      = $url;
            $attach_id  = attachment_url_to_postid( $url );
            if ( $attach_id > 0 ) {
                $thumb = wp_get_attachment_image_url( $attach_id, 'medium' ) ?: $url;
            }
            $items[] = array(
                'index'    => $i,
                'url'      => $url,
                'thumb'    => $thumb,
                'alt'      => $alt,
                'is_cover' => ( 0 === $i ) || ( '' !== $cover_url && $url === $cover_url ),
            );
        }

        wp_send_json_success( array( 'items' => $items, 'count' => count( $items ) ) );
    }

    /**
     * AJAX: 内容管理 - 换图（支持指定位置 / 一键全部更换）
     *
     * 参数 index：
     * - 缺省或 0 → 换正文第 1 张图并同步封面（兼容旧「一键换图」行为）
     * - 数字 N（≥1）→ 只换正文第 N+1 张图，不动封面
     * - 'all' → 全部图片逐一更换，第 1 张同步封面
     *
     * 图片源遵循现有「配图设置」；替换只改 img 标签的 src/alt 并剥离
     * srcset/懒加载属性，保留标签其余结构（class、style、包裹元素）。
     */
    public function ajax_history_change_image() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $index_raw    = isset( $_POST['index'] ) ? sanitize_text_field( wp_unslash( $_POST['index'] ) ) : '0';
        $replace_all  = ( 'all' === $index_raw );
        $target_index = $replace_all ? -1 : max( 0, absint( $index_raw ) );

        // 全部更换可能逐张 AI 生图，放宽执行时间
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( $replace_all ? 300 : 120 );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '参数错误' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }
        // 只允许操作本插件生成的文章
        if ( get_post_meta( $post_id, 'yimai_gso_generated', true ) != 1 ) {
            wp_send_json_error( array( 'message' => '仅支持插件生成的文章' ) );
        }

        $content = (string) $post->post_content;
        preg_match_all( '/<img\s[^>]*src=["\'][^"\']+["\'][^>]*>/i', $content, $img_tags );
        $total_imgs = count( $img_tags[0] );

        // 目标位置不存在（单换模式）→ 明确报错而不是悄悄换第一张
        if ( ! $replace_all && $target_index > 0 && $target_index >= $total_imgs ) {
            wp_send_json_error( array( 'message' => "文章只有 {$total_imgs} 张图，没有第 " . ( $target_index + 1 ) . " 张的位置" ) );
        }
        // 全部换但正文无图 → 按单换（首图）处理：头部插一张 + 封面
        if ( $replace_all && 0 === $total_imgs ) {
            $replace_all  = false;
            $target_index = 0;
        }

        // 换图关键词：优先文章关键词，其次标题
        $keyword = get_post_meta( $post_id, 'yimai_gso_keywords', true );
        if ( empty( $keyword ) ) {
            $keyword = $post->post_title;
        }

        require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-image-handler.php';
        $handler  = new YIMAI_GSO_Image_Handler();
        $provider = YIMAI_GSO_Settings::get( 'image_provider', 'pexels' );
        $is_ai    = in_array( $provider, array( 'ai', 'dalle' ), true );

        // 取图数量：AI 按次计费——单换只取 1 张；全部换取目标张数（上限 10）。
        // 图库 API 结果顺序固定——取 3 张靠偏移轮换；全部换时取 min(N,10) 张循环分配。
        if ( $replace_all ) {
            $fetch_count = $is_ai ? min( max( $total_imgs, 1 ), 10 ) : min( max( $total_imgs, 3 ), 10 );
        } else {
            $fetch_count = $is_ai ? 1 : 3;
        }

        $images = $handler->fetch_images( $keyword, $fetch_count, array(
            'title'   => $post->post_title,
            'keyword' => $keyword,
            'excerpt' => (string) $post->post_excerpt,
            'source'  => 'change-image',
        ) );

        if ( is_wp_error( $images ) ) {
            wp_send_json_error( array( 'message' => '取图失败：' . $images->get_error_message() ) );
        }
        $images = array_values( array_filter( $images, static function ( $img ) {
            return ! empty( $img['url'] );
        } ) );
        if ( empty( $images ) ) {
            wp_send_json_error( array( 'message' => '取图失败：图片源未返回可用图片' ) );
        }

        // 轮换偏移：单换在同一批候选里循环取用；全部换作为整体分配起点
        $offset = (int) get_post_meta( $post_id, 'yimai_gso_change_image_offset', true );
        update_post_meta( $post_id, 'yimai_gso_change_image_offset', $offset + 1 );

        $pick_index = $replace_all ? $offset : ( $offset % count( $images ) );

        // 为每个要换的位置预上传一张图（全部换=每个位置一张；单换=一张）
        $positions = $replace_all ? range( 0, $total_imgs - 1 ) : array( $target_index );
        $new_map   = array(); // position => [attach_id, url, alt]
        foreach ( $positions as $pos ) {
            $candidate = $images[ $pick_index % count( $images ) ];
            $pick_index++;

            $alt_text  = ! empty( $candidate['alt'] ) ? $candidate['alt'] : $post->post_title;
            $attach_id = $handler->upload_image_to_media( $candidate['url'], '', $alt_text, 60 );
            if ( is_wp_error( $attach_id ) || $attach_id <= 0 ) {
                // 全部换模式下单张失败跳过该位置，其余位置继续；单换模式直接报错
                if ( ! $replace_all ) {
                    $err_msg = is_wp_error( $attach_id ) ? $attach_id->get_error_message() : '上传媒体库失败';
                    wp_send_json_error( array( 'message' => '图片入库失败：' . $err_msg ) );
                }
                continue;
            }
            $new_map[ $pos ] = array(
                'attach_id' => (int) $attach_id,
                'url'       => (string) wp_get_attachment_url( $attach_id ),
                'alt'       => $alt_text,
            );
        }
        if ( empty( $new_map ) ) {
            wp_send_json_error( array( 'message' => '图片入库失败：所有候选图均上传失败' ) );
        }

        // 替换正文 img 标签：只改 src/alt，剥离 srcset/sizes/懒加载属性，保留其余结构
        $counter = 0;
        $content = preg_replace_callback(
            '/<img\s[^>]*src=["\'][^"\']+["\'][^>]*>/i',
            static function ( $m ) use ( &$counter, $new_map ) {
                $pos = $counter++;
                if ( ! isset( $new_map[ $pos ] ) ) {
                    return $m[0]; // 该位置不换
                }
                $tag = $m[0];
                // 剥离响应式与懒加载属性（指向旧图，残留会盖过新 src）
                $tag = preg_replace( '/\s(srcset|sizes|data-src|data-lazy-src|data-original|data-lazy)=["\'][^"\']*["\']/i', '', $tag );
                $tag = preg_replace( '/\ssrc=["\'][^"\']*["\']/i', ' src="' . esc_url( $new_map[ $pos ]['url'] ) . '"', $tag, 1 );
                if ( preg_match( '/\salt=["\'][^"\']*["\']/i', $tag ) ) {
                    $tag = preg_replace( '/\salt=["\'][^"\']*["\']/i', ' alt="' . esc_attr( $new_map[ $pos ]['alt'] ) . '"', $tag, 1 );
                } else {
                    $tag = preg_replace( '/^<img\s/i', '<img alt="' . esc_attr( $new_map[ $pos ]['alt'] ) . '" ', $tag, 1 );
                }
                return $tag;
            },
            $content
        );

        // 单换首图且正文原本无图：头部插入新图
        if ( ! $replace_all && isset( $new_map[ $target_index ] ) && 0 === $total_imgs ) {
            $img = $new_map[ $target_index ];
            $content = '<p><img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['alt'] ) . '" /></p>' . "\n" . $content;
        }

        wp_update_post( array(
            'ID'           => $post_id,
            'post_content' => $content,
        ) );

        // 封面同步：换了第 1 张（或全部换）时更新缩略图与子比主题字段
        $results = array();
        if ( isset( $new_map[0] ) ) {
            set_post_thumbnail( $post_id, $new_map[0]['attach_id'] );
            update_post_meta( $post_id, 'zib_post_image', $new_map[0]['attach_id'] );
        }
        foreach ( $new_map as $pos => $img ) {
            $results[] = array(
                'index'     => $pos,
                'thumb_url' => wp_get_attachment_image_url( $img['attach_id'], 'medium' ) ?: $img['url'],
                'image_url' => $img['url'],
            );
        }

        // 图片计数累加（保留原有计数，仅追加）
        $old_count = (int) get_post_meta( $post_id, 'yimai_gso_image_count', true );
        update_post_meta( $post_id, 'yimai_gso_image_count', $old_count + count( $new_map ) );

        wp_send_json_success( array(
            'message'    => $replace_all ? '已更换全部 ' . count( $new_map ) . ' 张配图' : '已更换第 ' . ( $target_index + 1 ) . ' 张配图',
            'results'    => $results,
            'replaced'   => count( $new_map ),
        ) );
    }

    /**
     * AJAX: 生成记录 - 统计数据
     */
    public function ajax_history_stats() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        global $wpdb;

        // 用一次查询获取所有统计数据（6合1），减少数据库压力
        $today = current_time( 'Y-m-d' );
        $week_start = current_time( 'Y-m-d' );
        // 用 WP 本地时间计算本周一
        $week_ts = current_time( 'timestamp' );
        $week_start = gmdate( 'Y-m-d', strtotime( 'monday this week', $week_ts ) );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN DATE(p.post_date) = %s THEN 1 ELSE 0 END) AS today_count,
                SUM(CASE WHEN DATE(p.post_date) >= %s THEN 1 ELSE 0 END) AS week_count,
                SUM(CASE WHEN p.post_status = 'publish' THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN p.post_status = 'draft' THEN 1 ELSE 0 END) AS drafts,
                SUM(CASE WHEN p.post_status = 'future' THEN 1 ELSE 0 END) AS scheduled
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'yimai_gso_generated' AND pm.meta_value = '1'
             AND p.post_type = 'post' AND p.post_status != 'trash'",
            $today, $week_start
        ) );

        // 添加 transient 缓存（5分钟），避免频繁刷新时重复查询
        $stats = get_transient( 'yimai_gso_history_stats' );
        if ( false === $stats || ! isset( $stats['_ts'] ) || ( time() - $stats['_ts'] ) > 300 ) {
            $stats = array(
                'total'     => (int) ( $row->total ?? 0 ),
                'today'     => (int) ( $row->today_count ?? 0 ),
                'week'      => (int) ( $row->week_count ?? 0 ),
                'published' => (int) ( $row->published ?? 0 ),
                'drafts'    => (int) ( $row->drafts ?? 0 ),
                'scheduled' => (int) ( $row->scheduled ?? 0 ),
                '_ts'       => time(),
            );
            set_transient( 'yimai_gso_history_stats', $stats, 5 * MINUTE_IN_SECONDS );
        }

        wp_send_json_success( array(
            'total'     => $stats['total'],
            'today'     => $stats['today'],
            'week'      => $stats['week'],
            'published' => $stats['published'],
            'drafts'    => $stats['drafts'],
            'scheduled' => $stats['scheduled'],
        ) );
    }

    /**
     * AJAX: 获取AI摘要页面的文章列表（按有无摘要筛选）
     */
    public function ajax_excerpt_fetch_posts() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $keyword    = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
        $cat_id     = absint( $_POST['category_id'] ?? 0 );
        $status     = sanitize_text_field( $_POST['post_status'] ?? '' );
        $excerpt_filter = sanitize_text_field( $_POST['excerpt_filter'] ?? 'empty' ); // empty=无摘要, all=全部, has=有摘要
        $page       = max( 1, absint( $_POST['page'] ?? 1 ) );
        $per_page   = 20;

        $args = array(
            'post_type'      => 'post',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_status'    => $status && $status !== 'any' ? $status : 'any',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        if ( ! empty( $keyword ) ) {
            $args['s'] = $keyword;
        }
        if ( $cat_id > 0 ) {
            $args['cat'] = $cat_id;
        }

        // 按摘要状态筛选（直接用 post_excerpt 字段，不依赖自定义 meta）
        $excerpt_filter_applied = false;
        if ( 'empty' === $excerpt_filter ) {
            add_filter( 'posts_where', array( $this, 'filter_posts_where_excerpt_empty' ) );
            $excerpt_filter_applied = true;
        } elseif ( 'has' === $excerpt_filter ) {
            add_filter( 'posts_where', array( $this, 'filter_posts_where_excerpt_has' ) );
            $excerpt_filter_applied = true;
        }

        $query = new WP_Query( $args );

        // 精确清理本插件添加的 posts_where 过滤器，避免影响其他插件
        if ( $excerpt_filter_applied ) {
            remove_filter( 'posts_where', array( $this, 'filter_posts_where_excerpt_empty' ) );
            remove_filter( 'posts_where', array( $this, 'filter_posts_where_excerpt_has' ) );
        }

        $posts = array();

        foreach ( $query->posts as $p ) {
            $has_excerpt = ! empty( $p->post_excerpt );
            $cat_names = array();
            $cats = get_the_category( $p->ID );
            if ( $cats ) {
                foreach ( $cats as $cat ) {
                    $cat_names[] = $cat->name;
                }
            }
            $posts[] = array(
                'id'           => $p->ID,
                'title'        => $p->post_title,
                'status'       => $p->post_status,
                'date'         => mysql2date( 'Y-m-d H:i', $p->post_date ),
                'word_count'   => mb_strlen( preg_replace( '/\s+/', '', wp_strip_all_tags( $p->post_content ) ) ),
                'category'     => implode( ', ', $cat_names ),
                'has_excerpt'  => $has_excerpt,
                'excerpt_preview' => $has_excerpt ? mb_substr( $p->post_excerpt, 0, 60 ) : '',
            );
        }

        wp_send_json_success( array(
            'posts' => $posts,
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
            'page'  => $page,
        ) );
    }

    /**
     * AJAX: 单篇生成AI摘要
     */
    public function ajax_generate_excerpt() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '无效的文章ID' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        $generator = new YIMAI_GSO_AI_Generator();
        $excerpt   = $generator->generate_excerpt( $post );

        if ( is_wp_error( $excerpt ) ) {
            wp_send_json_error( array( 'message' => $excerpt->get_error_message(), 'post_id' => $post_id ) );
        }

        // 写入 post_excerpt
        $updated = wp_update_post( array(
            'ID'           => $post_id,
            'post_excerpt' => $excerpt,
        ), true );

        if ( is_wp_error( $updated ) ) {
            wp_send_json_error( array( 'message' => '摘要保存失败：' . $updated->get_error_message(), 'post_id' => $post_id ) );
        }

        // 同步到 SEO description 字段
        YIMAI_GSO_Publisher::write_seo_fields( $post_id, '', $excerpt );

        // 标记已有摘要
        update_post_meta( $post_id, 'yimai_gso_has_excerpt', 1 );

        wp_send_json_success( array(
            'post_id'   => $post_id,
            'title'     => $post->post_title,
            'excerpt'   => $excerpt,
            'char_count'=> mb_strlen( $excerpt ),
        ) );
    }

    /**
     * AJAX: 批量生成AI摘要（逐篇模式，前端循环调用）
     */
    public function ajax_batch_generate_excerpt() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '无效的文章ID' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        if ( empty( $post->post_content ) ) {
            wp_send_json_error( array( 'message' => '文章内容为空，无法生成摘要' ) );
        }

        $generator = new YIMAI_GSO_AI_Generator();
        $excerpt   = $generator->generate_excerpt( $post );

        if ( is_wp_error( $excerpt ) ) {
            wp_send_json_error( array( 'message' => $excerpt->get_error_message(), 'post_id' => $post_id ) );
        }

        // 写入 post_excerpt
        wp_update_post( array(
            'ID'           => $post_id,
            'post_excerpt' => $excerpt,
        ), true );

        // 同步 SEO description
        YIMAI_GSO_Publisher::write_seo_fields( $post_id, '', $excerpt );

        // 标记已有摘要
        update_post_meta( $post_id, 'yimai_gso_has_excerpt', 1 );

        wp_send_json_success( array(
            'post_id'    => $post_id,
            'title'      => $post->post_title,
            'excerpt'    => $excerpt,
            'char_count' => mb_strlen( $excerpt ),
        ) );
    }

    /**
     * AJAX: 数据看板统计
     */
    public function ajax_dashboard_stats() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        // 2 分钟缓存：避免高频刷新 Dashboard 产生 6 次重复 SQL 查询
        $cache_key = 'yimai_gso_dashboard_stats';
        $cached    = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            wp_send_json_success( $cached );
        }

        global $wpdb;
        $today       = current_time( 'Y-m-d' );
        $now_ts      = current_time( 'timestamp' );
        $yesterday   = gmdate( 'Y-m-d', strtotime( '-1 day', $now_ts ) );
        $week_start  = gmdate( 'Y-m-d', strtotime( '-7 days', $now_ts ) );
        $month_start = gmdate( 'Y-m-d', strtotime( '-30 days', $now_ts ) );

        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN DATE(p.post_date) = %s THEN 1 ELSE 0 END) AS today_count,
                SUM(CASE WHEN DATE(p.post_date) = %s THEN 1 ELSE 0 END) AS yesterday_count,
                SUM(CASE WHEN DATE(p.post_date) >= %s THEN 1 ELSE 0 END) AS week_count,
                SUM(CASE WHEN DATE(p.post_date) >= %s THEN 1 ELSE 0 END) AS month_count,
                SUM(CASE WHEN p.post_status = 'publish' THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN p.post_status = 'draft' THEN 1 ELSE 0 END) AS drafts,
                SUM(CASE WHEN p.post_status = 'future' THEN 1 ELSE 0 END) AS scheduled
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'yimai_gso_generated' AND pm.meta_value = '1'
               AND p.post_type = 'post' AND p.post_status != 'trash'",
            $today, $yesterday, $week_start, $month_start
        ), ARRAY_A );

        $stats = array_map( function( $v ) { return absint( $v ); }, $stats );

        // 按来源分布
        $source_stats = $wpdb->get_results(
            "SELECT pm2.meta_value AS source, COUNT(*) AS cnt
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm.post_id AND pm2.meta_key = 'yimai_gso_source'
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'yimai_gso_generated' AND pm.meta_value = '1'
               AND p.post_type = 'post' AND p.post_status != 'trash'
             GROUP BY pm2.meta_value ORDER BY cnt DESC",
            ARRAY_A
        );
        $source_map = array( 'batch' => '批量生成', 'single' => '单篇生成', 'manual' => '手动发布', 'autopilot' => '永动发布' );
        $sources = array();
        foreach ( $source_stats as $row ) {
            $key = $row['source'] ?: 'other';
            $sources[] = array( 'key' => $key, 'label' => $source_map[ $key ] ?? $key, 'count' => absint( $row['cnt'] ) );
        }

        // 最近7天趋势
        $trend = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(p.post_date) AS day_key, COUNT(*) AS total
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'yimai_gso_generated' AND pm.meta_value = '1'
               AND p.post_type = 'post' AND p.post_status != 'trash' AND DATE(p.post_date) >= %s
             GROUP BY DATE(p.post_date) ORDER BY day_key ASC",
            $week_start
        ), ARRAY_A );
        $trend_data = array();
        foreach ( $trend as $row ) {
            $trend_data[] = array( 'date' => $row['day_key'], 'count' => absint( $row['total'] ) );
        }

        // 最近5篇文章
        $recent = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_status, p.post_date,
                    pm_s.meta_value AS source, pm_wc.meta_value AS word_count
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             LEFT JOIN {$wpdb->postmeta} pm_s ON pm_s.post_id = p.ID AND pm_s.meta_key = 'yimai_gso_source'
             LEFT JOIN {$wpdb->postmeta} pm_wc ON pm_wc.post_id = p.ID AND pm_wc.meta_key = 'yimai_gso_word_count'
             WHERE pm.meta_key = 'yimai_gso_generated' AND pm.meta_value = '1'
               AND p.post_type = 'post' AND p.post_status != 'trash'
             ORDER BY p.post_date DESC LIMIT 5",
            ARRAY_A
        );
        $recent_posts = array();
        $st_labels = array( 'publish' => '已发布', 'draft' => '草稿', 'future' => '定时中', 'pending' => '待审核' );
        foreach ( $recent as $row ) {
            $recent_posts[] = array(
                'id' => absint( $row['ID'] ), 'title' => $row['post_title'],
                'status' => $st_labels[ $row['post_status'] ] ?? $row['post_status'],
                'source' => $source_map[ $row['source'] ] ?? ( $row['source'] ?: '其他' ),
                'date' => mysql2date( 'm-d H:i', $row['post_date'] ),
                'word_count' => absint( $row['word_count'] ),
            );
        }

        // 今日发布时段
        $hourly = $wpdb->get_results( $wpdb->prepare(
            "SELECT HOUR(p.post_date) AS hour_value, COUNT(*) AS total
             FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'yimai_gso_generated' AND pm.meta_value = '1'
               AND p.post_type = 'post' AND p.post_status != 'trash' AND DATE(p.post_date) = %s
             GROUP BY HOUR(p.post_date)", $today
        ), ARRAY_A );
        $hourly_data = array_fill( 0, 24, 0 );
        foreach ( $hourly as $row ) {
            $hourly_data[ intval( $row['hour_value'] ) ] = absint( $row['total'] );
        }

        // AI模型统计
        $model_stats = $wpdb->get_results(
            "SELECT pm.meta_value AS model, COUNT(*) AS cnt
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm.post_id
             WHERE pm.meta_key = 'yimai_gso_ai_model' AND pm2.meta_key = 'yimai_gso_generated'
               AND pm2.meta_value = '1' AND pm.meta_value != ''
             GROUP BY pm.meta_value ORDER BY cnt DESC LIMIT 5", ARRAY_A
        );
        $models = array();
        foreach ( $model_stats as $row ) {
            $models[] = array( 'model' => $row['model'], 'count' => absint( $row['cnt'] ) );
        }

        $result = array(
            'stats' => $stats, 'sources' => $sources, 'trend' => $trend_data,
            'recent_posts' => $recent_posts, 'hourly' => $hourly_data, 'models' => $models,
        );

        // 缓存结果 2 分钟
        set_transient( $cache_key, $result, 2 * MINUTE_IN_SECONDS );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: 搜索当前用户的文章（改写页"选择文章"标签用）
     */
    public function ajax_search_posts() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $keyword   = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? $_POST['search'] ?? '' ) );
        $page      = max( 1, intval( $_POST['page'] ?? 1 ) );
        $per_page  = min( 50, max( 1, intval( $_POST['per_page'] ?? 10 ) ) );
        $category  = intval( $_POST['category'] ?? 0 );

        $args = array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        if ( ! empty( $keyword ) ) {
            $args['s'] = $keyword;
        }

        if ( $category > 0 ) {
            $args['cat'] = $category;
        }

        $query = new WP_Query( $args );
        $posts = array();

        foreach ( $query->posts as $p ) {
            $content = $p->post_content;
            // 纯文本预览（前150字）
            $plain = wp_strip_all_tags( $content );
            if ( mb_strlen( $plain ) > 150 ) {
                $plain = mb_substr( $plain, 0, 150 ) . '...';
            }

            $status_map = array(
                'publish' => '已发布',
                'draft'   => '草稿',
                'pending' => '待审核',
                'private' => '私密',
            );
            $cats = array();
            $terms = get_the_category( $p->ID );
            foreach ( $terms as $t ) {
                $cats[] = $t->name;
            }

            $posts[] = array(
                'id'           => $p->ID,
                'title'        => $p->post_title,
                'date'         => get_the_date( 'Y-m-d H:i', $p->ID ),
                'post_date'    => $p->post_date,      // WP原始日期（供改写等模块使用）
                'status'       => $status_map[ $p->post_status ] ?? $p->post_status,
                'post_status'  => $p->post_status,     // WP原始状态slug（供改写等模块使用）
                'word_count'   => mb_strlen( wp_strip_all_tags( $content ) ),
                'preview'      => $plain,
                'categories'   => implode( ', ', $cats ),
                'content'      => $content, // 完整内容（含HTML）
                // 标题结构分析缓存数据（持久化显示）
                'ha_score'     => null,
                'ha_h2_count'  => null,
                'ha_kw_rate'   => null,
            );
        }

        // 批量查询标题分析缓存
        $post_ids = array_column( $posts, 'id' );
        if ( ! empty( $post_ids ) ) {
            global $wpdb;
            $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $sql = $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key = %s",
                ...array_merge( $post_ids, array( 'yimai_gso_heading_analysis' ) )
            );
            $meta_rows = $wpdb->get_results( $sql );
            $ha_cache = array();
            foreach ( $meta_rows as $row ) {
                $val = maybe_unserialize( $row->meta_value );
                if ( is_array( $val ) ) {
                    $ha_cache[ $row->post_id ] = array(
                        'score'    => isset( $val['score'] ) ? intval( $val['score'] ) : null,
                        'h2_count' => isset( $val['h2_structure']['count'] ) ? intval( $val['h2_structure']['count'] ) : null,
                        'kw_rate'  => isset( $val['kw_coverage']['rate'] ) ? intval( $val['kw_coverage']['rate'] ) : null,
                    );
                }
            }
            // 回填缓存数据
            foreach ( $posts as &$p ) {
                if ( isset( $ha_cache[ $p['id'] ] ) ) {
                    $p['ha_score']    = $ha_cache[ $p['id'] ]['score'];
                    $p['ha_h2_count'] = $ha_cache[ $p['id'] ]['h2_count'];
                    $p['ha_kw_rate']  = $ha_cache[ $p['id'] ]['kw_rate'];
                }
            }
            unset( $p );
        }

        wp_send_json_success( array(
            'posts'       => $posts,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
            'page'        => $page,
        ) );
    }

    /**
     * AJAX: 替换已有文章的标题和正文（改写后覆盖原文）
     */
    public function ajax_update_post() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $title   = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );

        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        // 安全校验：只能修改自己的文章
        $post = get_post( $post_id );
        if ( $post->post_author != get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
            wp_send_json_error( array( 'message' => '无权修改此文章' ) );
        }

        if ( empty( $title ) ) {
            wp_send_json_error( array( 'message' => '标题不能为空' ) );
        }

        $updated = wp_update_post( array(
            'ID'           => $post_id,
            'post_title'   => $title,
            'post_content' => $content,
        ), true );

        if ( is_wp_error( $updated ) ) {
            wp_send_json_error( array( 'message' => $updated->get_error_message() ) );
        }

        wp_send_json_success( array(
            'post_id'   => $post_id,
            'title'     => $title,
            'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
            'post_url'  => get_permalink( $post_id ),
        ) );
    }

    // ======================== 内容改写 AJAX ========================

    /**
     * AJAX: 粘贴原文改写
     */
    public function ajax_rewrite_text() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $source_text = wp_unslash( $_POST['source_text'] ?? '' );
        $strength    = sanitize_text_field( wp_unslash( $_POST['strength'] ?? 'medium' ) );
        $strength    = in_array( $strength, array( 'light', 'medium', 'deep' ), true ) ? $strength : 'medium';
        $style       = sanitize_text_field( wp_unslash( $_POST['style'] ?? 'default' ) );
        $style       = in_array( $style, array( 'default', 'professional', 'casual', 'marketing', 'academic' ), true ) ? $style : 'default';
        $keywords    = sanitize_text_field( wp_unslash( $_POST['keywords'] ?? '' ) );
        $fields      = isset( $_POST['fields'] ) && is_array( $_POST['fields'] )
            ? array_map( 'sanitize_text_field', $_POST['fields'] )
            : array();

        if ( empty( trim( $source_text ) ) ) {
            wp_send_json_error( array( 'message' => '原文内容不能为空' ) );
        }

        $generator = new YIMAI_GSO_AI_Generator();

        try {
            $result = $generator->rewrite_article( $source_text, '', $strength, $style, $keywords, $fields );
        } catch ( Throwable $e ) {
            wp_send_json_error( array( 'message' => '改写异常：' . $e->getMessage() ) );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // 确保正文内容不为空
        if ( empty( trim( $result['content'] ?? '' ) ) ) {
            wp_send_json_error( array( 'message' => 'AI 返回的正文内容为空，请更换改写力度或检查 API 设置后重试' ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: 单篇文章翻新改写
     * overwrite=true 时直接覆盖原文，否则仅返回结果供预览
     */
    public function ajax_rewrite_post() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id   = intval( $_POST['post_id'] ?? 0 );
        $strength  = sanitize_text_field( wp_unslash( $_POST['strength'] ?? 'medium' ) );
        $strength  = in_array( $strength, array( 'light', 'medium', 'deep' ), true ) ? $strength : 'medium';
        $overwrite = ! empty( $_POST['overwrite'] );

        if ( ! $post_id || ! ( $post = get_post( $post_id ) ) ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        // 安全校验：只能修改自己的文章
        if ( $post->post_author != get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
            wp_send_json_error( array( 'message' => '无权修改此文章' ) );
        }

        $source_text = $post->post_content;
        $source_title = $post->post_title;

        $generator = new YIMAI_GSO_AI_Generator();

        try {
            $result = $generator->rewrite_article( $source_text, $source_title, $strength );
        } catch ( Throwable $e ) {
            wp_send_json_error( array( 'message' => '改写异常：' . $e->getMessage() ) );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // 校验改写结果不为空
        if ( empty( trim( $result['content'] ?? '' ) ) ) {
            wp_send_json_error( array( 'message' => 'AI 返回的正文内容为空，请更换改写力度或检查 API 设置后重试' ) );
        }

        // 如果要求直接覆盖原文
        $updated_post_id = 0;
        if ( $overwrite ) {
            $updated = wp_update_post( array(
                'ID'           => $post_id,
                'post_title'   => $result['title'],
                'post_content' => $result['content'],
                'post_excerpt' => $result['excerpt'],
            ), true );

            if ( is_wp_error( $updated ) ) {
                wp_send_json_error( array( 'message' => '写入失败：' . $updated->get_error_message() ) );
            }

            $updated_post_id = $post_id;

            // 标记为 AI 生成
            update_post_meta( $post_id, 'yimai_gso_generated', '1' );
            update_post_meta( $post_id, 'yimai_gso_source', 'rewrite' );

            // 记录改写日志
            $this->append_rewrite_log( array(
                'post_id'  => $post_id,
                'mode'     => 'single',
                'strength' => $strength,
                'status'   => 'success',
            ) );
        }

        wp_send_json_success( array(
            'title'       => $result['title'],
            'content'     => $result['content'],
            'excerpt'     => $result['excerpt'],
            'tags'        => $result['tags'],
            'post_id'     => $post_id,
            'overwritten' => $overwrite,
            'edit_url'    => $overwrite ? get_edit_post_link( $post_id, 'raw' ) : '',
        ) );
    }

    /**
     * AJAX: 批量翻新改写（逐篇串行）
     * 前端逐篇请求，每篇完成后返回进度
     */
    public function ajax_batch_rewrite() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        // 用户角色权限检查（必须具有编辑文章能力）
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_id   = intval( $_POST['post_id'] ?? 0 );
        $strength  = sanitize_text_field( wp_unslash( $_POST['strength'] ?? 'medium' ) );
        $strength  = in_array( $strength, array( 'light', 'medium', 'deep' ), true ) ? $strength : 'medium';
        $index     = intval( $_POST['index'] ?? 0 );
        $total     = intval( $_POST['total'] ?? 1 );

        if ( ! $post_id || ! ( $post = get_post( $post_id ) ) ) {
            wp_send_json_error( array( 'message' => "文章 #{$post_id} 不存在" ) );
        }

        // 安全校验
        if ( $post->post_author != get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
            wp_send_json_error( array( 'message' => "无权修改文章 #{$post_id}" ) );
        }

        $start_time = microtime( true );
        $generator  = new YIMAI_GSO_AI_Generator();

        try {
            $result = $generator->rewrite_article( $post->post_content, $post->post_title, $strength );
        } catch ( Throwable $e ) {
            $this->append_rewrite_log( array(
                'post_id'  => $post_id,
                'mode'     => 'batch',
                'strength' => $strength,
                'status'   => 'failed',
                'error'    => '改写异常：' . $e->getMessage(),
            ) );
            wp_send_json_success( array(
                'post_id' => $post_id,
                'title'   => $post->post_title,
                'status'  => 'failed',
                'error'   => '改写异常：' . $e->getMessage(),
                'time'    => round( microtime( true ) - $start_time, 1 ),
                'index'   => $index,
                'total'   => $total,
            ) );
        }

        if ( is_wp_error( $result ) ) {
            $this->append_rewrite_log( array(
                'post_id'  => $post_id,
                'mode'     => 'batch',
                'strength' => $strength,
                'status'   => 'failed',
                'error'    => $result->get_error_message(),
            ) );
            wp_send_json_success( array(
                'post_id' => $post_id,
                'title'   => $post->post_title,
                'status'  => 'failed',
                'error'   => $result->get_error_message(),
                'time'    => round( microtime( true ) - $start_time, 1 ),
                'index'   => $index,
                'total'   => $total,
            ) );
        }

        // 校验改写结果不为空
        if ( empty( trim( $result['content'] ?? '' ) ) ) {
            $this->append_rewrite_log( array(
                'post_id'  => $post_id,
                'mode'     => 'batch',
                'strength' => $strength,
                'status'   => 'failed',
                'error'    => 'AI 返回的正文内容为空',
            ) );
            wp_send_json_success( array(
                'post_id' => $post_id,
                'title'   => $post->post_title,
                'status'  => 'failed',
                'error'   => 'AI 返回的正文内容为空，请更换改写力度或检查 API 设置后重试',
                'time'    => round( microtime( true ) - $start_time, 1 ),
                'index'   => $index,
                'total'   => $total,
            ) );
        }

        // 直接覆盖原文
        $updated = wp_update_post( array(
            'ID'           => $post_id,
            'post_title'   => $result['title'],
            'post_content' => $result['content'],
            'post_excerpt' => $result['excerpt'],
        ), true );

        if ( is_wp_error( $updated ) ) {
            $error_msg = $updated->get_error_message();
            $this->append_rewrite_log( array(
                'post_id'  => $post_id,
                'mode'     => 'batch',
                'strength' => $strength,
                'status'   => 'failed',
                'error'    => "写入失败：{$error_msg}",
            ) );
            wp_send_json_success( array(
                'post_id' => $post_id,
                'title'   => $post->post_title,
                'status'  => 'failed',
                'error'   => "写入失败：{$error_msg}",
                'time'    => round( microtime( true ) - $start_time, 1 ),
                'index'   => $index,
                'total'   => $total,
            ) );
        }

        update_post_meta( $post_id, 'yimai_gso_generated', '1' );
        update_post_meta( $post_id, 'yimai_gso_source', 'rewrite' );

        $elapsed = round( microtime( true ) - $start_time, 1 );

        // 记录改写日志
        $this->append_rewrite_log( array(
            'post_id'  => $post_id,
            'mode'     => 'batch',
            'strength' => $strength,
            'status'   => 'success',
            'time'     => $elapsed,
        ) );

        wp_send_json_success( array(
            'post_id'    => $post_id,
            'title'      => $result['title'],
            'old_title'  => $post->post_title,
            'status'     => 'success',
            'time'       => $elapsed,
            'index'      => $index,
            'total'      => $total,
            'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
        ) );
    }

    /**
     * AJAX: 粘贴改写 → 存草稿
     */
    public function ajax_rewrite_save_draft() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $title    = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $content  = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );
        $excerpt  = sanitize_textarea_field( wp_unslash( $_POST['excerpt'] ?? '' ) );
        $tags     = sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) );
        $category = intval( $_POST['category'] ?? 0 );

        if ( empty( $title ) || empty( $content ) ) {
            wp_send_json_error( array( 'message' => '标题和内容不能为空' ) );
        }

        // 默认分类
        if ( $category < 1 ) {
            $category = (int) YIMAI_GSO_Settings::get( 'default_category', 1 );
        }

        $post_data = array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => 'draft',
            'post_type'    => 'post',
            'post_category' => array( $category ),
        );

        // 处理标签
        if ( ! empty( $tags ) ) {
            $tag_array = array_map( 'trim', explode( ',', $tags ) );
            $tag_ids   = array();
            foreach ( $tag_array as $tag_name ) {
                if ( ! empty( $tag_name ) ) {
                    $term = term_exists( $tag_name, 'post_tag' );
                    if ( ! $term ) {
                        $term = wp_insert_term( $tag_name, 'post_tag' );
                    }
                    if ( ! is_wp_error( $term ) ) {
                        $tag_ids[] = (int) $term['term_id'];
                    }
                }
            }
            $post_data['tax_input'] = array( 'post_tag' => $tag_ids );
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => '保存草稿失败：' . $post_id->get_error_message() ) );
        }

        // 标记为 AI 生成 + 改写来源
        update_post_meta( $post_id, 'yimai_gso_generated', '1' );
        update_post_meta( $post_id, 'yimai_gso_source', 'rewrite_paste' );

        // 记录改写日志
        $this->append_rewrite_log( array(
            'post_id'  => $post_id,
            'mode'     => 'paste_draft',
            'strength' => sanitize_text_field( wp_unslash( $_POST['strength'] ?? 'medium' ) ),
            'status'   => 'success',
        ) );

        wp_send_json_success( array(
            'post_id'  => $post_id,
            'edit_url' => get_edit_post_link( $post_id, 'raw' ),
            'title'    => $title,
        ) );
    }

    /**
     * 记录改写日志
     */
    private function append_rewrite_log( $entry ) {
        $logs = get_option( 'yimai_gso_rewrite_logs', array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        $entry['time']    = current_time( 'mysql' );
        $entry['user_id'] = get_current_user_id();

        array_unshift( $logs, $entry );
        $logs = array_slice( $logs, 0, 100 ); // 最多保留100条
        update_option( 'yimai_gso_rewrite_logs', $logs, false );
    }

    /**
     * AJAX: 永动发布开关切换
     */
    public function ajax_autopilot_toggle() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        if ( ! class_exists( 'YIMAI_GSO_Autopilot_Plan' ) ) {
            wp_send_json_error( array( 'message' => '永动发布模块未加载' ) );
        }

        $enabled = ! empty( $_POST['enabled'] );
        
        // 获取当前设置
        $settings = YIMAI_GSO_Autopilot_Plan::get_settings();
        
        // 只更新 enabled 字段
        $settings['enabled'] = $enabled ? 1 : 0;
        
        YIMAI_GSO_Autopilot_Plan::save_settings( $settings );

        // 同步 cron 调度
        if ( class_exists( 'YIMAI_GSO_Autopilot_Scheduler' ) ) {
            YIMAI_GSO_Autopilot_Scheduler::sync_schedule();
        }

        wp_send_json_success( array(
            'enabled' => $enabled,
            'message' => $enabled ? '永动发布已开启' : '永动发布已关闭',
        ) );
    }

    /**
     * posts_where 过滤器：筛选无摘要的文章（excerpt_filter=empty）
     * 由 ajax_excerpt_fetch_posts() 动态添加/移除
     */
    public function filter_posts_where_excerpt_empty( $where ) {
        global $wpdb;
        return $where . " AND {$wpdb->posts}.post_excerpt = ''";
    }

    /**
     * posts_where 过滤器：筛选有摘要的文章（excerpt_filter=has）
     * 由 ajax_excerpt_fetch_posts() 动态添加/移除
     */
    public function filter_posts_where_excerpt_has( $where ) {
        global $wpdb;
        return $where . " AND {$wpdb->posts}.post_excerpt != ''";
    }
}

// ─────────── 卸载钩子 ───────────

/**
 * 插件卸载时清理所有数据
 * 由 register_uninstall_hook 调用，仅在用户从后台删除插件时触发
 * 支持单站点和多站点（Multisite）环境
 */
function yimai_gso_uninstall() {
    if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        exit;
    }

    global $wpdb;

    // 多站点环境：遍历所有子站点清理数据
    if ( is_multisite() && function_exists( 'get_sites' ) ) {
        $sites = get_sites( array( 'number' => 0 ) );
        foreach ( $sites as $site ) {
            switch_to_blog( $site->blog_id );
            yimai_gso_uninstall_site_data();
            restore_current_blog();
        }
    } else {
        yimai_gso_uninstall_site_data();
    }

    // 清理缓存
    wp_cache_flush();
}

/**
 * 清理单个站点的插件数据（单站点或多站点子站点通用）
 */
function yimai_gso_uninstall_site_data() {
    global $wpdb;

    // 删除自定义表（D1 修复）
    //
    // 原清单有两类缺陷：
    //   1. 写了 2 张**从不创建**的表，DROP 无效：
    //        yimai_gso_autopilot_log （真实名 yimai_gso_autopilot_runs）
    //        yimai_gso_crawler_logs  （真实名 geo_crawler_logs）
    //   2. 漏删了 2 张真实存在的表，卸载后数据永久残留：
    //        geo_crawler_logs        （爬虫日志，会持续增长）
    //        yimai_gso_seo_push_logs （推送日志）
    // 根因是表名硬编码后与各模块漂移。此处改为优先向各 owner 类取表名（唯一真源），
    // 类不可用时回退到修正后的字面量，避免再次漂移。
    $tables = array();

    if ( class_exists( 'YIMAI_GSO_Crawler_Tracker' ) ) {
        $tables[] = YIMAI_GSO_Crawler_Tracker::table_name();
    } else {
        $tables[] = 'geo_crawler_logs';
    }
    if ( class_exists( 'YIMAI_GSO_Referral_Tracker' ) ) {
        $tables[] = YIMAI_GSO_Referral_Tracker::table_name();
    } else {
        $tables[] = 'yimai_gso_referral_logs';
    }
    if ( class_exists( 'YIMAI_GSO_Alert_Engine' ) ) {
        $tables[] = YIMAI_GSO_Alert_Engine::table_name();
    } else {
        $tables[] = 'yimai_gso_alert_logs';
    }
    if ( class_exists( 'YIMAI_GSO_Autopilot_Log' ) ) {
        $tables[] = YIMAI_GSO_Autopilot_Log::get_runs_table();
        $tables[] = YIMAI_GSO_Autopilot_Log::get_items_table();
    } else {
        $tables[] = 'yimai_gso_autopilot_runs';
        $tables[] = 'yimai_gso_autopilot_items';
    }

    // 其余表名在各模块中为私有常量，使用核对过的字面量
    foreach ( array(
        'yimai_gso_seo_push_logs',      // D1 补漏：原先漏删
        'yimai_gso_knowledge_documents',
        'yimai_gso_knowledge_chunks',
        'yimai_gso_geo_keywords',
        'yimai_gso_geo_brand_scans',
        'yimai_gso_geo_brand_scan_details',
        'yimai_gso_geo_jobs',
        'yimai_gso_geo_job_logs',
    ) as $literal_table ) {
        $tables[] = $literal_table;
    }

    foreach ( array_unique( $tables ) as $table ) {
        // 注意：各 owner 类的 table_name()/get_*_table() **已包含 $wpdb->prefix**，
        // 而上面的字面量回退值不含前缀。此处统一归一化，避免出现 wp_wp_ 双重前缀。
        $table = (string) $table;
        if ( 0 === strpos( $table, $wpdb->prefix ) ) {
            $full_table = $table;
            $bare       = substr( $table, strlen( $wpdb->prefix ) );
        } else {
            $bare       = $table;
            $full_table = $wpdb->prefix . $table;
        }

        // 严格标识符白名单校验，确保拼进 DDL 的字符串只含 [A-Za-z0-9_]。
        // （不使用 %i 占位符：那是 WP 6.2+ 才支持，本插件声明兼容 WP 5.8+。）
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $bare ) ) {
            continue;
        }
        $wpdb->query( "DROP TABLE IF EXISTS `{$full_table}`" );
    }

    // 删除所有 yimai_gso_ 前缀的 options（原先是连续两行完全相同的 DELETE，重复执行）
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'yimai_gso_%'" );

    // 清除相关 transients
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yimai_gso_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_yimai_gso_%'" );

    // 清除文章/术语/用户元数据（D1 补漏）
    // 原实现从不清理 meta，卸载后文章元数据（SEO 标题、GEO 评分、生成标记等）永久残留。
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'yimai\\_gso\\_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE 'yimai\\_gso\\_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'yimai\\_gso\\_%'" );

    // 清除计划任务（原实现未清理，遗留 cron 会在下次请求触发已删除的回调）
    if ( function_exists( '_get_cron_array' ) ) {
        foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
            foreach ( array_keys( (array) $hooks ) as $hook ) {
                if ( is_string( $hook ) && strpos( $hook, 'yimai_gso' ) === 0 ) {
                    wp_clear_scheduled_hook( $hook );
                }
            }
        }
    }
}

// 启动插件
YIMAI_GSO_Plugin::get_instance();
