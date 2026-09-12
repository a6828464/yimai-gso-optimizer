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
