<?php
/**
 * 在线更新安装链路 · 静态自检（开发期用，随 tools/ 不进发布包）
 *
 * 用法：php tools/test-updater-install.php
 *
 * 锁的是 2026-11-27 这次「在线更新失败」的修复口径：
 *  - 包源不得再依赖 codeload（与 github.com 不同 CDN，国内常见清单能取、包超时）
 *  - 下载必须有单通道超时与总预算，不能把三条通道各挂 240 秒把 PHP 执行时间吃光
 *  - 200 但不是 zip 要当场识别
 *  - sha256 不一致时换通道，而不是整次更新失败
 *  - 覆盖完成后要回读磁盘版本，防止「报了成功但版本没变」
 *  - 自检入口必须注册并有后端实现
 */
$root = is_file( __DIR__ . '/../includes/class-updater.php' ) ? __DIR__ . '/..' : __DIR__;
$src  = file_get_contents( $root . '/includes/class-updater.php' );
$js   = file_get_contents( $root . '/admin/js/updater.js' );
$adm  = file_get_contents( $root . '/includes/class-admin.php' );
$fail = 0; $n = 0;
function a( $cond, $msg ) { global $fail, $n; $n++; if ( $cond ) { echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

a( false === strpos( $src, "'https://codeload" ), '包源不再走 codeload（注释里提到不算）' );
a( false !== strpos( $src, "releases/download/auto-latest/' . self::PACKAGE_NAME" ), 'GitHub 通道改用 Release 资产' );
a( false !== strpos( $src, "'timeout'    => 90," ), '单通道下载超时压到 90 秒' );
a( false !== strpos( $src, '$deadline   = microtime( true ) + 180;' ), '整次下载有 180 秒总预算' );
a( false !== strpos( $src, '本次更新的下载时间预算已用完' ), '超预算的通道会明确跳过并记录' );
a( false !== strpos( $src, '"PK\\x03\\x04" !== substr( $body, 0, 4 )' ), '200 但不是 zip 会被识别' );
a( false !== strpos( $src, 'sha256 与清单不一致' ), 'sha256 不一致按通道失败处理' );
a( false !== strpos( $src, 'hash_equals( $expect_sha, $actual )' ), '下载阶段做 sha256 比对' );
a( false !== strpos( $src, 'download_package( get_temp_dir()' ) && false !== strpos( $src, '$download_errors, $manifest )' ), 'run() 把清单传给下载阶段' );
a( false !== strpos( $src, '落盘校验通过' ), '覆盖后回读磁盘版本' );
a( false !== strpos( $src, '文件已覆盖但磁盘上仍是 v' ), '版本没变则报错而不是假成功' );
a( false !== strpos( $src, "wp_ajax_yimai_gso_update_selfcheck" ), '自检 AJAX 已注册' );
a( false !== strpos( $src, 'public static function selfcheck()' ), '自检后端存在' );
a( false !== strpos( $src, "'插件目录'       =>" ), '自检检查插件目录可写' );
a( false !== strpos( $adm, 'id="yimai-gso-updater-selfcheck"' ), '面板有自检按钮' );
a( false !== strpos( $js, 'yimai_gso_update_selfcheck' ), 'JS 调自检接口' );
a( false !== strpos( $js, 'lines.join(' ) , 'JS 渲染自检结果' );

echo "合计 $n 通过, $fail 失败\n";
exit( $fail ? 1 : 0 );
