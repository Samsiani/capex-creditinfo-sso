<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Capex_Public {

    private $sso_engine;

    public function init() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_shortcode( 'capex_form', array( $this, 'render_form_shortcode' ) );
        add_shortcode( 'capex_sso_handler', array( $this, 'render_sso_handler_shortcode' ) );
        add_action( 'wp_ajax_capex_submit_application', array( $this, 'handle_submit_application' ) );
        add_action( 'wp_ajax_nopriv_capex_submit_application', array( $this, 'handle_submit_application' ) );
        
        if ( class_exists( 'Capex_SSO' ) ) {
            $this->sso_engine = new Capex_SSO();
        }
    }

    public function enqueue_assets() {
        wp_register_style( 'capex-front-css', CAPEX_PLUGIN_URL . 'public/css/capex-front.css', array(), time() );
        wp_register_script( 'capex-front-js', CAPEX_PLUGIN_URL . 'public/js/capex-front.js', array( 'jquery' ), time(), true );
        
        wp_localize_script( 'capex-front-js', 'capex_obj', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'capex_form_nonce' )
        ));
    }

    public function render_sso_handler_shortcode() {
        if ( ! isset( $_GET['code'] ) || ! isset( $_GET['state'] ) ) return '';

        $return_url = $this->sso_engine->validate_state_and_get_url( $_GET['state'] );
        if ( ! $return_url ) return '<div style="color:red; border:1px solid red; padding:10px;">სესია ვადაგასულია.</div>';

        $token = $this->sso_engine->exchange_code_for_token( $_GET['code'] );
        if ( $token ) {
            $user_data = $this->sso_engine->get_user_info( $token );
            if ( $user_data ) {
                if(!isset($_COOKIE['capex_session_id'])) {
                    $session_id = md5(uniqid(rand(), true));
                    setcookie('capex_session_id', $session_id, time() + 3600, '/');
                } else {
                    $session_id = $_COOKIE['capex_session_id'];
                }
                set_transient( 'capex_user_data_' . $session_id, $user_data, 5 * MINUTE_IN_SECONDS );
                echo '<script>window.location.href = "' . esc_url($return_url) . '";</script>';
                exit;
            }
        }
        return '<div style="color:red;">ავტორიზაციის შეცდომა.</div>';
    }

    public function render_form_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts );
        $form_id = intval( $atts['id'] );
        
        if ( ! $form_id ) return '<p style="color:red;">ფორმა ID მითითებული არ არის.</p>';

        $structure_json = get_post_meta( $form_id, '_capex_form_structure', true );
        $structure = json_decode( $structure_json, true );

        if ( empty( $structure ) || ! is_array( $structure ) ) {
            return '<p style="color:red;">ფორმა ცარიელია ან დაზიანებული. შედით ადმინ პანელში და განაახლეთ (Update) ფორმა.</p>';
        }

        wp_enqueue_style( 'capex-front-css' );
        wp_enqueue_script( 'capex-front-js' );

        $prefill_data = array();
        if(isset($_COOKIE['capex_session_id'])) {
            $session_id = $_COOKIE['capex_session_id'];
            $cached_data = get_transient( 'capex_user_data_' . $session_id );
            if($cached_data) {
                $prefill_data = $cached_data;
                delete_transient( 'capex_user_data_' . $session_id );
            }
        }

        $current_url = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $sso_url = $this->sso_engine->get_auth_url( $current_url );

        ob_start();
        ?>
        <div class="capex-form-wrapper" data-form-id="<?php echo $form_id; ?>">
            
            <div id="capex-error-box" class="cx-error-summary" style="display:none;">
                <div class="cx-error-title">⚠️ ყურადღება!</div>
                <ul id="capex-error-list"></ul>
            </div>

            <div class="capex-progress">
                <?php foreach($structure as $index => $step): ?>
                    <div class="step-indicator <?php echo $index === 0 ? 'active' : ''; ?>" data-step="<?php echo $index + 1; ?>">
                        <?php echo $index + 1; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <form class="capex-loan-form" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
                
                <?php foreach($structure as $index => $step): 
                    $step_num = $index + 1;
                    $is_active = ($index === 0) ? 'active' : '';
                ?>
                    <div class="form-step <?php echo $is_active; ?>" id="step-<?php echo $step_num; ?>" data-step="<?php echo $step_num; ?>">
                        
                        <?php if($index === 0): ?>
                            <?php if(!empty($prefill_data)): ?>
                                <div class="cx-success-msg">✔ მონაცემები მიღებულია Creditinfo-დან</div>
                            <?php elseif($sso_url): ?>
                                <a href="<?php echo esc_url($sso_url); ?>" class="btn-creditinfo">
                                    MyCreditinfo ავტორიზაცია
                                </a>
                                <div class="or-divider">ან შეავსეთ ხელით</div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <div class="cx-fields-row">
                            <?php 
                            if(!empty($step['fields'])):
                                foreach($step['fields'] as $field): 
                                    $this->render_field($field, $prefill_data);
                                endforeach;
                            endif;
                            ?>
                        </div>

                        <div class="form-actions">
                            <?php if($index > 0): ?>
                                <button type="button" class="btn btn-prev" onclick="capexPrevStep()">უკან</button>
                            <?php else: ?>
                                <div></div>
                            <?php endif; ?>

                            <?php if($index < count($structure) - 1): ?>
                                <button type="button" class="btn btn-next" onclick="capexNextStep()">შემდეგი &rarr;</button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-submit">გაგზავნა</button>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>

                <div class="form-step" id="step-success" style="text-align:center; padding:50px 0;">
                     <h2 style="color:#0073aa;">განაცხადი მიღებულია!</h2>
                     <p>ჩვენი ოპერატორი მალე დაგიკავშირდებათ.</p>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_field($field, $prefill_data) {
        $id = isset($field['id']) ? $field['id'] : uniqid('f_');
        $label = isset($field['label']) ? $field['label'] : '';
        $type = isset($field['type']) ? $field['type'] : 'text';
        $width = isset($field['width']) ? $field['width'] : '100'; 
        $required = !empty($field['required']) ? 'required' : '';
        $sso_map = isset($field['sso_map']) ? $field['sso_map'] : '';
        $html_content = isset($field['html_content']) ? $field['html_content'] : '';
        
        // პარამეტრები
        $checkbox_label = !empty($field['html_checkbox_label']) ? $field['html_checkbox_label'] : 'გავეცანი და ვეთანხმები';
        $auto_height = !empty($field['html_auto_height']) ? 'auto-height' : ''; 
        $max_length = !empty($field['max_length']) ? 'maxlength="'.intval($field['max_length']).'"' : '';
        
        // მხოლოდ ციფრების ლოგიკა
        $numbers_only_attr = '';
        if(!empty($field['numbers_only'])) {
            // oninput: შლის არა-ციფრებს, inputmode: მობილურზე ციფრულ კლავიატურას ხსნის
            $numbers_only_attr = 'oninput="this.value = this.value.replace(/[^0-9]/g, \'\')" inputmode="numeric" pattern="\d*"';
        }

        // Logic Data
        $logic = isset($field['logic']) ? $field['logic'] : array();
        $logic_attr = '';
        if(!empty($logic['enabled'])) {
            $logic_attr = "data-logic='" . esc_attr(json_encode($logic)) . "'";
        }

        $value = '';
        if ($sso_map && !empty($prefill_data[$sso_map])) {
            $value = esc_attr($prefill_data[$sso_map]);
        }

        // Autofill Mapping
        $autocomplete = 'autocomplete="off"'; // Default to off or generic
        switch($sso_map) {
            case 'name': $autocomplete = 'autocomplete="given-name"'; break;
            case 'surname': $autocomplete = 'autocomplete="family-name"'; break;
            case 'email': $autocomplete = 'autocomplete="email"'; break;
            case 'phone': $autocomplete = 'autocomplete="tel"'; break;
            case 'address': $autocomplete = 'autocomplete="street-address"'; break;
            case 'dob': $autocomplete = 'autocomplete="bday"'; break;
            default: $autocomplete = 'autocomplete="on"'; break; // თუ SSO არ არის, ჩვეულებრივი Autofill
        }

        $col_class = 'cx-col-' . esc_attr($width);

        echo '<div id="container_'.esc_attr($id).'" class="form-group ' . $col_class . ' field-type-'.esc_attr($type).'" '.$logic_attr.'>';
        
        if ($type === 'html') {
             echo '<label class="form-label">'.esc_html($label).' <span class="required">*</span></label>';
             echo '<div class="legal-scroll-box '.esc_attr($auto_height).'">' . wp_kses_post($html_content) . '</div>';
             echo '<label class="checkbox-label"><input type="checkbox" name="'.esc_attr($id).'" value="1" '.$required.'> '.esc_html($checkbox_label).'</label>';
        } 
        elseif ($type === 'file') {
             echo '<label class="form-label">'.esc_html($label).' '.($required?'<span class="required">*</span>':'').'</label>';
             echo '<div class="file-drop-area" onclick="document.getElementById(\''.esc_attr($id).'\').click()">';
             echo '<span style="font-size:24px;">📂</span> <span class="file-msg">აირჩიეთ ფაილი</span>';
             echo '<input type="file" id="'.esc_attr($id).'" name="'.esc_attr($id).'[]" multiple hidden>';
             echo '</div>';
             echo '<div class="file-list-display" id="display_'.esc_attr($id).'"></div>';
        }
        elseif ($type === 'radio') {
             echo '<label class="form-label">'.esc_html($label).' '.($required?'<span class="required">*</span>':'').'</label>';
             if(!empty($field['options'])) {
                 foreach($field['options'] as $opt) {
                     echo '<label class="checkbox-label" style="margin-bottom:5px;">';
                     echo '<input type="radio" name="'.esc_attr($id).'" value="'.esc_attr($opt['value']).'" '.$required.'> ';
                     echo esc_html($opt['label']);
                     echo '</label>';
                 }
             }
        }
        elseif ($type === 'select') {
             echo '<label class="form-label">'.esc_html($label).' '.($required?'<span class="required">*</span>':'').'</label>';
             echo '<select name="'.esc_attr($id).'" class="form-control" '.$required.' '.$autocomplete.'>';
             echo '<option value="">- აირჩიეთ -</option>';
             if(!empty($field['options'])) {
                 foreach($field['options'] as $opt) {
                     echo '<option value="'.esc_attr($opt['value']).'">'.esc_html($opt['label']).'</option>';
                 }
             }
             echo '</select>';
        }
        elseif ($type === 'date') {
            echo '<label class="form-label">'.esc_html($label).' '.($required?'<span class="required">*</span>':'').'</label>';
            echo '<div style="display:flex; gap:10px;">';
            echo '<input type="datetime-local" id="'.esc_attr($id).'" name="'.esc_attr($id).'" class="form-control" value="'.$value.'" '.$required.' '.$autocomplete.'>';
            echo '<button type="button" class="cx-btn-now" style="padding:0 15px; border:1px solid #ddd; background:#f1f1f1; cursor:pointer; border-radius:4px; font-size:13px;">ახლა</button>';
            echo '</div>';
       }
        else {
             // Text, Number, Email
             echo '<label class="form-label">'.esc_html($label).' '.($required?'<span class="required">*</span>':'').'</label>';
             $extra_class = '';
             if($sso_map == 'name') $extra_class = 'cx-input-name';
             if($sso_map == 'surname') $extra_class = 'cx-input-surname';
             
             // ვამატებთ ახალ ატრიბუტებს: maxlength, inputmode, oninput
             echo '<input type="'.esc_attr($type).'" id="'.esc_attr($id).'" name="'.esc_attr($id).'" class="form-control '.esc_attr($extra_class).'" value="'.$value.'" '.$required.' '.$autocomplete.' '.$max_length.' '.$numbers_only_attr.'>';
        }
        echo '</div>';
    }

    public function handle_submit_application() {
        check_ajax_referer( 'capex_form_nonce', 'security' );

        $form_id = intval( $_POST['form_id'] );
        if ( ! $form_id ) wp_send_json_error( array( 'message' => 'ID Error' ) );

        $entry_data = array();
        foreach ( $_POST as $key => $val ) {
            if ( strpos( $key, 'field_' ) === 0 ) {
                $entry_data[ sanitize_text_field($key) ] = sanitize_text_field( $val );
            }
        }

        if ( ! empty( $_FILES ) ) {
            foreach ( $_FILES as $field_id => $file_array ) {
                $uploaded = $this->handle_secure_upload( $file_array );
                if ( ! empty( $uploaded ) ) {
                    $entry_data[ $field_id ] = $uploaded;
                }
            }
        }

        $title = 'განაცხადი #' . time();
        $post_id = wp_insert_post( array(
            'post_title'  => $title,
            'post_type'   => 'capex_entry',
            'post_status' => 'publish',
        ));

        if ( $post_id ) {
            update_post_meta( $post_id, '_capex_form_id', $form_id );
            update_post_meta( $post_id, '_capex_entry_data', $entry_data );
            $admin_email = get_option( 'admin_email' );
            wp_mail( $admin_email, 'ახალი განაცხადი', 'ახალი განაცხადი შემოვიდა.' );
            wp_send_json_success();
        } else {
            wp_send_json_error( array( 'message' => 'DB Error' ) );
        }
    }

    private function handle_secure_upload( $file_array ) {
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/capex_secure_docs/';
        $target_url = $upload_dir['baseurl'] . '/capex_secure_docs/';
        $saved_files = array();
        if(is_array($file_array['name'])) {
            $count = count($file_array['name']);
            for($i=0; $i<$count; $i++) {
                if($file_array['error'][$i] == 0) {
                    $ext = pathinfo($file_array['name'][$i], PATHINFO_EXTENSION);
                    $new_name = time() . '_' . rand(1000,9999) . '.' . $ext;
                    if(move_uploaded_file($file_array['tmp_name'][$i], $target_dir . $new_name)) {
                        $saved_files[] = $target_url . $new_name;
                    }
                }
            }
        } 
        return $saved_files;
    }
}