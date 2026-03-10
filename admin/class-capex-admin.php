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
        if ( ( 'post.php' === $hook || 'post-new.php' === $hook ) ) {
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

        echo '<div class="capex-view-wrapper">';

        // სისტემური ინფო
        echo '<div class="cx-system-info">';
        echo '<strong>ფორმა:</strong> <a href="'.get_edit_post_link($form_id).'">'.get_the_title($form_id).'</a> | ';
        echo '<strong>დრო:</strong> ' . get_the_date('Y-m-d H:i:s', $post->ID);
        echo '</div>';

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
                    $value = isset($entry_data[$id]) ? $entry_data[$id] : '-';

                    echo '<div class="cx-row">';
                    echo '<div class="cx-label">' . esc_html($label) . ':</div>';
                    echo '<div class="cx-val">';
                    
                    // ტიპების მიხედვით გამოტანა
                    if ( $type === 'file' ) {
                        if(is_array($value)) {
                            foreach($value as $file_url) {
                                echo '<a href="'.esc_url($file_url).'" target="_blank" class="cx-file-link">📄 გახსნა</a> ';
                            }
                        } else {
                            echo "ფაილი არ არის";
                        }
                    } elseif ( $type === 'html' ) {
                         // HTML/Consent ტიპის ველებზე ვაჩვენებთ რომ დაეთანხმა
                         echo '<span style="color:green">✔ დადასტურებულია</span>';
                    } else {
                        echo esc_html($value);
                    }
                    
                    echo '</div></div>';
                }
            }
        }

        echo '</div>'; // wrapper
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
        register_setting( 'capex_options_group', 'capex_client_id' );
        register_setting( 'capex_options_group', 'capex_client_secret' );
        register_setting( 'capex_options_group', 'capex_redirect_handler' );
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
                        <th scope="row">Client ID</th>
                        <td><input type="text" name="capex_client_id" value="<?php echo esc_attr( get_option('capex_client_id') ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Client Secret</th>
                        <td><input type="password" name="capex_client_secret" value="<?php echo esc_attr( get_option('capex_client_secret') ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Redirect Handler URL (ტექნიკური გვერდი)</th>
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
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}