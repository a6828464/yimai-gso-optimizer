/**
 * 一麦GSO双擎优化 · 在线更新面板交互
 * 依赖 wpaipData（wpaip-admin 句柄已 localize：ajaxUrl / nonce / version）
 */
(function ($) {
	'use strict';

	var checked = false;

	function log(lines) {
		if (!lines || !lines.length) return;
		var $log = $('#wpaip-updater-log');
		$log.text($log.text() ? $log.text() + '\n' + lines.join('\n') : lines.join('\n'));
	}

	function request(action, $btn, done) {
		var label = $btn.text();
		$btn.prop('disabled', true).text('处理中…');
		$.post(wpaipData.ajaxUrl, { action: action, nonce: wpaipData.nonce })
			.done(function (res) {
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
				$btn.prop('disabled', false).text(label);
			});
	}

	function renderCheck(res) {
		var data = res && res.data ? res.data : null;
		if (!data || !data.ok) {
			$('#wpaip-updater-status').text(data && data.message ? '（' + data.message + '）' : '（检查失败）');
			log(data && data.message ? [data.message] : null);
			return;
		}
		var remote = data.remote || {};
		if (data.up_to_date) {
			$('#wpaip-updater-status').text('（已是最新版本，远端 v' + remote.version + '）');
			$('#wpaip-updater-run').hide();
		} else {
			$('#wpaip-updater-status').text('（发现新版本 v' + remote.version + '）');
			$('#wpaip-updater-run').show();
		}
		if (remote.notes && remote.notes.length) {
			log(['远端版本说明：'].concat(remote.notes.map(function (n) { return '- ' + n; })));
		}
	}

	function check() {
		$('#wpaip-updater-status').text('（正在检查…）');
		request('wpaip_gso_update_check', $('#wpaip-updater-check'), function (res) {
			checked = true;
			renderCheck(res);
		});
	}

	function run() {
		$('#wpaip-updater-log').text('');
		request('wpaip_gso_update_run', $('#wpaip-updater-run'), function (res) {
			var data = res && res.data ? res.data : {};
			log(data.log || []);
			if (data.ok) {
				$('#wpaip-updater-status').text('（已更新到 v' + data.version + '，刷新页面生效）');
				$('#wpaip-updater-run').hide();
				if (window.wp && wp.data) { /* no-op：保持简单，提示用户手动刷新 */ }
			} else {
				$('#wpaip-updater-status').text('（更新失败）');
			}
		});
	}

	$('#wpaip-updater-check').on('click', check);
	$('#wpaip-updater-run').on('click', run);

	// 首次切到「在线更新」页签时自动检查一次
	$(document).on('click', '.wpaip-sidebar-tab[data-tab="updater"]', function () {
		if (!checked) { check(); }
	});
	// 直接以 tab=updater 打开页面时也自动检查
	if ($('.wpaip-main-panel[data-tab="updater"]').hasClass('active')) {
		check();
	}
})(jQuery);
