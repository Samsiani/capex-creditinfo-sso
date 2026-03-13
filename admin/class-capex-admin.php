<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Capex_Admin {

    public function init() {
        // სკრიპტები და სტილები ადმინისთვის
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // მეტა ბოქსები (ბილდერი და განაცხადის ნახვა)
        add_action( 'add_meta_boxes', array( $this, 'add_custom_meta_boxes' ) );

        // ფორმის შენახვა
        add_action( 'save_post', array( $this, 'save_form_builder_data' ) );

        // სეთინგების მენიუ
        add_action( 'admin_menu', array( $this, 'add_plugin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Secure file proxy
        add_action( 'admin_init', array( $this, 'handle_secure_file_proxy' ) );

        // Export/Import
        add_filter( 'post_row_actions', array( $this, 'add_export_row_action' ), 10, 2 );
        add_action( 'admin_init', array( $this, 'handle_form_export' ) );
        add_action( 'admin_init', array( $this, 'handle_form_import' ) );
    }

    /**
     * სკრიპტების ჩატვირთვა (მხოლოდ ჩვენს გვერდებზე)
     */
    public function enqueue_admin_assets( $hook ) {
        global $post;

        // მხოლოდ capex_form ან capex_entry გვერდებზე
        if ( ( 'post.php' === $hook || 'post-new.php' === $hook ) && $post ) {
            if ( 'capex_form' === $post->post_type ) {
                // ბილდერის სტილები და სკრიპტი
                wp_enqueue_style( 'capex-admin-css', CAPEX_PLUGIN_URL . 'admin/css/capex-admin.css', array(), CAPEX_VERSION );
                wp_enqueue_script( 'capex-builder-js', CAPEX_PLUGIN_URL . 'admin/js/capex-builder.js', array( 'jquery', 'jquery-ui-sortable' ), CAPEX_VERSION, true );
                
                // არსებული მონაცემების გადაცემა JS-ისთვის
                $structure_json = get_post_meta( $post->ID, '_capex_form_structure', true );
                
                // ვალიდაცია: თუ JSON არასწორია ან ცარიელი, ვაბრუნებთ ცარიელ მასივს
                $structure_data = json_decode( $structure_json );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    $structure_data = array();
                }

                wp_localize_script( 'capex-builder-js', 'capex_builder_data', array(
                    'structure' => $structure_data
                ));
            }

            if ( 'capex_entry' === $post->post_type ) {
                // განაცხადის ნახვის სტილები
                wp_enqueue_style( 'capex-admin-css', CAPEX_PLUGIN_URL . 'admin/css/capex-admin.css', array(), CAPEX_VERSION );
            }
        }
    }

    /**
     * მეტა ბოქსების დამატება
     */
    public function add_custom_meta_boxes() {
        // 1. ფორმის კონსტრუქტორი (მხოლოდ capex_form-ზე)
        add_meta_box(
            'capex_form_builder',
            'ფორმის კონსტრუქტორი',
            array( $this, 'render_form_builder' ),
            'capex_form',
            'normal',
            'high'
        );

        // 2. განაცხადის დეტალები (მხოლოდ capex_entry-ზე)
        add_meta_box(
            'capex_entry_view',
            'განაცხადის მონაცემები',
            array( $this, 'render_entry_view' ),
            'capex_entry',
            'normal',
            'high'
        );
    }

    /**
     * RENDER: ფორმის კონსტრუქტორი (HTML კონტეინერი)
     * რეალური ინტერფეისი აეწყობა JS-ით
     */
    public function render_form_builder( $post ) {
        wp_nonce_field( 'capex_save_form_action', 'capex_save_form_nonce' );
        ?>
        <div id="capex-builder-app">
            <div class="cx-loading">იტვირთება კონსტრუქტორი...</div>
        </div>
        <textarea name="capex_form_structure" id="capex_form_structure" style="display:none;"></textarea>
        <?php
    }

    /**
     * SAVE: ფორმის სტრუქტურის შენახვა (FIXED BUG HERE)
     */
    public function save_form_builder_data( $post_id ) {
        // 1. უსაფრთხოების შემოწმებები
        if ( ! isset( $_POST['capex_save_form_nonce'] ) || ! wp_verify_nonce( $_POST['capex_save_form_nonce'], 'capex_save_form_action' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // 2. JSON-ის შენახვა
        if ( isset( $_POST['capex_form_structure'] ) ) {
            
            // BUG FIX: WordPress ამატებს Slashes-ს (მაგ: \"value\"). 
            // ჩვენ გვჭირდება სუფთა JSON, ამიტომ ვიყენებთ wp_unslash()
            $json_data = wp_unslash( $_POST['capex_form_structure'] );
            
            // ვამოწმებთ, არის თუ არა ვალიდური JSON
            json_decode( $json_data );
            
            if ( json_last_error() === JSON_ERROR_NONE ) {
                // მხოლოდ ვალიდური JSON-ს ვიწერთ
                update_post_meta( $post_id, '_capex_form_structure', $json_data );
            }
        }
    }

    /**
     * RENDER: განაცხადის ნახვა (დინამიური)
     */
    public function render_entry_view( $post ) {
        // Mark as read
        if ( ! get_post_meta( $post->ID, '_capex_entry_read', true ) ) {
            update_post_meta( $post->ID, '_capex_entry_read', current_time( 'Y-m-d H:i:s' ) );
        }

        // 1. გავიგოთ რომელ ფორმას ეკუთვნის ეს განაცხადი
        $form_id = get_post_meta( $post->ID, '_capex_form_id', true );
        
        if ( ! $form_id ) {
            echo '<p style="color:red;">შეცდომა: განაცხადს არ აქვს ფორმის წყარო.</p>';
            return;
        }

        // 2. წამოვიღოთ ფორმის სტრუქტურა
        $structure_json = get_post_meta( $form_id, '_capex_form_structure', true );
        $structure = json_decode( $structure_json, true ); // Array

        // 3. წამოვიღოთ შენახული მონაცემები
        $entry_data = get_post_meta( $post->ID, '_capex_entry_data', true ); // ყველა ველის ინფო ერთ მასივში

        echo '<div class="capex-view-wrapper" id="capex-print-area">';

        // სისტემური ინფო + Print ღილაკი
        echo '<div class="cx-system-info" style="display:flex; justify-content:space-between; align-items:center;">';
        $user_ip = isset($entry_data['_user_ip']) ? $entry_data['_user_ip'] : '';
        echo '<div><strong>ფორმა:</strong> <a href="'.esc_url(get_edit_post_link($form_id)).'">'.esc_html(get_the_title($form_id)).'</a> | ';
        echo '<strong>დრო:</strong> ' . get_the_date('Y-m-d H:i:s', $post->ID);
        if ( $user_ip ) {
            echo ' | <span class="cx-no-print"><strong>User IP:</strong> ' . esc_html( $user_ip ) . '</span>';
        }
        echo '</div>';
        echo '<button type="button" class="cx-btn cx-btn-primary cx-no-print" onclick="capexPrintEntry()">&#128424; ბეჭდვა</button>';
        echo '</div>';

        // Auth method badge
        $auth_method = isset($entry_data['_capex_auth_method']) ? $entry_data['_capex_auth_method'] : 'manual';
        if ( $auth_method === 'mycreditinfo' ) {
            echo '<div class="cx-auth-badge cx-auth-sso">&#10004; ავტორიზებულია MyCreditinfo-ით</div>';
        } else {
            echo '<div class="cx-auth-badge cx-auth-manual">&#9997; შევსებულია ხელით</div>';
        }

        if ( empty( $structure ) || ! is_array( $structure ) ) {
            echo "ფორმის სტრუქტურა ვერ მოიძებნა ან დაზიანებულია.";
            return;
        }

        // ლუპი ნაბიჯებზე (Steps)
        foreach ( $structure as $step_index => $step ) {
            echo '<div class="cx-section-title">ნაბიჯი ' . ($step_index + 1) . '</div>';
            
            if(!empty($step['fields'])) {
                foreach($step['fields'] as $field) {
                    $label = isset($field['label']) ? $field['label'] : 'Label';
                    $type  = isset($field['type']) ? $field['type'] : 'text';
                    $id    = isset($field['id']) ? $field['id'] : '';
                    
                    // მნიშვნელობის ამოღება
                    $value = isset($entry_data[$id]) ? $entry_data[$id] : '';

                    // ცარიელი ველების გამოტოვება
                    if ( $type !== 'html' && $type !== 'file' ) {
                        if ( empty( $value ) || $value === '-' ) {
                            continue;
                        }
                    }
                    if ( $type === 'file' && ( ! is_array( $value ) || empty( $value ) ) ) {
                        continue;
                    }

                    echo '<div class="cx-row">';
                    echo '<div class="cx-label">' . esc_html($label) . ':</div>';
                    echo '<div class="cx-val">';

                    // ტიპების მიხედვით გამოტანა
                    if ( $type === 'file' ) {
                        foreach($value as $file_url) {
                            $proxy_url = self::get_secure_file_url( $file_url );
                            echo '<a href="'.esc_url($proxy_url).'" target="_blank" class="cx-file-link">📄 გახსნა</a> ';
                        }
                    } elseif ( $type === 'html' ) {
                         $timestamp = isset($entry_data[$id . '_timestamp']) ? $entry_data[$id . '_timestamp'] : '';
                         echo '<span style="color:green">✔ დადასტურებულია</span>';
                         if ( $timestamp ) {
                             echo ' <span style="color:#666; font-size:12px;">(' . esc_html( $timestamp ) . ')</span>';
                         }
                    } else {
                        echo esc_html($value);
                    }

                    echo '</div></div>';
                }
            }
        }

        echo '</div>'; // wrapper

        // Print functionality
        ?>
        <script>
        function capexPrintEntry() {
            var area = document.getElementById('capex-print-area');
            var win = window.open('', '_blank');
            win.document.write('<!DOCTYPE html><html><head><meta charset="utf-8">');
            win.document.write('<title><?php echo esc_js( get_the_title( $post->ID ) ); ?></title>');
            win.document.write('<style>');
            win.document.write('body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 30px; color: #333; max-width: 800px; margin: 0 auto; }');
            win.document.write('.cx-system-info { background: #fff8e5; border: 1px solid #f0c33c; padding: 10px; font-size: 12px; margin-bottom: 20px; }');
            win.document.write('.cx-section-title { background: #f0f0f1; padding: 10px; font-weight: bold; font-size: 14px; margin-top: 25px; border-left: 4px solid #0073aa; }');
            win.document.write('.cx-row { display: flex; border-bottom: 1px solid #eee; padding: 10px 0; }');
            win.document.write('.cx-label { width: 250px; font-weight: 600; color: #555; flex-shrink: 0; }');
            win.document.write('.cx-val { flex-grow: 1; color: #1d2327; }');
            win.document.write('.cx-no-print, .cx-system-info, .cx-section-title { display: none !important; }');
            win.document.write('a { color: #0073aa; }');
            win.document.write('</style></head><body>');
            win.document.write(area.innerHTML);
            win.document.write('</body></html>');
            win.document.close();
            win.onload = function() { win.print(); };
        }
        </script>
        <?php
    }

    /**
     * Serve files from capex_secure_docs via admin proxy (capability-gated).
     */
    public function handle_secure_file_proxy() {
        if ( empty( $_GET['capex_file'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.', 403 );
        }

        $filename = sanitize_file_name( $_GET['capex_file'] );

        // Prevent path traversal
        if ( $filename !== basename( $filename ) || strpos( $filename, '..' ) !== false ) {
            wp_die( 'Invalid filename.', 400 );
        }

        $upload    = wp_upload_dir();
        $secure_dir = $upload['basedir'] . '/capex_secure_docs/';
        $filepath   = $secure_dir . $filename;

        // Verify resolved path stays within secure directory
        $real_path   = realpath( $filepath );
        $real_secure = realpath( $secure_dir );
        if ( false === $real_path || false === $real_secure || strpos( $real_path, $real_secure ) !== 0 ) {
            wp_die( 'Access denied.', 403 );
        }

        $mime = wp_check_filetype( $filename );
        $type = ! empty( $mime['type'] ) ? $mime['type'] : 'application/octet-stream';

        header( 'Content-Type: ' . $type );
        header( 'Content-Length: ' . filesize( $filepath ) );

        // Images/PDFs display inline; everything else downloads
        $inline_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf' );
        if ( in_array( $type, $inline_types, true ) ) {
            header( 'Content-Disposition: inline; filename="' . $filename . '"' );
        } else {
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        }

        readfile( $filepath );
        exit;
    }

    /**
     * Convert a direct capex_secure_docs URL to an admin proxy URL.
     */
    public static function get_secure_file_url( $direct_url ) {
        $upload  = wp_upload_dir();
        $base    = $upload['baseurl'] . '/capex_secure_docs/';
        if ( strpos( $direct_url, $base ) === 0 ) {
            $filename = basename( $direct_url );
            return admin_url( 'edit.php?post_type=capex_entry&capex_file=' . urlencode( $filename ) );
        }
        return $direct_url;
    }

    /**
     * Export row action — adds "Export" link to each form in the list table.
     */
    public function add_export_row_action( $actions, $post ) {
        if ( 'capex_form' === $post->post_type && current_user_can( 'manage_options' ) ) {
            $export_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=capex_export_form&form_id=' . $post->ID ),
                'capex_export_form_' . $post->ID
            );
            // Use admin_url approach but handled via admin_init.
            $export_url = wp_nonce_url(
                add_query_arg( [
                    'capex_export_form' => '1',
                    'form_id'           => $post->ID,
                ], admin_url( 'edit.php?post_type=capex_form' ) ),
                'capex_export_form_' . $post->ID
            );
            $actions['capex_export'] = '<a href="' . esc_url( $export_url ) . '">Export JSON</a>';
        }
        return $actions;
    }

    /**
     * Handle form JSON export — streams a .json file download.
     */
    public function handle_form_export() {
        if ( empty( $_GET['capex_export_form'] ) || empty( $_GET['form_id'] ) ) {
            return;
        }

        $form_id = absint( $_GET['form_id'] );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'capex_export_form_' . $form_id ) ) {
            wp_die( 'Invalid nonce.' );
        }

        $post = get_post( $form_id );
        if ( ! $post || 'capex_form' !== $post->post_type ) {
            wp_die( 'Form not found.' );
        }

        $structure_json = get_post_meta( $form_id, '_capex_form_structure', true );
        $structure      = json_decode( $structure_json, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $structure = [];
        }

        $export = [
            'capex_form_export' => true,
            'version'           => CAPEX_VERSION,
            'exported_at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'form'              => [
                'title'     => $post->post_title,
                'status'    => $post->post_status,
                'structure' => $structure,
            ],
        ];

        $filename = sanitize_file_name( $post->post_title ) . '-export.json';

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );

        echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * Handle form JSON import — processes uploaded file.
     */
    public function handle_form_import() {
        if ( empty( $_POST['capex_import_form'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'capex_import_form' ) ) {
            wp_die( 'Invalid nonce.' );
        }

        if ( empty( $_FILES['capex_import_file']['tmp_name'] ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p>ფაილი არ აირჩა.</p></div>';
            } );
            return;
        }

        $file     = $_FILES['capex_import_file'];
        $ext      = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

        if ( 'json' !== $ext ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p>მხოლოდ JSON ფაილები.</p></div>';
            } );
            return;
        }

        $contents = file_get_contents( $file['tmp_name'] );
        $data     = json_decode( $contents, true );

        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['capex_form_export'] ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p>არავალიდური ფორმის ექსპორტის ფაილი.</p></div>';
            } );
            return;
        }

        $form_data = $data['form'] ?? [];
        $title     = ! empty( $form_data['title'] ) ? sanitize_text_field( $form_data['title'] ) : 'Imported Form';
        $structure = $form_data['structure'] ?? [];

        // Create the form post.
        $post_id = wp_insert_post( [
            'post_type'   => 'capex_form',
            'post_title'  => $title,
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $post_id ) ) {
            $err = $post_id->get_error_message();
            add_action( 'admin_notices', function () use ( $err ) {
                echo '<div class="notice notice-error"><p>შეცდომა: ' . esc_html( $err ) . '</p></div>';
            } );
            return;
        }

        // Save structure as JSON string.
        update_post_meta( $post_id, '_capex_form_structure', wp_json_encode( $structure, JSON_UNESCAPED_UNICODE ) );

        // Redirect to the new form's edit page.
        wp_safe_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit&imported=1' ) );
        exit;
    }

    /**
     * სეთინგების გვერდი
     */
    public function add_plugin_menu() {
        add_submenu_page(
            'edit.php?post_type=capex_form',
            'ფორმის იმპორტი',
            'Import Form',
            'manage_options',
            'capex-import',
            array( $this, 'render_import_page' )
        );

        add_submenu_page(
            'edit.php?post_type=capex_form',
            'CreditInfo პარამეტრები',
            'SSO Settings',
            'manage_options',
            'capex-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Import page UI.
     */
    public function render_import_page() {
        ?>
        <div class="wrap">
            <h1>ფორმის იმპორტი</h1>
            <p>ატვირთეთ ფორმის JSON ფაილი, რომელიც ექსპორტირებულია სხვა საიტიდან.</p>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( 'capex_import_form' ); ?>
                <input type="hidden" name="capex_import_form" value="1" />
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="capex_import_file">JSON ფაილი</label></th>
                        <td>
                            <input type="file" name="capex_import_file" id="capex_import_file" accept=".json" required />
                            <p class="description">მხოლოდ <code>.json</code> ფაილი, რომელიც ექსპორტირებულია "Export JSON" ღილაკით.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'იმპორტი' ); ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting( 'capex_options_group', 'capex_environment' );
        register_setting( 'capex_options_group', 'capex_client_id' );
        register_setting( 'capex_options_group', 'capex_client_secret' );
        register_setting( 'capex_options_group', 'capex_ssokey' );
        register_setting( 'capex_options_group', 'capex_scope_id' );
        register_setting( 'capex_options_group', 'capex_redirect_handler' );
        register_setting( 'capex_options_group', 'capex_notification_email', [
            'sanitize_callback' => 'sanitize_email',
        ] );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>CreditInfo SSO ინტეგრაცია</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'capex_options_group' ); ?>
                <?php do_settings_sections( 'capex_options_group' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">გარემო (Environment)</th>
                        <td>
                            <?php $env = get_option( 'capex_environment', 'test' ); ?>
                            <select name="capex_environment">
                                <option value="test" <?php selected( $env, 'test' ); ?>>TEST (sso-test.mycreditinfo.ge)</option>
                                <option value="live" <?php selected( $env, 'live' ); ?>>LIVE (sso.mycreditinfo.ge)</option>
                            </select>
                            <p class="description">ტესტირება დაასრულეთ TEST გარემოზე. LIVE-ზე გადართეთ მხოლოდ CreditInfo-ს დასტურის შემდეგ.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Client ID</th>
                        <td>
                            <input type="text" name="capex_client_id" value="<?php echo esc_attr( get_option('capex_client_id') ); ?>" class="regular-text" />
                            <p class="description">მაგ: <code>capitalexpress.ge</code></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Client Secret</th>
                        <td><input type="password" name="capex_client_secret" value="<?php echo esc_attr( get_option('capex_client_secret') ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">SSO Key</th>
                        <td>
                            <input type="password" name="capex_ssokey" value="<?php echo esc_attr( get_option('capex_ssokey') ); ?>" class="regular-text" />
                            <p class="description">API Gateway-ს საიდუმლო გასაღები (<code>ssokey</code> header). TEST და LIVE გარემოებში სხვადასხვაა.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Scope ID</th>
                        <td>
                            <input type="text" name="capex_scope_id" value="<?php echo esc_attr( get_option('capex_scope_id') ); ?>" class="regular-text" />
                            <p class="description">Scope ID, რომელიც MyCreditinfo-მ გადმოგცათ რეგისტრაციისას.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Redirect Handler URL</th>
                        <td>
                            <input type="text" name="capex_redirect_handler" value="<?php echo esc_attr( get_option('capex_redirect_handler') ); ?>" class="regular-text" />
                            <p class="description">
                                1. შექმენით ახალი გვერდი WordPress-ში (მაგ: "SSO Handler").<br>
                                2. ჩასვით მასში შორთკოდი: <code>[capex_sso_handler]</code><br>
                                3. დააკოპირეთ იმ გვერდის ლინკი და ჩასვით აქ.<br>
                                4. მიაწოდეთ ეს ლინკი Creditinfo-ს როგორც <strong>Redirect URL</strong>.
                            </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">შეტყობინების ელ-ფოსტა</th>
                        <td>
                            <input type="email" name="capex_notification_email" value="<?php echo esc_attr( get_option('capex_notification_email') ); ?>" class="regular-text" />
                            <p class="description">ელ-ფოსტა, სადაც გაიგზავნება შევსებული განაცხადის შეტყობინება. ცარიელის შემთხვევაში გაიგზავნება ადმინის ელ-ფოსტაზე.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}