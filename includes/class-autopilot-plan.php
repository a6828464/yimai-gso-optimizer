<?php
/**
 * 永动发布计划配置管理
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Autopilot_Plan {

    const OPTION_KEY = 'yimai_gso_autopilot_settings';

    public static function get_defaults() {
        return array(
            'enabled'        => 0,
            'run_time'       => '08:00',
            'daily_count'    => 3,
            'topic_keywords' => '',
            'extra_prompt'   => '',
            'category_id'    => absint( get_option( 'yimai_gso_default_category', 1 ) ),
            'word_count'     => absint( get_option( 'yimai_gso_word_count', 1000 ) ),
            'image_count'    => absint( get_option( 'yimai_gso_image_count', 1 ) ),
            'tags'           => '',
            'use_ai_tags'    => 1,
            'auto_category'  => 1,
            'post_status'    => 'publish',
            'future_delay_hours' => 2,
            'log_retention_days' => 30,
        );
    }

    public static function get_settings() {
        $settings = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return wp_parse_args( self::sanitize_settings( $settings ), self::get_defaults() );
    }

    public static function save_settings( $raw ) {
        $settings = self::sanitize_settings( is_array( $raw ) ? $raw : array() );
        $settings = wp_parse_args( $settings, self::get_defaults() );
        update_option( self::OPTION_KEY, $settings );
        return $settings;
    }

    public static function sanitize_settings( $raw ) {
        $defaults = self::get_defaults();
        $settings = wp_parse_args( is_array( $raw ) ? $raw : array(), $defaults );

        $run_time = sanitize_text_field( $settings['run_time'] ?? $defaults['run_time'] );
        if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $run_time ) ) {
            $run_time = $defaults['run_time'];
        }

        $post_status = sanitize_text_field( $settings['post_status'] ?? 'publish' );
        if ( ! in_array( $post_status, array( 'publish', 'draft', 'future' ), true ) ) {
            $post_status = 'publish';
        }

        $topic_keywords = isset( $settings['topic_keywords'] ) ? wp_unslash( $settings['topic_keywords'] ) : '';
        $extra_prompt   = isset( $settings['extra_prompt'] ) ? wp_unslash( $settings['extra_prompt'] ) : '';

        return array(
            'enabled'        => empty( $settings['enabled'] ) ? 0 : 1,
            'run_time'       => $run_time,
            'daily_count'    => max( 1, min( 10, absint( $settings['daily_count'] ?? $defaults['daily_count'] ) ) ),
            'topic_keywords' => self::sanitize_multiline_text( $topic_keywords ),
            'extra_prompt'   => self::sanitize_multiline_text( $extra_prompt ),
            'category_id'    => max( 1, absint( $settings['category_id'] ?? $defaults['category_id'] ) ),
            'word_count'     => max( 300, min( 5000, absint( $settings['word_count'] ?? $defaults['word_count'] ) ) ),
            'image_count'    => max( 0, min( 5, absint( $settings['image_count'] ?? $defaults['image_count'] ) ) ),
            'tags'           => sanitize_text_field( $settings['tags'] ?? '' ),
            'use_ai_tags'    => empty( $settings['use_ai_tags'] ) ? 0 : 1,
            'auto_category'  => empty( $settings['auto_category'] ) ? 0 : 1,
            'post_status'    => $post_status,
            'future_delay_hours' => max( 1, min( 3, absint( $settings['future_delay_hours'] ?? $defaults['future_delay_hours'] ) ) ),
            'log_retention_days' => max( 7, min( 365, absint( $settings['log_retention_days'] ?? $defaults['log_retention_days'] ) ) ),
        );
    }

    public static function sanitize_multiline_text( $text ) {
        $text = (string) $text;
        $text = str_replace( array( "\r\n", "\r" ), "\n", $text );
        $text = trim( $text );
        if ( '' === $text ) {
            return '';
        }

        $lines = array();
        foreach ( explode( "\n", $text ) as $line ) {
            $line = sanitize_text_field( trim( $line ) );
            if ( '' !== $line ) {
                $lines[] = $line;
            }
        }

        return implode( "\n", $lines );
    }

    public static function get_topic_lines( $settings = null ) {
        $settings = is_array( $settings ) ? $settings : self::get_settings();
        $raw      = str_replace( array( "\r\n", "\r" ), "\n", (string) ( $settings['topic_keywords'] ?? '' ) );
        $items    = array();

        foreach ( explode( "\n", $raw ) as $line ) {
            $line = trim( $line );
            if ( '' !== $line ) {
                $items[] = $line;
            }
        }

        if ( empty( $items ) && class_exists( 'YIMAI_GSO_Knowledge_Center' ) ) {
            $items = YIMAI_GSO_Knowledge_Center::get_topic_seeds( 30 );
        }

        return $items;
    }

    public static function has_topic_keywords( $settings = null ) {
        return ! empty( self::get_topic_lines( $settings ) );
    }

    public static function pick_topic_keyword( $settings = null, $date_key = '' ) {
        $topics = self::get_topic_lines( $settings );
        if ( empty( $topics ) ) {
            return '';
        }

        if ( count( $topics ) === 1 ) {
            return $topics[0];
        }

        $date_key = $date_key ? (string) $date_key : self::get_local_date_key();
        $hash     = absint( sprintf( '%u', crc32( $date_key ) ) );
        $index    = $hash % count( $topics );

        return $topics[ $index ];
    }

    public static function get_local_date_key( $timestamp = null ) {
        $timestamp = null === $timestamp ? time() : (int) $timestamp;
        return wp_date( 'Y-m-d', $timestamp, wp_timezone() );
    }

    public static function get_due_timestamp_for_date( $date_key, $settings = null ) {
        $settings = is_array( $settings ) ? $settings : self::get_settings();
        $date_key = $date_key ? $date_key : self::get_local_date_key();
        $run_time = $settings['run_time'] ?? '08:00';

        try {
            $date_time = new DateTimeImmutable( $date_key . ' ' . $run_time . ':00', wp_timezone() );
            return $date_time->getTimestamp();
        } catch ( Exception $e ) {
            return 0;
        }
    }

    public static function is_due_now( $settings = null ) {
        $settings = is_array( $settings ) ? $settings : self::get_settings();
        // get_due_timestamp_for_date() 使用 DateTimeImmutable::getTimestamp()，返回标准 UTC Unix 时间戳
        // 因此比较时必须使用 time()（UTC 时间戳），不能用 current_time('timestamp')（本地偏移后的伪时间戳）
        return time() >= self::get_due_timestamp_for_date( self::get_local_date_key(), $settings );
    }

    public static function get_next_run_label( $settings = null ) {
        // 确保传入的 settings 已补齐默认值（避免 Undefined array key 警告）
        $settings = ( is_array( $settings ) && isset( $settings['run_time'] ) ) ? $settings : self::get_settings();
        $now      = new DateTimeImmutable( 'now', wp_timezone() );
        $today    = self::get_local_date_key();
        $due_ts   = self::get_due_timestamp_for_date( $today, $settings );

        if ( $due_ts > 0 && $due_ts > $now->getTimestamp() ) {
            return wp_date( 'm-d H:i', $due_ts, wp_timezone() ) . '（今天）';
        }

        try {
            $next = $now->setTime( 0, 0, 0 )->modify( '+1 day' );
            $next = $next->setTime(
                absint( substr( $settings['run_time'], 0, 2 ) ),
                absint( substr( $settings['run_time'], 3, 2 ) ),
                0
            );
            return $next->format( 'm-d H:i' ) . '（明天）';
        } catch ( Exception $e ) {
            return '暂无';
        }
    }

    public static function build_daily_run_key( $date_key = '' ) {
        $date_key = $date_key ? $date_key : self::get_local_date_key();
        return 'daily-' . $date_key;
    }

    public static function build_manual_run_key() {
        return 'manual-' . wp_date( 'Ymd-His', time(), wp_timezone() );
    }

    public static function build_run_snapshot( $settings = null, $run_key = '', $trigger_source = 'cron' ) {
        $settings   = is_array( $settings ) ? $settings : self::get_settings();
        $date_key   = preg_replace( '/^(daily-|manual-)/', '', (string) $run_key );
        $date_key   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_key ) ? $date_key : self::get_local_date_key();
        $topic_seed = self::pick_topic_keyword( $settings, $date_key );

        $snapshot = $settings;
        $snapshot['resolved_topic_keyword'] = $topic_seed;
        $snapshot['run_key']                = $run_key;
        $snapshot['trigger_source']         = $trigger_source;
        $snapshot['language']               = YIMAI_GSO_Settings::get( 'article_language', 'zh_CN' );
        $snapshot['created_local_time']     = current_time( 'mysql' );

        return $snapshot;
    }

    /**
     * 标准分类体系：slug 必须与站点现有分类保持一致，避免重复建类。
     * keywords 用于「按选题自动归类」，越长越具体的关键词权重越高。
     */
    public static function get_standard_categories() {
        return array(
            'xinshou-rumen'   => array(
                'name'     => '新手入门',
                'desc'     => '第一次进馆、零基础起步、课前准备与流程',
                'keywords' => array( '新手', '第一次', '零基础', '入门', '会尴尬', '跟得上', '要不要先', '准备什么', '什么都不会', '没运动过', '穿什么', '第一节', '从零开始', '来得及', '要不要吃饭' ),
            ),
            'titai-liliao'    => array(
                'name'     => '体态与理疗',
                'desc'     => '圆肩驼背、骨盆前倾、腰颈不适、睡眠压力与身体不适的练习方案',
                'keywords' => array( '驼背', '圆肩', '骨盆', '前倾', '假胯宽', '腰疼', '腰痛', '肩颈', '颈椎', '含胸', '肋骨', '体态', '久坐', '僵硬', '脖子', '下背', '腿型', '脊柱', '前伸', '膝盖', '手腕', '脚踝', '小腿', '浮肿', '手脚冰凉', '怕冷', '睡眠', '失眠', '压力', '焦虑', '减压', '放松', '生理期', '头晕', '酸痛', '体力', '耐力', '代谢', '不适', '基数', '没力气', '提不动', '打字', '高跟鞋', '开车', '低头', '久站', '酸', '柔韧', '很硬', '摸不到', '骨密度', '前屈' ),
            ),
            'chanhou-yunchan' => array(
                'name'     => '产后与孕产',
                'desc'     => '产后修复、孕期练习、盆底与腹直肌',
                'keywords' => array( '产后', '孕', '剖腹产', '顺产', '盆底', '漏尿', '腹直肌', '月子', '备孕', '二胎', '妈妈', '生完', '胯变宽' ),
                'strong'   => array( '产后', '剖腹产', '盆底', '腹直肌', '漏尿', '孕晚期', '孕期' ),
            ),
            'pulati-qixie'    => array(
                'name'     => '普拉提器械',
                'desc'     => '核心床、稳踏椅、凯迪拉克床、梯桶与垫上普拉提',
                'keywords' => array( '普拉提', '核心床', '稳踏椅', '凯迪拉克', '梯桶', '器械', '垫上', 'reformer', 'chair', '大器械' ),
                'strong'   => array( '核心床', '稳踏椅', '凯迪拉克', '梯桶', '普拉提大器械', '垫上普拉提' ),
            ),
            'yujia-kecheng'   => array(
                'name'     => '瑜伽课程',
                'desc'     => '空中、墙绳、椅子、轮、颂钵、芳香、蹦极、哈他与阴瑜伽',
                'keywords' => array( '空中', '墙绳', '椅子瑜伽', '轮瑜伽', '颂钵', '芳香', '蹦极', '哈他', '阴瑜伽', '内观流', '流瑜伽', '冥想', '呼吸', '拉伸' ),
                'strong'   => array( '空中瑜伽', '墙绳', '椅子瑜伽', '轮瑜伽', '颂钵', '芳香瑜伽', '蹦极', '哈他', '阴瑜伽', '内观流', '流瑜伽' ),
            ),
            'suxing-jianzhi'  => array(
                'name'     => '塑形与减脂',
                'desc'     => '维密体雕、抗阻力塑形、减脂与线条训练',
                'keywords' => array( '塑形', '减脂', '减肚子', '维密', '体雕', '线条', '变瘦', '掉秤', '臀', '马甲线', '体重没变', '体重变化', '体重', '身材', '核心力量', '粗壮', '练细', '大腿', '胯' ),
                'strong'   => array( '维密', '体雕' ),
            ),
            'xuanguan-banka'  => array(
                'name'     => '选馆与办卡',
                'desc'     => '怎么选馆、办卡避坑、价格构成与卡项对比',
                'keywords' => array( '哪家', '怎么选', '避坑', '办卡', '年卡', '次卡', '值不值', '价格', '多少钱', '收费', '推销', '退款', '对比', '区别', '划算', '预算', '套餐', '可信', '能信', '跑路', '能换', '私教', '条款', '预付', '买课', '报课', '体验课', '停卡', '能不能停', '转店', '有效期', '分期', '贵', '包瘦', '加课', '装修', '专业吗' ),
            ),
            'menwei-yuyue'    => array(
                'name'     => '门店与预约',
                'desc'     => '两家门店、地址交通、停车、馆内设施与预约',
                'keywords' => array( '门店', '地址', '停车', '地铁', '杉杉', '绿地', '缤纷城', '来福士', '淋浴', '储物', '更衣', '预约', '约课', '采光', '教室', '环境', '鄞州', '江北', '宁波', '人太多', '一节课', '带娃', '孩子', '朋友', '节假日', '周末', '空调', '饮水', '柜' ),
            ),
            'lianxi-baike'    => array(
                'name'     => '练习百科',
                'desc'     => '练习频率、时间安排、装备选择与常见疑问的科普解释',
                'keywords' => array( '多久', '频率', '几次', '时间', '空腹', '洗澡', '装备', '瑜伽垫', '辅具', '瑜伽砖', '伸展带', '毛巾', '在家练', '线上', '冥想', '呼吸', '原理', '区别', '为什么', '怎么回事', '科学', '入门知识', '一周', '每天', '有用', '会不会', '能改善', '反弹', '多久见效' ),
                'strong'   => array( '瑜伽垫', '辅具', '瑜伽砖', '伸展带', '空腹', '洗澡', '原理' ),
            ),
            'jiaopei-qiye'    => array(
                'name'     => '教培与企业课',
                'desc'     => 'RYT200 教练培训、企业团建与异业合作',
                'keywords' => array( 'RYT', '教培', '教练证', '教练培训', '企业', '团建', '异业', '月子中心', '上门' ),
                'strong'   => array( 'RYT', '教培', '教练证', '教练培训', '企业瑜伽', '团建', '异业' ),
            ),
        );
    }

    /**
     * 按选题文本自动匹配标准分类。
     *
     * 命中规则：关键词出现在选题里即计分（按关键词字数加权，越长越具体越优先），
     * 取最高分分类；未命中返回 $fallback_id（发布参数里选的兜底分类）。
     *
     * @param string $topic       选题/标题文本
     * @param int    $fallback_id 兜底分类 ID
     * @return int
     */
    public static function map_category_for_topic( $topic, $fallback_id = 0 ) {
        $topic = trim( (string) $topic );
        if ( '' === $topic ) {
            return absint( $fallback_id );
        }

        // 第一层：强特征词优先（课程名 / 器械名 / 教培词 / 产后词），命中即归类，
        // 避免「空中瑜伽适合新手吗」被「新手」抢走、「练普拉提体重没变」被「普拉提」抢走。
        $strong_hits = array();
        foreach ( self::get_standard_categories() as $slug => $category ) {
            foreach ( (array) ( $category['strong'] ?? array() ) as $needle ) {
                if ( '' !== $needle && false !== mb_stripos( $topic, $needle ) ) {
                    $strong_hits[ $slug ] = ( $strong_hits[ $slug ] ?? 0 ) + mb_strlen( $needle );
                }
            }
        }
        if ( ! empty( $strong_hits ) ) {
            arsort( $strong_hits );
            $slug = (string) key( $strong_hits );
            $term = get_term_by( 'slug', $slug, 'category' );
            if ( $term ) {
                return (int) $term->term_id;
            }
        }

        $scores = array();
        foreach ( self::get_standard_categories() as $slug => $category ) {
            $score = 0;
            foreach ( $category['keywords'] as $keyword ) {
                if ( '' !== $keyword && false !== mb_stripos( $topic, $keyword ) ) {
                    $score += mb_strlen( $keyword );
                }
            }
            if ( $score > 0 ) {
                $scores[ $slug ] = $score;
            }
        }

        if ( empty( $scores ) ) {
            return absint( $fallback_id );
        }

        arsort( $scores );
        $slug = (string) key( $scores );
        $term = get_term_by( 'slug', $slug, 'category' );

        return $term ? (int) $term->term_id : absint( $fallback_id );
    }

    public static function build_article_prompt( $title, $snapshot ) {
        $title        = trim( (string) $title );
        $extra_prompt = trim( (string) ( $snapshot['extra_prompt'] ?? '' ) );
        $requirements = "文章服务于一麦瑜伽官网博客，优先回答宁波本地用户的真实问题；品牌事实必须来自已导入知识资料；不得虚构门店、价格、教练资质、会员案例或效果；健康类内容不得诊断、治疗或保证效果。";
        if ( '' !== $extra_prompt ) {
            $requirements .= "\n" . $extra_prompt;
        }

        return $title . "\n\n<additional_requirements>\n" . $requirements . "\n</additional_requirements>";
    }
}
