/**
 * 一麦GSO AI 平台管理面板 JS
 *
 * 依赖：jQuery（WordPress 默认加载）
 * 在 yimaiGsoData 全局对象可用时运行
 *
 * @since 3.0.0
 */
(function($) {
    'use strict';

    var PlatformManager = {
        platforms: {},
        activeKeys: [],

        /**
         * 初始化
         */
        init: function() {
            this.loadConfig();
            this.bindEvents();
        },

        /**
         * 从服务器加载平台配置
         */
        loadConfig: function() {
            var self = this;

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_platform_get_config',
                _wpnonce: yimaiGsoData.nonce
            }, function(resp) {
                if (resp && resp.success && resp.data) {
                    self.platforms = resp.data.platforms || {};
                    self.activeKeys = resp.data.active_keys || [];
                    self.render();
                }
            }).fail(function() {
                console.warn('[一麦GSO L6] 平台配置加载失败');
            });
        },

        /**
         * 渲染管理面板
         */
        render: function() {
            var $container = $('.yimai-gso-platform-list');
            if (!$container.length) return;

            var html = '';
            var platforms = this.platforms;
            var activeKeys = this.activeKeys;

            // P0 平台
            html += '<div class="yimai-gso-platform-group">';
            html += '<h3>核心平台（P0）</h3>';
            html += '<p class="yimai-gso-platform-group-desc">默认启用，覆盖全球主流 AI 搜索引擎</p>';

            $.each(platforms, function(key, platform) {
                if (platform.priority !== 'p0') return true; // continue
                html += PlatformManager.renderPlatformCard(key, platform, activeKeys.indexOf(key) >= 0);
            });
            html += '</div>';

            // P1 平台
            html += '<div class="yimai-gso-platform-group">';
            html += '<h3>扩展平台（P1）</h3>';
            html += '<p class="yimai-gso-platform-group-desc">按需启用，覆盖国内及新兴 AI 平台</p>';

            $.each(platforms, function(key, platform) {
                if (platform.priority !== 'p1') return true; // continue
                html += PlatformManager.renderPlatformCard(key, platform, activeKeys.indexOf(key) >= 0);
            });
            html += '</div>';

            $container.html(html);
        },

        /**
         * 渲染单个平台卡片
         */
        renderPlatformCard: function(key, platform, enabled) {
            var botsHtml = '';
            if (platform.bots && platform.bots.length > 0) {
                botsHtml = platform.bots.map(function(b) {
                    return '<code>' + PlatformManager.escapeHtml(b) + '</code>';
                }).join(', ');
            } else {
                botsHtml = '<em style="color:#9ca3af;">爬虫标识检测中</em>';
            }

            var adaptations = platform.adaptations || {};
            var badges = '';
            if (adaptations.meta_tags) badges += '<span class="pf-tag">Meta 标签</span>';
            if (adaptations.llms_txt) badges += '<span class="pf-tag">llms.txt</span>';
            if (adaptations.schema_priority && adaptations.schema_priority.length) {
                badges += '<span class="pf-tag">Schema: ' + PlatformManager.escapeHtml(adaptations.schema_priority.join(',')) + '</span>';
            }

            return '' +
            '<div class="yimai-gso-platform-card' + (enabled ? ' enabled' : '') + '" data-key="' + PlatformManager.escapeHtml(key) + '">' +
            '  <div class="yimai-gso-platform-card-header">' +
            '    <label class="yimai-gso-platform-toggle">' +
            '      <input type="checkbox" ' + (enabled ? 'checked' : '') + ' data-toggle="platform" data-key="' + PlatformManager.escapeHtml(key) + '">' +
            '      <span class="yimai-gso-platform-toggle-slider"></span>' +
            '    </label>' +
            '    <div class="yimai-gso-platform-card-info">' +
            '      <strong>' + PlatformManager.escapeHtml(platform.name) + '</strong>' +
            '      <span class="pf-bots">' + botsHtml + '</span>' +
            '    </div>' +
            '  </div>' +
            '  <div class="yimai-gso-platform-card-body">' +
            '    <p>' + PlatformManager.escapeHtml(platform.note || '') + '</p>' +
            '    <div class="pf-badges">' + badges + '</div>' +
            '  </div>' +
            '</div>';
        },

        /**
         * 绑定事件
         */
        bindEvents: function() {
            var self = this;

            // 平台开关切换
            $(document).on('change', '[data-toggle="platform"]', function() {
                var $checkbox = $(this);
                var key = $checkbox.data('key');
                var enabled = $checkbox.is(':checked');
                var $card = $checkbox.closest('.yimai-gso-platform-card');

                if (enabled) {
                    $card.addClass('enabled');
                } else {
                    $card.removeClass('enabled');
                }

                self.saveConfig();
            });

            // 保存按钮
            $(document).on('click', '#yimai-gso-platform-save', function() {
                self.saveConfig();
            });
        },

        /**
         * 收集当前配置并保存到服务器
         */
        saveConfig: function() {
            var config = {};
            var $toggles = $('[data-toggle="platform"]');

            $toggles.each(function() {
                var $toggle = $(this);
                var key = $toggle.data('key');
                config[key] = {
                    enabled: $toggle.is(':checked')
                };
            });

            $.post(yimaiGsoData.ajaxUrl, {
                action: 'yimai_gso_platform_save_config',
                _wpnonce: yimaiGsoData.nonce,
                config: JSON.stringify(config)
            }, function(resp) {
                if (resp && resp.success) {
                    // 静默成功，可选：显示 toast
                }
            }).fail(function() {
                console.warn('[一麦GSO L6] 平台配置保存失败');
            });
        },

        /**
         * HTML 转义
         */
        escapeHtml: function(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };

    // 页面就绪时初始化
    $(document).ready(function() {
        if ($('.yimai-gso-platform-list').length) {
            PlatformManager.init();
        }
    });

    // 暴露到全局
    window.YIMAI_GSO_PlatformManager = PlatformManager;

})(jQuery);
