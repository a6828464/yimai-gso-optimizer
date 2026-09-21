<?php
/**
 * 内容检测器：检测禁用词、极限词、敏感词、广告法风险词
 * 改为“发布后自动检测 + 混合式自动修正 + 日志记录”
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Content_Detector {

    private static $instance = null;
    private static $processing_posts = array();

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'transition_post_status', array( $this, 'handle_transition_post_status' ), 20, 3 );
    }

    public function handle_transition_post_status( $new_status, $old_status, $post ) {
        // 预览请求跳过（防止内存溢出）
        if ( function_exists( 'yimai_gso_is_preview_request' ) && yimai_gso_is_preview_request() ) {
            return;
        }

        if ( ! self::is_enabled() ) {
            return;
        }

        if ( ! ( $post instanceof WP_Post ) ) {
            return;
        }

        if ( ! YIMAI_GSO_Post_Type_Helper::is_supported( $post->post_type ) ) {
            return;
        }

        if ( 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }

        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
            return;
        }

        self::process_published_post( $post->ID, $post );
    }

    public static function is_enabled() {
        // 默认开启（安装后自动启用发布后自动检测与自动修正）
        return (bool) get_option( 'yimai_gso_detection_enabled', 1 );
    }

    public static function get_recent_logs( $limit = 20 ) {
        $logs = get_option( 'yimai_gso_detection_logs', array() );
        if ( ! is_array( $logs ) ) {
            return array();
        }

        return array_slice( $logs, 0, max( 1, absint( $limit ) ) );
    }

    public static function get_summary_stats() {
        $logs  = self::get_recent_logs( 100 );
        $stats = array(
            'total'      => 0,
            'clean'      => 0,
            'fixed'      => 0,
            'warning'    => 0,
            'last_run'   => '',
        );

        foreach ( $logs as $log ) {
            $stats['total']++;
            $status = $log['status'] ?? 'clean';
            if ( isset( $stats[ $status ] ) ) {
                $stats[ $status ]++;
            }
            if ( empty( $stats['last_run'] ) && ! empty( $log['time'] ) ) {
                $stats['last_run'] = $log['time'];
            }
        }

        return $stats;
    }

    public static function process_published_post( $post_id, $post = null ) {
        $post = $post instanceof WP_Post ? $post : get_post( $post_id );
        if ( ! ( $post instanceof WP_Post ) || 'publish' !== $post->post_status ) {
            return false;
        }

        if ( isset( self::$processing_posts[ $post_id ] ) ) {
            return false;
        }

        self::$processing_posts[ $post_id ] = true;

        $article = array(
            'title'   => (string) $post->post_title,
            'excerpt' => (string) $post->post_excerpt,
            'content' => (string) $post->post_content,
        );

        $initial_detection = self::detect_article( $article );
        $working_article   = $article;
        $actions           = array();
        $final_detection   = $initial_detection;
        $update_error      = null;

        if ( ! $initial_detection['passed'] ) {
            $replacement_result = self::apply_direct_replacements( $working_article, $initial_detection['hits'] );
            $working_article    = $replacement_result['article'];
            if ( ! empty( $replacement_result['applied'] ) ) {
                $actions[] = 'replace';
            }

            $final_detection = self::detect_article( $working_article );

            if ( ! $final_detection['passed'] ) {
                $mask_result     = self::apply_final_mask_replacements( $working_article, $final_detection['hits'] );
                $working_article = $mask_result['article'];
                if ( ! empty( $mask_result['applied'] ) ) {
                    $actions[] = 'final_mask';
                }
                $final_detection = self::detect_article( $working_article );
            }
        }

        $has_changed = self::article_changed( $article, $working_article );
        if ( $has_changed ) {
            $update_result = wp_update_post(
                array(
                    'ID'           => $post_id,
                    'post_title'   => $working_article['title'],
                    'post_excerpt' => $working_article['excerpt'],
                    'post_content' => $working_article['content'],
                ),
                true
            );

            if ( is_wp_error( $update_result ) ) {
                $update_error = $update_result->get_error_message();
            }
        }

        $report = array(
            'time'               => current_time( 'mysql' ),
            'post_id'            => $post_id,
            'post_title'         => get_the_title( $post_id ),
            'edit_url'           => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
            'view_url'           => get_permalink( $post_id ),
            'initial_summary'    => $initial_detection['summary'],
            'final_summary'      => $update_error ? '修正写回失败：' . $update_error : $final_detection['summary'],
            'initial_terms'      => $initial_detection['matched_terms'],
            'remaining_terms'    => $final_detection['matched_terms'],
            'initial_hits'       => $initial_detection['unique_hits'],
            'remaining_hits'     => $final_detection['unique_hits'],
            'actions'            => array_values( array_unique( $actions ) ),
            'updated'            => $has_changed && ! $update_error,
            'status'             => $update_error ? 'warning' : self::resolve_report_status( $initial_detection, $final_detection ),
            'status_label'       => $update_error ? '写回失败' : self::get_status_label( self::resolve_report_status( $initial_detection, $final_detection ) ),
        );

        self::store_post_report( $post_id, $report );
        self::append_log( $report );

        unset( self::$processing_posts[ $post_id ] );

        return $report;
    }

    public static function detect_article( $article ) {
        $fields = array(
            'title'   => self::normalize_text( $article['title'] ?? '' ),
            'excerpt' => self::normalize_text( $article['excerpt'] ?? '' ),
            'content' => self::normalize_text( $article['content'] ?? '' ),
        );

        $hits = array();
        foreach ( self::get_rules() as $category => $terms ) {
            foreach ( $terms as $term ) {
                $term = trim( (string) $term );
                if ( '' === $term ) {
                    continue;
                }

                $count     = 0;
                $locations = array();
                foreach ( $fields as $field_key => $field_value ) {
                    if ( '' === $field_value ) {
                        continue;
                    }

                    $field_count = substr_count( $field_value, $term );
                    if ( $field_count > 0 ) {
                        $count      += $field_count;
                        $locations[] = self::get_field_label( $field_key );
                    }
                }

                if ( $count > 0 ) {
                    $hits[] = array(
                        'term'           => $term,
                        'category'       => $category,
                        'category_label' => self::get_category_label( $category ),
                        'count'          => $count,
                        'locations'      => array_values( array_unique( $locations ) ),
                    );
                }
            }
        }

        usort( $hits, function ( $a, $b ) {
            if ( $a['count'] === $b['count'] ) {
                return strcmp( $a['term'], $b['term'] );
            }
            return $b['count'] - $a['count'];
        } );

        $total_occurrences = 0;
        foreach ( $hits as $hit ) {
            $total_occurrences += (int) $hit['count'];
        }

        return array(
            'passed'            => empty( $hits ),
            'unique_hits'       => count( $hits ),
            'total_occurrences' => $total_occurrences,
            'matched_terms'     => array_values( array_unique( wp_list_pluck( $hits, 'term' ) ) ),
            'hits'              => $hits,
            'summary'           => self::build_summary( $hits ),
        );
    }

    public static function get_rules() {
        $rules = array(
            'forbidden' => array(
                '刷单', '刷量', '外挂', '破解版', '代开发票', '套现', '洗钱', '赌博', '私彩', '毒品',
                '违禁', '违禁品', '枪支', '爆炸物', '色情', '淫秽', '返利送彩金'
            ),
            'superlative' => array(
                '国家级', '世界级', '宇宙级', '最高级', '最佳', '最好', '第一', '唯一', '首选', '顶级',
                '顶尖', '冠军', '销量冠军', '全网第一', '永久', '万能', '绝对', '100%', '零风险', '零副作用',
                '最先进', '最专业', '最安全', '最有效', '史上最低', '史无前例', '终极', '无敌'
            ),
            'sensitive' => array(
                '封号', '灰产', '黑产', '暴利', '引流神器', '躺赚', '一夜暴富', '稳赚不赔', '高回报',
                '精准加粉', '私域爆粉', '代考', '代写论文', '黑科技', '地下钱庄'
            ),
            'ads_law' => array(
                '包治百病', '治疗', '治愈', '根治', '彻底解决', '无效退款', '保证见效', '绝对安全', '无毒无害', '安全无忧',
                '全额退款', '百分百有效', '祖传秘方', '药到病除', '当天见效', '三天见效', '立刻见效', '无需节食', '无需运动', '无需成本'
            ),
        );

        return apply_filters( 'yimai_gso_content_detector_rules', $rules );
    }

    public static function get_category_label( $category ) {
        $labels = array(
            'forbidden'   => '禁用词',
            'superlative' => '极限词',
            'sensitive'   => '敏感词',
            'ads_law'     => '广告法风险词',
        );

        return isset( $labels[ $category ] ) ? $labels[ $category ] : $category;
    }

    public static function get_status_label( $status ) {
        $labels = array(
            'clean'   => '检测通过',
            'fixed'   => '已自动修正',
            'warning' => '仍需人工复查',
        );

        return $labels[ $status ] ?? $status;
    }

    private static function build_excerpt( $original_excerpt, $content ) {
        if ( '' === trim( (string) $original_excerpt ) ) {
            return '';
        }

        // 不用 wp_trim_words()，避免内部 preg_match_all('/./u') 对长文本的灾难性回溯
        $text = wp_strip_all_tags( (string) $content, true );
        $text = trim( preg_replace( '/\s+/', ' ', $text ) );
        if ( mb_strlen( $text ) > 160 ) {
            return mb_substr( $text, 0, 157 ) . '...';
        }
        return $text;
    }

    private static function article_changed( $original, $updated ) {
        return (string) ( $original['title'] ?? '' ) !== (string) ( $updated['title'] ?? '' )
            || (string) ( $original['excerpt'] ?? '' ) !== (string) ( $updated['excerpt'] ?? '' )
            || (string) ( $original['content'] ?? '' ) !== (string) ( $updated['content'] ?? '' );
    }

    private static function resolve_report_status( $initial_detection, $final_detection ) {
        if ( $initial_detection['passed'] ) {
            return 'clean';
        }

        return $final_detection['passed'] ? 'fixed' : 'warning';
    }

    private static function store_post_report( $post_id, $report ) {
        update_post_meta( $post_id, 'yimai_gso_detection_last_run', $report['time'] );
        update_post_meta( $post_id, 'yimai_gso_detection_status', $report['status'] );
        update_post_meta( $post_id, 'yimai_gso_detection_summary', $report['final_summary'] );
        update_post_meta( $post_id, 'yimai_gso_detection_report', $report );
    }

    private static function append_log( $report ) {
        $logs = get_option( 'yimai_gso_detection_logs', array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        array_unshift( $logs, $report );
        $logs = array_slice( $logs, 0, 50 );
        update_option( 'yimai_gso_detection_logs', $logs, false );
    }

    private static function apply_direct_replacements( $article, $hits ) {
        $applied = false;
        foreach ( $hits as $hit ) {
            $term        = (string) ( $hit['term'] ?? '' );
            $replacement = self::get_term_replacement( $hit );
            if ( '' === $term || '' === $replacement ) {
                continue;
            }

            foreach ( array( 'title', 'excerpt', 'content' ) as $field ) {
                if ( false !== strpos( (string) $article[ $field ], $term ) ) {
                    $article[ $field ] = str_replace( $term, $replacement, (string) $article[ $field ] );
                    $applied = true;
                }
            }
        }

        return array(
            'article' => $article,
            'applied' => $applied,
        );
    }

    private static function apply_final_mask_replacements( $article, $hits ) {
        $applied = false;
        foreach ( $hits as $hit ) {
            $term = (string) ( $hit['term'] ?? '' );
            if ( '' === $term ) {
                continue;
            }

            $replacement = self::get_final_mask_by_category( $hit['category'] ?? '' );
            foreach ( array( 'title', 'excerpt', 'content' ) as $field ) {
                if ( false !== strpos( (string) $article[ $field ], $term ) ) {
                    $article[ $field ] = str_replace( $term, $replacement, (string) $article[ $field ] );
                    $applied = true;
                }
            }
        }

        return array(
            'article' => $article,
            'applied' => $applied,
        );
    }

    private static function get_term_replacement( $hit ) {
        $term = (string) ( $hit['term'] ?? '' );
        $map  = self::get_replacement_map();

        if ( isset( $map[ $term ] ) ) {
            return $map[ $term ];
        }

        return self::get_final_mask_by_category( $hit['category'] ?? '' );
    }

    private static function get_final_mask_by_category( $category ) {
        $map = array(
            'forbidden'   => '合规内容',
            'superlative' => '适度描述',
            'sensitive'   => '审慎表述',
            'ads_law'     => '合规表述',
        );

        return $map[ $category ] ?? '合规表述';
    }

    private static function get_replacement_map() {
        return array(
            '刷单'     => '违规操作',
            '刷量'     => '异常操作',
            '外挂'     => '辅助工具',
            '破解版'   => '正式版',
            '代开发票' => '合规票务服务',
            '套现'     => '违规取现',
            '洗钱'     => '违法资金操作',
            '赌博'     => '违规博彩',
            '私彩'     => '违规彩票',
            '毒品'     => '违禁物品',
            '违禁'     => '违规',
            '违禁品'   => '违规商品',
            '枪支'     => '危险器械',
            '爆炸物'   => '危险物品',
            '色情'     => '低俗',
            '淫秽'     => '不雅',
            '返利送彩金' => '活动福利',
            '国家级'   => '高标准',
            '世界级'   => '行业领先水平',
            '宇宙级'   => '高规格',
            '最高级'   => '较高等级',
            '最佳'     => '更合适',
            '最好'     => '更稳妥',
            '第一'     => '靠前',
            '唯一'     => '少见',
            '首选'     => '优先考虑',
            '顶级'     => '高配',
            '顶尖'     => '成熟',
            '冠军'     => '领先',
            '销量冠军' => '热销产品',
            '全网第一' => '广受关注',
            '永久'     => '长期',
            '万能'     => '多场景适用',
            '绝对'     => '相对',
            '100%'     => '较高比例',
            '零风险'   => '需关注风险',
            '零副作用' => '副作用较低',
            '最先进'   => '较新',
            '最专业'   => '更专业',
            '最安全'   => '更稳妥',
            '最有效'   => '更有帮助',
            '史上最低' => '近期较低',
            '史无前例' => '比较少见',
            '终极'     => '进阶',
            '无敌'     => '强劲',
            '封号'     => '账号限制',
            '灰产'     => '灰色业务',
            '黑产'     => '违规产业',
            '暴利'     => '较高利润',
            '引流神器' => '引流工具',
            '躺赚'     => '轻量变现',
            '一夜暴富' => '快速起量',
            '稳赚不赔' => '需自行评估风险',
            '高回报'   => '潜在回报',
            '精准加粉' => '精准触达',
            '私域爆粉' => '私域增长',
            '代考'     => '代学服务',
            '代写论文' => '论文辅导',
            '黑科技'   => '新技术',
            '地下钱庄' => '非法资金通道',
            '包治百病' => '多场景适用',
            '治疗'     => '调理',
            '治愈'     => '改善',
            '根治'     => '持续改善',
            '彻底解决' => '有效缓解',
            '无效退款' => '不满意可协商处理',
            '保证见效' => '按实际情况而定',
            '绝对安全' => '相对稳妥',
            '无毒无害' => '配方温和',
            '安全无忧' => '尽量降低风险',
            '全额退款' => '售后可沟通',
            '百分百有效' => '效果因人而异',
            '祖传秘方' => '经验配方',
            '药到病除' => '逐步改善',
            '当天见效' => '短期可见变化',
            '三天见效' => '数日内可观察',
            '立刻见效' => '较快起效',
            '无需节食' => '可减少饮食负担',
            '无需运动' => '可配合轻运动',
            '无需成本' => '成本较低',
        );
    }

    private static function get_field_label( $field_key ) {
        $labels = array(
            'title'   => '标题',
            'excerpt' => '摘要',
            'content' => '正文',
        );

        return isset( $labels[ $field_key ] ) ? $labels[ $field_key ] : $field_key;
    }

    private static function normalize_text( $text ) {
        $text = wp_strip_all_tags( (string) $text, true );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return trim( (string) $text );
    }

    private static function build_summary( $hits ) {
        if ( empty( $hits ) ) {
            return '未发现禁用词、极限词、敏感词或广告法风险词';
        }

        $parts = array();
        foreach ( array_slice( $hits, 0, 6 ) as $hit ) {
            $parts[] = sprintf(
                '%1$s（%2$s / %3$s）',
                $hit['term'],
                $hit['category_label'],
                implode( '、', $hit['locations'] )
            );
        }

        if ( count( $hits ) > 6 ) {
            $parts[] = '其余 ' . ( count( $hits ) - 6 ) . ' 项风险词';
        }

        return '命中 ' . count( $hits ) . ' 个风险词：' . implode( '，', $parts );
    }
}
