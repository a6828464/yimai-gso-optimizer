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
 * 2.5.0 起新增第 6 步：建「公众号草稿箱同步」队列表（dbDelta，可重复执行），
 * 老站点升级不走插件激活钩子，这张表只能在这里补建。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YIMAI_GSO_DB_Migration {

	const DB_VERSION    = '2.5.0';
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

		// 并发锁（D2）：本方法同时挂在 init 与 admin_init（见本类 init()），
		// 同一站点的多个并发请求可能同时进入，而原先没有任何锁保护。
		// 交错执行会留下部分改名的中间状态。用 MySQL 命名锁（与插件其它处同一模式）
		// 保证同一时刻只有一个进程执行迁移。
		$mutex     = 'yimai_gso_migration_lock';
		$got_mutex = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $mutex ) );
		if ( ! $got_mutex ) {
			// 其他进程正在迁移：本次直接返回，版本号尚未写入，下次请求会重新尝试
			return;
		}

		try {
			self::migrate_locked();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex ) );
		}
	}

	/**
	 * 迁移的实际执行体——调用方必须已持有 migrate() 中的命名锁。
	 *
	 * D2 修复要点：原先所有 $wpdb->query() 的返回值都不检查，末尾却**无条件**写入版本号。
	 * 一旦 ALTER TABLE RENAME 失败（例如数据库账号缺少 ALTER 权限），
	 * 版本号照样被记录 → maybe_migrate() 此后永远直接 return，
	 * 迁移**永不重试**，10 张旧表此后永远读为空（不可恢复）。
	 * 现在：只要关键步骤（表重命名）确认失败，就不写版本号，让下次请求重试，
	 * 并把失败详情写入错误日志与后台提示，避免静默失败。
	 */
	private static function migrate_locked() {
		global $wpdb;

		$errors = array();

		// 1. 自定义数据表重命名（旧表存在且新表不存在才执行）
		foreach ( self::LEGACY_TABLES as $legacy_table ) {
			$new_table = 'yimai_gso_' . substr( $legacy_table, strlen( 'wpaip_' ) );

			$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $legacy_table ) );
			$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $new_table ) );

			if ( $old_exists && ! $new_exists ) {
				$renamed = $wpdb->query( "ALTER TABLE `{$wpdb->prefix}{$legacy_table}` RENAME TO `{$wpdb->prefix}{$new_table}`" );

				// D2：显式检查结果。失败时复查一次实际表状态，
				// 只有确认「旧表仍在且新表仍不存在」才判定为失败，避免误报。
				if ( false === $renamed ) {
					$still_old = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $legacy_table ) );
					$now_new   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $new_table ) );
					if ( $still_old && ! $now_new ) {
						$errors[] = sprintf(
							'表重命名失败：%s%s → %s%s（%s）',
							$wpdb->prefix,
							$legacy_table,
							$wpdb->prefix,
							$new_table,
							$wpdb->last_error ? $wpdb->last_error : '未知数据库错误'
						);
					}
				}
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

		// 6. 公众号草稿箱同步队列表（2.5.0 新增）。
		// 为什么放在这里而不是各模块自己判版本号：全插件只认一个 yimai_gso_db_version，
		// 再造一套「模块级版本号」会出现「版本号写了但表没建好」的半升级状态。
		// 建表失败要落进 $errors：下方的版本号写入正是靠它来决定要不要重试。
		if ( class_exists( 'YIMAI_GSO_Wechat_Publisher' ) ) {
			if ( ! YIMAI_GSO_Wechat_Publisher::create_table() ) {
				$errors[] = sprintf(
					'公众号草稿箱队列表创建失败：%s%s（%s）',
					$wpdb->prefix,
					'yimai_gso_wx_jobs',
					$wpdb->last_error ? $wpdb->last_error : '未知数据库错误'
				);
			}
		}

		// D2：仅在关键步骤全部成功时才记录版本号，否则保留旧值以便下次重试。
		if ( ! empty( $errors ) ) {
			error_log( '[一麦GSO 迁移] 迁移未完成，已保留版本号以便下次重试。失败项：' . implode( '；', $errors ) );
			// 同时在后台暴露问题，避免静默失败
			if ( is_admin() ) {
				set_transient( 'yimai_gso_migration_error', implode( '；', $errors ), DAY_IN_SECONDS );
			}
			return;
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}
}
