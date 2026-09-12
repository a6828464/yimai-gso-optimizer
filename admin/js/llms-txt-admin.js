/**
 * LLMs.txt 生成器 - 后台交互 JS
 * 
 * @package BaiqueAI_SEO_Content_Engine
 * @since 2.2.0
 */
(function($) {
    'use strict';

jQuery( document ).ready( function( $ ) {

    // 内联通知辅助函数（替代 alert）
    function showNotice( message, type ) {
        type = type || 'success';
        var color = type === 'success' ? '#00a32a' : '#d63638';
        var $notice = $( '<div>' ).css( {
            padding: '8px 12px',
            margin: '8px 0',
            borderRadius: '4px',
            background: type === 'success' ? '#edfaef' : '#fcf0f1',
            border: '1px solid ' + color,
            color: color,
            fontWeight: 500
        } ).text( message );
        $( '#llms-txt-notice-area' ).html( $notice ).show();
        setTimeout( function() { $notice.fadeOut( 400, function() { $( this ).remove(); } ); }, 4000 );
    }

    // 实时预览网站简介
    $( '#yimai_gso_llms_txt_intro' ).on( 'input', function() {
        var intro = $( this ).val();
        $( '#llms-txt-preview-intro' ).text( intro ? '> ' + intro : '' );
    } );

    // 实时预览详细说明
    $( '#yimai_gso_llms_txt_details' ).on( 'input', function() {
        var details = $( this ).val();
        $( '#llms-txt-preview-details' ).text( details ? '## Optional\n\n' + details : '' );
    } );

    // 生成并预览 llms.txt
    $( '#yimai-gso-generate-preview' ).on( 'click', function( e ) {
        e.preventDefault();

        var $button = $( this );
        var originalText = $button.text();

        $button.prop( 'disabled', true ).text( '生成中...' );

        $.ajax( {
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'yimai_gso_generate_llms_txt_preview',
                nonce: yimaiGsoData.nonce,
                settings: $( 'form' ).serializeArray()
            },
            success: function( response ) {
                if ( response.success ) {
                    $( '#llms-txt-preview-content' ).text( response.data.content );
                    $( '#llms-txt-preview' ).show();
                } else {
                    showNotice( response.data.message || '生成失败', 'error' );
                }
            },
            error: function() {
                showNotice( '请求失败，请重试', 'error' );
            },
            complete: function() {
                $button.prop( 'disabled', false ).text( originalText );
            }
        } );
    } );

    // 复制 llms.txt 内容
    $( '#yimai-gso-copy-llms-txt' ).on( 'click', function( e ) {
        e.preventDefault();

        var content = $( '#llms-txt-preview-content' ).text();

        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText( content ).then( function() {
                showNotice( '已复制到剪贴板' );
            } );
        } else {
            // 降级方案
            var $temp = $( '<textarea>' ).val( content ).appendTo( 'body' ).select();
            document.execCommand( 'copy' );
            $temp.remove();
            showNotice( '已复制到剪贴板' );
        }
    } );

    // 测试 llms.txt 访问
    $( '#yimai-gso-test-llms-txt' ).on( 'click', function( e ) {
        e.preventDefault();

        var $button = $( this );
        var originalText = $button.text();

        $button.prop( 'disabled', true ).text( '测试中...' );

        $.ajax( {
            url: yimaiGsoData.ajaxUrl,
            type: 'HEAD',
            data: {
                action: 'yimai_gso_test_llms_txt_access'
            },
            success: function( response ) {
                if ( response.success ) {
                    $( '#llms-txt-test-result' ).removeClass( 'notice-error' ).addClass( 'notice-success' ).text( '✅ llms.txt 可访问' ).show();
                } else {
                    $( '#llms-txt-test-result' ).removeClass( 'notice-success' ).addClass( 'notice-error' ).text( '❌ llms.txt 无法访问：' + ( response.data.message || '未知错误' ) ).show();
                }
            },
            error: function() {
                $( '#llms-txt-test-result' ).removeClass( 'notice-success' ).addClass( 'notice-error' ).text( '❌ 请求失败，请检查固定链接设置' ).show();
            },
            complete: function() {
                $button.prop( 'disabled', false ).text( originalText );
            }
        } );
    } );

    // 刷新固定链接提示（旧版选择器兼容）
    $( '#yimai-gso-flush-rewrite-rules' ).on( 'click', function( e ) {
        e.preventDefault();

        if ( ! confirm( '确定要刷新固定链接吗？这可能会影响网站访问。' ) ) {
            return;
        }

        $.ajax( {
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'yimai_gso_flush_rewrite_rules',
                nonce: yimaiGsoData.nonce
            },
            success: function( response ) {
                if ( response.success ) {
                    showNotice( '固定链接已刷新，请访问 /llms.txt 测试' );
                } else {
                    showNotice( '刷新失败：' + ( response.data.message || '未知错误' ), 'error' );
                }
            },
            error: function() {
                showNotice( '请求失败，请重试', 'error' );
            }
        } );
    } );

    // 刷新固定链接（工作台面板内联按钮）
    $( '#yimai-gso-flush-rewrite-rules-btn' ).on( 'click', function( e ) {
        e.preventDefault();

        var $button = $( this );
        var $result = $( '#yimai-gso-flush-rewrite-result' );
        var originalText = $button.text();

        $button.prop( 'disabled', true ).text( '刷新中...' );
        $result.hide();

        $.ajax( {
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'yimai_gso_flush_rewrite_rules',
                nonce: yimaiGsoData.nonce
            },
            success: function( response ) {
                if ( response.success ) {
                    $result.css( 'color', '#00a32a' ).text( '固定链接已刷新' ).show();
                } else {
                    $result.css( 'color', '#d63638' ).text( '刷新失败：' + ( response.data.message || '未知错误' ) ).show();
                }
            },
            error: function() {
                $result.css( 'color', '#d63638' ).text( '请求失败，请重试' ).show();
            },
            complete: function() {
                $button.prop( 'disabled', false ).text( originalText );
            }
        } );
    } );

} );

})(jQuery);
