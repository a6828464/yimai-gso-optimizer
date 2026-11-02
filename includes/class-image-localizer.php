<?php
/**
 * 正文外链图片「落地」补扫器
 *
 * 为什么需要独立的一次性补扫，而不是在发布流程里多试几次：
 *   配图来源 MT Photos 的图片地址是 /gateway/file/{id}/{md5}?auth_code=… 的签名外链，
 *   服务端签发后 24h 失效，而这条链路在境内服务器上慢且抖（实测同一张 170KB 的图，
 *   有时 1.6s 拿完，有时 30s 只收到 163KB）。发布请求受 PHP-FPM request_terminate_timeout
 *   （本站 100s）限制，一篇 3 张图根本没有重试预算：一次失败就把签名外链写进正文，
 *   24h 后前台裂图、公众号同步再失败一次。
 *
 * 所以把「落地」拆成后台每小时的补扫：每次只处理少量图片，单张 30s、总预算 80s，
 * 失败下轮再来。正文里最终只留本站媒体库地址，不再依赖 Cloudflare 的可用性。
 *
 * 只碰 URL 里带 /gateway/ 的图，不重写作者自己的外链图；每次改正文前存一份备份 meta。
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.14.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Image_Localizer {

    const HOOK         = 'yimai_gso_localize_images';
    const CRON_SCHEDULE = 'hourly';
    const MAX_IMAGES   = 2;   // 每轮最多落地 2 张：单张 30s，留足余量给 100s 的 FPM 上限
    const TIME_BUDGET  = 80;  // 秒
    const DOWNLOAD_TIMEOUT = 30;
    const BACKUP_PREFIX = 'yimai_gso_content_backup_';

    /** 每次请求都判断「有没有排上」太贵，用 transient 挡 10 分钟 */
    const SCHEDULE_GUARD = 'yimai_gso_localizer_scheduled';

    public static function init() {
        add_action( self::HOOK, array( __CLASS__, 'run' ) );
        add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
    }

    /** 只排一次，重复排会让同一个 hook 每小时跑多遍 */
    public static function ensure_schedule() {
        if ( get_transient( self::SCHEDULE_GUARD ) ) {
            return;
        }
        set_transient( self::SCHEDULE_GUARD, 1, 600 );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 300, self::CRON_SCHEDULE, self::HOOK );
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook( self::HOOK );
        delete_transient( self::SCHEDULE_GUARD );
    }

    /**
     * 补扫入口：找正文里还有 gateway 外链的文章，落地几张就收工
     */
    public static function run() {
        global $wpdb;

        if ( ! class_exists( 'YIMAI_GSO_Image_Handler' ) || ! class_exists( 'YIMAI_GSO_MTPhotos_Client' ) ) {
            return;
        }

        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','future')
               AND post_content LIKE '%/gateway/%'
             ORDER BY post_modified DESC
             LIMIT 5"
        );

        if ( empty( $ids ) ) {
            return;
        }

        $handler = new YIMAI_GSO_Image_Handler();
        $client  = new YIMAI_GSO_MTPhotos_Client();
        $deadline = microtime( true ) + self::TIME_BUDGET;
        $done = 0;

        foreach ( $ids as $pid ) {
            if ( $done >= self::MAX_IMAGES || microtime( true ) > $deadline ) {
                break;
            }

            $post = get_post( (int) $pid );
            if ( ! $post ) {
                continue;
            }

            $content = (string) $post->post_content;
            if ( ! preg_match_all( '/<img\b[^>]*>/i', $content, $tags ) ) {
                continue;
            }

            $out     = $content;
            $changed = 0;

            foreach ( $tags[0] as $tag ) {
                if ( $done >= self::MAX_IMAGES || microtime( true ) > $deadline ) {
                    break;
                }
                if ( ! preg_match( '/src=["\']([^"\']+)["\']/i', $tag, $sm ) ) {
                    continue;
                }
                $src = html_entity_decode( $sm[1], ENT_QUOTES );
                if ( false === strpos( $src, '/gateway/' ) ) {
                    continue; // 作者自己的外链图不归这里管
                }

                $alt = '';
                if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $tag, $am ) ) {
                    $alt = $am[1];
                }

                // 每次都用最新 auth_code 重签：正文里那份可能已经过期（401 Unauthorized）
                $url = $client->refresh_image_url( $src );
                if ( is_wp_error( $url ) ) {
                    error_log( '[YIMAI_GSO_LOCALIZE] 重签失败 post=' . $pid . ' → ' . $url->get_error_message() );
                    continue;
                }

                $attachment_id = $handler->upload_image_to_media( $url, '', $alt, self::DOWNLOAD_TIMEOUT );
                $done++;

                if ( is_wp_error( $attachment_id ) ) {
                    error_log( '[YIMAI_GSO_LOCALIZE] 落地失败 post=' . $pid . ' src=' . substr( $src, 0, 100 ) . ' → ' . $attachment_id->get_error_message() );
                    continue;
                }

                $local_url = wp_get_attachment_url( $attachment_id );
                if ( ! $local_url ) {
                    continue;
                }

                $out = str_replace( $tag, str_replace( $sm[1], $local_url, $tag ), $out );
                $changed++;
            }

            if ( $changed > 0 ) {
                update_post_meta( (int) $pid, self::BACKUP_PREFIX . time(), wp_slash( $content ) );
                wp_update_post( array( 'ID' => (int) $pid, 'post_content' => wp_slash( $out ) ) );
                error_log( '[YIMAI_GSO_LOCALIZE] post=' . $pid . ' 落地 ' . $changed . ' 张' );
            }
        }
    }
}
