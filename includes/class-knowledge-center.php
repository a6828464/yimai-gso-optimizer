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
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('确定删除这条资料及其索引吗？');">
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
        <style>
            .yimai-knowledge-wrap{max-width:1320px}.yimai-kb-stats{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:12px;margin:20px 0}.yimai-kb-stats>div{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:16px}.yimai-kb-stats strong{display:block;font-size:26px;line-height:1.1}.yimai-kb-stats span{display:block;margin-top:6px;color:#50575e}.yimai-kb-import{background:#fff;border:1px solid #dcdcde;padding:18px;margin:16px 0 24px}.yimai-kb-import h2{margin-top:0}.yimai-kb-import input[type=file]{min-width:420px}.yimai-kb-table td{vertical-align:top}.yimai-kb-table code{font-size:11px;word-break:break-all}@media(max-width:782px){.yimai-kb-stats{grid-template-columns:repeat(2,1fr)}.yimai-kb-import input[type=file]{min-width:0;width:100%}}
        </style>
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
        $summary    = '由本地规则完成初步分类，等待管理员确认。';
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
