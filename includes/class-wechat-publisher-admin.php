<?php
/**
 * 公众号同步 · 后台界面
 *
 * 职责边界：本类只做「界面 + 把一次用户意图翻译成一次静态方法调用」。
 * 队列、状态机、正文渲染、微信接口全部在
 * YIMAI_GSO_Wechat_Publisher / YIMAI_GSO_Wx_Renderer / YIMAI_GSO_Wx_Api 里，
 * 界面改版永远不会碰坏同步逻辑，同步逻辑出问题也不用改界面。
 *
 * 四个入口：
 *  1) 子菜单「公众号同步」：状态统计 → 只进草稿箱的说明 → 同步设置 → 最近任务表
 *  2) 文章编辑页 metabox（side）：当前状态 / 立即同步 / 不同步这篇文章
 *  3) 文章列表列「公众号」：状态徽标
 *  4) 三个 AJAX 端点：retry / skip / sync_now
 *
 * 界面只用 docs/UI组件规范.md 里的 .yimai-gso-* 组件类，
 * 本文件不输出任何内联样式，也不自定义颜色。
 *
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YIMAI_GSO_Wechat_Publisher_Admin {

    /** 子菜单 slug（也是页面 hook 的一部分） */
    const PAGE_SLUG = 'yimai-gso-wechat-sync';

    /** 设置表单：admin-post.php 的 action */
    const SAVE_ACTION = 'yimai_gso_wx_sync_save';

    /** 设置表单 nonce action（与 action 同名，便于对照排查） */
    const SAVE_NONCE = 'yimai_gso_wx_sync_save';

    /** 三个 AJAX 端点共用的 nonce action */
    const AJAX_NONCE = 'yimai_gso_wx_admin';

    /** metabox 的 nonce */
    const META_NONCE = 'yimai_gso_wx_meta_box';
    const META_FIELD = 'yimai_gso_wx_meta_nonce';

    /** 列表列 key：带上插件前缀，避开 WP 内置列名（用 tags / categories 会被内核顶掉） */
    const COLUMN = 'yimai_gso_wx_state';

    /** 最近任务表显示多少条：够运营判断「最近有没有跑通」，再多就该翻页了 */
    const JOB_LIMIT = 20;

    /** 错误原因列的截断长度（悬停看全文） */
    const ERROR_CHARS = 60;

    /** 本页 JS 的 handle */
    const JS_HANDLE = 'yimai-gso-wx-sync';

    /* ==================== 初始化 ==================== */

    /**
     * 只注册钩子，不查库、不读设置、不跑队列。
     *
     * 本方法在插件加载阶段执行（所有前台请求都会走到），任何昂贵操作都会拖慢整站。
     */
    public static function init() {
        // 优先级 20：顶级菜单由 class-admin.php 在 admin_menu 默认优先级 10 注册，
        // 子菜单必须排在它之后，父级 slug 才是确定存在的。
        add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_' . self::SAVE_ACTION, array( __CLASS__, 'handle_save' ) );

        add_action( 'add_meta_boxes_post', array( __CLASS__, 'register_meta_box' ) );
        add_action( 'save_post_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );

        // 只给 post 类型加列：同步不支持其它文章类型，加到页面/CPT 列表上只会是噪音。
        // WP 内核实际的钩子是 manage_{$post_type}_posts_columns（不带 $post_type 参数）。
        add_filter( 'manage_post_posts_columns', array( __CLASS__, 'add_column' ) );
        add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );

        add_action( 'wp_ajax_yimai_gso_wx_retry', array( __CLASS__, 'ajax_retry' ) );
        add_action( 'wp_ajax_yimai_gso_wx_skip', array( __CLASS__, 'ajax_skip' ) );
        add_action( 'wp_ajax_yimai_gso_wx_sync_now', array( __CLASS__, 'ajax_sync_now' ) );
    }

    /* ==================== 菜单与资源 ==================== */

    /**
     * 在「一麦GSO双擎优化」下挂「公众号同步」子菜单。
     *
     * capability 用 manage_options（不是主菜单的 edit_posts）：
     * 这个页面能改全局同步开关、能把文章送进公众号草稿箱，属于站点级配置。
     */
    public static function register_page() {
        add_submenu_page(
            'yimai-gso-center',
            '公众号同步',
            '公众号同步',
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * 只在「公众号同步」页和文章编辑页加载本模块 JS。
     *
     * 为什么不并进 class-admin.php 的 enqueue_assets：那里是所有插件页共用的底座，
     * 而同步页的交互（图片选择器、三个 AJAX、表格行状态回写）只有这两处用得上；
     * 挂全局等于让每个后台页都白下载一份。
     *
     * 版本号取 filemtime：改完 JS 立刻生效，不必为了看一眼效果去 bump 插件版本。
     */
    public static function enqueue_assets( $hook ) {
        $hook = (string) $hook;

        $is_sync_page = ( false !== strpos( $hook, self::PAGE_SLUG ) );

        $screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_editor = ( $screen && 'post' === $screen->base && 'post' === $screen->post_type );

        if ( ! $is_sync_page && ! $is_editor ) {
            return;
        }

        if ( $is_sync_page ) {
            // 兜底封面要用 wp.media 选图。这里显式调一次（幂等），
            // 不依赖 class-admin.php 是否也给本页 enqueue 过 media。
            wp_enqueue_media();
        }

        $relative = 'admin/js/wechat-sync-admin.js';
        $absolute = YIMAI_GSO_PLUGIN_DIR . $relative;
        $version  = defined( 'YIMAI_GSO_VERSION' ) ? YIMAI_GSO_VERSION : '2.10.0';

        if ( file_exists( $absolute ) ) {
            $stamp = filemtime( $absolute );
            if ( $stamp ) {
                $version = (string) $stamp;
            }
        }

        wp_enqueue_script(
            self::JS_HANDLE,
            YIMAI_GSO_PLUGIN_URL . $relative,
            array(),
            $version,
            true
        );

        wp_localize_script(
            self::JS_HANDLE,
            'yimaiGsoWxSync',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
                'mpUrl'   => self::mp_draft_box_url(),
                'i18n'    => array(
                    'failed'      => '操作失败',
                    'badResponse' => '服务器返回了非 JSON 内容（可能有安全插件拦截了 admin-ajax.php）',
                    'netError'    => '请求没发出去：',
                    'syncing'     => '同步中…（单篇最长约 2 分钟）',
                    'working'     => '提交中…',
                    'skipConfirm' => '取消排队后，这篇文章以后不会再自动同步。确定吗？',
                    'coverTitle'  => '选择兜底封面',
                    'coverButton' => '用这张图',
                    'coverAlt'    => '兜底封面',
                    'coverEmpty'  => '未选择。文章没有特色图片时会用这张图当封面。',
                    'noPost'      => '没有拿到文章 ID，请刷新页面后重试。',
                ),
            )
        );
    }

    /* ==================== 页面一：公众号同步 ==================== */

    /**
     * 同步页：第一屏就要能回答三个问题
     *  —— 现在能不能同步（统计卡 + 启用徽章）
     *  —— 某篇同步到哪一步了（最近任务表的状态列）
     *  —— 失败了为什么（错误原因列 + 悬停全文）
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html( '权限不足：只有管理员可以查看公众号同步。' ) );
        }

        $ready      = self::publisher_ready();
        $table_ok   = self::table_exists();
        $enabled    = $ready ? YIMAI_GSO_Wechat_Publisher::is_enabled() : false;
        $auto       = $ready ? YIMAI_GSO_Wechat_Publisher::is_auto_enabled() : true;
        $author     = self::setting( 'wx_default_author', '' );
        $cover_id   = (int) self::setting( 'wx_cover_fallback_id', 0 );
        $selected   = self::selected_categories();
        $categories = get_categories( array( 'hide_empty' => false, 'number' => 200 ) );
        $categories = is_array( $categories ) ? $categories : array();
        $stats      = self::collect_stats();
        $jobs       = $ready ? YIMAI_GSO_Wechat_Publisher::get_recent_jobs( self::JOB_LIMIT ) : array();
        $jobs       = is_array( $jobs ) ? $jobs : array();
        $latest_id  = self::latest_published_post_id();
        $just_saved = isset( $_GET['saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['saved'] ) );
        ?>
        <div class="wrap yimai-gso-wrap">
            <div class="yimai-gso-page">

                <div class="yimai-gso-page-head">
                    <div>
                        <h1><span class="dashicons dashicons-megaphone"></span>公众号同步</h1>
                        <p class="yimai-gso-page-desc">
                            把已发布的文章按公众号排版重写一遍，送进公众号草稿箱。
                            草稿只在公众号后台可见，群发这一步永远留给你自己做。
                        </p>
                        <?php if ( $latest_id <= 0 ) : ?>
                            <p class="yimai-gso-field-hint">当前还没有已发布的文章，先发一篇再回来同步。</p>
                        <?php endif; ?>
                    </div>
                    <div class="yimai-gso-actions">
                        <button type="button"
                                class="yimai-gso-btn-modern-primary"
                                id="yimai-gso-wx-sync-latest"
                                data-post-id="<?php echo esc_attr( $latest_id ); ?>"
                                title="同步最近发布的那一篇；如果它已经同步过，会覆盖原来那份草稿"
                                <?php echo $latest_id > 0 ? '' : 'disabled'; ?>>立即同步最新一篇</button>
                    </div>
                </div>

                <?php if ( $just_saved ) : ?>
                    <div class="yimai-gso-notice is-success"><p><strong>同步设置已保存。</strong>开关变更会立即生效，队列在一两分钟内按新设置出队。</p></div>
                <?php endif; ?>

                <?php if ( ! $ready ) : ?>
                    <div class="yimai-gso-notice is-error">
                        <p><strong>同步模块没有加载。</strong>includes/class-wechat-publisher.php 未引入，页面上的按钮点了也会失败，请先检查插件文件是否完整。</p>
                    </div>
                <?php elseif ( ! $table_ok ) : ?>
                    <div class="yimai-gso-notice is-error">
                        <p><strong>队列表还没建好。</strong>数据库里找不到任务表，同步会全部失败。停用再启用一次插件（或重新访问后台触发升级）即可重建。</p>
                    </div>
                <?php endif; ?>

                <div class="yimai-gso-notice is-info">
                    <p><strong>本功能只把文章写进公众号草稿箱，不会自动群发。</strong></p>
                    <p>同步完成后请到公众号后台（mp.weixin.qq.com → 草稿箱）复核排版、封面与摘要，再手动发布。这个插件不会替你按下「群发」。</p>
                </div>

                <div class="yimai-gso-stat-grid">
                    <div class="yimai-gso-stat">
                        <p class="yimai-gso-stat-k">待同步数</p>
                        <p class="yimai-gso-stat-v <?php echo $stats['pending'] > 0 ? 'is-info' : 'is-muted'; ?>"><?php echo (int) $stats['pending']; ?></p>
                    </div>
                    <div class="yimai-gso-stat">
                        <p class="yimai-gso-stat-k">已进草稿箱</p>
                        <p class="yimai-gso-stat-v is-pass"><?php echo (int) $stats['drafted']; ?></p>
                    </div>
                    <div class="yimai-gso-stat">
                        <p class="yimai-gso-stat-k">失败数</p>
                        <p class="yimai-gso-stat-v <?php echo $stats['failed'] > 0 ? 'is-warn' : 'is-muted'; ?>"><?php echo (int) $stats['failed']; ?></p>
                    </div>
                    <div class="yimai-gso-stat">
                        <p class="yimai-gso-stat-k">今日同步</p>
                        <p class="yimai-gso-stat-v"><?php echo (int) $stats['today']; ?></p>
                    </div>
                </div>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
                    <?php wp_nonce_field( self::SAVE_NONCE ); ?>

                    <div class="yimai-gso-section">
                        <div class="yimai-gso-section-head">
                            <h2><span class="dashicons dashicons-admin-generic"></span>同步设置</h2>
                            <span class="yimai-gso-badge <?php echo $enabled ? 'is-pass' : 'is-muted'; ?>"><?php echo $enabled ? '总开关已启用' : '总开关已关闭'; ?></span>
                        </div>
                        <div class="yimai-gso-section-body">

                            <div class="yimai-gso-field">
                                <span class="yimai-gso-field-label">启用公众号同步</span>
                                <div class="yimai-gso-field-control">
                                    <label><input type="checkbox" name="wx_sync_enabled" value="1" <?php checked( $enabled ); ?> /> 允许把文章同步进公众号草稿箱</label>
                                    <p class="yimai-gso-field-hint">这是总开关：关掉后不再自动入队，队列也停止出队。但「立即同步」按钮仍然可用——人工点的动作不该被开关拦住。</p>
                                </div>
                            </div>

                            <div class="yimai-gso-field">
                                <span class="yimai-gso-field-label">自动同步</span>
                                <div class="yimai-gso-field-control">
                                    <label><input type="checkbox" name="wx_sync_auto" value="1" <?php checked( $auto ); ?> /> 文章发布时自动加入同步队列</label>
                                    <p class="yimai-gso-field-hint">默认开启。取消勾选会明确保存为「关闭」——不会退回默认值，所以关了就是关了。</p>
                                </div>
                            </div>

                            <div class="yimai-gso-field">
                                <label class="yimai-gso-field-label" for="wx_default_author">默认作者</label>
                                <div class="yimai-gso-field-control">
                                    <input type="text"
                                           id="wx_default_author"
                                           name="wx_default_author"
                                           value="<?php echo esc_attr( $author ); ?>"
                                           placeholder="一麦瑜伽"
                                           autocomplete="off" />
                                    <p class="yimai-gso-field-hint">草稿卡片上显示的公众号作者名。留空则用「一麦瑜伽」。</p>
                                </div>
                            </div>

                            <div class="yimai-gso-field">
                                <span class="yimai-gso-field-label">同步分类</span>
                                <div class="yimai-gso-field-control">
                                    <?php if ( empty( $categories ) ) : ?>
                                        <p class="yimai-gso-field-hint">还没有任何文章分类，所有文章都会同步。</p>
                                    <?php else : ?>
                                        <?php foreach ( $categories as $term ) : ?>
                                            <?php if ( ! $term instanceof WP_Term ) : continue; endif; ?>
                                            <label>
                                                <input type="checkbox"
                                                       name="wx_sync_categories[]"
                                                       value="<?php echo esc_attr( (int) $term->term_id ); ?>"
                                                       <?php checked( in_array( (int) $term->term_id, $selected, true ) ); ?> />
                                                <?php echo esc_html( $term->name ); ?>（<?php echo (int) $term->count; ?>）
                                            </label>
                                        <?php endforeach; ?>
                                        <p class="yimai-gso-field-hint">全不选 = 所有分类都同步。勾了父分类时，子分类下的文章也算命中。</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="yimai-gso-field">
                                <span class="yimai-gso-field-label">兜底封面</span>
                                <div class="yimai-gso-field-control">
                                    <div id="yimai-gso-wx-cover-preview">
                                        <?php if ( $cover_id > 0 ) : ?>
                                            <?php
                                            echo wp_get_attachment_image(
                                                $cover_id,
                                                'thumbnail',
                                                false,
                                                array( 'alt' => '兜底封面' )
                                            );
                                            ?>
                                        <?php else : ?>
                                            <span class="yimai-gso-field-hint">未选择。文章没有特色图片时会用这张图当封面。</span>
                                        <?php endif; ?>
                                    </div>
                                    <input type="hidden" id="wx_cover_fallback_id" name="wx_cover_fallback_id" value="<?php echo esc_attr( $cover_id ); ?>" />
                                    <div class="yimai-gso-actions">
                                        <button type="button" class="button" id="yimai-gso-wx-cover-pick">选择图片</button>
                                        <button type="button" class="button" id="yimai-gso-wx-cover-clear">移除</button>
                                    </div>
                                    <p class="yimai-gso-field-hint">没有特色图片、也没设兜底封面时，那一篇会直接失败（微信必须有封面）。封面会自动裁成 900×383。</p>
                                </div>
                            </div>

                            <?php // 保存按钮放在区块内部：放在区块外时，≤782 下按钮底边会和下一张卡片的顶边贴成 0 间距（.yimai-gso-actions 只有上边距），看起来像两个东西粘在一起。 ?>
                            <div class="yimai-gso-actions">
                                <button type="submit" class="yimai-gso-btn-modern-primary">保存同步设置</button>
                            </div>
                        </div>
                    </div>
                </form>

                <div class="yimai-gso-section">
                    <div class="yimai-gso-section-head">
                        <h2><span class="dashicons dashicons-list-view"></span>最近同步任务</h2>
                        <span class="yimai-gso-badge is-muted">最近 <?php echo (int) count( $jobs ); ?> 条</span>
                    </div>
                    <div class="yimai-gso-section-body">
                        <?php if ( empty( $jobs ) ) : ?>
                            <div class="yimai-gso-empty">
                                <div class="yimai-gso-empty-icon">📄</div>
                                <div class="yimai-gso-empty-title">还没有同步记录</div>
                                <div class="yimai-gso-empty-hint">发布一篇文章，或在编辑页点「立即同步」；之后这里会显示每一篇同步到了哪一步、失败了为什么。</div>
                            </div>
                        <?php else : ?>
                            <div class="yimai-gso-table-wrap">
                                <table class="yimai-gso-table">
                                    <thead>
                                        <tr>
                                            <th>文章</th>
                                            <th class="is-nowrap">状态</th>
                                            <th class="is-nowrap is-num">尝试</th>
                                            <th class="is-nowrap">更新时间</th>
                                            <th>错误原因</th>
                                            <th class="is-nowrap">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $jobs as $job ) : ?>
                                            <?php
                                            if ( ! is_object( $job ) ) {
                                                continue;
                                            }
                                            $job_id   = (int) $job->id;
                                            $post_id  = (int) $job->post_id;
                                            $state    = (string) $job->state;
                                            $title    = (string) get_the_title( $post_id );
                                            $edit_url = get_edit_post_link( $post_id );
                                            $error    = isset( $job->error_msg ) ? (string) $job->error_msg : '';
                                            $err_code = isset( $job->error_code ) ? (int) $job->error_code : 0;
                                            if ( '' === $error && $err_code > 0 ) {
                                                $error = '接口返回 errcode ' . $err_code;
                                            }
                                            ?>
                                            <tr data-yimai-wx-row
                                                data-post-id="<?php echo esc_attr( $post_id ); ?>"
                                                data-job-id="<?php echo esc_attr( $job_id ); ?>"
                                                data-yimai-wx-state="<?php echo esc_attr( $state ); ?>">
                                                <td>
                                                    <?php if ( ! empty( $edit_url ) ) : ?>
                                                        <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( '' !== $title ? $title : '（文章已删除）' ); ?></a>
                                                    <?php else : ?>
                                                        <span><?php echo esc_html( '' !== $title ? $title : '（文章已删除）' ); ?></span>
                                                        <span>（#<?php echo (int) $post_id; ?>）</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="is-nowrap" data-yimai-wx-badge><?php echo self::get_badge_html( $state ); // 内部逐段 esc_html / esc_attr ?></td>
                                                <td class="is-nowrap is-num"><?php echo (int) $job->attempts; ?></td>
                                                <td class="is-nowrap"><?php echo esc_html( self::format_time( isset( $job->updated_at ) ? $job->updated_at : '' ) ); ?></td>
                                                <td>
                                                    <?php if ( '' !== $error ) : ?>
                                                        <span title="<?php echo esc_attr( $error ); ?>"><?php echo esc_html( wp_html_excerpt( $error, self::ERROR_CHARS, '…' ) ); ?></span>
                                                    <?php else : ?>
                                                        <span title="没有错误记录">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="is-nowrap" data-yimai-wx-actions><?php self::render_row_actions( $job ); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="yimai-gso-field-hint">
                                「查看草稿」只打开公众号后台首页（不带 media_id）。草稿箱在后台左侧菜单「草稿箱」，按标题核对后手动发布。
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="yimai-gso-notice" id="yimai-gso-wx-page-result" aria-live="polite" hidden><p></p></div>

            </div>
        </div>
        <?php
    }

    /**
     * 表格行内操作按钮：按状态给唯一正确的那个动作
     *
     * @param object $job 任务行
     */
    private static function render_row_actions( $job ) {
        $state   = (string) $job->state;
        $job_id  = (int) $job->id;
        $post_id = (int) $job->post_id;

        if ( 'failed' === $state || 'dead' === $state ) {
            printf(
                '<button type="button" class="button button-small" data-yimai-wx-action="retry" data-job-id="%1$d" data-post-id="%2$d">重试</button>',
                $job_id,
                $post_id
            );
            return;
        }

        if ( 'pending' === $state ) {
            printf(
                '<button type="button" class="button button-small" data-yimai-wx-action="skip" data-job-id="%1$d" data-post-id="%2$d">取消</button>',
                $job_id,
                $post_id
            );
            return;
        }

        if ( 'drafted' === $state ) {
            printf(
                '<a class="button button-small" href="%1$s" target="_blank" rel="noopener noreferrer">查看草稿</a>',
                esc_url( self::mp_draft_box_url() )
            );
            return;
        }

        // processing（正在跑，别抢） / skipped（已被排除） / 未知状态：不给动作
        echo '—';
    }

    /**
     * 公众号后台草稿箱入口
     *
     * 只给首页地址：草稿箱链接需要 token 参数，硬拼一个会 302 到登录页并丢掉目标；
     * 首页进去点「草稿箱」只多一步，且永远不会失效。
     *
     * @return string
     */
    private static function mp_draft_box_url() {
        return 'https://mp.weixin.qq.com/';
    }

    /* ==================== 统计 ==================== */

    /**
     * 四张统计卡的数据
     *
     * 每次都是独立 COUNT(*)：任务表通常只有几百行，四条走 idx_state_run 的
     * 计数比拉全表再在 PHP 里分类更省内存，也不会因为 LIMIT 漏数。
     *
     * @return array
     */
    private static function collect_stats() {
        global $wpdb;

        $stats = array(
            'pending' => 0,
            'drafted' => 0,
            'failed'  => 0,
            'today'   => 0,
        );

        if ( ! self::table_exists() ) {
            return $stats;
        }

        $table = YIMAI_GSO_Wechat_Publisher::get_table_name();

        $stats['pending'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE state IN (%s, %s)",
                YIMAI_GSO_Wechat_Publisher::STATE_PENDING,
                YIMAI_GSO_Wechat_Publisher::STATE_PROCESSING
            )
        );

        $stats['drafted'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE state = %s",
                YIMAI_GSO_Wechat_Publisher::STATE_DRAFTED
            )
        );

        $stats['failed'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE state IN (%s, %s)",
                YIMAI_GSO_Wechat_Publisher::STATE_FAILED,
                YIMAI_GSO_Wechat_Publisher::STATE_DEAD
            )
        );

        // 表里的时间一律是 UTC（与队列的 UTC_TIMESTAMP() 比较口径一致），
        // 所以「今天」的起点要先按站点时区算出来，再转回 UTC。
        $stats['today'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE state = %s AND updated_at >= %s",
                YIMAI_GSO_Wechat_Publisher::STATE_DRAFTED,
                self::today_start_utc()
            )
        );

        return $stats;
    }

    /**
     * 站点时区的「今天 00:00:00」换算成 UTC 字符串
     *
     * @return string
     */
    private static function today_start_utc() {
        if ( function_exists( 'current_datetime' ) ) {
            $start = current_datetime()->setTime( 0, 0, 0 );
            return $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        }

        // 老版本 WP 没有 current_datetime()：用 gmt_offset 手工换算（支持 +5.5 这类半小时时区）
        $offset = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
        $local  = gmdate( 'Y-m-d', time() + $offset );
        return gmdate( 'Y-m-d H:i:s', strtotime( $local . ' 00:00:00 UTC' ) - $offset );
    }

    /* ==================== 设置保存 ==================== */

    /**
     * admin-post.php 保存入口
     */
    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html( '权限不足：只有管理员可以修改同步设置。' ) );
        }

        check_admin_referer( self::SAVE_NONCE );

        if ( ! class_exists( 'YIMAI_GSO_Settings' ) ) {
            wp_safe_redirect( self::page_url( array( 'saved' => '0' ) ) );
            exit;
        }

        // 两个开关都显式写 1 / 0。
        // 尤其 wx_sync_auto：后端把「未设置 / 空串」都当开启，
        // 所以取消勾选必须写 0；删掉 option 会把用户刚关掉的开关重新打开。
        YIMAI_GSO_Settings::set( 'wx_sync_enabled', isset( $_POST['wx_sync_enabled'] ) ? 1 : 0 );
        YIMAI_GSO_Settings::set( 'wx_sync_auto', isset( $_POST['wx_sync_auto'] ) ? 1 : 0 );

        $author = isset( $_POST['wx_default_author'] )
            ? sanitize_text_field( wp_unslash( $_POST['wx_default_author'] ) )
            : '';
        YIMAI_GSO_Settings::set( 'wx_default_author', trim( $author ) );

        $cats = array();
        if ( isset( $_POST['wx_sync_categories'] ) && is_array( $_POST['wx_sync_categories'] ) ) {
            $raw = wp_unslash( $_POST['wx_sync_categories'] );
            foreach ( (array) $raw as $one ) {
                $term_id = absint( $one );
                if ( $term_id > 0 ) {
                    $cats[] = $term_id;
                }
            }
        }
        // 空数组 = 全部同步（后端就是这样判的），所以这里存空数组而不是删 option，
        // 免得和「没配置过」混在一起分不清。
        YIMAI_GSO_Settings::set( 'wx_sync_categories', array_values( array_unique( $cats ) ) );

        $cover_id = isset( $_POST['wx_cover_fallback_id'] ) ? absint( $_POST['wx_cover_fallback_id'] ) : 0;
        YIMAI_GSO_Settings::set( 'wx_cover_fallback_id', $cover_id );

        // 刚打开总开关时 cron 可能还没排上（ensure_schedule 挂在 init 上，
        // 而这次请求的 init 早于保存执行），这里补一次，避免队列要等到下一次访问才动。
        if ( self::publisher_ready() ) {
            YIMAI_GSO_Wechat_Publisher::ensure_schedule();
        }

        wp_safe_redirect( self::page_url( array( 'saved' => '1' ) ) );
        exit;
    }

    /**
     * 本页地址（可带额外参数）
     *
     * @param array $args
     * @return string
     */
    private static function page_url( $args = array() ) {
        $args = array_merge( array( 'page' => self::PAGE_SLUG ), (array) $args );
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    /* ==================== 页面二：编辑页 metabox ==================== */

    /**
     * 编辑页侧栏 metabox
     *
     * 只挂 post 类型：同步模块本身只处理 post（见 Publisher::on_publish）。
     *
     * @param WP_Post|null $post
     */
    public static function register_meta_box( $post = null ) {
        add_meta_box(
            'yimai-gso-wx-sync',
            '公众号同步',
            array( __CLASS__, 'render_meta_box' ),
            'post',
            'side',
            'high'
        );
    }

    /**
     * metabox 内容：当前状态 → 失败原因 → 两个动作 → 一行说明
     *
     * @param WP_Post $post
     */
    public static function render_meta_box( $post ) {
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $post_id  = (int) $post->ID;
        $job      = self::get_job( $post_id );
        $media_id = (string) get_post_meta( $post_id, self::meta_key( 'media' ), true );
        $optout   = (bool) get_post_meta( $post_id, self::meta_key( 'optout' ), true );

        $state    = '';
        $attempts = 0;
        $updated  = '';
        $error    = '';
        $err_code = 0;

        if ( $job ) {
            $state    = (string) $job->state;
            $attempts = (int) $job->attempts;
            // 侧栏只有 280px：带年份的完整时间会把值列挤成两行，这里省掉年份
            $updated  = self::format_time( isset( $job->updated_at ) ? $job->updated_at : '', 'm-d H:i' );
            $error    = isset( $job->error_msg ) ? (string) $job->error_msg : '';
            $err_code = isset( $job->error_code ) ? (int) $job->error_code : 0;
        } else {
            // 任务行可能还没建（文章刚发布、队列没跑），但 meta 里的状态更贴近用户认知
            $state = (string) get_post_meta( $post_id, self::meta_key( 'state' ), true );
        }

        if ( '' === $error && $err_code > 0 ) {
            $error = '接口返回 errcode ' . $err_code;
        }
        ?>
        <div class="yimai-gso-page">
            <?php wp_nonce_field( self::META_NONCE, self::META_FIELD ); ?>
            <input type="hidden" id="yimai-gso-wx-post-id" value="<?php echo esc_attr( $post_id ); ?>" />

            <p data-yimai-wx-badge id="yimai-gso-wx-meta-badge"><?php echo self::get_badge_html( $state ); // 内部逐段转义 ?></p>

            <?php if ( '' !== $media_id ) : ?>
                <p class="yimai-gso-field-hint is-success">草稿已生成。再次同步会覆盖同一份草稿，不会在草稿箱里多出一篇。</p>
            <?php endif; ?>

            <?php if ( '' !== $error ) : ?>
                <details>
                    <summary>失败原因<?php echo $err_code > 0 ? '（errcode ' . (int) $err_code . '）' : ''; ?></summary>
                    <p class="yimai-gso-field-hint is-error"><?php echo esc_html( $error ); ?></p>
                </details>
            <?php endif; ?>

            <?php if ( $job ) : ?>
                <div class="yimai-gso-kv">
                    <div class="yimai-gso-kv-k">尝试次数</div>
                    <div class="yimai-gso-kv-v"><?php echo (int) $attempts; ?></div>
                    <div class="yimai-gso-kv-k">最近更新</div>
                    <div class="yimai-gso-kv-v"><?php echo esc_html( '' !== $updated ? $updated : '—' ); ?></div>
                </div>
            <?php endif; ?>

            <?php if ( 'publish' !== $post->post_status ) : ?>
                <p class="yimai-gso-field-hint">这篇文章还没发布。队列只处理已发布文章，发布后会自动进队列，也可以回来点「立即同步」。</p>
            <?php endif; ?>

            <div class="yimai-gso-actions">
                <?php
                // 未发布的文章点「立即同步」只会得到一个 wx_skip 错误、并在任务表里多出一条「已跳过」，
                // 与其让用户点了才知道，不如直接禁用按钮 + 上面那行提示说明原因。
                ?>
                <button type="button"
                        class="yimai-gso-btn-modern-primary"
                        id="yimai-gso-wx-sync-now"
                        <?php echo ( 'publish' === $post->post_status ) ? '' : 'disabled'; ?>>立即同步</button>
            </div>

            <p class="yimai-gso-field-hint">
                <label>
                    <input type="checkbox" name="yimai_gso_wx_optout" value="1" <?php checked( $optout ); ?> />
                    不同步这篇文章
                </label>
            </p>

            <p class="yimai-gso-field-hint" id="yimai-gso-wx-meta-result" aria-live="polite"></p>
            <p class="yimai-gso-field-hint">仅进草稿箱，不自动发布。</p>
        </div>
        <?php
    }

    /**
     * 保存 metabox 的「不同步这篇文章」
     *
     * 勾上 = 写 optout；取消 = 删 meta（恢复自动同步）。
     * 这里**不**顺手入队：编辑文章时偷偷触发微信接口，是最容易被投诉的行为。
     *
     * @param int     $post_id
     * @param WP_Post $post
     */
    public static function save_meta_box( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! isset( $_POST[ self::META_FIELD ] ) ) {
            return;
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST[ self::META_FIELD ] ) );
        if ( ! wp_verify_nonce( $nonce, self::META_NONCE ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['yimai_gso_wx_optout'] ) && '1' === (string) wp_unslash( $_POST['yimai_gso_wx_optout'] ) ) {
            update_post_meta( $post_id, self::meta_key( 'optout' ), 1 );
            return;
        }

        delete_post_meta( $post_id, self::meta_key( 'optout' ) );
    }

    /* ==================== 页面三：文章列表列 ==================== */

    /**
     * 在标题列后面插一列「公众号」
     *
     * @param array $columns
     * @return array
     */
    public static function add_column( $columns ) {
        if ( ! is_array( $columns ) || isset( $columns[ self::COLUMN ] ) ) {
            return $columns;
        }

        $out = array();
        foreach ( $columns as $key => $label ) {
            $out[ $key ] = $label;
            if ( 'title' === $key ) {
                $out[ self::COLUMN ] = '公众号';
            }
        }

        // 万一别的插件把 title 列删了，也不能把新列丢掉
        if ( ! isset( $out[ self::COLUMN ] ) ) {
            $out[ self::COLUMN ] = '公众号';
        }

        return $out;
    }

    /**
     * 列内容：状态徽标；没有任务记录显示破折号
     *
     * 状态从 post meta 读，不查任务表：Publisher 每次状态变更都会同步写这个 meta，
     * 而 WP 列表页本来就会预热 post meta 缓存 —— 这里 0 次数据库查询。
     * 换成按 job 行查会变成「每行一条 SQL」，把每屏条数调到 999 时后台会明显变慢。
     * 失败原因不在列表里展开（列太窄，展开也是半句话），点标题进编辑页看侧栏 metabox。
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_column( $column, $post_id ) {
        if ( self::COLUMN !== $column ) {
            return;
        }

        $post_id = (int) $post_id;
        $state   = (string) get_post_meta( $post_id, self::meta_key( 'state' ), true );

        if ( '' === $state ) {
            if ( (bool) get_post_meta( $post_id, self::meta_key( 'optout' ), true ) ) {
                echo '<span title="已标记为不同步">—</span>';
                return;
            }
            echo '—';
            return;
        }

        echo self::get_badge_html( $state ); // 内部逐段转义
    }

    /* ==================== AJAX ==================== */

    /**
     * 三个 AJAX 共用的校验：nonce + 能力
     *
     * nonce 用 $die = false：失败也要返回 JSON，
     * 好让界面提示「刷新页面重试」而不是弹一个 -1。
     */
    private static function verify_ajax() {
        if ( false === check_ajax_referer( self::AJAX_NONCE, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => '页面已过期（校验失败），请刷新后重试' ), 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足：只有管理员能操作公众号同步' ), 403 );
        }
        if ( ! self::publisher_ready() ) {
            wp_send_json_error( array( 'message' => '同步模块没有加载，请检查插件文件是否完整' ), 500 );
        }
    }

    /**
     * 重试一个 failed / dead 任务
     */
    public static function ajax_retry() {
        self::verify_ajax();

        $job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
        if ( $job_id <= 0 ) {
            wp_send_json_error( array( 'message' => '缺少任务 ID' ), 400 );
        }

        $job = YIMAI_GSO_Wechat_Publisher::get_job_by_id( $job_id );
        if ( ! $job ) {
            wp_send_json_error( array( 'message' => '任务不存在（可能已被清理），刷新页面看看最新状态' ), 404 );
        }

        if ( ! YIMAI_GSO_Wechat_Publisher::retry( $job_id ) ) {
            wp_send_json_error( array( 'message' => '重新排队失败：这篇正在同步中，或数据库写入失败' ), 409 );
        }

        wp_send_json_success(
            self::job_payload( (int) $job->post_id, '已重新排队。下一轮定时任务（最多 5 分钟）会尝试同步。' )
        );
    }

    /**
     * 取消一个 pending 任务，并标记「以后不再自动同步」
     */
    public static function ajax_skip() {
        self::verify_ajax();

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '缺少文章 ID' ), 400 );
        }
        if ( ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => '文章不存在（可能已被删除）' ), 404 );
        }

        YIMAI_GSO_Wechat_Publisher::skip( $post_id );

        $payload = self::job_payload( $post_id, '' );

        if ( 'skipped' === $payload['state'] ) {
            $payload['message'] = '已取消排队，这篇文章以后不会再自动同步。';
            wp_send_json_success( $payload );
        }

        if ( '' === $payload['state'] ) {
            $payload['message'] = '已标记为「不同步这篇文章」。这篇文章目前还没有同步任务。';
            wp_send_json_success( $payload );
        }

        // processing / drafted：后端有意不打断（请求已经发出去了 / 草稿已经存在）
        $payload['message'] = '已标记为「不同步这篇文章」，但当前任务是「' . $payload['label'] . '」，队列状态没有改动。';
        wp_send_json_success( $payload );
    }

    /**
     * 立即同步一篇（同步执行，最长约 2 分钟）
     */
    public static function ajax_sync_now() {
        self::verify_ajax();

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '缺少文章 ID' ), 400 );
        }
        if ( ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => '文章不存在（可能已被删除）' ), 404 );
        }

        $result  = YIMAI_GSO_Wechat_Publisher::process_now( $post_id );
        $payload = self::job_payload( $post_id, '' );

        if ( is_wp_error( $result ) ) {
            $payload['message'] = $result->get_error_message();
            wp_send_json_error( $payload, 422 );
        }

        $payload['message'] = '已写入公众号草稿箱。请到公众号后台「草稿箱」复核封面、排版与摘要后手动发布。';
        wp_send_json_success( $payload );
    }

    /**
     * AJAX 返回给界面的状态快照（界面据此就地更新徽标与行内按钮，不用整页刷新）
     *
     * @param int    $post_id
     * @param string $message
     * @return array
     */
    private static function job_payload( $post_id, $message ) {
        $job   = self::get_job( $post_id );
        $state = $job ? (string) $job->state : '';
        $info  = self::state_info( $state );

        return array(
            'message' => (string) $message,
            'postId'  => (int) $post_id,
            'state'   => $state,
            'label'   => $info['label'],
            'badge'   => $info['badge'],
        );
    }

    /* ==================== 状态映射与工具 ==================== */

    /**
     * 状态 → 人话 + 徽章变体
     *
     * 六个状态与后端状态机一一对应；未知状态原样显示（灰色），
     * 这样后端将来加状态时界面不会显示成空白。
     *
     * @param string $state
     * @return array
     */
    private static function state_info( $state ) {
        $map = array(
            'pending'    => array( 'label' => '待处理', 'badge' => 'is-info' ),
            'processing' => array( 'label' => '处理中', 'badge' => 'is-info' ),
            'drafted'    => array( 'label' => '已进草稿箱', 'badge' => 'is-pass' ),
            'failed'     => array( 'label' => '失败', 'badge' => 'is-warn' ),
            'dead'       => array( 'label' => '已放弃', 'badge' => 'is-fail' ),
            'skipped'    => array( 'label' => '已跳过', 'badge' => 'is-muted' ),
        );

        $state = (string) $state;
        if ( isset( $map[ $state ] ) ) {
            return $map[ $state ];
        }

        return array(
            'label' => '' === $state ? '未同步' : $state,
            'badge' => 'is-muted',
        );
    }

    /**
     * 徽标 HTML
     *
     * 返回值里的每个变量都过了 esc_attr / esc_html，调用处直接 echo 即可。
     *
     * @param string $state
     * @return string
     */
    public static function get_badge_html( $state ) {
        $info = self::state_info( $state );
        return '<span class="yimai-gso-badge ' . esc_attr( $info['badge'] ) . '">' . esc_html( $info['label'] ) . '</span>';
    }

    /**
     * 任务行的 UTC 时间 → 站点时区显示
     *
     * @param string $gmt
     * @param string $format 表格用完整日期，侧栏 metabox 用省年份的短格式
     * @return string
     */
    private static function format_time( $gmt, $format = 'Y-m-d H:i' ) {
        $gmt = (string) $gmt;
        if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
            return '—';
        }

        $local = get_date_from_gmt( $gmt, (string) $format );
        return ( is_string( $local ) && '' !== $local ) ? $local : $gmt;
    }

    /**
     * 读设置（Settings 类缺失时给默认值，保证页面永远能渲染）
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    private static function setting( $key, $default = '' ) {
        if ( ! class_exists( 'YIMAI_GSO_Settings' ) ) {
            return $default;
        }
        $value = YIMAI_GSO_Settings::get( $key, $default );
        return ( null === $value ) ? $default : $value;
    }

    /**
     * 已勾选的分类 ID（兼容历史存法：数组 / 逗号分隔字符串）
     *
     * @return int[]
     */
    private static function selected_categories() {
        $raw = self::setting( 'wx_sync_categories', array() );

        if ( is_string( $raw ) ) {
            $raw = '' === trim( $raw ) ? array() : preg_split( '/[,\s]+/', $raw );
        }

        $out = array();
        foreach ( (array) $raw as $one ) {
            $term_id = (int) $one;
            if ( $term_id > 0 ) {
                $out[] = $term_id;
            }
        }

        return array_values( array_unique( $out ) );
    }

    /**
     * 最近发布的一篇文章（「立即同步最新一篇」的目标）
     *
     * @return int
     */
    private static function latest_published_post_id() {
        $ids = get_posts(
            array(
                'post_type'              => 'post',
                'post_status'            => 'publish',
                'numberposts'            => 1,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
            )
        );

        if ( ! is_array( $ids ) || empty( $ids ) ) {
            return 0;
        }

        return (int) $ids[0];
    }

    /**
     * 队列模块是否已加载（缺文件时页面要能降级显示，不能白屏）
     *
     * @return bool
     */
    private static function publisher_ready() {
        return class_exists( 'YIMAI_GSO_Wechat_Publisher' );
    }

    /**
     * 取一篇文章的任务行
     *
     * @param int $post_id
     * @return object|null
     */
    private static function get_job( $post_id ) {
        if ( ! self::publisher_ready() ) {
            return null;
        }
        $job = YIMAI_GSO_Wechat_Publisher::get_job( (int) $post_id );
        return ( is_object( $job ) ) ? $job : null;
    }

    /**
     * 任务表是否已建（迁移失败时页面要给可操作提示，而不是静默 0 / 空表）
     *
     * 单请求内缓存一次：一个页面会用到多次（统计 4 条 + 列表）。
     *
     * @return bool
     */
    private static function table_exists() {
        static $exists = null;

        if ( null !== $exists ) {
            return $exists;
        }

        if ( ! self::publisher_ready() ) {
            $exists = false;
            return $exists;
        }

        global $wpdb;
        $table  = YIMAI_GSO_Wechat_Publisher::get_table_name();
        $found  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $exists = ( (string) $found === (string) $table );

        return $exists;
    }

    /**
     * 文章 meta key：优先用后端常量，避免两处各写一份字符串日后漂移
     *
     * @param string $which state|optout|media
     * @return string
     */
    private static function meta_key( $which ) {
        if ( self::publisher_ready() ) {
            if ( 'state' === $which ) {
                return YIMAI_GSO_Wechat_Publisher::META_STATE;
            }
            if ( 'optout' === $which ) {
                return YIMAI_GSO_Wechat_Publisher::META_OPTOUT;
            }
            if ( 'media' === $which ) {
                return YIMAI_GSO_Wechat_Publisher::META_MEDIA_ID;
            }
        }

        $fallback = array(
            'state'  => '_yimai_wx_state',
            'optout' => '_yimai_wx_optout',
            'media'  => '_yimai_wx_media_id',
        );

        return isset( $fallback[ $which ] ) ? $fallback[ $which ] : '';
    }
}
