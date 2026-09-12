<?php
/**
 * 数据迁移类：旧 wpaip_ 前缀 → yimai_gso_ 新前缀
 *
 *  起插件所有标识符（类名、常量、option、post meta、数据表、
 * cron hook、AJAX action）统一为 yimai_gso_/YIMAI_GSO_ 前缀。
 * 老站点升级后首次加载时执行一次本迁移，保证：
 *  - 后台配置不丢失（options 原值原样改名）
 *  - 文章元数据、术语/用户元数据保留
 *  - 自定义数据表原样重命名，历史记录继续可用
 *  - 旧前缀 cron 任务清理，新任务由各模块自行重新注册
 *
 * 迁移幂等：完成后写入 yimai_gso_db_version，不会重复执行。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YIMAI_GSO_DB_Migration {

	const DB_VERSION    = '2.4.0';
	const VERSION_OPTION = 'yimai_gso_db_version';

	/** 旧前缀自定义数据表（wpaip_ → yimai_gso_） */
	const LEGACY_TABLES = array(
		'wpaip_autopilot_items',
		'wpaip_autopilot_runs',
		'wpaip_geo_brand_scan_details',
		'wpaip_geo_brand_scans',
		'wpaip_geo_jobs',
		'wpaip_geo_keywords',
		'wpaip_knowledge_chunks',
		'wpaip_knowledge_documents',
		'wpaip_referral_logs',
		'wpaip_seo_push_logs',
	);

	/** 日志/队列/报告类大对象 option，关闭 autoload 避免每个请求全量加载 */
	const HEAVY_OPTIONS = array(
		'yimai_gso_404_redirects',
		'yimai_gso_404_logs',
		'yimai_gso_alt_queue',
		'yimai_gso_alt_batch_log',
		'yimai_gso_title_history',
		'yimai_gso_internal_link_logs',
		'yimai_gso_rewrite_logs',
		'yimai_gso_seo_meta_logs',
		'yimai_gso_detection_logs',
		'yimai_gso_geo_audit_report',
		'yimai_gso_audit_content_progress',
		'yimai_gso_broken_link_audit_result',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ), 1 );
	}

	public static function maybe_migrate() {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::migrate();
	}

	public static function migrate() {
		global $wpdb;

		// 1. 自定义数据表重命名（旧表存在且新表不存在才执行）
		foreach ( self::LEGACY_TABLES as $legacy_table ) {
			$new_table = 'yimai_gso_' . substr( $legacy_table, strlen( 'wpaip_' ) );

			$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $legacy_table ) );
			$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $new_table ) );

			if ( $old_exists && ! $new_exists ) {
				$wpdb->query( "ALTER TABLE `{$wpdb->prefix}{$legacy_table}` RENAME TO `{$wpdb->prefix}{$new_table}`" );
			}
		}

		// 2. options 改名（含 transient 及其 timeout 键）。
		// 目标键名已存在时跳过该行，避免撞 option_name 唯一索引导致整条 UPDATE 失败。
		$wpdb->query(
			"UPDATE {$wpdb->options} src
			 LEFT JOIN {$wpdb->options} dst
			     ON dst.option_name = REPLACE( src.option_name, 'wpaip_', 'yimai_gso_' )
			 SET src.option_name = REPLACE( src.option_name, 'wpaip_', 'yimai_gso_' )
			 WHERE src.option_name LIKE '%wpaip\\_%'
			   AND dst.option_id IS NULL"
		);

		// 3. post / term / user meta 改名（meta_key 无唯一约束，直接替换）
		foreach ( array( $wpdb->postmeta, $wpdb->termmeta, $wpdb->usermeta ) as $meta_table ) {
			$wpdb->query(
				"UPDATE {$meta_table}
				 SET meta_key = REPLACE( meta_key, 'wpaip_', 'yimai_gso_' )
				 WHERE meta_key LIKE '%wpaip\\_%'"
			);
		}

		// 4. 日志/队列类大对象 option 关闭 autoload（WP 6.6+ 用官方 API，旧版本直接改列）
		foreach ( self::HEAVY_OPTIONS as $heavy_option ) {
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( $heavy_option, false );
			} else {
				$wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $heavy_option ) );
			}
		}

		// 5. 清理旧前缀 cron 任务（含动态后缀 hook）。
		// 新前缀任务由各模块在后续初始化中自行重新注册。
		$cron        = _get_cron_array();
		$legacy_hooks = array();
		foreach ( $cron as $timestamp => $hooks ) {
			foreach ( array_keys( (array) $hooks ) as $hook ) {
				if ( is_string( $hook ) && strpos( $hook, 'wpaip' ) === 0 ) {
					$legacy_hooks[] = $hook;
				}
			}
		}
		foreach ( array_unique( $legacy_hooks ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}
}
