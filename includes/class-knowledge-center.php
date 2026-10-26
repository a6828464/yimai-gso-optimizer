<?php
/**
 * Knowledge import, classification and retrieval for article generation.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YIMAI_GSO_Knowledge_Center {
    const MENU_SLUG       = 'yimai-gso-knowledge';
    const IMPORT_ACTION   = 'yimai_gso_import_knowledge';
    const UPDATE_ACTION   = 'yimai_gso_update_knowledge';
    const DELETE_ACTION   = 'yimai_gso_delete_knowledge';
    const MAX_ZIP_FILES   = 1000;
    const MAX_ZIP_BYTES   = 104857600;
    const MAX_TEXT_BYTES  = 2097152;
    const CONTEXT_LIMIT   = 6;
    const CONTEXT_CHARS   = 8000;
    const AI_CLASSIFY_HOOK = 'yimai_gso_classify_knowledge';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'handle_import' ) );
        add_action( 'admin_post_' . self::UPDATE_ACTION, array( $this, 'handle_update' ) );
        add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
        add_action( self::AI_CLASSIFY_HOOK, array( $this, 'process_ai_classification_queue' ) );
        add_action( 'wp_ajax_yimai_gso_kb_get_document', array( $this, 'ajax_get_document' ) );
        add_action( 'wp_ajax_yimai_gso_kb_run_classification', array( $this, 'ajax_run_classification' ) );
    }

    public function register_menu() {
        add_submenu_page(
            'yimai-gso-center',
            '一麦品牌资料',
            '品牌资料',
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    /** Rendered inside the unified GSO settings screen in . */
    public function render_embedded_panel() {
        $this->render_page();
    }

    private static function documents_table() {
        global $wpdb;
        return $wpdb->prefix . 'yimai_gso_knowledge_documents';
    }

    private static function chunks_table() {
        global $wpdb;
        return $wpdb->prefix . 'yimai_gso_knowledge_chunks';
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset   = $wpdb->get_charset_collate();
        $documents = self::documents_table();
        $chunks    = self::chunks_table();

        dbDelta( "CREATE TABLE {$documents} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_name varchar(500) NOT NULL DEFAULT '',
            file_name varchar(500) NOT NULL DEFAULT '',
            mime_type varchar(120) NOT NULL DEFAULT '',
            checksum char(64) NOT NULL,
            title varchar(500) NOT NULL DEFAULT '',
            summary text NULL,
            content longtext NULL,
            visibility varchar(20) NOT NULL DEFAULT 'internal',
            risk_level varchar(20) NOT NULL DEFAULT 'medium',
            status varchar(30) NOT NULL DEFAULT 'ready',
            tags text NULL,
            metadata longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY checksum (checksum),
            KEY visibility_status (visibility,status),
            KEY updated_at (updated_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$chunks} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            document_id bigint(20) unsigned NOT NULL,
            chunk_index int(10) unsigned NOT NULL DEFAULT 0,
            heading varchar(500) NOT NULL DEFAULT '',
            content longtext NOT NULL,
            keywords text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY document_chunk (document_id,chunk_index),
            KEY document_id (document_id)
        ) {$charset};" );

        update_option( 'yimai_gso_knowledge_schema_version', '1.0.0', false );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足。' );
        }

        self::create_tables();
        global $wpdb;
        $documents = self::documents_table();
        $stats     = $wpdb->get_results( "SELECT visibility, status, COUNT(*) AS total FROM {$documents} GROUP BY visibility, status", ARRAY_A );
        $rows      = $wpdb->get_results( "SELECT * FROM {$documents} ORDER BY updated_at DESC, id DESC LIMIT 100", ARRAY_A );
        $message   = isset( $_GET['yimai_message'] ) ? sanitize_text_field( wp_unslash( $_GET['yimai_message'] ) ) : '';
        $error     = isset( $_GET['yimai_error'] ) ? sanitize_text_field( wp_unslash( $_GET['yimai_error'] ) ) : '';
        $counts    = array( 'public' => 0, 'internal' => 0, 'restricted' => 0, 'blocked' => 0, 'pending' => 0 );

        foreach ( $stats as $stat ) {
            $visibility = $stat['visibility'];
            if ( isset( $counts[ $visibility ] ) ) {
                $counts[ $visibility ] += absint( $stat['total'] );
            }
            if ( 'pending' === $stat['status'] ) {
                $counts['pending'] += absint( $stat['total'] );
            }
        }
        ?>
        <div class="wrap yimai-knowledge-wrap">
            <h1>品牌资料</h1>
            <p>导入与检索一麦瑜伽的品牌资料。系统会先执行隐私硬规则，再使用已配置的 AI 识别主题、公开范围和内容风险。</p>

            <?php if ( $message ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
            <?php endif; ?>
            <?php if ( $error ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <div class="yimai-kb-stats">
                <?php
                $labels = array( 'public' => '公开资料', 'internal' => '内部参考', 'restricted' => '受限资料', 'blocked' => '已拦截', 'pending' => '待处理' );
                foreach ( $labels as $key => $label ) :
                ?>
                    <div><strong><?php echo esc_html( (string) $counts[ $key ] ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
                <?php endforeach; ?>
            </div>

            <div class="yimai-kb-import">
                <h2>导入资料</h2>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>">
                    <?php wp_nonce_field( self::IMPORT_ACTION ); ?>
                    <input type="file" name="knowledge_files[]" multiple required accept=".zip,.md,.txt,.json,.csv,.docx,.pdf,.xlsx">
                    <?php submit_button( '上传并识别', 'primary', 'submit', false ); ?>
                    <p class="description">支持 ZIP、Markdown、TXT、JSON、CSV、DOCX、PDF、XLSX。PDF 在服务器缺少解析器时会进入待处理，不会猜测内容。</p>
                </form>
            </div>

            <h2>已导入资料</h2>
            <?php
            global $wpdb;
            $pending_ai = absint( $wpdb->get_var( "SELECT COUNT(*) FROM " . self::documents_table() . " WHERE status = 'ready' AND visibility != 'blocked' AND metadata LIKE '%\"classification\":\"rules\"%'" ) );
            ?>
            <p class="yimai-kb-tools">
                <button type="button" class="button" id="yimai-kb-run-classification"<?php disabled( $pending_ai < 1 ); ?>>
                    ⚡ AI 复核待识别资料<?php echo $pending_ai > 0 ? '（' . esc_html( (string) $pending_ai ) . ' 条）' : ''; ?>
                </button>
                <span id="yimai-kb-classify-msg" style="margin-left:8px;"></span>
            </p>
            <table class="widefat striped yimai-kb-table">
                <thead><tr><th>资料</th><th>识别摘要</th><th>范围</th><th>风险</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7">尚未导入资料。</td></tr>
                <?php else : foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $row['title'] ?: $row['file_name'] ); ?></strong><br><code><?php echo esc_html( $row['source_name'] ); ?></code></td>
                        <td><?php echo esc_html( wp_trim_words( (string) $row['summary'], 28 ) ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr( self::UPDATE_ACTION ); ?>">
                                <input type="hidden" name="document_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
                                <?php wp_nonce_field( self::UPDATE_ACTION . '_' . $row['id'] ); ?>
                                <select name="visibility" onchange="this.form.submit()" <?php disabled( 'blocked', $row['visibility'] ); ?>>
                                    <?php foreach ( array( 'public' => '公开', 'internal' => '内部参考', 'restricted' => '受限', 'blocked' => '拦截' ) as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['visibility'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td><?php echo esc_html( $row['risk_level'] ); ?></td>
                        <td><?php echo esc_html( $row['status'] ); ?></td>
                        <td><?php echo esc_html( $row['updated_at'] ); ?></td>
                        <td>
                            <button type="button" class="button button-small yimai-kb-view-btn" data-id="<?php echo esc_attr( (string) $row['id'] ); ?>">👁 查看</button>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('确定删除这条资料及其索引吗？');" style="display:inline">
                                <input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>">
                                <input type="hidden" name="document_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
                                <?php wp_nonce_field( self::DELETE_ACTION . '_' . $row['id'] ); ?>
                                <button class="button-link-delete" type="submit">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div id="yimai-kb-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="yimai-kb-modal-title">
            <div class="yimai-kb-modal-backdrop" data-close="1"></div>
            <div class="yimai-kb-modal-box">
                <button type="button" class="yimai-kb-modal-close notice-dismiss" data-close="1" aria-label="关闭"><span class="screen-reader-text">关闭</span></button>
                <h2 id="yimai-kb-modal-title">资料详情</h2>
                <div id="yimai-kb-modal-body"><span class="spinner is-active"></span></div>
            </div>
        </div>
        <style>
            .yimai-knowledge-wrap{max-width:1320px}.yimai-kb-stats{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:12px;margin:20px 0}.yimai-kb-stats>div{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:16px}.yimai-kb-stats strong{display:block;font-size:26px;line-height:1.1}.yimai-kb-stats span{display:block;margin-top:6px;color:#50575e}.yimai-kb-import{background:#fff;border:1px solid #dcdcde;padding:18px;margin:16px 0 24px}.yimai-kb-import h2{margin-top:0}.yimai-kb-import input[type=file]{min-width:420px}.yimai-kb-table td{vertical-align:top}.yimai-kb-table code{font-size:11px;word-break:break-all}.yimai-kb-tools{margin:8px 0}
            #yimai-kb-modal{position:fixed;inset:0;z-index:100050}.yimai-kb-modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55)}.yimai-kb-modal-box{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(860px,92vw);max-height:84vh;overflow:auto;background:#fff;border-radius:6px;padding:20px 24px;box-shadow:0 8px 40px rgba(0,0,0,.25)}.yimai-kb-modal-close{position:absolute;top:8px;right:8px}
            .yimai-kb-meta{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 16px}.yimai-kb-meta span{background:#f0f0f1;border:1px solid #dcdcde;border-radius:3px;padding:2px 10px;font-size:12px}.yimai-kb-summary{background:#f6f7f7;border-left:4px solid #2271b1;padding:10px 14px;margin:0 0 14px}.yimai-kb-content{white-space:pre-wrap;word-break:break-word;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:12px;max-height:44vh;overflow:auto;font-size:12.5px;line-height:1.7}
            @media(max-width:782px){.yimai-kb-stats{grid-template-columns:repeat(2,1fr)}.yimai-kb-import input[type=file]{min-width:0;width:100%}}
        </style>
        <script>
        jQuery(function($){
            // ── 查看内容弹窗 ──
            $('#yimai-kb-modal').on('click','[data-close]',function(e){ if(e.target===this||$(this).is('.yimai-kb-modal-backdrop')){$('#yimai-kb-modal').hide();} });
            $(document).on('keydown', function(e){ if(e.key==='Escape'){$('#yimai-kb-modal').hide();} });
            $('.yimai-kb-view-btn').on('click', function(){
                var id = $(this).data('id');
                $('#yimai-kb-modal-body').html('<span class="spinner is-active"></span>');
                $('#yimai-kb-modal').show();
                $.post(yimaiGsoData.ajaxUrl, { action:'yimai_gso_kb_get_document', nonce: yimaiGsoData.nonce, document_id: id }, function(res){
                    if(!res.success){ $('#yimai-kb-modal-body').html('<p style="color:#b32d2e">'+(res.data&&res.data.message||'加载失败')+'</p>'); return; }
                    var d = res.data;
                    var visLabel = {public:'公开', internal:'内部参考', restricted:'受限', blocked:'已拦截'};
                    var html = '<p style="margin:0 0 8px"><strong>'+escapeHtml(d.title)+'</strong> <code style="font-size:11px">'+escapeHtml(d.source_name)+'</code></p>';
                    html += '<div class="yimai-kb-meta"><span>范围：'+(visLabel[d.visibility]||d.visibility)+'</span><span>风险：'+escapeHtml(d.risk_level)+'</span><span>状态：'+escapeHtml(d.status)+'</span><span>分块：'+d.chunk_count+' 块</span><span>更新：'+escapeHtml(d.updated_at)+'</span></div>';
                    html += '<div class="yimai-kb-summary"><strong>识别摘要：</strong>'+escapeHtml(d.summary||'（暂无）')+'</div>';
                    if(d.headings && d.headings.length){ html += '<p style="font-size:12px;color:#50575e;margin:0 0 12px">已建索引的章节锚点：'+d.headings.map(escapeHtml).join(' · ')+'</p>'; }
                    if(d.content){
                        html += '<p style="font-size:12px;margin:0 0 6px"><strong>资料全文</strong>（'+d.content.length+' 字符）：</p><div class="yimai-kb-content">'+escapeHtml(d.content)+'</div>';
                    } else {
                        html += '<p style="color:#8a6d3b">正文为空（已拦截资料不保留正文；pending/empty 状态无正文）。</p>';
                    }
                    $('#yimai-kb-modal-body').html(html);
                }).fail(function(){ $('#yimai-kb-modal-body').html('<p style="color:#b32d2e">请求失败，请重试。</p>'); });
            });
            function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
            // ── 手动 AI 复核 ──
            $('#yimai-kb-run-classification').on('click', function(){
                var $btn = $(this).prop('disabled', true).text('AI 识别中…');
                $('#yimai-kb-classify-msg').text('');
                $.post(yimaiGsoData.ajaxUrl, { action:'yimai_gso_kb_run_classification', nonce: yimaiGsoData.nonce }, function(res){
                    $btn.prop('disabled', false);
                    if(res.success){
                        $('#yimai-kb-classify-msg').text(res.data.message);
                        if(res.data.remaining === 0){ setTimeout(function(){location.reload();}, 1200); }
                        else { $btn.text('⚡ 继续处理剩余 '+res.data.remaining+' 条'); setTimeout(function(){location.reload();}, 2500); }
                    } else {
                        $btn.text('⚡ AI 复核待识别资料');
                        $('#yimai-kb-classify-msg').css('color','#b32d2e').text(res.data&&res.data.message||'执行失败');
                    }
                }).fail(function(){ $btn.prop('disabled', false).text('⚡ AI 复核待识别资料'); $('#yimai-kb-classify-msg').css('color','#b32d2e').text('请求失败，请重试。'); });
            });
        });
        </script>
        <?php
    }

    public function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足。' );
        }
        check_admin_referer( self::IMPORT_ACTION );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 );
        }

        if ( empty( $_FILES['knowledge_files']['name'] ) || ! is_array( $_FILES['knowledge_files']['name'] ) ) {
            $this->redirect_with_result( 0, array( '请选择要导入的资料。' ) );
        }

        self::create_tables();
        $files    = $_FILES['knowledge_files'];
        $imported = 0;
        $errors   = array();

        foreach ( $files['name'] as $index => $name ) {
            if ( UPLOAD_ERR_OK !== (int) $files['error'][ $index ] ) {
                $errors[] = sanitize_file_name( $name ) . ' 上传失败。';
                continue;
            }

            $file = array(
                'name'     => $name,
                'type'     => $files['type'][ $index ],
                'tmp_name' => $files['tmp_name'][ $index ],
                'error'    => $files['error'][ $index ],
                'size'     => $files['size'][ $index ],
            );

            $result = $this->store_uploaded_file( $file );
            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
                continue;
            }

            $processed = $this->process_file( $result['path'], $result['name'] );
            if ( file_exists( $result['path'] ) ) {
                unlink( $result['path'] );
            }
            if ( is_wp_error( $processed ) ) {
                $errors[] = $processed->get_error_message();
                continue;
            }
            $imported += absint( $processed );
        }

        $this->redirect_with_result( $imported, $errors );
    }

    private function store_uploaded_file( $file ) {
        $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, $this->supported_extensions(), true ) ) {
            return new WP_Error( 'unsupported_file', sanitize_file_name( $file['name'] ) . '：不支持的文件类型。' );
        }

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'upload_dir_error', $uploads['error'] );
        }

        $directory = trailingslashit( $uploads['basedir'] ) . 'yimai-gso-knowledge/originals';
        if ( ! wp_mkdir_p( $directory ) ) {
            return new WP_Error( 'directory_error', '无法创建知识库资料目录。' );
        }
        $this->protect_directory( dirname( $directory ) );

        $name = wp_unique_filename( $directory, sanitize_file_name( $file['name'] ) );
        $path = trailingslashit( $directory ) . $name;
        if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) {
            return new WP_Error( 'move_failed', sanitize_file_name( $file['name'] ) . '：无法保存上传文件。' );
        }

        return array( 'path' => $path, 'name' => $name );
    }

    private function protect_directory( $directory ) {
        wp_mkdir_p( $directory );
        if ( ! file_exists( trailingslashit( $directory ) . 'index.php' ) ) {
            file_put_contents( trailingslashit( $directory ) . 'index.php', "<?php\n// Silence is golden.\n" );
        }
        if ( ! file_exists( trailingslashit( $directory ) . '.htaccess' ) ) {
            file_put_contents( trailingslashit( $directory ) . '.htaccess', "Options -Indexes\n<FilesMatch \".*\">\nRequire all denied\n</FilesMatch>\n" );
        }
    }

    private function process_file( $path, $source_name ) {
        $extension = strtolower( pathinfo( $source_name, PATHINFO_EXTENSION ) );
        if ( 'zip' === $extension ) {
            return $this->process_zip( $path, $source_name );
        }

        $result = $this->import_document( $source_name, $path );
        return is_wp_error( $result ) ? $result : 1;
    }

    private function process_zip( $path, $archive_name ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'zip_unavailable', '服务器未启用 ZipArchive，无法读取 ZIP。' );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            return new WP_Error( 'invalid_zip', $archive_name . '：ZIP 文件无法打开。' );
        }

        if ( $zip->numFiles > self::MAX_ZIP_FILES ) {
            $zip->close();
            return new WP_Error( 'zip_too_many_files', $archive_name . '：ZIP 文件数量超过 ' . self::MAX_ZIP_FILES . '。' );
        }

        $total    = 0;
        $imported = 0;
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $stat = $zip->statIndex( $i );
            $name = str_replace( '\\', '/', (string) ( $stat['name'] ?? '' ) );
            $size = absint( $stat['size'] ?? 0 );
            $total += $size;

            if ( $total > self::MAX_ZIP_BYTES ) {
                $zip->close();
                return new WP_Error( 'zip_too_large', $archive_name . '：解压后文件总量超过 100 MB。' );
            }
            if ( '' === $name || '/' === substr( $name, -1 ) || false !== strpos( $name, '../' ) || 0 === strpos( $name, '/' ) ) {
                continue;
            }

            $extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            if ( ! in_array( $extension, array_diff( $this->supported_extensions(), array( 'zip' ) ), true ) ) {
                continue;
            }
            if ( $size > self::MAX_TEXT_BYTES && in_array( $extension, array( 'md', 'txt', 'json', 'csv' ), true ) ) {
                continue;
            }

            $data = $zip->getFromIndex( $i );
            if ( false === $data ) {
                continue;
            }

            $display_name = $archive_name . '::' . $name;
            $result       = $this->import_document( $display_name, '', $data, $extension );
            if ( ! is_wp_error( $result ) ) {
                $imported++;
            }
        }
        $zip->close();
        return $imported;
    }

    private function import_document( $source_name, $path = '', $raw_data = null, $extension = '' ) {
        $extension = $extension ?: strtolower( pathinfo( $source_name, PATHINFO_EXTENSION ) );
        $text      = $this->extract_text( $extension, $path, $raw_data );
        $status    = 'ready';

        if ( is_wp_error( $text ) ) {
            if ( 'parser_unavailable' !== $text->get_error_code() ) {
                return $text;
            }
            $status = 'pending';
            $text   = '';
        }

        $text     = $this->normalize_text( $text );
        $checksum = hash( 'sha256', '' !== $text ? $text : $source_name );
        $analysis = $this->hard_classification( $source_name, $text );
        $analysis['method'] = 'rules';

        if ( 'blocked' === $analysis['visibility'] ) {
            $status = 'blocked';
            $text   = '';
        } elseif ( '' === $text && 'pending' !== $status ) {
            $status = 'empty';
        }

        global $wpdb;
        $table    = self::documents_table();
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE checksum = %s", $checksum ) );
        $now      = current_time( 'mysql' );
        $data     = array(
            'source_name' => $source_name,
            'file_name'   => basename( str_replace( '::', '/', $source_name ) ),
            'mime_type'   => $this->mime_for_extension( $extension ),
            'checksum'    => $checksum,
            'title'       => $analysis['title'],
            'summary'     => $analysis['summary'],
            'content'     => $text,
            'visibility'  => $analysis['visibility'],
            'risk_level'  => $analysis['risk_level'],
            'status'      => $status,
            'tags'        => implode( ',', $analysis['tags'] ),
            'metadata'    => wp_json_encode( array( 'classification' => $analysis['method'], 'extension' => $extension ), JSON_UNESCAPED_UNICODE ),
            'updated_at'  => $now,
        );

        if ( $existing ) {
            $document_id = absint( $existing );
            $wpdb->update( $table, $data, array( 'id' => $document_id ) );
        } else {
            $data['created_at'] = $now;
            $wpdb->insert( $table, $data );
            $document_id = absint( $wpdb->insert_id );
        }

        if ( $document_id > 0 ) {
            $this->replace_chunks( $document_id, $text, $analysis );
            if ( 'blocked' !== $analysis['visibility'] && ! wp_next_scheduled( self::AI_CLASSIFY_HOOK ) ) {
                wp_schedule_single_event( time() + 10, self::AI_CLASSIFY_HOOK );
            }
        }
        return $document_id;
    }

    public function process_ai_classification_queue() {
        if ( ! class_exists( 'YIMAI_GSO_AI_Generator' ) || ! YIMAI_GSO_Settings::get( 'ai_api_key', '' ) ) {
            return;
        }

        global $wpdb;
        $table = self::documents_table();
        $rows  = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'ready' AND visibility != 'blocked' AND metadata LIKE '%\"classification\":\"rules\"%' ORDER BY id ASC LIMIT 5",
            ARRAY_A
        );

        foreach ( $rows as $row ) {
            $analysis = $this->classify_document( $row['source_name'], $row['content'] );
            if ( 'rules+ai' !== $analysis['method'] ) {
                wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::AI_CLASSIFY_HOOK );
                return;
            }

            $content = 'blocked' === $analysis['visibility'] ? '' : (string) $row['content'];
            $status  = 'blocked' === $analysis['visibility'] ? 'blocked' : 'ready';
            $wpdb->update(
                $table,
                array(
                    'title'      => $analysis['title'],
                    'summary'    => $analysis['summary'],
                    'content'    => $content,
                    'visibility' => $analysis['visibility'],
                    'risk_level' => $analysis['risk_level'],
                    'status'     => $status,
                    'tags'       => implode( ',', $analysis['tags'] ),
                    'metadata'   => wp_json_encode( array( 'classification' => 'rules+ai', 'extension' => pathinfo( $row['file_name'], PATHINFO_EXTENSION ) ), JSON_UNESCAPED_UNICODE ),
                    'updated_at' => current_time( 'mysql' ),
                ),
                array( 'id' => absint( $row['id'] ) )
            );
            $this->replace_chunks( absint( $row['id'] ), $content, $analysis );
        }

        $remaining = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'ready' AND visibility != 'blocked' AND metadata LIKE '%\"classification\":\"rules\"%'" );
        if ( absint( $remaining ) > 0 && ! wp_next_scheduled( self::AI_CLASSIFY_HOOK ) ) {
            wp_schedule_single_event( time() + 20, self::AI_CLASSIFY_HOOK );
        }
    }

    private function extract_text( $extension, $path, $raw_data ) {
        if ( in_array( $extension, array( 'md', 'txt', 'json', 'csv' ), true ) ) {
            $data = null !== $raw_data ? $raw_data : file_get_contents( $path );
            if ( false === $data ) {
                return new WP_Error( 'read_failed', '无法读取 ' . basename( $path ) );
            }
            return substr( $data, 0, self::MAX_TEXT_BYTES );
        }

        $temporary = '';
        if ( null !== $raw_data ) {
            $temporary = wp_tempnam( 'yimai-knowledge-' . $extension );
            if ( ! $temporary || false === file_put_contents( $temporary, $raw_data ) ) {
                return new WP_Error( 'temporary_file_failed', '无法创建解析临时文件。' );
            }
            $path = $temporary;
        }

        if ( 'docx' === $extension ) {
            $result = $this->extract_docx( $path );
        } elseif ( 'xlsx' === $extension ) {
            $result = $this->extract_xlsx( $path );
        } elseif ( 'pdf' === $extension ) {
            $result = $this->extract_pdf( $path );
        } else {
            $result = new WP_Error( 'unsupported_file', '不支持的文件类型。' );
        }

        if ( $temporary && file_exists( $temporary ) ) {
            unlink( $temporary );
        }
        return $result;
    }

    private function extract_docx( $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'parser_unavailable', '服务器未启用 DOCX 解析组件。' );
        }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            return new WP_Error( 'invalid_docx', 'DOCX 文件无法打开。' );
        }
        $xml = $zip->getFromName( 'word/document.xml' );
        $zip->close();
        if ( false === $xml ) {
            return new WP_Error( 'invalid_docx', 'DOCX 缺少正文。' );
        }
        $xml = str_replace( array( '</w:p>', '</w:tr>', '<w:tab/>' ), array( "\n", "\n", "\t" ), $xml );
        return html_entity_decode( wp_strip_all_tags( $xml ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
    }

    private function extract_xlsx( $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'parser_unavailable', '服务器未启用 XLSX 解析组件。' );
        }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            return new WP_Error( 'invalid_xlsx', 'XLSX 文件无法打开。' );
        }
        $parts = array();
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = (string) $zip->getNameIndex( $i );
            if ( 'xl/sharedStrings.xml' !== $name && ! preg_match( '#^xl/worksheets/sheet\d+\.xml$#', $name ) ) {
                continue;
            }
            $xml = $zip->getFromIndex( $i );
            if ( preg_match_all( '#<(?:t|v)(?:\s[^>]*)?>(.*?)</(?:t|v)>#s', (string) $xml, $matches ) ) {
                foreach ( $matches[1] as $value ) {
                    $parts[] = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
                }
            }
        }
        $zip->close();
        return implode( "\n", $parts );
    }

    private function extract_pdf( $path ) {
        if ( class_exists( '\\Smalot\\PdfParser\\Parser' ) ) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                return $parser->parseFile( $path )->getText();
            } catch ( Exception $exception ) {
                return new WP_Error( 'pdf_parse_failed', 'PDF 解析失败：' . $exception->getMessage() );
            }
        }
        return new WP_Error( 'parser_unavailable', '服务器未安装 PDF 文本解析器，资料已标记为待处理。' );
    }

    private function normalize_text( $text ) {
        $text = (string) $text;
        $text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
        $text = str_replace( array( "\r\n", "\r" ), "\n", $text );
        $text = preg_replace( '/[ \t]+\n/', "\n", $text );
        $text = preg_replace( '/\n{4,}/', "\n\n\n", $text );
        return trim( wp_check_invalid_utf8( $text, true ) );
    }

    private function classify_document( $source_name, $text ) {
        $hard = $this->hard_classification( $source_name, $text );
        $ai   = $this->ai_classification( $source_name, $text );

        if ( is_wp_error( $ai ) ) {
            return array(
                'title'      => $hard['title'],
                'summary'    => $hard['summary'],
                'visibility' => $hard['visibility'],
                'risk_level' => $hard['risk_level'],
                'tags'       => $hard['tags'],
                'method'     => 'rules',
            );
        }

        $levels     = array( 'public' => 0, 'internal' => 1, 'restricted' => 2, 'blocked' => 3 );
        $visibility = $levels[ $hard['visibility'] ] >= $levels[ $ai['visibility'] ] ? $hard['visibility'] : $ai['visibility'];
        $risks      = array( 'low' => 0, 'medium' => 1, 'high' => 2 );
        $risk       = $risks[ $hard['risk_level'] ] >= $risks[ $ai['risk_level'] ] ? $hard['risk_level'] : $ai['risk_level'];

        return array(
            'title'      => $ai['title'] ?: $hard['title'],
            'summary'    => $ai['summary'] ?: $hard['summary'],
            'visibility' => $visibility,
            'risk_level' => $risk,
            'tags'       => array_values( array_unique( array_merge( $hard['tags'], $ai['tags'] ) ) ),
            'method'     => 'rules+ai',
        );
    }

    private function hard_classification( $source_name, $text ) {
        $haystack   = strtolower( $source_name . "\n" . self::string_substr( $text, 0, 4000 ) );
        // Only classify explicit records as sensitive. Policy text such as “不得写入病史”
        // must not cause an otherwise public document to be blocked.
        $blocked    = array( 'body-test-history', '体测档案', '会员档案', 'sender_id', 'userid_confirmed', '未成交客户', '身份证' );
        $restricted = array( 'staff-directory', '员工识别', 'member-contract', '会员合同', 'pricing', '价格表', '套餐价格', '收费标准', '薪资', '考勤', '内部执行', '客户跟进' );
        $health     = array( '诊断', '治疗', '疼痛', '麻木', '孕期', '产后', '康复', '药物', '禁忌', '转介' );
        $public     = array( 'entities/yi-mai-yoga', 'entities\\yi-mai-yoga', 'coach-team', 'brand', '品牌', '课程介绍', '门店' );

        $visibility = 'internal';
        $risk       = 'low';
        $summary    = 'AI 主题识别排队中（本地规则已完成初步分类，AI 复核后自动更新）。';
        foreach ( $public as $needle ) {
            if ( false !== strpos( $haystack, strtolower( $needle ) ) ) {
                $visibility = 'public';
                break;
            }
        }
        foreach ( $restricted as $needle ) {
            if ( false !== strpos( $haystack, strtolower( $needle ) ) ) {
                $visibility = 'restricted';
                $summary    = '检测到经营或员工敏感信息，默认不参与文章生成。';
                break;
            }
        }
        foreach ( $blocked as $needle ) {
            if ( false !== strpos( $haystack, strtolower( $needle ) ) ) {
                $visibility = 'blocked';
                $risk       = 'high';
                $summary    = '检测到个人、会员或健康隐私字段，内容已拦截且不会建立检索索引。';
                break;
            }
        }
        if ( 'blocked' !== $visibility && preg_match( '/(?:手机号|手机号码|身份证号|证件号|病史|既往史|诊断结果|孕周|预产期)\s*[:：=]/u', $haystack ) ) {
            $visibility = 'blocked';
            $risk       = 'high';
            $summary    = '检测到带有具体值的个人或健康隐私字段，内容已拦截且不会建立检索索引。';
        }
        foreach ( $health as $needle ) {
            if ( false !== strpos( $haystack, strtolower( $needle ) ) ) {
                $risk = 'high';
                break;
            }
        }

        return array(
            'title'      => pathinfo( basename( str_replace( '::', '/', $source_name ) ), PATHINFO_FILENAME ),
            'summary'    => $summary,
            'visibility' => $visibility,
            'risk_level' => $risk,
            'tags'       => $risk === 'high' ? array( '健康边界', '需复核' ) : array(),
        );
    }

    private function ai_classification( $source_name, $text ) {
        if ( self::string_len( $text ) < 40 || ! class_exists( 'YIMAI_GSO_AI_Generator' ) || ! YIMAI_GSO_Settings::get( 'ai_api_key', '' ) ) {
            return new WP_Error( 'ai_unavailable', 'AI 未配置或文本不足。' );
        }

        $sample = self::string_substr( $text, 0, 7000 );
        $prompt = "你是一麦瑜伽官网知识管理员。请判断资料是否适合用于官网文章。只返回 JSON，不要 Markdown。\n" .
            "visibility 只能是 public、internal、restricted、blocked；risk_level 只能是 low、medium、high。\n" .
            "会员身份、联系方式、体测、病史、员工账号必须 blocked；价格合同和内部经营信息至少 restricted；医疗健康内容 risk_level=high。\n" .
            "返回格式：{\"title\":\"\",\"summary\":\"\",\"visibility\":\"internal\",\"risk_level\":\"medium\",\"tags\":[\"\"]}\n\n" .
            "文件：{$source_name}\n内容：\n{$sample}";

        $response = ( new YIMAI_GSO_AI_Generator() )->call_api(
            array(
                array( 'role' => 'system', 'content' => '你负责知识资料隐私分级。宁可提高限制等级，不得泄露个人信息。' ),
                array( 'role' => 'user', 'content' => $prompt ),
            ),
            900,
            array( 'temperature' => 0.1, 'top_p' => 0.2 )
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $content = (string) ( $response['choices'][0]['message']['content'] ?? '' );
        if ( preg_match( '/\{.*\}/s', $content, $match ) ) {
            $content = $match[0];
        }
        $data = json_decode( $content, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_ai_classification', 'AI 分类结果无法解析。' );
        }

        $visibility = sanitize_key( $data['visibility'] ?? 'internal' );
        $risk       = sanitize_key( $data['risk_level'] ?? 'medium' );
        return array(
            'title'      => sanitize_text_field( $data['title'] ?? '' ),
            'summary'    => sanitize_textarea_field( $data['summary'] ?? '' ),
            'visibility' => in_array( $visibility, array( 'public', 'internal', 'restricted', 'blocked' ), true ) ? $visibility : 'internal',
            'risk_level' => in_array( $risk, array( 'low', 'medium', 'high' ), true ) ? $risk : 'medium',
            'tags'       => array_map( 'sanitize_text_field', array_slice( (array) ( $data['tags'] ?? array() ), 0, 12 ) ),
        );
    }

    private function replace_chunks( $document_id, $text, $analysis ) {
        global $wpdb;
        $table = self::chunks_table();
        $wpdb->delete( $table, array( 'document_id' => $document_id ), array( '%d' ) );

        if ( '' === $text || ! in_array( $analysis['visibility'], array( 'public', 'internal' ), true ) ) {
            return;
        }

        $length = self::string_len( $text );
        $offset = 0;
        $index  = 0;
        while ( $offset < $length && $index < 500 ) {
            $chunk = trim( self::string_substr( $text, $offset, 1200 ) );
            if ( '' !== $chunk ) {
                $heading = '';
                if ( preg_match( '/^#{1,6}\s+(.+)$/m', $chunk, $match ) ) {
                    $heading = sanitize_text_field( $match[1] );
                }
                $wpdb->insert(
                    $table,
                    array(
                        'document_id' => $document_id,
                        'chunk_index' => $index,
                        'heading'     => $heading,
                        'content'     => $chunk,
                        'keywords'    => implode( ',', $analysis['tags'] ),
                        'created_at'  => current_time( 'mysql' ),
                    ),
                    array( '%d', '%d', '%s', '%s', '%s', '%s' )
                );
                $index++;
            }
            $offset += 1050;
        }
    }

    public static function get_context( $query, $limit = self::CONTEXT_LIMIT, $max_chars = self::CONTEXT_CHARS ) {
        global $wpdb;
        $query = trim( wp_strip_all_tags( (string) $query ) );
        if ( '' === $query ) {
            return array();
        }

        $documents = self::documents_table();
        $chunks    = self::chunks_table();
        $terms     = preg_split( '/[\s,，。！？、:：;；]+/u', $query, -1, PREG_SPLIT_NO_EMPTY );
        $terms     = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $terms ) ) ) ), 0, 8 );
        if ( empty( $terms ) ) {
            $terms = array( self::string_substr( $query, 0, 40 ) );
        }

        $where  = array();
        $params = array();
        foreach ( $terms as $term ) {
            $like     = '%' . $wpdb->esc_like( $term ) . '%';
            $where[]  = '(c.content LIKE %s OR c.heading LIKE %s OR c.keywords LIKE %s OR d.title LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT c.id, c.document_id, c.heading, c.content, d.title, d.source_name, d.visibility, d.risk_level
            FROM {$chunks} c INNER JOIN {$documents} d ON d.id = c.document_id
            WHERE d.status = 'ready' AND d.visibility IN ('public','internal') AND (" . implode( ' OR ', $where ) . ")
            ORDER BY d.visibility ASC, d.updated_at DESC LIMIT 80";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        foreach ( $rows as &$row ) {
            $score = 0;
            foreach ( $terms as $term ) {
                $score += substr_count( self::string_lower( $row['title'] . ' ' . $row['heading'] ), self::string_lower( $term ) ) * 6;
                $score += substr_count( self::string_lower( $row['content'] ), self::string_lower( $term ) );
            }
            $row['score'] = $score;
        }
        unset( $row );
        usort( $rows, function ( $left, $right ) { return $right['score'] <=> $left['score']; } );

        $result = array();
        $used   = 0;
        foreach ( $rows as $row ) {
            $length = self::string_len( $row['content'] );
            if ( $used + $length > $max_chars ) {
                $row['content'] = self::string_substr( $row['content'], 0, max( 0, $max_chars - $used ) );
                $length         = self::string_len( $row['content'] );
            }
            if ( $length < 20 ) {
                continue;
            }
            $result[] = $row;
            $used += $length;
            if ( count( $result ) >= $limit || $used >= $max_chars ) {
                break;
            }
        }
        return $result;
    }

    public static function get_topic_seeds( $limit = 30 ) {
        global $wpdb;
        $limit = max( 1, min( 100, absint( $limit ) ) );
        $table = self::documents_table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT title, tags FROM {$table} WHERE status = 'ready' AND visibility IN ('public','internal') ORDER BY visibility ASC, updated_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        $topics = array();
        foreach ( $rows as $row ) {
            $title = sanitize_text_field( $row['title'] ?? '' );
            if ( self::string_len( $title ) >= 4 ) {
                $topics[] = $title;
            }
        }
        return array_values( array_unique( $topics ) );
    }

    public static function augment_prompt( $prompt, $title = '' ) {
        $context = self::get_context( trim( $title . ' ' . $prompt ) );

        $lines = array(
            '<yimai_knowledge_context>',
            '以下内容来自管理员维护的一麦瑜伽品牌事实。只能使用资料中明确存在的事实，不得补造地址、价格、资质、人物、案例或效果数据。内部参考资料只能用于理解，不得逐字公开。健康内容不得诊断、治疗或保证效果。',
            '品牌边界：本文默认服务一麦瑜伽官网。除非用户明确要求做竞品对比，否则不得把其他瑜伽、健身或培训品牌写成推荐对象，不得用其他品牌替换一麦瑜伽。',
        );
        if ( class_exists( 'YIMAI_GSO_GEO_Entity' ) ) {
            $entity = YIMAI_GSO_GEO_Entity::get_entity_data();
            if ( ! empty( $entity['entity_name'] ) ) {
                $lines[] = '<yimai_canonical_entity>';
                $lines[] = '以下是双擎工作台中维护的 canonical 实体信息。品牌名称、实体类型和品牌描述可直接作为事实使用；联系方式和地址仅在问题明确询问时使用。';
                $lines[] = '实体名称：' . sanitize_text_field( $entity['entity_name'] );
                $lines[] = '实体类型：' . sanitize_text_field( $entity['entity_type'] ?? 'Organization' );
                $lines[] = '品牌描述：' . sanitize_textarea_field( $entity['entity_description'] ?? '' );
                $lines[] = '实体地址：' . sanitize_textarea_field( $entity['entity_address'] ?? '' );
                $lines[] = '</yimai_canonical_entity>';
            }
        }
        foreach ( $context as $item ) {
            $lines[] = sprintf(
                '<source id="kb-%d" visibility="%s" title="%s">%s</source>',
                absint( $item['document_id'] ),
                esc_attr( $item['visibility'] ),
                esc_attr( $item['title'] ),
                esc_html( $item['content'] )
            );
        }
        $lines[] = '</yimai_knowledge_context>';
        $lines[] = '<source_requirements>文章中的品牌事实必须能由上述 source 支持；资料不足时使用一般性表达。不要在正文中显示内部 source ID。</source_requirements>';

        // 即使资料检索没有命中，也要保留 canonical 实体和品牌边界。
        return $prompt . "\n\n" . implode( "\n", $lines );
    }

    public function handle_update() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足。' );
        }
        $document_id = absint( $_POST['document_id'] ?? 0 );
        check_admin_referer( self::UPDATE_ACTION . '_' . $document_id );
        $visibility = sanitize_key( $_POST['visibility'] ?? 'internal' );
        if ( ! in_array( $visibility, array( 'public', 'internal', 'restricted', 'blocked' ), true ) ) {
            $visibility = 'internal';
        }

        global $wpdb;
        $table = self::documents_table();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $document_id ), ARRAY_A );
        if ( $row && 'blocked' !== $row['visibility'] ) {
            $wpdb->update( $table, array( 'visibility' => $visibility, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $document_id ) );
            $analysis = array( 'visibility' => $visibility, 'tags' => array_filter( array_map( 'trim', explode( ',', (string) $row['tags'] ) ) ) );
            $this->replace_chunks( $document_id, (string) $row['content'], $analysis );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=yimai-gso-center&tab=settings&subtab=knowledge&yimai_message=' . rawurlencode( '资料范围已更新。' ) ) );
        exit;
    }

    public function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足。' );
        }
        $document_id = absint( $_POST['document_id'] ?? 0 );
        check_admin_referer( self::DELETE_ACTION . '_' . $document_id );
        global $wpdb;
        $wpdb->delete( self::chunks_table(), array( 'document_id' => $document_id ), array( '%d' ) );
        $wpdb->delete( self::documents_table(), array( 'id' => $document_id ), array( '%d' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=yimai-gso-center&tab=settings&subtab=knowledge&yimai_message=' . rawurlencode( '资料及其索引已删除。' ) ) );
        exit;
    }

    /**
     * AJAX：获取单条资料详情（标题、摘要、全文、分块统计）
     * 供「查看内容」弹窗使用，权限 manage_options，nonce 复用主 nonce。
     */
    public function ajax_get_document() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足。' ) );
        }
        $document_id = absint( $_POST['document_id'] ?? 0 );
        if ( $document_id < 1 ) {
            wp_send_json_error( array( 'message' => '缺少资料 ID。' ) );
        }

        global $wpdb;
        $table = self::documents_table();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $document_id ), ARRAY_A );
        if ( ! $row ) {
            wp_send_json_error( array( 'message' => '资料不存在。' ) );
        }

        $chunks_table = self::chunks_table();
        $chunk_count  = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$chunks_table} WHERE document_id = %d", $document_id ) ) );
        $chunk_rows   = $wpdb->get_results( $wpdb->prepare( "SELECT heading FROM {$chunks_table} WHERE document_id = %d ORDER BY chunk_index ASC LIMIT 6", $document_id ), ARRAY_A );
        $headings     = array_values( array_filter( array_map( function ( $c ) { return (string) ( $c['heading'] ?? '' ); }, $chunk_rows ) ) );

        wp_send_json_success( array(
            'id'          => absint( $row['id'] ),
            'title'       => (string) ( $row['title'] ?: $row['file_name'] ),
            'source_name' => (string) $row['source_name'],
            'summary'     => (string) $row['summary'],
            'content'     => (string) $row['content'],
            'visibility'  => (string) $row['visibility'],
            'risk_level'  => (string) $row['risk_level'],
            'status'      => (string) $row['status'],
            'tags'        => (string) $row['tags'],
            'chunk_count' => $chunk_count,
            'headings'    => $headings,
            'updated_at'  => (string) $row['updated_at'],
            'file_name'   => (string) $row['file_name'],
            'mime_type'   => (string) $row['mime_type'],
        ) );
    }

    /**
     * AJAX：手动触发 AI 分类队列（不等待 wp-cron）
     * 一次最多处理 5 条（与 process_ai_classification_queue 同批），返回剩余待处理数。
     */
    public function ajax_run_classification() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足。' ) );
        }
        if ( ! YIMAI_GSO_Settings::get( 'ai_api_key', '' ) ) {
            wp_send_json_error( array( 'message' => 'AI 未配置：请先在「设置 → AI配置」填写 API Key。' ) );
        }

        global $wpdb;
        $table = self::documents_table();
        $remaining = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'ready' AND visibility != 'blocked' AND metadata LIKE '%\"classification\":\"rules\"%'" ) );

        if ( $remaining < 1 ) {
            wp_send_json_success( array( 'message' => '没有待 AI 识别的资料（全部已处理或被拦截）。', 'remaining' => 0 ) );
        }

        // 直接同步跑一轮（批 5 条，每条一次 AI 调用），失败由返回 message 透出
        $this->process_ai_classification_queue();

        $remaining = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'ready' AND visibility != 'blocked' AND metadata LIKE '%\"classification\":\"rules\"%'" ) );
        wp_send_json_success( array(
            'message'   => $remaining > 0
                ? sprintf( '已处理一批（每批最多 5 条），还剩 %d 条待 AI 识别，可再点一次继续。', $remaining )
                : 'AI 识别已全部完成，列表将刷新。',
            'remaining' => $remaining,
        ) );
    }

    private function redirect_with_result( $imported, $errors ) {
        $args = array( 'page' => 'yimai-gso-center', 'tab' => 'settings', 'subtab' => 'knowledge' );
        if ( $imported > 0 ) {
            $args['yimai_message'] = sprintf( '已识别并写入 %d 条资料。', $imported );
        }
        if ( ! empty( $errors ) ) {
            $args['yimai_error'] = implode( '；', array_slice( $errors, 0, 5 ) );
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private function supported_extensions() {
        return array( 'zip', 'md', 'txt', 'json', 'csv', 'docx', 'pdf', 'xlsx' );
    }

    private function mime_for_extension( $extension ) {
        $map = array(
            'md' => 'text/markdown', 'txt' => 'text/plain', 'json' => 'application/json',
            'csv' => 'text/csv', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf',
        );
        return $map[ $extension ] ?? 'application/octet-stream';
    }

    private static function string_len( $value ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
    }

    private static function string_substr( $value, $start, $length ) {
        return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length, 'UTF-8' ) : substr( (string) $value, $start, $length );
    }

    private static function string_lower( $value ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
    }
}
