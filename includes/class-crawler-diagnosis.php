<?php
/**
 * AI 爬虫友好度诊断 — robots.txt + meta robots 检查 + 优化建议
 *
 * 5 维诊断：
 *   1. 核心AI爬虫开放度（Crawler_Editor 配置）
 *   2. robots.txt 实际文件一致性
 *   3. 全局 noindex 扫描
 *   4. llms.txt / llms-full.txt 可访问性
 *   5. AI 元标签覆盖率
 *
 * 诊断结果缓存 1 小时（Transient）
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Crawler_Diagnosis {

    private static $instance = null;

    /** 关键 AI 爬虫列表（用于开放度检查） */
    const KEY_AI_CRAWLERS = array(
        'GPTBot', 'ChatGPT-User', 'OAI-SearchBot',   // OpenAI
        'Google-Extended', 'Gemini',                   // Google
        'ClaudeBot', 'PerplexityBot',                  // Anthropic + Perplexity
        'DeepSeekBot', 'Bytespider',                   // 中文主流
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_yimai_gso_l5_diagnosis_run',           array( $this, 'ajax_run' ) );
        add_action( 'wp_ajax_yimai_gso_l5_diagnosis_robots_preview', array( $this, 'ajax_robots_preview' ) );
    }

    // ==================== 全量诊断 ====================

    /**
     * 执行完整诊断并返回结果（供仪表盘调用，不直接输出 JSON）
     *
     * @param bool $skip_cache 是否跳过缓存
     * @return array
     */
    public function get_diagnosis_result( $skip_cache = false ) {
        $cache_key = 'yimai_gso_l5_diagnosis_result';
        if ( ! $skip_cache ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $checks   = array();
        $issues   = array();
        $weighted = 0;

        // 检查 1: 核心AI爬虫开放度（权重 30%）
        $check1 = $this->check_crawler_openness();
        $checks[] = $check1;
        $weighted += $check1['score'] * 0.3;
        if ( ! empty( $check1['issues'] ) ) {
            $issues = array_merge( $issues, $check1['issues'] );
        }

        // 检查 2: robots.txt 实际文件一致性（权重 20%）
        $check2 = $this->check_robots_consistency();
        $checks[] = $check2;
        $weighted += $check2['score'] * 0.2;
        if ( ! empty( $check2['issues'] ) ) {
            $issues = array_merge( $issues, $check2['issues'] );
        }

        // 检查 3: 全局 noindex 扫描（权重 20%）
        $check3 = $this->check_noindex();
        $checks[] = $check3;
        $weighted += $check3['score'] * 0.2;
        if ( ! empty( $check3['issues'] ) ) {
            $issues = array_merge( $issues, $check3['issues'] );
        }

        // 检查 4: llms.txt 可访问性（权重 15%）
        $check4 = $this->check_llms_accessibility();
        $checks[] = $check4;
        $weighted += $check4['score'] * 0.15;
        if ( ! empty( $check4['issues'] ) ) {
            $issues = array_merge( $issues, $check4['issues'] );
        }

        // 检查 5: AI 元标签覆盖率（权重 15%）
        $check5 = $this->check_ai_meta_coverage();
        $checks[] = $check5;
        $weighted += $check5['score'] * 0.15;
        if ( ! empty( $check5['issues'] ) ) {
            $issues = array_merge( $issues, $check5['issues'] );
        }

        $overall = round( $weighted );
        $overall = max( 0, min( 100, $overall ) );
        $grade   = $this->get_grade( $overall );

        // 被封锁的爬虫列表
        $blocked_crawlers = array();
        if ( class_exists( 'YIMAI_GSO_Crawler_Editor' ) ) {
            $rules = YIMAI_GSO_Crawler_Editor::get_rules();
            foreach ( self::KEY_AI_CRAWLERS as $bot ) {
                if ( isset( $rules[ $bot ] ) && 'disallow' === $rules[ $bot ] ) {
                    $blocked_crawlers[] = array(
                        'bot'          => $bot,
                        'suggestion'   => '建议在「AI爬虫编辑器」中将 ' . $bot . ' 设为 Allow，允许该AI搜索引擎索引你的内容',
                    );
                }
            }
        }

        $result = array(
            'overall_score'    => $overall,
            'grade'            => $grade,
            'checks'           => $checks,
            'blocked_crawlers' => $blocked_crawlers,
            'issues'           => $issues,
            'checked_at'       => current_time( 'mysql' ),
        );

        set_transient( $cache_key, $result, HOUR_IN_SECONDS );

        return $result;
    }

    /**
     * AJAX: yimai_gso_l5_diagnosis_run
     * 执行完整诊断
     */
    public function ajax_run() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $force = ! empty( $_POST['force'] );
        $result = $this->get_diagnosis_result( $force );
        wp_send_json_success( $result );
    }

    // ==================== 5 项检查 ====================

    /**
     * 检查 1: 核心 AI 爬虫开放度
     */
    private function check_crawler_openness() {
        $score     = 100;
        $detail    = array();
        $issues    = array();
        $blocked   = array();

        if ( ! class_exists( 'YIMAI_GSO_Crawler_Editor' ) ) {
            return array(
                'name'      => '核心AI爬虫开放度',
                'weight'    => 30,
                'score'     => 50,
                'max_score' => 100,
                'status'    => 'warn',
                'detail'    => 'AI爬虫编辑器模块未加载',
                'issues'    => array( '建议在「AI爬虫编辑器」中配置AI爬虫访问策略' ),
            );
        }

        $rules = YIMAI_GSO_Crawler_Editor::get_rules();

        $per_crawler_deduct = 100 / count( self::KEY_AI_CRAWLERS );

        foreach ( self::KEY_AI_CRAWLERS as $bot ) {
            if ( isset( $rules[ $bot ] ) && 'disallow' === $rules[ $bot ] ) {
                $score -= $per_crawler_deduct;
                $blocked[] = $bot;
            } elseif ( ! isset( $rules[ $bot ] ) ) {
                // 未明确配置，默认为允许（部分降分）
                $score -= $per_crawler_deduct * 0.3;
            }
        }

        $score = round( max( 0, $score ) );

        $status = 'pass';
        if ( count( $blocked ) >= 3 ) {
            $status = 'fail';
            $issues[] = sprintf( '%d 个关键AI爬虫被封锁（%s），可能导致被AI搜索引擎忽略',
                count( $blocked ), implode( '、', $blocked ) );
        } elseif ( count( $blocked ) > 0 ) {
            $status = 'warn';
            $issues[] = sprintf( '%d 个AI爬虫被封锁（%s），建议放开以提升AI可见性',
                count( $blocked ), implode( '、', $blocked ) );
        }

        $detail = empty( $blocked )
            ? '全部 ' . count( self::KEY_AI_CRAWLERS ) . ' 个关键AI爬虫均已开放'
            : count( $blocked ) . '/' . count( self::KEY_AI_CRAWLERS ) . ' 个爬虫被封锁';

        return array(
            'name'      => '核心AI爬虫开放度',
            'weight'    => 30,
            'score'     => $score,
            'max_score' => 100,
            'status'    => $status,
            'detail'    => $detail,
            'blocked'   => $blocked,
            'issues'    => $issues,
        );
    }

    /**
     * 检查 2: robots.txt 实际文件一致性
     * 对比实际 robots.txt 中 AI 爬虫规则与插件 Crawler_Editor 配置的差异
     */
    private function check_robots_consistency() {
        $score   = 100;
        $detail  = '实际 robots.txt 与插件配置一致';
        $issues  = array();

        // 获取实际 robots.txt
        $response = wp_remote_get( home_url( '/robots.txt' ), array(
            'timeout'   => 10,
            'sslverify' => false,
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'name'      => 'robots.txt 一致性',
                'weight'    => 20,
                'score'     => 50,
                'max_score' => 100,
                'status'    => 'warn',
                'detail'    => '无法获取 robots.txt：' . $response->get_error_message(),
                'issues'    => array( '请确认 /robots.txt 路径可被访问' ),
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return array(
                'name'      => 'robots.txt 一致性',
                'weight'    => 20,
                'score'     => 50,
                'max_score' => 100,
                'status'    => 'warn',
                'detail'    => "robots.txt 返回 HTTP {$code}",
                'issues'    => array( 'robots.txt 返回非 200 状态码' ),
            );
        }

        $content = wp_remote_retrieve_body( $response );

        // 获取插件配置中 AI 爬虫的 Allow/Disallow 状态
        $plugin_rules = array();
        if ( class_exists( 'YIMAI_GSO_Crawler_Editor' ) ) {
            $plugin_rules = YIMAI_GSO_Crawler_Editor::get_rules();
        }

        // 对比：统计实际 robots.txt 中被 Disallow 的 AI 爬虫
        $disallowed_bots  = array();
        $has_ai_rules     = false;

        foreach ( self::KEY_AI_CRAWLERS as $bot ) {
            if ( false !== stripos( $content, $bot ) ) {
                $has_ai_rules = true;
                // 检查该爬虫是否有 Disallow: /
                $pattern = '/' . preg_quote( $bot, '/' ) . '[\s\S]*?Disallow:\s*\/\s*$/im';
                if ( preg_match( $pattern, $content ) ) {
                    $disallowed_bots[] = $bot;
                }
            }
        }

        $disallowed_count = count( $disallowed_bots );

        if ( $disallowed_count > 0 ) {
            // 每处扣 10 分（比之前的 15 更温和），底分 20
            $score  = max( 20, 100 - $disallowed_count * 10 );

            $bot_list = implode( '、', array_slice( $disallowed_bots, 0, 5 ) );
            if ( $disallowed_count > 5 ) {
                $bot_list .= '等 ' . $disallowed_count . ' 个';
            }

            $detail = "实际 robots.txt 中有 {$disallowed_count} 个 AI 爬虫被 Disallow（{$bot_list}）";
            $issues[] = '建议在「GEO优化 → AI爬虫编辑器」中将需要的 AI 爬虫改为 Allow，减少过度封锁';
        } elseif ( ! $has_ai_rules ) {
            $detail = 'robots.txt 中未发现 AI 爬虫规则声明（插件规则可能未生效）';
            $score  = 70;
        }

        $status = $score >= 80 ? 'pass' : ( $score >= 50 ? 'warn' : 'fail' );

        return array(
            'name'      => 'robots.txt 一致性',
            'weight'    => 20,
            'score'     => $score,
            'max_score' => 100,
            'status'    => $status,
            'detail'    => $detail,
            'disallowed_bots' => $disallowed_bots,
            'issues'    => $issues,
        );
    }

    /**
     * 检查 3: 全局 noindex 扫描
     */
    private function check_noindex() {
        $score  = 100;
        $detail = '首页和文章均无 noindex 限制';
        $issues = array();

        // 检查首页
        $front_page_id = get_option( 'page_on_front' );
        $noindex_pages = array();

        // 检查 WordPress 设置 "建议搜索引擎不索引"
        if ( get_option( 'blog_public' ) != '1' ) {
            $score -= 50;
            $noindex_pages[] = '全站（WordPress设置 → 阅读 → 建议搜索引擎不索引本网站）';
        }

        // 检查首页 meta robots
        if ( $front_page_id ) {
            $front_noindex = get_post_meta( $front_page_id, 'yimai_gso_noindex', true );
            if ( '1' === $front_noindex ) {
                $score -= 30;
                $noindex_pages[] = '首页（已设置 noindex）';
            }
        }

        // 抽样检查最近文章
        $sample_posts = get_posts( array(
            'post_type'      => YIMAI_GSO_Post_Type_Helper::get_supported(),
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $noindex_post_count = 0;
        foreach ( $sample_posts as $post ) {
            $noindex = get_post_meta( $post->ID, 'yimai_gso_noindex', true );
            if ( '1' === $noindex ) {
                $noindex_post_count++;
                $noindex_pages[] = sprintf( '「%s」(#%d)', $post->post_title, $post->ID );
            }
        }

        if ( $noindex_post_count > 0 ) {
            $score -= $noindex_post_count * 10;
            $detail = sprintf( '%d 篇文章 / %d 个页面设置了 noindex', $noindex_post_count, count( $noindex_pages ) );
            if ( $noindex_post_count >= 5 ) {
                $issues[] = '大量文章设置了 noindex，AI 爬虫无法抓取。如非必要建议取消';
            }
        }

        $score  = max( 0, $score );
        $status = $score >= 80 ? 'pass' : ( $score >= 50 ? 'warn' : 'fail' );

        return array(
            'name'      => 'noindex 全局扫描',
            'weight'    => 20,
            'score'     => $score,
            'max_score' => 100,
            'status'    => $status,
            'detail'    => $detail,
            'noindex_pages' => array_slice( $noindex_pages, 0, 10 ),
            'issues'    => $issues,
        );
    }

    /**
     * 检查 4: llms.txt 可访问性
     */
    private function check_llms_accessibility() {
        $score   = 0;
        $detail  = '';
        $issues  = array();

        // 使用 HTTP HEAD 快速检查（带 5 秒超时）
        $llms_url  = home_url( '/llms.txt' );
        $full_url  = home_url( '/llms-full.txt' );
        $timeout   = 5;

        // 先检查 transient 缓存
        $llms_ok  = get_transient( 'yimai_gso_l5_llms_available' );
        $full_ok  = get_transient( 'yimai_gso_l5_llms_full_available' );

        if ( false === $llms_ok ) {
            $response = wp_remote_head( $llms_url, array( 'timeout' => $timeout, 'sslverify' => false ) );
            $llms_ok  = ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) );
            set_transient( 'yimai_gso_l5_llms_available', $llms_ok, HOUR_IN_SECONDS );
        }

        if ( false === $full_ok ) {
            $response = wp_remote_head( $full_url, array( 'timeout' => $timeout, 'sslverify' => false ) );
            $full_ok  = ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) );
            set_transient( 'yimai_gso_l5_llms_full_available', $full_ok, HOUR_IN_SECONDS );
        }

        $parts = array();
        if ( $llms_ok ) {
            $score += 50;
            $parts[] = 'llms.txt ✅';
        } else {
            $parts[] = 'llms.txt ❌';
            $issues[] = 'llms.txt 不可访问，AI 爬虫无法获取网站内容索引。建议在「LLMs生成」面板中启用';
        }

        if ( $full_ok ) {
            $score += 50;
            $parts[] = 'llms-full.txt ✅';
        } else {
            $parts[] = 'llms-full.txt ❌';
            $issues[] = 'llms-full.txt 不可访问，建议启用完整内容导出以提供深度索引';
        }

        $detail  = implode( ' · ', $parts );
        $status  = $score >= 100 ? 'pass' : ( $score >= 50 ? 'warn' : 'fail' );

        return array(
            'name'      => 'llms.txt 可访问性',
            'weight'    => 15,
            'score'     => $score,
            'max_score' => 100,
            'status'    => $status,
            'detail'    => $detail,
            'issues'    => $issues,
        );
    }

    /**
     * 检查 5: AI 元标签覆盖率
     * 抽样检查最近文章是否有 AI 可用的描述/关键词
     * 与 output_ai_meta() 使用相同的字段优先级：
     *   描述: yimai_gso_excerpt → description → Yoast → RankMath
     *   关键词: yimai_gso_keywords → Yoast → RankMath
     */
    private function check_ai_meta_coverage() {
        $score  = 100;
        $detail = '';
        $issues = array();

        // 检查首页
        $front_page_id = get_option( 'page_on_front' );
        $sample_ids    = array();

        if ( $front_page_id ) {
            $sample_ids[] = $front_page_id;
        }

        $sample_posts = get_posts( array(
            'post_type'      => YIMAI_GSO_Post_Type_Helper::get_supported(),
            'post_status'    => 'publish',
            'posts_per_page' => 5,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        foreach ( $sample_posts as $post ) {
            $sample_ids[] = $post->ID;
        }

        $total_checks     = 0;
        $missing_ai_desc  = 0;
        $missing_ai_kw    = 0;

        foreach ( $sample_ids as $pid ) {
            $total_checks++;

            // AI 描述：与 output_ai_meta() 同字段优先级
            $ai_desc = get_post_meta( $pid, 'yimai_gso_excerpt', true );
            if ( empty( $ai_desc ) ) $ai_desc = get_post_meta( $pid, 'description', true );
            if ( empty( $ai_desc ) ) $ai_desc = get_post_meta( $pid, '_yoast_wpseo_metadesc', true );
            if ( empty( $ai_desc ) ) $ai_desc = get_post_meta( $pid, 'rank_math_description', true );

            if ( empty( $ai_desc ) ) {
                $missing_ai_desc++;
            }

            // AI 关键词：与 output_ai_meta() 同字段优先级
            $ai_kw = get_post_meta( $pid, 'yimai_gso_keywords', true );
            if ( empty( $ai_kw ) ) $ai_kw = get_post_meta( $pid, '_yoast_wpseo_focuskw', true );
            if ( empty( $ai_kw ) ) $ai_kw = get_post_meta( $pid, 'rank_math_focus_keyword', true );

            if ( empty( $ai_kw ) ) {
                $missing_ai_kw++;
            }
        }

        if ( $total_checks > 0 ) {
            $desc_coverage = round( ( $total_checks - $missing_ai_desc ) / $total_checks * 100 );
            $kw_coverage   = round( ( $total_checks - $missing_ai_kw )   / $total_checks * 100 );
            $score         = round( ( $desc_coverage + $kw_coverage ) / 2 );

            $detail = sprintf( 'AI描述覆盖率 %d%%，AI关键词覆盖率 %d%%（抽样 %d 篇）',
                $desc_coverage, $kw_coverage, $total_checks );

            if ( $score < 50 ) {
                $issues[] = 'AI 元标签覆盖率偏低，建议在文章编辑时填写 SEO 描述（yimai_gso_excerpt/description）和关键词（yimai_gso_keywords）';
            }
        } else {
            $detail = '无已发布文章可检查';
        }

        $status = $score >= 70 ? 'pass' : ( $score >= 40 ? 'warn' : 'fail' );

        return array(
            'name'      => 'AI元标签覆盖率',
            'weight'    => 15,
            'score'     => $score,
            'max_score' => 100,
            'status'    => $status,
            'detail'    => $detail,
            'issues'    => $issues,
        );
    }

    // ==================== 等级判定 ====================

    /**
     * 综合评分 → 等级
     */
    private function get_grade( $score ) {
        if ( $score >= 85 ) {
            return array( 'letter' => 'A', 'label' => '优秀', 'color' => '#10b981', 'bg' => '#d1fae5' );
        } elseif ( $score >= 70 ) {
            return array( 'letter' => 'B', 'label' => '良好', 'color' => '#3b82f6', 'bg' => '#dbeafe' );
        } elseif ( $score >= 55 ) {
            return array( 'letter' => 'C', 'label' => '中等', 'color' => '#f59e0b', 'bg' => '#fef3c7' );
        } elseif ( $score >= 40 ) {
            return array( 'letter' => 'D', 'label' => '需改进', 'color' => '#f97316', 'bg' => '#ffedd5' );
        }
        return array( 'letter' => 'F', 'label' => '严重问题', 'color' => '#ef4444', 'bg' => '#fee2e2' );
    }

    // ==================== robots.txt 预览 ====================

    /**
     * AJAX: 预览解析后的 robots.txt AI 爬虫规则
     */
    public function ajax_robots_preview() {
        check_ajax_referer( 'yimai_gso_ajax_nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $response = wp_remote_get( home_url( '/robots.txt' ), array(
            'timeout'   => 10,
            'sslverify' => false,
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => '无法获取 robots.txt：' . $response->get_error_message() ) );
        }

        $content = wp_remote_retrieve_body( $response );
        $code    = wp_remote_retrieve_response_code( $response );

        // 提取 AI 爬虫相关规则
        $lines     = explode( "\n", $content );
        $ai_rules  = array();
        $current_ua = null;

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) || $line[0] === '#' ) {
                continue;
            }

            if ( preg_match( '/^User-agent:\s*(.+)/i', $line, $m ) ) {
                $current_ua = trim( $m[1] );

                // 检查是否属于已知 AI 爬虫
                $is_ai = false;
                foreach ( self::KEY_AI_CRAWLERS as $bot ) {
                    if ( false !== stripos( $current_ua, $bot ) ) {
                        $is_ai = true;
                        break;
                    }
                }

                if ( ! $is_ai ) {
                    $current_ua = null;  // 非 AI 爬虫段落，跳过
                }
            }

            if ( $current_ua ) {
                if ( preg_match( '/^(Allow|Disallow):\s*(.+)/i', $line, $m ) ) {
                    $ai_rules[] = array(
                        'ua'       => $current_ua,
                        'directive' => strtolower( $m[1] ),
                        'path'      => trim( $m[2] ),
                    );
                }
            }
        }

        // 提取所有 AI 爬虫 User-Agent
        $ai_uas = array();
        foreach ( $ai_rules as $rule ) {
            if ( ! in_array( $rule['ua'], $ai_uas ) ) {
                $ai_uas[] = $rule['ua'];
            }
        }

        wp_send_json_success( array(
            'http_code' => $code,
            'ai_rules'  => $ai_rules,
            'ai_uas'    => $ai_uas,
            'has_ai_rules' => ! empty( $ai_rules ),
            'raw_length' => strlen( $content ),
        ) );
    }
}
