<?php
/**
 * 公众号草稿箱同步：入队 → 出队 → 草稿（只到草稿箱，不群发、不自动发布）
 *
 * 为什么要有队列，而不是发布文章时顺手推过去：
 *  - 微信 draft/add 要先把正文里的图片逐张转存（每张一次 HTTP 往返），
 *    一篇多图文章轻松超过 30 秒；放在 transition_post_status 里同步跑，
 *    会把「点发布」这个动作一起拖死，甚至撞上 PHP 的 max_execution_time。
 *  - 微信接口有配额与频控（45009 / 45028 / 40164 白名单），失败必须退避重试，
 *    而文章的发布动作本身不能跟着重试。
 *
 * 状态机（唯一事实来源是表里的 state；文章 meta `_yimai_wx_state` 只是给编辑页看的镜像）：
 *
 *     pending ──抢占──▶ processing ──成功──▶ drafted
 *        ▲                  │
 *        │                  ├──可重试错误──▶ failed ──到点──▶（再次被抢占）
 *        │                  ├──第 5 次失败──▶ dead（放弃，等人工 retry）
 *        └──enqueue/retry───┴──状态不可同步──▶ skipped
 *
 * 三条硬约束（用户原话：不会重复、不会丢、不会卡死）：
 *  1) 不会重复：唯一键 (post_id, content_hash) 打底，并且同一篇文章只保留一行任务；
 *     已有 wx_media_id 时走 draft/update 覆盖，不会在草稿箱里多出一份同标题草稿。
 *  2) 不会丢：入队代表「已经表达过同步意图」，失败按 1 / 5 / 25 / 60 分钟退避，
 *     第 5 次才置 dead；dead 也能被 retry() 或「立即同步」救活。
 *  3) 不会卡死：出队用带条件的 UPDATE 抢占，两个 cron 进程不会同时处理同一行；
 *     PHP 超时 / 进程被杀留下的 processing 僵尸行，10 分钟后自动退回 pending。
 *
 * 明确不做：本类只调用 draft/add 与 draft/update 两个写接口，草稿之后要不要群发
 * 由人在公众号后台决定。自动发布不在本模块职责内。
 *
 * 同步源（2.13.0 起）：已发布文章发布时自动入队；已发布 / 草稿 / 待审文章都可以由人
 * 在后台「公众号同步 → 从历史文章选择」或文章编辑页手动挑一篇同步。草稿同步不会
 * 改变本站的发布状态，也不会生成公开链接（微信侧「阅读原文」留空）。
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Wechat_Publisher {

    /* ==================== 队列与调度 ==================== */

    const TABLE          = 'yimai_gso_wx_jobs';
    const CRON_HOOK      = 'yimai_gso_wx_sync_cron';
    /** 复用 YIMAI_GSO_Autopilot_Scheduler 已注册的 5 分钟间隔，本类不重复注册 cron_schedules */
    const CRON_INTERVAL  = 'yimai_gso_every_five_minutes';

    /* ==================== 状态 ==================== */

    const STATE_PENDING    = 'pending';
    const STATE_PROCESSING = 'processing';
    const STATE_DRAFTED    = 'drafted';
    const STATE_FAILED     = 'failed';
    const STATE_DEAD       = 'dead';
    const STATE_SKIPPED    = 'skipped';

    /* ==================== 重试与批处理 ==================== */

    const MAX_ATTEMPTS      = 5;      // 第 5 次失败即放弃（人工介入前不再自动跑）
    const BATCH_SIZE        = 3;      // 每轮最多 3 篇，避免一次 cron 打满 120 秒
    const TIME_LIMIT        = 120;    // 单轮 / 单篇的时间上限（秒）
    const BACKOFF_BASE      = 60;     // 第一次退避 1 分钟
    const BACKOFF_FACTOR    = 5;      // 1 → 5 → 25 →（超过 60 被截断）
    const BACKOFF_MAX       = 3600;   // 上限 1 小时
    const STALE_PROCESSING  = 600;    // processing 超过 10 分钟视为僵尸行

    /* ==================== 文章字段规则 ==================== */

    const TITLE_MAX      = 64;      // 微信标题上限 64 字
    const TITLE_KEEP     = 62;      // 截断后留 2 字给省略号
    const DIGEST_MAX     = 120;     // 摘要上限 120 字
    const DIGEST_KEEP    = 118;
    const DIGEST_FALLBACK = 110;    // 没有手写摘要时，从正文取 110 字
    const AUTHOR_DEFAULT = '一麦瑜伽';

    /* ==================== 封面 ==================== */

    const COVER_WIDTH     = 900;      // 微信封面按 2.35:1 展示
    const COVER_HEIGHT    = 383;
    const COVER_QUALITY   = 82;
    const COVER_MAX_BYTES = 10485760; // 永久图片素材硬上限 10MB

    /* ==================== 文章 meta ==================== */

    const META_STATE     = '_yimai_wx_state';
    const META_OPTOUT    = '_yimai_wx_optout';
    const META_MEDIA_ID  = '_yimai_wx_media_id';
    const META_SYNCED_AT = '_yimai_wx_synced_at';

    /* ==================== 可同步的文章状态 ==================== */

    /**
     * 允许送进公众号草稿箱的文章状态
     *
     * publish 是常规路径（发布时自动入队）；draft / pending 只能由人显式挑进来：
     * 「从历史文章选择同步」或文章编辑页的「立即同步」。
     *
     * 为什么放行草稿：运营习惯先在 WordPress 里把稿子写完、直接推一份到公众号
     * 草稿箱看排版效果，等确认后再回本站发布。草稿被同步**不会**因此变成已发布
     * 文章，前台可见性完全不变，所以这条不会把没定稿的内容放出去。
     *
     * 其余状态（private / future / trash / auto-draft / inherit）一律不同步：
     * 私密稿、定时稿、回收站里的残稿推进公众号草稿箱，等于把「明确不想公开的内容」
     * 搬到一个更容易被误发出去的地方，代价远大于收益。
     */
    const SYNCABLE_STATUSES = array( 'publish', 'draft', 'pending' );

    /* ==================== 钩子注册 ==================== */

    /**
     * 只做钩子注册，不读设置、不查库：本方法在插件加载阶段被调用，
     * 任何昂贵的初始化都会拖慢所有前台请求。
     */
    public static function init() {
        // 20 与 SEO 推送 / 内容检测保持同一优先级带：让「发布」本身先落地
        add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 20, 3 );

        // 预留：class-publisher.php 的自动发布管线跑完后会触发本 action（$post_id 或 WP_Post）
        add_action( 'yimai_gso_after_publish', array( __CLASS__, 'on_pipeline_publish' ), 20, 1 );

        add_action( self::CRON_HOOK, array( __CLASS__, 'run_queue' ) );
        add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 20 );
    }

    /* ==================== 建表 ==================== */

    /**
     * 队列表全名
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * dbDelta 建表（幂等）
     *
     * dbDelta 的书写纪律（写错会静默不建表，这个坑非常难查）：
     *  - 每个字段独占一行；
     *  - PRIMARY KEY 后面必须**两个空格**再接字段名；
     *  - 主键 / 索引都只用 KEY，不用 INDEX；
     *  - 最后一行定义后面不能留逗号。
     *
     * @return bool 表是否可用
     */
    public static function create_table() {
        global $wpdb;

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table           = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            content_hash char(32) NOT NULL,
            state varchar(16) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            next_run_at datetime NOT NULL,
            wx_media_id varchar(128) DEFAULT NULL,
            wx_thumb_id varchar(128) DEFAULT NULL,
            article_url varchar(255) DEFAULT NULL,
            error_code int(11) DEFAULT NULL,
            error_msg text NULL,
            payload longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_post_hash (post_id,content_hash),
            KEY idx_state_run (state,next_run_at)
        ) {$charset_collate};";

        dbDelta( $sql );

        // dbDelta 不会回报「有没有真的建成」，这里复查一次：
        // 建表失败却当成成功，会让排障时完全看不到异常（只剩接口报错）。
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    /* ==================== 开关 ==================== */

    /**
     * 功能总开关
     *
     * 只挡「自动同步」与「队列自动出队」。后台点「立即同步」是明确的人工意图，
     * 不受这里拦截 —— 否则用户想临时补一篇还要先去找开关。
     *
     * @return bool
     */
    public static function is_enabled() {
        if ( ! class_exists( 'YIMAI_GSO_Settings' ) ) {
            return false;
        }
        return (bool) YIMAI_GSO_Settings::get( 'wx_sync_enabled', 0 );
    }

    /**
     * 是否允许「文章发布后自动入队」
     *
     * 空值按「开启」处理：后台表单直接 unset 掉复选框时存的是空串，
     * 若当成关闭，用户只开了总开关却什么都不同步，看起来就像坏了。
     *
     * @return bool
     */
    public static function is_auto_enabled() {
        if ( ! class_exists( 'YIMAI_GSO_Settings' ) ) {
            return false;
        }
        $value = YIMAI_GSO_Settings::get( 'wx_sync_auto', 1 );
        if ( '' === $value || null === $value ) {
            return true;
        }
        return (bool) $value;
    }

    /**
     * 这篇文章是否被明确排除同步
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_opted_out( $post_id ) {
        return (bool) get_post_meta( (int) $post_id, self::META_OPTOUT, true );
    }

    /* ==================== 状态口径 ==================== */

    /**
     * 这个文章状态能不能同步
     *
     * 后台挑文章、编辑页按钮、出队校验都走这一处，避免三处各写一份 in_array
     * 而口径慢慢漂移。
     *
     * @param string $status WordPress 文章状态
     * @return bool
     */
    public static function is_syncable_status( $status ) {
        return in_array( (string) $status, self::SYNCABLE_STATUSES, true );
    }

    /**
     * 可同步状态清单（后台筛选下拉与 WP_Query 共用这份口径）
     *
     * @return array
     */
    public static function syncable_statuses() {
        return self::SYNCABLE_STATUSES;
    }

    /**
     * 文章状态 → 人话
     *
     * 未知状态原样返回：WordPress 或别的插件新增状态时，界面不该显示成空白。
     *
     * @param string $status
     * @return string
     */
    public static function status_label( $status ) {
        $map = array(
            'publish'    => '已发布',
            'draft'      => '草稿',
            'pending'    => '待审',
            'private'    => '私密',
            'future'     => '定时',
            'trash'      => '回收站',
            'auto-draft' => '自动草稿',
            'inherit'    => '继承',
        );

        $status = (string) $status;
        if ( isset( $map[ $status ] ) ) {
            return $map[ $status ];
        }
        return '' === $status ? '未知' : $status;
    }

    /**
     * 文章状态 → 徽章变体（取值与后台 state_info() 同一套）
     *
     * @param string $status
     * @return string
     */
    public static function status_badge( $status ) {
        switch ( (string) $status ) {
            case 'publish':
                return 'is-pass';
            case 'pending':
                return 'is-warn';
            case 'private':
            case 'future':
                return 'is-info';
            case 'trash':
                return 'is-fail';
            default:
                return 'is-muted';
        }
    }

    /* ==================== 触发点 ==================== */

    /**
     * 文章首次变为「已发布」时入队
     *
     * 只在 old != publish 时触发：更新已发布文章（改标题、改错别字）不该每改一次
     * 就重排一次队。内容变更后要覆盖草稿，由人在后台点「同步」或调 enqueue() 决定，
     * 不会在保存按钮上偷偷跑微信接口。
     *
     * @param string       $new_status
     * @param string       $old_status
     * @param WP_Post|null $post
     */
    public static function on_publish( $new_status, $old_status, $post ) {
        if ( 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }
        if ( ! is_object( $post ) || ! isset( $post->post_type ) || 'post' !== $post->post_type ) {
            return;
        }
        self::maybe_enqueue_from_trigger( (int) $post->ID );
    }

    /**
     * 自动发布管线（class-publisher.php）跑完后的预留接入口
     *
     * 管线那边传 $post_id 或 WP_Post 都兼容：写 action 的人不该被参数形状绊住。
     *
     * @param int|WP_Post|mixed $post_ref
     */
    public static function on_pipeline_publish( $post_ref ) {
        $post_id = 0;
        if ( $post_ref instanceof WP_Post ) {
            $post_id = (int) $post_ref->ID;
        } elseif ( is_numeric( $post_ref ) ) {
            $post_id = (int) $post_ref;
        }
        if ( $post_id <= 0 ) {
            return;
        }
        if ( 'post' !== get_post_type( $post_id ) ) {
            return;
        }
        if ( 'publish' !== get_post_status( $post_id ) ) {
            return;
        }
        self::maybe_enqueue_from_trigger( $post_id );
    }

    /**
     * 自动触发共用的闸门
     *
     * 三道判断缺一不可：功能开着、这篇没被排除、分类在白名单内。
     *
     * @param int $post_id
     */
    private static function maybe_enqueue_from_trigger( $post_id ) {
        if ( ! self::is_enabled() || ! self::is_auto_enabled() ) {
            return;
        }
        if ( self::is_opted_out( $post_id ) ) {
            return;
        }
        if ( ! self::match_categories( $post_id ) ) {
            return;
        }
        self::enqueue( $post_id );
    }

    /**
     * 分类白名单匹配
     *
     * 配置为空 = 全部分类都同步（不是全部不同步）。这是运营最容易踩反的地方：
     * 装上插件没配分类就该能用，而不是静默什么都不发生。
     *
     * @param int $post_id
     * @return bool
     */
    private static function match_categories( $post_id ) {
        $wanted = self::wanted_categories();
        if ( empty( $wanted ) ) {
            return true;
        }

        $assigned = array_map( 'intval', (array) wp_get_post_categories( (int) $post_id ) );
        if ( empty( $assigned ) ) {
            // 白名单非空但文章一个分类都没有：不能算命中
            return false;
        }
        if ( array_intersect( $wanted, $assigned ) ) {
            return true;
        }

        // 子分类也算命中父分类：运营通常只勾大类，文章却常常发在小类下
        foreach ( $assigned as $cat_id ) {
            $ancestors = array_map( 'intval', (array) get_ancestors( $cat_id, 'category' ) );
            if ( array_intersect( $wanted, $ancestors ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 读取分类白名单，兼容数组 / 逗号分隔字符串两种存法
     *
     * @return int[]
     */
    private static function wanted_categories() {
        $raw = class_exists( 'YIMAI_GSO_Settings' ) ? YIMAI_GSO_Settings::get( 'wx_sync_categories', array() ) : array();

        if ( is_string( $raw ) ) {
            $raw = '' === trim( $raw ) ? array() : preg_split( '/[,\s]+/', $raw );
        }

        $out = array();
        foreach ( (array) $raw as $one ) {
            $one = (int) $one;
            if ( $one > 0 ) {
                $out[] = $one;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /* ==================== 入队 / 查询 ==================== */

    /**
     * 把一篇文章放进同步队列（幂等）
     *
     * 幂等键是 (post_id, content_hash)：
     *  - 同内容已有任务 → 不新增，按现有状态决定要不要重新激活；
     *  - 内容变了 → 复用**同一行**并更新哈希（保留 wx_media_id），
     *    出队时走 draft/update 覆盖原草稿，而不是在草稿箱里再来一份。
     *
     * 本方法**不判断** optout / 分类白名单 / 总开关：那些是「自动触发」的闸门，
     * 在 maybe_enqueue_from_trigger() 里判断。手动点同步是明确意图，必须能越过闸门。
     *
     * @param int $post_id
     * @return bool
     */
    public static function enqueue( $post_id ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( $post_id <= 0 || ! get_post( $post_id ) ) {
            return false;
        }

        $table = self::get_table_name();
        $hash  = self::content_hash( $post_id );
        if ( '' === $hash ) {
            return false;
        }
        $now = self::now();

        $same = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE post_id = %d AND content_hash = %s ORDER BY id DESC LIMIT 1",
                $post_id,
                $hash
            )
        );

        if ( $same ) {
            if ( self::STATE_PENDING === $same->state || self::STATE_PROCESSING === $same->state ) {
                return true; // 已经在队列里，重复点不产生第二行
            }
            if ( self::STATE_DRAFTED === $same->state ) {
                return true; // 同内容已经同步进草稿箱，不必再排一次
            }
            // failed / dead / skipped：内容没变，重新给满重试预算
            return self::reset_to_pending( (int) $same->id, $post_id );
        }

        $existing = self::get_job( $post_id );
        if ( $existing ) {
            $updated = $wpdb->update(
                $table,
                array(
                    'content_hash' => $hash,
                    'state'        => self::STATE_PENDING,
                    'attempts'     => 0,
                    'next_run_at'  => $now,
                    'error_code'   => null,
                    'error_msg'    => null,
                    'updated_at'   => $now,
                ),
                array( 'id' => (int) $existing->id )
            );
            if ( false === $updated ) {
                return false;
            }
            update_post_meta( $post_id, self::META_STATE, self::STATE_PENDING );
            return true;
        }

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                 (post_id, content_hash, state, attempts, next_run_at, created_at, updated_at)
                 VALUES (%d, %s, %s, %d, %s, %s, %s)",
                $post_id,
                $hash,
                self::STATE_PENDING,
                0,
                $now,
                $now,
                $now
            )
        );
        if ( false === $inserted ) {
            return false;
        }
        // INSERT IGNORE 被唯一键挡掉时也返回 0：那说明另一端刚插过同一行，视为成功
        update_post_meta( $post_id, self::META_STATE, self::STATE_PENDING );
        return true;
    }

    /**
     * 取一篇文章最新的任务行（后台编辑页 / 列表用）
     *
     * @param int $post_id
     * @return object|null
     */
    public static function get_job( $post_id ) {
        global $wpdb;
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return null;
        }
        $table = self::get_table_name();
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT 1", $post_id )
        );
        return $row ? $row : null;
    }

    /**
     * 按 id 取任务行
     *
     * @param int $job_id
     * @return object|null
     */
    public static function get_job_by_id( $job_id ) {
        global $wpdb;
        $job_id = absint( $job_id );
        if ( $job_id <= 0 ) {
            return null;
        }
        $table = self::get_table_name();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $job_id ) );
        return $row ? $row : null;
    }

    /**
     * 一次取多篇文章各自的最新任务行（后台「从历史文章选择」列表用）
     *
     * 为什么要有这个：列表一屏 8 篇，逐篇 get_job() 就是 8 次查询；
     * 这里一条 IN 查询全部拿回来，再按 id 倒序保留每篇的第一行（即最新那条）。
     *
     * @param array $post_ids
     * @return array post_id => 任务行
     */
    public static function get_jobs_map( array $post_ids ) {
        global $wpdb;

        $ids = array();
        foreach ( $post_ids as $post_id ) {
            $post_id = absint( $post_id );
            if ( $post_id > 0 ) {
                $ids[ $post_id ] = $post_id;
            }
        }
        if ( empty( $ids ) ) {
            return array();
        }

        $table        = self::get_table_name();
        $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
        $sql          = "SELECT * FROM {$table} WHERE post_id IN ({$placeholders}) ORDER BY id DESC";

        // ids 全是 absint 过的整数，占位符数量由数组长度决定，没有外部拼接
        $prepared = call_user_func_array(
            array( $wpdb, 'prepare' ),
            array_merge( array( $sql ), array_values( $ids ) )
        );
        if ( ! is_string( $prepared ) || '' === $prepared ) {
            return array();
        }

        $rows = $wpdb->get_results( $prepared );
        $map  = array();
        foreach ( (array) $rows as $row ) {
            $post_id = isset( $row->post_id ) ? (int) $row->post_id : 0;
            if ( $post_id > 0 && ! isset( $map[ $post_id ] ) ) {
                $map[ $post_id ] = $row;
            }
        }
        return $map;
    }

    /**
     * 最近的任务（后台「同步记录」表格用）
     *
     * @param int $limit
     * @return array
     */
    public static function get_recent_jobs( $limit = 20 ) {
        global $wpdb;
        $limit = max( 1, min( 200, (int) $limit ) );
        $table = self::get_table_name();
        $rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}" );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * 重试一个任务：状态回 pending、重试次数归零、立刻可跑
     *
     * 不清 _yimai_wx_optout：那就是「这篇别同步」的意思，retry 只负责把任务重新排上，
     * 想恢复自动同步要在后台取消勾选（skip() 写的就是那个 meta）。
     *
     * @param int $job_id
     * @return bool
     */
    public static function retry( $job_id ) {
        global $wpdb;

        $job_id = absint( $job_id );
        $row    = self::get_job_by_id( $job_id );
        if ( ! $row ) {
            return false;
        }
        if ( self::STATE_PROCESSING === $row->state ) {
            return false; // 正在跑，别和它抢
        }

        $now     = self::now();
        $updated = $wpdb->update(
            self::get_table_name(),
            array(
                'state'       => self::STATE_PENDING,
                'attempts'    => 0,
                'next_run_at' => $now,
                'error_code'  => null,
                'error_msg'   => null,
                'updated_at'  => $now,
            ),
            array( 'id' => $job_id )
        );
        if ( false === $updated ) {
            return false;
        }

        update_post_meta( (int) $row->post_id, self::META_STATE, self::STATE_PENDING );
        return true;
    }

    /**
     * 这篇文章以后别同步
     *
     * 只标「还没跑完」的行（pending / failed）。正在 processing 的那一行不动：
     * 微信请求已经发出去了，此刻改状态只会让「草稿已建好、本地却显示已跳过」。
     * 已经 drafted 的也不动 —— 草稿在公众号后台，要不要删由人去删。
     *
     * @param int $post_id
     */
    public static function skip( $post_id ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return;
        }

        update_post_meta( $post_id, self::META_OPTOUT, 1 );

        $table  = self::get_table_name();
        $now    = self::now();
        $marked = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET state = %s, updated_at = %s WHERE post_id = %d AND state IN (%s, %s)",
                self::STATE_SKIPPED,
                $now,
                $post_id,
                self::STATE_PENDING,
                self::STATE_FAILED
            )
        );

        if ( $marked ) {
            update_post_meta( $post_id, self::META_STATE, self::STATE_SKIPPED );
        }
    }

    /* ==================== 出队 ==================== */

    /**
     * cron 入口：抢占并在本请求里处理最多 3 篇
     *
     * 只挂在 cron 钩子上。普通 WordPress 请求（包括后台页面）永远不跑整条队列，
     * 否则后台点开一篇文章就可能卡 120 秒。
     */
    public static function run_queue() {
        global $wpdb;

        if ( ! self::is_enabled() ) {
            return;
        }

        self::extend_time_limit();
        self::rescue_stale_processing();

        $table = self::get_table_name();
        // 表名与 LIMIT 都是代码里的常量，没有外部输入，不需要 prepare
        $rows  = $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE state IN ('pending','failed') AND next_run_at <= UTC_TIMESTAMP()
             ORDER BY next_run_at ASC
             LIMIT " . (int) self::BATCH_SIZE
        );
        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            // 抢占失败说明另一个进程（或后台「立即同步」）已经拿走了这一行，跳过
            if ( ! self::claim( (int) $row->id ) ) {
                continue;
            }
            $fresh = self::get_job_by_id( (int) $row->id );
            if ( ! $fresh ) {
                continue;
            }
            self::finish( $fresh, self::process( $fresh ) );
        }
    }

    /**
     * 后台「立即同步」：同步跑一篇，供按钮直接拿结果
     *
     * 不受总开关限制，也不看 optout（人工意图优先）：用户点这个按钮就是要
     * 「现在同步这一篇」，dead 或之前点过「不同步」的文章都应该能跑一次。
     * 会先把任务重置成 pending，重试预算重新给满。
     *
     * @param int $post_id
     * @return true|WP_Error
     */
    public static function process_now( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return new WP_Error( 'yimai_gso_wx_bad_post', '文章 ID 无效' );
        }
        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'yimai_gso_wx_no_post', '文章不存在' );
        }

        self::extend_time_limit();

        if ( ! self::enqueue( $post_id ) ) {
            return new WP_Error( 'yimai_gso_wx_enqueue_failed', '任务入队失败，请检查数据库权限' );
        }

        $row = self::get_job( $post_id );
        if ( ! $row ) {
            return new WP_Error( 'yimai_gso_wx_no_job', '任务入队后没有找到队列记录' );
        }
        if ( self::STATE_PROCESSING === $row->state ) {
            return new WP_Error( 'yimai_gso_wx_busy', '这篇正在同步中，请稍等十几秒后再看结果' );
        }

        self::reset_to_pending( (int) $row->id, $post_id );

        if ( ! self::claim( (int) $row->id ) ) {
            return new WP_Error( 'yimai_gso_wx_busy', '这篇正在被另一个进程同步，请稍后再看结果' );
        }

        $fresh = self::get_job_by_id( (int) $row->id );
        if ( ! $fresh ) {
            return new WP_Error( 'yimai_gso_wx_no_job', '任务行在同步过程中消失' );
        }

        $result = self::process( $fresh );
        self::finish( $fresh, $result );

        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * 统一收尾：按 process() 的返回值落状态
     *
     * @param object          $row
     * @param array|WP_Error  $result
     */
    private static function finish( $row, $result ) {
        if ( ! is_wp_error( $result ) ) {
            self::handle_success( $row, (array) $result );
            return;
        }
        if ( 'wx_skip' === $result->get_error_code() ) {
            self::mark_skipped( $row, $result->get_error_message() );
            return;
        }
        self::handle_failure( $row, $result );
    }

    /**
     * 带条件抢占：只有还在 pending / failed 的行才会被改成 processing
     *
     * 这是一条 UPDATE 而不是「先 SELECT 再 UPDATE」，靠 MySQL 的行锁保证原子性；
     * 受影响行数为 1 才算抢到，避免两个 cron 进程重复提交同一篇。
     *
     * @param int $job_id
     * @return bool
     */
    private static function claim( $job_id ) {
        global $wpdb;

        $now     = self::now();
        $table   = self::get_table_name();
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET state = %s, updated_at = %s WHERE id = %d AND state IN (%s, %s)",
                self::STATE_PROCESSING,
                $now,
                (int) $job_id,
                self::STATE_PENDING,
                self::STATE_FAILED
            )
        );
        if ( 1 !== (int) $updated ) {
            return false;
        }

        $row = self::get_job_by_id( $job_id );
        if ( $row ) {
            update_post_meta( (int) $row->post_id, self::META_STATE, self::STATE_PROCESSING );
        }
        return true;
    }

    /**
     * 僵尸行救援：processing 卡超过 10 分钟就退回 pending
     *
     * PHP 超时、fpm 进程被杀、数据库连接断掉都会让一行永远停在 processing，
     * 而 WRITE 操作没有 finally 能兜住 fatal。这里不计 attempts：
     * 那次执行没拿到微信的错误码，不该消耗重试预算。
     */
    private static function rescue_stale_processing() {
        global $wpdb;

        $table     = self::get_table_name();
        $now       = self::now();
        $threshold = self::now_plus( -1 * self::STALE_PROCESSING );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET state = %s, next_run_at = %s, updated_at = %s
                 WHERE state = %s AND updated_at < %s",
                self::STATE_PENDING,
                $now,
                $now,
                self::STATE_PROCESSING,
                $threshold
            )
        );
    }

    /* ==================== 单篇处理 ==================== */

    /**
     * 单篇完整流程：校验 → 渲染 → 封面 → 建/改草稿
     *
     * @param object $row 队列表行
     * @return array|WP_Error 成功时返回要写回队列的字段
     */
    private static function process( $row ) {
        $post = get_post( (int) $row->post_id );
        if ( ! $post ) {
            // 用 wx_skip 这个错误码和真正的失败区分开：文章下线不是「同步坏了」，
            // 不该消耗重试预算，也不该在后台显示成红色错误。
            return new WP_Error( 'wx_skip', '文章已被删除，本次不同步' );
        }
        // 可同步范围见 SYNCABLE_STATUSES：已发布 + 草稿 + 待审（后两者只能人工挑进来）。
        // 私密 / 定时 / 回收站一律不同步 —— 那些是明确不想公开的内容。
        if ( ! self::is_syncable_status( $post->post_status ) ) {
            return new WP_Error(
                'wx_skip',
                '文章当前状态是「' . self::status_label( $post->post_status ) . '」，不在可同步范围内，本次不同步'
            );
        }

        if ( ! class_exists( 'YIMAI_GSO_Wx_Renderer' ) || ! class_exists( 'YIMAI_GSO_Wx_Api' ) ) {
            return new WP_Error( 'yimai_gso_wx_missing_class', '公众号模块未加载完整（渲染器或 API 类缺失）' );
        }

        // 渲染器会调 download_url() 下载正文图片，它定义在 wp-admin/includes/file.php，
        // 而 cron / 前台请求默认不加载那个文件；不在这里补 require 会直接 fatal。
        if ( ! function_exists( 'download_url' ) || ! function_exists( 'wp_tempnam' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $content = YIMAI_GSO_Wx_Renderer::render( $post );
        if ( is_wp_error( $content ) ) {
            return $content;
        }
        $content = (string) $content;
        if ( '' === $content ) {
            return new WP_Error( 'yimai_gso_wx_empty_render', '正文渲染结果为空，未提交到草稿箱' );
        }

        $cover = self::ensure_cover( $post, $row );
        if ( is_wp_error( $cover ) ) {
            return $cover;
        }

        // 封面素材先落库：后面 draft 调用失败时，下一次重试还能复用这张图，
        // 不白烧一个永久素材额度（素材上限 5000，重复上传很快就会用完）。
        self::save_cover_result( (int) $row->id, $cover );

        $media_id = isset( $row->wx_media_id ) ? (string) $row->wx_media_id : '';
        $article  = self::build_article( $post, $content, $cover['media_id'] );

        $is_update = ( '' !== $media_id );
        $response  = $is_update
            ? YIMAI_GSO_Wx_Api::update_draft( $media_id, 0, $article )
            : YIMAI_GSO_Wx_Api::create_draft( $article );

        if ( is_wp_error( $response ) ) {
            return self::maybe_repair_media_error( $row, $is_update, $response );
        }

        if ( ! $is_update ) {
            $media_id = isset( $response['media_id'] ) ? (string) $response['media_id'] : '';
            if ( '' === $media_id ) {
                // 没拿到 media_id 就无法确认草稿到底建没建，不能当成功
                return new WP_Error( 'yimai_gso_wx_no_media_id', '微信没有返回草稿 media_id，无法确认是否创建成功' );
            }
        }

        return array(
            'wx_media_id' => $media_id,
            'wx_thumb_id' => (string) $cover['media_id'],
            'article_url' => self::source_url( $post ),
            'payload'     => self::build_payload( $cover, $content, 0 ),
        );
    }

    /**
     * media_id 失效时的自愈：清掉坏 id，让下一次重试走重建分支
     *
     * 40007 = media_id 无效。草稿或封面素材被人在公众号后台删掉时会报这个码。
     * 不清的话每次重试都撞同一个死 id，5 次后任务 dead，只能进数据库手改。
     *
     * @param object  $row
     * @param bool    $is_update 本次是 draft/update（否则是 draft/add）
     * @param WP_Error $error
     * @return WP_Error
     */
    private static function maybe_repair_media_error( $row, $is_update, $error ) {
        if ( 40007 !== self::error_code( $error ) ) {
            return $error;
        }

        if ( $is_update ) {
            self::clear_remote_field( (int) $row->id, 'wx_media_id' );
            return new WP_Error(
                'yimai_gso_wx_draft_gone',
                '公众号后台已删除这份草稿，已清空记录，下次重试会自动重建一份新草稿',
                $error->get_error_data()
            );
        }

        self::clear_remote_field( (int) $row->id, 'wx_thumb_id' );
        return new WP_Error(
            'yimai_gso_wx_thumb_gone',
            '封面素材已失效（可能被后台清理），已清空记录，下次重试会重新上传封面',
            $error->get_error_data()
        );
    }

    /**
     * 组装 draft/add / draft/update 需要的文章对象
     *
     * @param WP_Post $post
     * @param string  $content
     * @param string  $thumb_media_id
     * @return array
     */
    private static function build_article( $post, $content, $thumb_media_id ) {
        return array(
            'title'                 => self::trim_title( $post ),
            'author'                => self::author_name(),
            'digest'                => self::build_digest( $post ),
            'content'               => (string) $content,
            'content_source_url'    => self::source_url( $post ),
            'thumb_media_id'        => (string) $thumb_media_id,
            'need_open_comment'     => 0,
            'only_fans_can_comment' => 0,
        );
    }

    /**
     * 草稿里的「阅读原文」地址
     *
     * 只有已发布文章才有公开地址。草稿 / 待审文章 get_permalink() 返回的是
     * `?p=ID` 这类只对登录用户有效的预览链接，塞进公众号用户点开就是 404 或登录页，
     * 所以这些状态一律留空（微信在没有地址时不显示「阅读原文」）。
     *
     * @param WP_Post $post
     * @return string
     */
    private static function source_url( $post ) {
        if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
            return '';
        }
        return (string) get_permalink( $post );
    }

    /**
     * 标题：去标签、折叠空白、超过 64 字截到 62 加省略号
     *
     * @param WP_Post $post
     * @return string
     */
    private static function trim_title( $post ) {
        $title = wp_strip_all_tags( (string) $post->post_title );
        $title = trim( preg_replace( '/\s+/u', ' ', $title ) );

        // 微信要求标题非空，空标题会被接口直接拒掉；给一个可辨识的占位
        if ( '' === $title ) {
            $title = '未命名文章';
        }
        if ( mb_strlen( $title ) > self::TITLE_MAX ) {
            $title = mb_substr( $title, 0, self::TITLE_KEEP ) . '…';
        }
        return $title;
    }

    /**
     * 作者名（草稿卡片上显示的那行）
     *
     * @return string
     */
    private static function author_name() {
        $author = class_exists( 'YIMAI_GSO_Settings' )
            ? YIMAI_GSO_Settings::get( 'wx_default_author', self::AUTHOR_DEFAULT )
            : self::AUTHOR_DEFAULT;

        $author = trim( wp_strip_all_tags( (string) $author ) );
        return '' === $author ? self::AUTHOR_DEFAULT : $author;
    }

    /**
     * 摘要：手写 excerpt 优先，没有就从正文抓 110 字；超过 120 字截到 118 加省略号
     *
     * @param WP_Post $post
     * @return string
     */
    private static function build_digest( $post ) {
        $digest = '';
        if ( has_excerpt( $post ) ) {
            $digest = (string) get_the_excerpt( $post );
        }

        $plain = trim( wp_strip_all_tags( $digest ) );
        if ( '' === $plain ) {
            // 走短代码：不跑的话 [caption]/[gallery] 会当成文字留在摘要里
            $fallback = (string) do_shortcode( (string) $post->post_content );
            $fallback = trim( wp_strip_all_tags( $fallback ) );
            $digest   = mb_substr( $fallback, 0, self::DIGEST_FALLBACK );
        }

        $digest = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $digest ) ) );
        if ( mb_strlen( $digest ) > self::DIGEST_MAX ) {
            $digest = mb_substr( $digest, 0, self::DIGEST_KEEP ) . '…';
        }
        return $digest;
    }

    /* ==================== 封面 ==================== */

    /**
     * 取封面并上传为永久素材
     *
     * 特色图片优先，没有就用设置里的兜底封面（大多数站点的文章没有逐篇设置特色图）。
     * 同一张附件已经传过就直接复用 media_id，避免每次同步都往素材库里塞一张重复图。
     *
     * @param WP_Post     $post
     * @param object|null $row 当前任务行（用于复用已上传的素材）
     * @return array|WP_Error array( media_id, attachment_id, reused )
     */
    private static function ensure_cover( $post, $row = null ) {
        $attachment_id = (int) get_post_thumbnail_id( $post->ID );
        if ( $attachment_id <= 0 && class_exists( 'YIMAI_GSO_Settings' ) ) {
            $attachment_id = (int) YIMAI_GSO_Settings::get( 'wx_cover_fallback_id', 0 );
        }
        if ( $attachment_id <= 0 ) {
            return new WP_Error(
                'yimai_gso_wx_no_cover',
                '缺少封面图：请给文章设置特色图片，或在设置页指定一张兜底封面'
            );
        }

        if ( $row && ! empty( $row->wx_thumb_id ) ) {
            $payload = self::decode_payload( isset( $row->payload ) ? $row->payload : '' );
            if ( isset( $payload['cover_attachment_id'] ) && (int) $payload['cover_attachment_id'] === $attachment_id ) {
                return array(
                    'media_id'      => (string) $row->wx_thumb_id,
                    'attachment_id' => $attachment_id,
                    'reused'        => true,
                );
            }
        }

        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            return new WP_Error(
                'yimai_gso_wx_cover_missing',
                '封面图的本地文件不存在（附件 ' . $attachment_id . '），请重新上传这张图'
            );
        }

        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            return new WP_Error( 'yimai_gso_wx_cover_editor', '封面图无法处理：' . $editor->get_error_message() );
        }

        // 微信封面按 2.35:1 展示（900×383），不裁的话手机端会把竖图切得只剩中间一条
        $resized = $editor->resize( self::COVER_WIDTH, self::COVER_HEIGHT, true );
        if ( is_wp_error( $resized ) ) {
            return new WP_Error( 'yimai_gso_wx_cover_resize', '封面裁剪失败：' . $resized->get_error_message() );
        }
        $editor->set_quality( self::COVER_QUALITY );

        if ( ! function_exists( 'wp_tempnam' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp_path = wp_tempnam( 'wx-cover' );
        $out_path = $tmp_path . '.jpg';
        $saved    = $editor->save( $out_path, 'image/jpeg' );
        if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
            @unlink( $tmp_path );
            return new WP_Error( 'yimai_gso_wx_cover_save', '封面转 JPG 失败：微信只接受 JPG / PNG' );
        }

        if ( (int) @filesize( $saved['path'] ) > self::COVER_MAX_BYTES ) {
            @unlink( $saved['path'] );
            @unlink( $tmp_path );
            return new WP_Error( 'yimai_gso_wx_cover_big', '封面压缩后仍超过 10MB，请先手动压缩原图' );
        }

        $binary = @file_get_contents( $saved['path'] );
        @unlink( $saved['path'] );
        @unlink( $tmp_path );

        if ( ! is_string( $binary ) || '' === $binary ) {
            return new WP_Error( 'yimai_gso_wx_cover_read', '封面压缩完成后读取失败' );
        }

        if ( ! class_exists( 'YIMAI_GSO_Wx_Api' ) ) {
            return new WP_Error( 'yimai_gso_wx_missing_class', '公众号 API 类未加载' );
        }

        $media_id = YIMAI_GSO_Wx_Api::upload_permanent_image( $binary, 'wx-cover-' . $attachment_id . '.jpg' );
        if ( is_wp_error( $media_id ) ) {
            return $media_id;
        }

        return array(
            'media_id'      => (string) $media_id,
            'attachment_id' => $attachment_id,
            'reused'        => false,
        );
    }

    /**
     * 封面结果落库（draft 未成功也要留，重试时才能复用素材）
     *
     * @param int   $job_id
     * @param array $cover
     */
    private static function save_cover_result( $job_id, array $cover ) {
        global $wpdb;

        $row = self::get_job_by_id( $job_id );
        if ( ! $row ) {
            return;
        }

        $payload = self::decode_payload( isset( $row->payload ) ? $row->payload : '' );
        $payload['cover_attachment_id'] = (int) $cover['attachment_id'];
        $payload['cover_media_id']      = (string) $cover['media_id'];

        $wpdb->update(
            self::get_table_name(),
            array(
                'wx_thumb_id' => (string) $cover['media_id'],
                'payload'     => self::encode_payload( $payload ),
                'updated_at'  => self::now(),
            ),
            array( 'id' => (int) $job_id )
        );
    }

    /* ==================== 状态落地 ==================== */

    /**
     * 成功：pending/failed/processing → drafted
     *
     * @param object $row
     * @param array  $result
     */
    private static function handle_success( $row, array $result ) {
        global $wpdb;

        $now      = self::now();
        $post_id  = (int) $row->post_id;
        $media_id = isset( $result['wx_media_id'] ) ? (string) $result['wx_media_id'] : '';

        $wpdb->update(
            self::get_table_name(),
            array(
                'state'       => self::STATE_DRAFTED,
                'wx_media_id' => '' === $media_id ? null : $media_id,
                'wx_thumb_id' => isset( $result['wx_thumb_id'] ) ? (string) $result['wx_thumb_id'] : null,
                'article_url' => isset( $result['article_url'] ) ? (string) $result['article_url'] : null,
                'error_code'  => null,
                'error_msg'   => null,
                'payload'     => isset( $result['payload'] ) ? $result['payload'] : null,
                'updated_at'  => $now,
            ),
            array( 'id' => (int) $row->id )
        );

        update_post_meta( $post_id, self::META_STATE, self::STATE_DRAFTED );
        if ( '' !== $media_id ) {
            update_post_meta( $post_id, self::META_MEDIA_ID, $media_id );
        }
        update_post_meta( $post_id, self::META_SYNCED_AT, time() );
    }

    /**
     * 失败：累加 attempts，按指数退避排下一次；第 5 次置 dead
     *
     * @param object   $row
     * @param WP_Error $error
     */
    private static function handle_failure( $row, $error ) {
        global $wpdb;

        $attempts = (int) $row->attempts + 1;
        $state    = $attempts >= self::MAX_ATTEMPTS ? self::STATE_DEAD : self::STATE_FAILED;
        $delay    = self::backoff_seconds( $attempts );
        $now      = self::now();
        $code     = self::error_code( $error );
        $message  = mb_substr( (string) $error->get_error_message(), 0, 1000 );

        // 用库里的 payload 而不是 $row->payload：process() 可能在本次执行中刚写过
        // 封面素材信息（save_cover_result），拿着抢占前的旧副本会把它覆盖掉，
        // 下一轮就会重新上传一张封面，白烧一个永久素材额度。
        $latest  = self::get_job_by_id( (int) $row->id );
        $payload = self::decode_payload( $latest && isset( $latest->payload ) ? $latest->payload : '' );

        $payload['last_error_code']    = $code;
        $payload['last_error_message'] = $message;
        $payload['attempts']           = $attempts;
        $payload['next_run_in']        = $delay;
        $payload['at']                 = $now;

        $wpdb->update(
            self::get_table_name(),
            array(
                'state'       => $state,
                'attempts'    => $attempts,
                'next_run_at' => self::now_plus( $delay ),
                // 本地错误（渲染失败 / 网络超时）没有微信错误码，存 NULL 比存 0 更清楚
                'error_code'  => $code > 0 ? $code : null,
                'error_msg'   => $message,
                'payload'     => self::encode_payload( $payload ),
                'updated_at'  => $now,
            ),
            array( 'id' => (int) $row->id )
        );

        update_post_meta( (int) $row->post_id, self::META_STATE, $state );
    }

    /**
     * 文章下线：标 skipped，不计 attempts
     *
     * @param object $row
     * @param string $reason
     */
    private static function mark_skipped( $row, $reason ) {
        global $wpdb;

        $wpdb->update(
            self::get_table_name(),
            array(
                'state'      => self::STATE_SKIPPED,
                'error_code' => null,
                'error_msg'  => mb_substr( (string) $reason, 0, 1000 ),
                'updated_at' => self::now(),
            ),
            array( 'id' => (int) $row->id )
        );

        update_post_meta( (int) $row->post_id, self::META_STATE, self::STATE_SKIPPED );
    }

    /**
     * 退回 pending 并把重试预算清零
     *
     * @param int $job_id
     * @param int $post_id
     * @return bool
     */
    private static function reset_to_pending( $job_id, $post_id ) {
        global $wpdb;

        $now     = self::now();
        $updated = $wpdb->update(
            self::get_table_name(),
            array(
                'state'       => self::STATE_PENDING,
                'attempts'    => 0,
                'next_run_at' => $now,
                'error_code'  => null,
                'error_msg'   => null,
                'updated_at'  => $now,
            ),
            array( 'id' => (int) $job_id )
        );
        if ( false === $updated ) {
            return false;
        }

        update_post_meta( (int) $post_id, self::META_STATE, self::STATE_PENDING );
        return true;
    }

    /**
     * 清掉一个「远端已失效」的 id 字段
     *
     * 字段名走白名单，避免把拼接进来的字符串塞进 SQL。
     *
     * @param int    $job_id
     * @param string $field
     */
    private static function clear_remote_field( $job_id, $field ) {
        global $wpdb;

        if ( ! in_array( $field, array( 'wx_media_id', 'wx_thumb_id' ), true ) ) {
            return;
        }

        $wpdb->update(
            self::get_table_name(),
            array( $field => null, 'updated_at' => self::now() ),
            array( 'id' => (int) $job_id )
        );
    }

    /* ==================== 工具 ==================== */

    /**
     * 退避秒数：1 分钟 → 5 → 25 → 60（封顶）
     *
     * 用 pow 而不是四段 if：档位是「5 的 n 次方分钟」这一个规则，
     * 以后要调曲线只改两个常量。
     *
     * @param int $attempts 本次是第几次失败（从 1 开始）
     * @return int
     */
    private static function backoff_seconds( $attempts ) {
        $step = max( 0, (int) $attempts - 1 );
        return (int) min( self::BACKOFF_MAX, self::BACKOFF_BASE * pow( self::BACKOFF_FACTOR, $step ) );
    }

    /**
     * 从 WP_Error 里取微信错误码
     *
     * 前置层（YIMAI_GSO_Wx_Api::api_error）把微信原始返回数组整个塞进 error_data，
     * 所以这里直接读 errcode；本地错误（渲染失败、网络超时）没有这个键，返回 0。
     *
     * @param WP_Error $error
     * @return int
     */
    private static function error_code( $error ) {
        if ( ! is_object( $error ) || ! method_exists( $error, 'get_error_data' ) ) {
            return 0;
        }
        $data = $error->get_error_data();
        return ( is_array( $data ) && isset( $data['errcode'] ) ) ? (int) $data['errcode'] : 0;
    }

    /**
     * 内容哈希：决定「同一篇的这次修改算不算新内容」
     *
     * 标题 + 正文 + 修改时间 + 封面都进哈希。带修改时间是兜底：
     * 摘要、缩略图这类改动同样会 bump post_modified_gmt，漏掉就会出现
     * 「改了摘要却更新不了草稿」。
     *
     * @param int $post_id
     * @return string 失败返回空串
     */
    private static function content_hash( $post_id ) {
        $post = get_post( (int) $post_id );
        if ( ! $post ) {
            return '';
        }
        return md5(
            (string) $post->post_title . '|'
            . (string) $post->post_content . '|'
            . (string) $post->post_modified_gmt . '|'
            . (int) get_post_thumbnail_id( $post->ID )
        );
    }

    /**
     * 组装 payload（给后台看「这次到底发生了什么」）
     *
     * 只放诊断信息，不放正文：正文几十 KB 存进队列表会让列表查询变慢，
     * 而且正文里可能有不该进数据库的内容。
     *
     * @param array  $cover
     * @param string $content
     * @param int    $error_code
     * @return string|null
     */
    private static function build_payload( array $cover, $content, $error_code = 0 ) {
        $warnings = array();
        if ( class_exists( 'YIMAI_GSO_Wx_Renderer' ) && method_exists( 'YIMAI_GSO_Wx_Renderer', 'get_warnings' ) ) {
            $warnings = (array) YIMAI_GSO_Wx_Renderer::get_warnings();
        }

        return self::encode_payload(
            array(
                'cover_attachment_id' => isset( $cover['attachment_id'] ) ? (int) $cover['attachment_id'] : 0,
                'cover_media_id'      => isset( $cover['media_id'] ) ? (string) $cover['media_id'] : '',
                'cover_reused'        => isset( $cover['reused'] ) ? (bool) $cover['reused'] : false,
                'content_bytes'       => strlen( (string) $content ),
                'error_code'          => (int) $error_code,
                'warnings'            => array_slice( array_map( 'strval', $warnings ), 0, 10 ),
                'at'                  => self::now(),
            )
        );
    }

    /**
     * @param array $data
     * @return string|null
     */
    private static function encode_payload( array $data ) {
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $json ) {
            return null;
        }
        // 截断只影响可读性；解码失败时调用方按空数组处理，不会崩
        return substr( (string) $json, 0, 4000 );
    }

    /**
     * @param mixed $payload
     * @return array
     */
    private static function decode_payload( $payload ) {
        if ( ! is_string( $payload ) || '' === $payload ) {
            return array();
        }
        $data = json_decode( $payload, true );
        return is_array( $data ) ? $data : array();
    }

    /**
     * 当前 UTC 时间（与表里的 UTC_TIMESTAMP() 同一时区）
     *
     * @return string
     */
    private static function now() {
        return gmdate( 'Y-m-d H:i:s' );
    }

    /**
     * 偏移后的 UTC 时间
     *
     * @param int $offset 秒，可为负
     * @return string
     */
    private static function now_plus( $offset ) {
        return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
    }

    /**
     * 单篇处理可能跑满 120 秒（转存十几张图），而 wp-cron 那条请求的默认
     * max_execution_time 往往只有 30 秒。共享主机常禁用 set_time_limit，
     * 禁用了就保持原样，不影响流程。
     */
    private static function extend_time_limit() {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( self::TIME_LIMIT );
        }
    }

    /**
     * 确保 cron 已排上（间隔复用永动发布调度器注册的 5 分钟那一档）
     */
    public static function ensure_schedule() {
        $scheduled = wp_next_scheduled( self::CRON_HOOK );

        if ( ! self::is_enabled() ) {
            if ( $scheduled ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
            }
            return;
        }

        if ( $scheduled ) {
            return;
        }

        // 间隔由 YIMAI_GSO_Autopilot_Scheduler 注册；万一它没被加载，
        // wp_schedule_event 会因为找不到间隔而失败，所以先确认存在再排（不重复注册 cron_schedules）
        $schedules = wp_get_schedules();
        if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
            return;
        }

        wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::CRON_HOOK );
    }

    /**
     * 停用插件时清掉本模块的 cron（避免留下永远不会成功的幽灵任务）
     */
    public static function unschedule() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
}
