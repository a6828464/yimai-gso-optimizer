/**
 * 一麦GSO双擎优化 · 在线更新面板交互
 * 依赖 yimaiGsoData（yimai-gso-admin 句柄已 localize：ajaxUrl / nonce / version）
 */
(function ($) {
	'use strict';

	var checked = false;
	// 请求序号：进页签会自动检查一次，用户再点一次就是两个并发请求；
	// 慢的那个后到会把新结果覆盖成旧结果（实测：点完仍停在「正在检查…」）。
	// 只认最后一次请求的响应。
	var seq = 0;

	function log(lines) {
		if (!lines || !lines.length) return;
		var $log = $('#yimai-gso-updater-log');
		$log.text($log.text() ? $log.text() + '\n' + lines.join('\n') : lines.join('\n'));
	}

	function request(action, $btn, done) {
		var label = $btn.text();
		var my = ++seq;
		$btn.prop('disabled', true).text('处理中…');
		$.post(yimaiGsoData.ajaxUrl, { action: action, nonce: yimaiGsoData.nonce })
			.done(function (res) {
				if (my !== seq) { return; }
				done(res);
			})
			.fail(function (xhr) {
				var msg = '请求失败';
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				log(['请求失败：' + msg]);
			})
			.always(function () {
				if (my !== seq) { return; }
				$btn.text(label);
				// done 里被判为「不需要更新」或失败时会把按钮锁上，这里不能无脑解禁
				if ( ! $btn.data('locked') ) {
					$btn.prop('disabled', false);
				}
			});
	}

	function renderCheck(res) {
		var data = res && res.data ? res.data : null;
		if (!data || !data.ok) {
			$('#yimai-gso-updater-status').text(data && data.message ? '（' + data.message + '）' : '（检查失败）');
			$('#yimai-gso-updater-run').data('locked', true).prop('disabled', true).attr('title', '检查失败，先解决网络/权限后再试');
			log(data && data.message ? [data.message] : null);
			// 双通道全挂时，展示上次成功检查到的远端信息（1 小时缓存）作参考
			if (data && data.last_check) {
				var lc = data.last_check;
				log(['上次成功检查（' + lc.time + '，来源 ' + lc.source + '）：远端 v' + (lc.remote && lc.remote.version)]);
			}
			return;
		}
		var remote = data.remote || {};
		var srcLabel = { gitee: 'Gitee', github: 'GitHub', github_mirror: 'GitHub 反代' }[data.source] || data.source;
		var $run = $('#yimai-gso-updater-run');
		if (data.up_to_date) {
			$('#yimai-gso-updater-status').text('（已是最新版本，远端 v' + remote.version + ' · 经 ' + srcLabel + '）');
			// 按钮保留在页面上：已最新时禁用并说明，而不是让入口凭空消失
			$run.data('locked', true).prop('disabled', true).attr('title', '已是最新版本 v' + remote.version + '，无需更新');
		} else {
			$('#yimai-gso-updater-status').text('（发现新版本 v' + remote.version + ' · 经 ' + srcLabel + '）');
			$run.data('locked', false).prop('disabled', false).attr('title', '更新到 v' + remote.version);
		}
		if (remote.notes && remote.notes.length) {
			log(['远端版本说明：'].concat(remote.notes.map(function (n) { return '- ' + n; })));
		}
	}

	function check() {
		$('#yimai-gso-updater-status').text('（正在检查…）');
		request('yimai_gso_update_check', $('#yimai-gso-updater-check'), function (res) {
			checked = true;
			renderCheck(res);
		});
	}

	function run() {
		$('#yimai-gso-updater-log').text('');
		request('yimai_gso_update_run', $('#yimai-gso-updater-run'), function (res) {
			var data = res && res.data ? res.data : {};
			log(data.log || []);
			if (data.ok) {
				$('#yimai-gso-updater-status').text('（已更新到 v' + data.version + '，刷新页面生效）');
				$('#yimai-gso-updater-run').data('locked', true).prop('disabled', true).attr('title', '本次已更新，刷新生效');
				if (window.wp && wp.data) { /* no-op：保持简单，提示用户手动刷新 */ }
			} else {
				$('#yimai-gso-updater-status').text('（更新失败）');
			}
		});
	}

	/**
	 * 更新自检：逐通道看「清单/包能不能取到」+ 服务器环境。
	 * 更新失败时先跑这个，把「墙、权限、zip 扩展」三种原因分开，
	 * 而不是让用户对着「更新失败」四个字猜。
	 */
	function selfcheck() {
		var $out = $('#yimai-gso-updater-selfcheck-out');
		var $btn = $('#yimai-gso-updater-selfcheck');
		var label = $btn.text();
		$out.show().text('自检中，最多等 1 分钟…');
		$btn.prop('disabled', true).text('自检中…');
		$.post(yimaiGsoData.ajaxUrl, { action: 'yimai_gso_update_selfcheck', nonce: yimaiGsoData.nonce })
			.done(function (res) {
				var d = res && res.data ? res.data : {};
				var lines = [];
				var names = { gitee: 'Gitee', github: 'GitHub', github_mirror: 'GitHub 反代' };
				if (d.channels && d.channels.length) {
					lines.push('【通道】');
					$.each(d.channels, function (i, c) {
						lines.push('- ' + (names[c.channel] || c.channel));
						lines.push('    清单：' + c.manifest);
						lines.push('    更新包：' + c.package);
					});
				}
				if (d.env) {
					lines.push('');
					lines.push('【环境】');
					$.each(d.env, function (k, v) { lines.push('- ' + k + '：' + v); });
				}
				$out.text(lines.length ? lines.join('\n') : '自检没有返回数据。');
			})
			.fail(function () {
				$out.text('自检请求失败：可能是 AJAX 被安全插件拦截，或登录已超时。');
			})
			.always(function () {
				$btn.prop('disabled', false).text(label);
			});
	}

	$('#yimai-gso-updater-check').on('click', check);
	$('#yimai-gso-updater-selfcheck').on('click', selfcheck);
	$('#yimai-gso-updater-run').on('click', run);

	// 首次切到「在线更新」页签时自动检查一次
	$(document).on('click', '.yimai-gso-sidebar-tab[data-tab="updater"]', function () {
		if (!checked) { check(); }
	});
	// 直接以 tab=updater 打开页面时也自动检查
	if ($('.yimai-gso-main-panel[data-tab="updater"]').hasClass('active')) {
		check();
	}
})(jQuery);
