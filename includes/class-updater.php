<?php
/**
 * 插件在线更新（对齐主题 inc/updater.php 的双平台模式）
 *
 * 发布渠道：Gitee 主仓库 + GitHub 镜像，双平台 auto-latest Release 由 tools/release.sh 刷新。
 * 更新包：GitHub codeload main 分支归档优先（内容实时生成，无 CDN 旧包问题）；Gitee Release 附件兜底。
 * 清单：双平台 Release 附件 yimai-gso-optimizer-manifest.json（请求带时间戳穿 CDN 缓存）。
 *
 * 环境约束（与主题一致，2026-09 探测测试服务器得出）：
 * - PHP 8.5 未装 zip 扩展 → 优先 PclZip（WordPress 内置），有 ZipArchive 则用之
 * - proc_open / chown 等不可依赖 → 纯 PHP 实现，不落 shell
 *
 * @package yimai-gso-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIP_Updater {

	const GITEE_REPO    = 'meng-taoo/yimai-gso-optimizer';
	const GITHUB_REPO   = 'a6828464/yimai-gso-optimizer';
	const MANIFEST_NAME = 'yimai-gso-optimizer-manifest.json';
	const PACKAGE_NAME  = 'yimai-gso-optimizer-latest.zip';

	public static function init() {
		add_action( 'wp_ajax_wpaip_gso_update_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_wpaip_gso_update_run', array( __CLASS__, 'ajax_run' ) );
	}

	/** 各发布渠道的清单与包地址 */
	public static function sources() {
		return array(
			'github' => array(
				'manifest' => 'https://github.com/' . self::GITHUB_REPO . '/releases/download/auto-latest/' . self::MANIFEST_NAME,
				'package'  => 'https://codeload.github.com/' . self::GITHUB_REPO . '/zip/refs/heads/main',
			),
			'gitee'  => array(
				'manifest' => 'https://gitee.com/' . self::GITEE_REPO . '/releases/download/auto-latest/' . self::MANIFEST_NAME,
				'package'  => 'https://gitee.com/' . self::GITEE_REPO . '/releases/download/auto-latest/' . self::PACKAGE_NAME,
			),
		);
	}

	public static function state() {
		$file = WPAIP_PLUGIN_DIR . '.update-state.json';
		if ( is_file( $file ) ) {
			$json = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $json ) ) {
				return $json;
			}
		}
		return array();
	}

	/** 后台「检查更新」：拉远端清单，与当前 WPAIP_VERSION 比较 */
	public static function check( &$source_used = null ) {
		$remote = array();
		foreach ( self::sources() as $source => $urls ) {
			// 时间戳破缓存：GitHub/Gitee 对同名 Release 资产有 CDN 缓存，重建同名 Release 后可能仍吐旧文件
			$url = $urls['manifest'] . ( strpos( $urls['manifest'], '?' ) === false ? '?' : '&' ) . 't=' . time();
			$resp = wp_remote_get( $url, array( 'timeout' => 12, 'headers' => array( 'Accept' => 'application/json' ) ) );
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				continue;
			}
			$json = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( is_array( $json ) && ! empty( $json['version'] ) ) {
				$remote       = $json;
				$source_used  = $source;
				break;
			}
		}

		if ( empty( $remote ) ) {
			return array(
				'ok'      => false,
				'message' => '无法获取远端版本信息（Gitee / GitHub 均不可达），请稍后重试。',
				'local'   => array( 'version' => WPAIP_VERSION ),
			);
		}

		return array(
			'ok'         => true,
			'local'      => array( 'version' => WPAIP_VERSION ),
			'remote'     => $remote,
			'source'     => $source_used,
			'up_to_date' => version_compare( (string) $remote['version'], (string) WPAIP_VERSION, '<=' ),
		);
	}

	private static function ensure_dir( $dir ) {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( '目录创建失败：' . $dir );
		}
	}

	/** 下载更新包到临时文件，GitHub codeload 优先、Gitee 兜底，返回 [文件, 来源] */
	private static function download_package( $dest ) {
		foreach ( self::sources() as $source => $urls ) {
			$url = $urls['package'] . ( strpos( $urls['package'], '?' ) === false ? '?' : '&' ) . 't=' . time();
			$resp = wp_remote_get( $url, array(
				'timeout'    => 240,
				'user-agent' => 'YimaiGSO-Updater/1.0',
			) );
			if ( is_wp_error( $resp ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $resp );
			$body = wp_remote_retrieve_body( $resp );
			if ( 200 !== (int) $code || strlen( $body ) < 1024 ) {
				continue;
			}
			file_put_contents( $dest, $body );
			return array( $dest, $source );
		}
		throw new RuntimeException( '更新包下载失败（Gitee 与 GitHub 均不可达），请检查服务器外网后重试。' );
	}

	/** 解压 zip（ZipArchive 或 PclZip），返回包根目录（含插件主文件的目录） */
	private static function extract( $zipfile, $dest ) {
		self::ensure_dir( $dest );
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( $zip->open( $zipfile ) !== true ) {
				throw new RuntimeException( '无法打开更新包' );
			}
			$zip->extractTo( $dest );
			$zip->close();
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$zip = new PclZip( $zipfile );
			if ( ( $zip->extract( PCLZIP_OPT_PATH, $dest ) ) === 0 ) {
				throw new RuntimeException( '解压失败：' . $zip->errorInfo( true ) );
			}
		}
		// 定位包根（含插件主文件的目录）：GitHub codeload 包为 <repo>-main 一层目录，
		// Gitee Release 包根即插件根（无外层目录）
		$root = '';
		if ( is_file( $dest . '/yimai-gso-optimizer.php' ) ) {
			$root = $dest;
		} else {
			foreach ( (array) scandir( $dest ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( is_file( $dest . '/' . $entry . '/yimai-gso-optimizer.php' ) ) {
					$root = $dest . '/' . $entry;
					break;
				}
			}
		}
		if ( '' === $root ) {
			throw new RuntimeException( '更新包结构异常：未找到 yimai-gso-optimizer.php' );
		}
		foreach ( array( 'includes/class-admin.php', 'includes/class-schema-generator.php' ) as $must ) {
			if ( ! is_file( $root . '/' . $must ) ) {
				throw new RuntimeException( '更新包结构异常：缺少 ' . $must );
			}
		}
		return $root;
	}

	/** 更新前备份当前插件代码（跳过备份目录），保留最近 2 份 */
	private static function backup( array &$log ) {
		$plugin_dir = WPAIP_PLUGIN_DIR;
		$backup_dir = $plugin_dir . '.backups';
		self::ensure_dir( $backup_dir );
		$dest = $backup_dir . '/pre-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) ) . '.zip';

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		$list = array();
		foreach ( $files as $file ) {
			$path = str_replace( '\\', '/', (string) $file );
			if ( false !== strpos( $path, '/.backups/' ) || basename( $path ) === '.update-state.json' ) {
				continue;
			}
			$list[] = $path;
		}
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			$zip->open( $dest, ZipArchive::CREATE | ZipArchive::OVERWRITE );
			foreach ( $list as $path ) {
				$zip->addFile( $path, ltrim( substr( $path, strlen( $plugin_dir ) ), '/' ) );
			}
			$zip->close();
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$zip = new PclZip( $dest );
			if ( ( $zip->create( $list, PCLZIP_OPT_REMOVE_PATH, $plugin_dir ) ) === 0 ) {
				throw new RuntimeException( '备份创建失败：' . $zip->errorInfo( true ) );
			}
		}
		$log[] = '已备份当前代码 → .backups/' . basename( $dest ) . '（' . round( filesize( $dest ) / 1024 ) . ' KB）';

		$keep = glob( $backup_dir . '/pre-*.zip' ) ?: array();
		if ( count( $keep ) > 2 ) {
			sort( $keep );
			foreach ( array_slice( $keep, 0, count( $keep ) - 2 ) as $old ) {
				@wp_delete_file( $old );
			}
			$log[] = '清理历史备份，保留最近 2 份';
		}
		return $dest;
	}

	/** 递归覆盖：包内文件 → 插件目录。不删除服务器上的多余文件，路径含 .. 直接拒绝 */
	private static function apply( $root, array &$log ) {
		$plugin_dir = str_replace( '\\', '/', WPAIP_PLUGIN_DIR );
		$root       = str_replace( '\\', '/', $root );
		$count      = 0;
		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iterator as $file ) {
			$src = str_replace( '\\', '/', (string) $file );
			$rel = ltrim( substr( $src, strlen( $root ) ), '/' );
			if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
				throw new RuntimeException( '更新包含异常路径，已中止：' . $rel );
			}
			$target = $plugin_dir . $rel;
			self::ensure_dir( dirname( $target ) );
			if ( ! @copy( $src, $target ) ) {
				throw new RuntimeException( '文件写入失败（检查目录权限）：' . $rel );
			}
			@touch( $target, (int) $file->getMTime() );
			$count++;
		}
		$log[] = "已应用 {$count} 个文件到插件目录（保留服务器本地文件：备份、更新状态）";
		return $count;
	}

	private static function cleanup_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $dir );
	}

	/** 后台「立即更新」：下载 → 解压校验 → 备份 → 覆盖 → 记录状态 */
	public static function run() {
		$log   = array();
		$start = microtime( true );
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				throw new RuntimeException( '无权限执行更新' );
			}

			list( $zipfile, $source ) = self::download_package( get_temp_dir() . 'yimai-gso-' . bin2hex( random_bytes( 4 ) ) . '.zip' );
			$log[] = '更新包下载完成（来源：' . $source . '，' . round( filesize( $zipfile ) / 1024 ) . ' KB）';

			$work   = get_temp_dir() . 'yimai-gso-extract-' . bin2hex( random_bytes( 4 ) );
			$root   = self::extract( $zipfile, $work );
			$remote_version = self::read_package_version( $root . '/yimai-gso-optimizer.php' );
			$log[] = '包内版本：v' . ( $remote_version ?: '?' ) . '（当前 v' . WPAIP_VERSION . '）';

			self::backup( $log );
			self::apply( $root, $log );

			$state = array(
				'version' => $remote_version,
				'time'    => current_time( 'mysql' ),
				'source'  => $source,
			);
			file_put_contents( WPAIP_PLUGIN_DIR . '.update-state.json', wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

			@unlink( $zipfile );
			self::cleanup_dir( $work );

			$log[] = sprintf( '更新完成，耗时 %.1f 秒。', microtime( true ) - $start );
			return array( 'ok' => true, 'version' => $remote_version, 'log' => $log );
		} catch ( Throwable $error ) {
			$log[] = '更新失败：' . $error->getMessage();
			return array( 'ok' => false, 'message' => $error->getMessage(), 'log' => $log );
		}
	}

	/** 从包内插件主文件头解析 Version（更新运行期间 WPAIP_VERSION 仍是旧值） */
	private static function read_package_version( $main_file ) {
		$head = (string) file_get_contents( $main_file, false, null, 0, 2048 );
		if ( preg_match( '/^[ \t\*]*Version:[ \t]*(.+)$/mi', $head, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	public static function ajax_check() {
		check_ajax_referer( 'wpaip_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '无权限' ), 403 );
		}
		wp_send_json_success( self::check() );
	}

	public static function ajax_run() {
		check_ajax_referer( 'wpaip_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '无权限' ), 403 );
		}
		wp_send_json_success( self::run() );
	}
}
