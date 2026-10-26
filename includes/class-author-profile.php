<?php
/**
 * GEO EEAT 作者专业档案模块
 *
 * 提供:
 * - 扩展 WordPress 用户资料（GEO 字段：职务/擅长领域/从业年限/资质/sameAs）
 * - 自动为作者存档页生成 Person Schema
 * - 文章 Article Schema 的 author 字段增强
 *
 * 数据存储: user_meta (yimai_gso_author_*)
 *
 * @package BaiqueAI_SEO_Content_Engine
 * @since 2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Author_Profile {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // WordPress 用户资料扩展钩子
        add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );

        // 作者存档页输出 Person Schema（仅在 is_author() 时触发）
        add_action( 'wp_head', array( $this, 'output_author_person_schema' ), 12 );

        // AJAX 保存（后台作者档案管理页用）
        add_action( 'wp_ajax_yimai_gso_save_author_profile', array( $this, 'ajax_save_profile' ) );
    }

    // ==================== 资料渲染 ====================

    /**
     * 在用户资料页添加 GEO 字段
     */
    public function render_profile_fields( $user ) {
        if ( ! current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }

        $title         = get_user_meta( $user->ID, 'yimai_gso_author_title', true );
        $expertise     = get_user_meta( $user->ID, 'yimai_gso_author_expertise', true );
        $years         = get_user_meta( $user->ID, 'yimai_gso_author_years', true );
        $certs         = get_user_meta( $user->ID, 'yimai_gso_author_certifications', true );
        $sameas        = get_user_meta( $user->ID, 'yimai_gso_author_sameas', true );
        ?>
        <h2>GEO 作者专业信息</h2>
        <p class="description" style="font-size:13px;color:#666;margin-bottom:14px;">
            以下信息将用于生成 Person Schema 和 AI 搜索中的作者权威度评估（EEAT 信号）。
        </p>
        <table class="form-table">
            <tr>
                <th><label for="yimai_gso_author_title">职务/头衔</label></th>
                <td>
                    <input type="text" name="yimai_gso_author_title" id="yimai_gso_author_title"
                           value="<?php echo esc_attr( $title ); ?>" class="regular-text"
                           placeholder="例如：高级SEO工程师、创始人兼CEO">
                    <p class="description">您的职业头衔，将出现在 Person Schema 的 jobTitle 字段中</p>
                </td>
            </tr>
            <tr>
                <th><label for="yimai_gso_author_expertise">擅长领域</label></th>
                <td>
                    <input type="text" name="yimai_gso_author_expertise" id="yimai_gso_author_expertise"
                           value="<?php echo esc_attr( $expertise ); ?>" class="regular-text"
                           placeholder="例如：SEO优化、内容营销、AI应用（逗号分隔）">
                    <p class="description">您的专业领域，帮助 AI 搜索引擎理解您的内容权威性</p>
                </td>
            </tr>
            <tr>
                <th><label for="yimai_gso_author_years">从业年限</label></th>
                <td>
                    <input type="number" name="yimai_gso_author_years" id="yimai_gso_author_years"
                           value="<?php echo esc_attr( $years ); ?>" min="0" max="100" style="width:80px;"
                           placeholder="如 10">
                    <p class="description">在核心领域的工作年限，数值越大 EEAT 评分越高</p>
                </td>
            </tr>
            <tr>
                <th><label for="yimai_gso_author_certifications">资质证书</label></th>
                <td>
                    <textarea name="yimai_gso_author_certifications" id="yimai_gso_author_certifications"
                              rows="3" class="large-text"
                              placeholder="例如：Google Analytics认证、百度搜索资源平台专家（每行一个）"><?php echo esc_textarea( $certs ); ?></textarea>
                    <p class="description">相关的行业资质和认证（每行一个）</p>
                </td>
            </tr>
            <tr>
                <th><label for="yimai_gso_author_sameas">sameAs 社交链接</label></th>
                <td>
                    <textarea name="yimai_gso_author_sameas" id="yimai_gso_author_sameas"
                              rows="3" class="large-text"
                              placeholder="例如：https://www.zhihu.com/people/xxx&#10;https://linkedin.com/in/xxx（每行一个URL）"><?php echo esc_textarea( $sameas ); ?></textarea>
                    <p class="description">您的社交媒体/专业平台主页链接，用于同一实体验证（每行一个 URL）</p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ==================== 资料保存 ====================

    /**
     * 保存用户资料 GEO 字段
     */
    public function save_profile_fields( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        $fields = array(
            'yimai_gso_author_title'          => 'sanitize_text_field',
            'yimai_gso_author_expertise'      => 'sanitize_text_field',
            'yimai_gso_author_years'          => 'absint',
            'yimai_gso_author_certifications' => 'sanitize_textarea_field',
            'yimai_gso_author_sameas'         => 'sanitize_textarea_field',
        );

        foreach ( $fields as $key => $sanitizer ) {
            if ( isset( $_POST[ $key ] ) ) {
                $value = call_user_func( $sanitizer, wp_unslash( $_POST[ $key ] ) );
                update_user_meta( $user_id, $key, $value );
            }
        }

        // 作者档案变更后清理 AI 就绪度审计缓存，避免 E3 长期显示旧结果
        delete_option( 'yimai_gso_geo_audit_report' );
    }

    // ==================== Person Schema ====================

    /**
     * 作者存档页输出 Person Schema
     */
    public function output_author_person_schema() {
        // 第三方SEO插件冲突检测：若已存在 Person Schema，不重复输出
        if ( class_exists( 'YIMAI_GSO_Schema_Generator' )
             && ! empty( YIMAI_GSO_Schema_Generator::detect_conflict_plugins() ) ) {
            return;
        }

        if ( ! is_author() ) {
            return;
        }

        $author_id = get_queried_object_id();
        if ( ! $author_id ) {
            return;
        }

        $schema = $this->build_author_person( $author_id );
        if ( ! $schema ) {
            return;
        }

        echo '<script type="application/ld+json">' .
             wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) .
             '</script>' . "\n";
    }

    /**
     * 构建 Person Schema
     */
    private function build_author_person( $author_id ) {
        $display_name = get_the_author_meta( 'display_name', $author_id );
        $description  = get_the_author_meta( 'description', $author_id );

        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Person',
            'name'     => $display_name,
            'url'      => get_author_posts_url( $author_id ),
        );

        if ( ! empty( $description ) ) {
            $schema['description'] = $description;
        }

        $title = get_user_meta( $author_id, 'yimai_gso_author_title', true );
        if ( ! empty( $title ) ) {
            $schema['jobTitle'] = $title;
        }

        $expertise = get_user_meta( $author_id, 'yimai_gso_author_expertise', true );
        if ( ! empty( $expertise ) ) {
            $schema['knowsAbout'] = $expertise;
        }

        $sameas = get_user_meta( $author_id, 'yimai_gso_author_sameas', true );
        if ( ! empty( $sameas ) ) {
            $links = array_filter( array_map( 'trim', explode( "\n", $sameas ) ) );
            if ( ! empty( $links ) ) {
                $schema['sameAs'] = array_values( $links );
            }
        }

        // ★ 修复（GEO 报告 S-8）：从业年限/资质证书两字段原先只存库不出 Schema，
        //   对前台 AI 可见性零贡献。映射到 schema.org Person 合法属性：
        //   hasCredential（资质证书）+ description 并入从业年限说明。
        $certifications = get_user_meta( $author_id, 'yimai_gso_author_certifications', true );
        if ( ! empty( $certifications ) ) {
            $certs = array_filter( array_map( 'trim', preg_split( '/[\n,，、;；]+/u', (string) $certifications ) ) );
            if ( ! empty( $certs ) ) {
                $schema['hasCredential'] = array_values( $certs );
            }
        }
        $years = get_user_meta( $author_id, 'yimai_gso_author_years', true );
        if ( ! empty( $years ) && is_numeric( $years ) ) {
            $years_text = sprintf( '从业 %d 年', absint( $years ) );
            $schema['description'] = empty( $schema['description'] )
                ? $years_text
                : $schema['description'] . '，' . $years_text;
        }

        return $schema;
    }

    /**
     * AJAX: 从后台作者档案页保存
     */
    public function ajax_save_profile() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => '用户不存在' ) );
        }

        update_user_meta( $user_id, 'yimai_gso_author_title', sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ) );
        update_user_meta( $user_id, 'yimai_gso_author_expertise', sanitize_text_field( wp_unslash( $_POST['expertise'] ?? '' ) ) );
        update_user_meta( $user_id, 'yimai_gso_author_years', absint( $_POST['years'] ?? 0 ) );
        update_user_meta( $user_id, 'yimai_gso_author_certifications', sanitize_textarea_field( wp_unslash( $_POST['certs'] ?? '' ) ) );
        update_user_meta( $user_id, 'yimai_gso_author_sameas', sanitize_textarea_field( wp_unslash( $_POST['sameas'] ?? '' ) ) );

        // 作者档案变更后清理 AI 就绪度审计缓存，避免 E3 长期显示旧结果
        delete_option( 'yimai_gso_geo_audit_report' );

        wp_send_json_success( array( 'message' => '作者信息已保存' ) );
    }
}
