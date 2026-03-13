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
        wp_register_style( 'capex-front-css', CAPEX_PLUGIN_URL . 'public/css/capex-front.css', array(), CAPEX_VERSION );
        wp_register_script( 'capex-front-js', CAPEX_PLUGIN_URL . 'public/js/capex-front.js', array( 'jquery' ), CAPEX_VERSION, true );

        wp_localize_script( 'capex-front-js', 'capex_obj', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'capex_form_nonce' )
        ));
    }

    /**
     * SSO callback handler shortcode.
     *
     * MyCreditinfo redirects here with ?code=...&session_state=...
     * If state param was sent in the authorize URL, it comes back as ?state=...
     * On failure, the SSO redirects to redirect_url/failed
     */
    public function render_sso_handler_shortcode() {
        // Handle failed SSO auth — MyCreditinfo appends /failed to redirect_url
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        if ( preg_match( '#/failed\b#i', $request_uri ) ) {
            return '<div style="color:#d63638; border:1px solid #d63638; padding:15px; border-radius:6px; margin:20px 0;">'
                 . '<strong>ავტორიზაცია ვერ მოხერხდა.</strong> გთხოვთ სცადოთ თავიდან ან შეავსეთ ფორმა ხელით.'
                 . '</div>';
        }

        if ( ! isset( $_GET['code'] ) ) {
            return '';
        }

        // SSO may return state (standard OAuth2) or session_state (MyCreditinfo specific)
        $state = isset( $_GET['state'] ) ? $_GET['state'] : '';

        if ( ! empty( $state ) ) {
            $return_url = $this->sso_engine->validate_state_and_get_url( $state );
            if ( ! $return_url ) {
                return '<div style="color:#d63638; border:1px solid #d63638; padding:15px; border-radius:6px; margin:20px 0;">'
                     . 'სესია ვადაგასულია. გთხოვთ სცადოთ თავიდან.'
                     . '</div>';
            }
        } else {
            // No state param — MyCreditinfo doesn't return it. Use saved transient.
            $saved_return = get_transient( 'capex_sso_return_url' );
            if ( $saved_return ) {
                delete_transient( 'capex_sso_return_url' );
                $return_url = $saved_return;
            } else {
                $return_url = home_url( '/' );
            }
        }

        $token = $this->sso_engine->exchange_code_for_token( sanitize_text_field( $_GET['code'] ) );
        if ( ! $token ) {
            return '<div style="color:#d63638; border:1px solid #d63638; padding:15px; border-radius:6px; margin:20px 0;">'
                 . 'ავტორიზაციის შეცდომა. ტოკენის მიღება ვერ მოხერხდა.'
                 . '</div>';
        }

        $user_data = $this->sso_engine->get_user_info( $token );
        if ( ! $user_data ) {
            return '<div style="color:#d63638; border:1px solid #d63638; padding:15px; border-radius:6px; margin:20px 0;">'
                 . 'ავტორიზაციის შეცდომა. მომხმარებლის მონაცემები ვერ მოიძებნა.'
                 . '</div>';
        }

        // Store SSO data in transient, keyed by session cookie
        if ( ! isset( $_COOKIE['capex_session_id'] ) ) {
            $session_id = wp_generate_password( 32, false );
            setcookie( 'capex_session_id', $session_id, time() + 3600, '/', '', is_ssl(), true );
        } else {
            $session_id = sanitize_text_field( $_COOKIE['capex_session_id'] );
        }

        set_transient( 'capex_user_data_' . $session_id, $user_data, 5 * MINUTE_IN_SECONDS );

        // Redirect to the form page
        echo '<script>window.location.href = ' . wp_json_encode( esc_url( $return_url ) ) . ';</script>';
        exit;
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
        if ( isset( $_COOKIE['capex_session_id'] ) ) {
            $session_id  = sanitize_text_field( $_COOKIE['capex_session_id'] );
            $cached_data = get_transient( 'capex_user_data_' . $session_id );
            if ( $cached_data ) {
                $prefill_data = $cached_data;
                delete_transient( 'capex_user_data_' . $session_id );
            }
        }

        // Build current page URL (HTTPS-aware)
        $current_url = set_url_scheme( home_url( $_SERVER['REQUEST_URI'] ) );
        $sso_url     = $this->sso_engine ? $this->sso_engine->get_auth_url( $current_url ) : false;

        ob_start();
        ?>
        <div class="capex-form-wrapper" data-form-id="<?php echo esc_attr( $form_id ); ?>">

            <div id="capex-error-box" class="cx-error-summary" style="display:none;">
                <div class="cx-error-title">&#9888;&#65039; ყურადღება!</div>
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
                <input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">

                <?php foreach($structure as $index => $step):
                    $step_num = $index + 1;
                    $is_active = ($index === 0) ? 'active' : '';
                ?>
                    <div class="form-step <?php echo $is_active; ?>" id="step-<?php echo $step_num; ?>" data-step="<?php echo $step_num; ?>">

                        <?php if($index === 0): ?>
                            <?php if(!empty($prefill_data)): ?>
                                <div class="cx-success-msg">&#10004; მონაცემები მიღებულია Creditinfo-დან</div>
                            <?php elseif($sso_url): ?>
                                <div class="cx-sso-row">
                                    <a href="<?php echo esc_url($sso_url); ?>" class="btn-creditinfo">MyCreditinfo ავტორიზაცია</a>
                                    <span class="or-divider">ან შეავსეთ ხელით</span>
                                </div>
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

        $checkbox_label = !empty($field['html_checkbox_label']) ? $field['html_checkbox_label'] : 'გავეცანი და ვეთანხმები';
        $auto_height = !empty($field['html_auto_height']) ? 'auto-height' : '';
        $max_length = !empty($field['max_length']) ? 'maxlength="'.intval($field['max_length']).'"' : '';

        $numbers_only_attr = '';
        if(!empty($field['numbers_only'])) {
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
        $autocomplete = 'autocomplete="on"';
        switch($sso_map) {
            case 'name':    $autocomplete = 'autocomplete="given-name"'; break;
            case 'surname': $autocomplete = 'autocomplete="family-name"'; break;
            case 'email':   $autocomplete = 'autocomplete="email"'; break;
            case 'phone':   $autocomplete = 'autocomplete="tel"'; break;
            case 'address': $autocomplete = 'autocomplete="street-address"'; break;
            case 'dob':     $autocomplete = 'autocomplete="bday"'; break;
            case 'country': $autocomplete = 'autocomplete="country"'; break;
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
             echo '<span style="font-size:24px;">&#128194;</span> <span class="file-msg">აირჩიეთ ფაილი</span>';
             echo '<input type="file" id="'.esc_attr($id).'" name="'.esc_attr($id).'[]" multiple hidden>';
             echo '</div>';
             echo '<div class="file-list-display" id="display_'.esc_attr($id).'"></div>';
        }
        elseif ($type === 'radio') {
             echo '<label class="form-label">'.esc_html($label).' '.($required?'<span class="required">*</span>':'').'</label>';
             if(!empty($field['options'])) {
                 foreach($field['options'] as $idx => $opt) {
                     // Auto-select from SSO: match by value or by option index for customer_type
                     $checked = '';
                     if ( $value !== '' ) {
                         if ( $value === $opt['value'] ) {
                             $checked = 'checked';
                         } elseif ( $sso_map === 'customer_type' ) {
                             // SSO returns PERSON/COMPANY — map to first/second radio option
                             if ( ( strtoupper($value) === 'PERSON' && $idx === 0 ) ||
                                  ( strtoupper($value) === 'COMPANY' && $idx === 1 ) ) {
                                 $checked = 'checked';
                             }
                         }
                     }
                     echo '<label class="checkbox-label" style="margin-bottom:5px;">';
                     echo '<input type="radio" name="'.esc_attr($id).'" value="'.esc_attr($opt['value']).'" '.$required.' '.$checked.'> ';
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
                     $selected = ($value === $opt['value']) ? 'selected' : '';
                     echo '<option value="'.esc_attr($opt['value']).'" '.$selected.'>'.esc_html($opt['label']).'</option>';
                 }
             }
             echo '</select>';
        }
        elseif ($type === 'date') {
            // For DOB fields: date-only, max = 18 years ago
            $is_dob = ($sso_map === 'dob');
            $input_type = $is_dob ? 'date' : 'datetime-local';
            $max_attr = '';
            if ( $is_dob ) {
                $max_date = date( 'Y-m-d', strtotime( '-18 years' ) );
                $max_attr = 'max="' . $max_date . '"';
                // Strip time portion if SSO returned datetime
                if ( $value && strlen($value) > 10 ) {
                    $value = substr($value, 0, 10);
                }
            }

            echo '<label class="form-label">'.esc_html($label).' '.($required?'<span class="required">*</span>':'').'</label>';
            echo '<div style="display:flex; gap:10px;">';
            echo '<input type="'.esc_attr($input_type).'" id="'.esc_attr($id).'" name="'.esc_attr($id).'" class="form-control" value="'.$value.'" '.$required.' '.$autocomplete.' '.$max_attr.'>';
            if ( ! $is_dob ) {
                echo '<button type="button" class="cx-btn-now" style="padding:0 15px; border:1px solid #ddd; background:#f1f1f1; cursor:pointer; border-radius:4px; font-size:13px;">ახლა</button>';
            }
            echo '</div>';
       }
        else {
             // Text, Number, Email
             echo '<label class="form-label">'.esc_html($label).' '.($required?'<span class="required">*</span>':'').'</label>';
             $extra_class = '';
             if($sso_map == 'name') $extra_class = 'cx-input-name';
             if($sso_map == 'surname') $extra_class = 'cx-input-surname';

             echo '<input type="'.esc_attr($type).'" id="'.esc_attr($id).'" name="'.esc_attr($id).'" class="form-control '.esc_attr($extra_class).'" value="'.$value.'" '.$required.' '.$autocomplete.' '.$max_length.' '.$numbers_only_attr.'>';
        }
        echo '</div>';
    }

    public function handle_submit_application() {
        check_ajax_referer( 'capex_form_nonce', 'security' );

        // Rate limiting: max 5 submissions per IP per hour (skip for admins)
        if ( ! current_user_can( 'manage_options' ) ) {
            $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
            $rate_key = 'capex_rate_' . md5( $ip );
            $attempts = (int) get_transient( $rate_key );
            if ( $attempts >= 5 ) {
                wp_send_json_error( array( 'message' => 'ძალიან ბევრი მოთხოვნა. სცადეთ მოგვიანებით.' ) );
            }
            set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );
        }

        $form_id = intval( $_POST['form_id'] );
        if ( ! $form_id ) wp_send_json_error( array( 'message' => 'ID Error' ) );

        // Load form structure once for field whitelist and consent timestamps
        $structure_json = get_post_meta( $form_id, '_capex_form_structure', true );
        $structure = json_decode( $structure_json, true );

        // Build whitelist of valid field IDs from form structure
        $allowed_fields = array();
        if ( ! empty( $structure ) && is_array( $structure ) ) {
            foreach ( $structure as $step ) {
                if ( empty( $step['fields'] ) ) continue;
                foreach ( $step['fields'] as $field ) {
                    if ( ! empty( $field['id'] ) ) {
                        $allowed_fields[ $field['id'] ] = $field['type'] ?? 'text';
                    }
                }
            }
        }

        // Only accept whitelisted fields
        $entry_data = array();
        foreach ( $_POST as $key => $val ) {
            $clean_key = sanitize_text_field( $key );
            if ( isset( $allowed_fields[ $clean_key ] ) ) {
                $entry_data[ $clean_key ] = sanitize_text_field( $val );
            }
        }

        if ( ! empty( $_FILES ) ) {
            foreach ( $_FILES as $field_id => $file_array ) {
                if ( ! isset( $allowed_fields[ $field_id ] ) ) continue;
                $uploaded = $this->handle_secure_upload( $file_array );
                if ( ! empty( $uploaded ) ) {
                    $entry_data[ $field_id ] = $uploaded;
                }
            }
        }

        // Record user IP
        $entry_data['_user_ip'] = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
            ? sanitize_text_field( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] )
            : sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( ! empty( $structure ) && is_array( $structure ) ) {
            foreach ( $structure as $step ) {
                if ( empty( $step['fields'] ) ) continue;
                foreach ( $step['fields'] as $field ) {
                    if ( isset( $field['type'] ) && $field['type'] === 'html' && ! empty( $field['id'] ) ) {
                        if ( ! empty( $entry_data[ $field['id'] ] ) ) {
                            $entry_data[ $field['id'] . '_timestamp' ] = current_time( 'Y-m-d H:i:s' );
                        }
                    }
                }
            }
        }

        $post_id = wp_insert_post( array(
            'post_title'  => 'განაცხადი',
            'post_type'   => 'capex_entry',
            'post_status' => 'publish',
        ));

        if ( $post_id ) {
            // Set title with post ID
            wp_update_post( array(
                'ID'         => $post_id,
                'post_title' => 'განაცხადი #' . $post_id,
            ));

            update_post_meta( $post_id, '_capex_form_id', $form_id );
            update_post_meta( $post_id, '_capex_entry_data', $entry_data );
            $notify_email = get_option( 'capex_notification_email' );
            if ( empty( $notify_email ) ) {
                $notify_email = get_option( 'admin_email' );
            }

            $email_body = $this->build_entry_email( $form_id, $entry_data, $post_id );
            $email_subject = 'ახალი განაცხადი — ' . get_the_title( $form_id ) . ' (განაცხადი #' . $post_id . ')';
            $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            wp_mail( $notify_email, $email_subject, $email_body, $headers );
            wp_send_json_success();
        } else {
            wp_send_json_error( array( 'message' => 'DB Error' ) );
        }
    }

    private function build_entry_email( $form_id, $entry_data, $post_id ) {
        $form_title = get_the_title( $form_id );
        $date       = current_time( 'Y-m-d H:i:s' );
        $edit_url   = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

        $structure_json = get_post_meta( $form_id, '_capex_form_structure', true );
        $structure      = json_decode( $structure_json, true );

        $rows_html = '';

        if ( ! empty( $structure ) && is_array( $structure ) ) {
            foreach ( $structure as $step_index => $step ) {
                if ( empty( $step['fields'] ) ) continue;

                $step_rows = '';
                foreach ( $step['fields'] as $field ) {
                    $id    = isset( $field['id'] ) ? $field['id'] : '';
                    $label = isset( $field['label'] ) ? $field['label'] : '';
                    $type  = isset( $field['type'] ) ? $field['type'] : 'text';
                    $value = isset( $entry_data[ $id ] ) ? $entry_data[ $id ] : '';

                    if ( $type === 'html' ) {
                        $timestamp = isset( $entry_data[ $id . '_timestamp' ] ) ? $entry_data[ $id . '_timestamp' ] : '';
                        $value = '&#10004; დადასტურებულია';
                        if ( $timestamp ) {
                            $value .= ' <span style="color:#666; font-size:12px;">(' . esc_html( $timestamp ) . ')</span>';
                        }
                    } elseif ( $type === 'file' ) {
                        if ( is_array( $value ) && ! empty( $value ) ) {
                            $links = array();
                            foreach ( $value as $url ) {
                                $proxy_url = Capex_Admin::get_secure_file_url( $url );
                                $links[] = '<a href="' . esc_url( $proxy_url ) . '">გახსნა</a>';
                            }
                            $value = implode( ', ', $links );
                        } else {
                            continue;
                        }
                    } else {
                        if ( empty( $value ) || $value === '-' ) continue;
                        $value = esc_html( $value );
                    }

                    $step_rows .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;color:#555;font-weight:600;width:40%;">' . esc_html( $label ) . '</td>';
                    $step_rows .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;color:#1d2327;">' . $value . '</td></tr>';
                }

                if ( ! empty( $step_rows ) ) {
                    $rows_html .= $step_rows;
                }
            }
        }

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:#f5f5f5;">';
        $html .= '<div style="max-width:600px;margin:20px auto;background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">';

        // Header
        $html .= '<div style="background:#0073aa;padding:20px 25px;color:#fff;">';
        $html .= '<h1 style="margin:0;font-size:20px;">ახალი განაცხადი</h1>';
        $user_ip = isset( $entry_data['_user_ip'] ) ? $entry_data['_user_ip'] : '';
        $html .= '<p style="margin:8px 0 0;font-size:13px;opacity:0.9;">ფორმა: ' . esc_html( $form_title ) . ' | დრო: ' . $date;
        if ( $user_ip ) {
            $html .= ' | User IP: ' . esc_html( $user_ip );
        }
        $html .= '</p>';
        $html .= '</div>';

        // Body
        $html .= '<div style="padding:20px 25px;">';
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
        $html .= $rows_html;
        $html .= '</table>';
        $html .= '</div>';

        // Footer
        $html .= '<div style="padding:15px 25px;background:#f9f9f9;border-top:1px solid #eee;font-size:13px;text-align:center;">';
        $html .= '<a href="' . esc_url( $edit_url ) . '" style="color:#0073aa;text-decoration:none;font-weight:600;">ნახვა ადმინ პანელში &rarr;</a>';
        $html .= '</div>';

        $html .= '</div></body></html>';

        return $html;
    }

    private static $allowed_upload_types = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    );

    private function handle_secure_upload( $file_array ) {
        $max_file_size = 10 * 1024 * 1024; // 10 MB per file
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/capex_secure_docs/';
        $target_url = $upload_dir['baseurl'] . '/capex_secure_docs/';
        $saved_files = array();
        if ( is_array( $file_array['name'] ) ) {
            $count = count( $file_array['name'] );
            for ( $i = 0; $i < $count; $i++ ) {
                if ( $file_array['error'][$i] != 0 ) continue;

                // File size check
                if ( $file_array['size'][$i] > $max_file_size ) continue;

                // MIME type validation via finfo
                $finfo = finfo_open( FILEINFO_MIME_TYPE );
                $mime  = finfo_file( $finfo, $file_array['tmp_name'][$i] );
                finfo_close( $finfo );

                if ( ! isset( self::$allowed_upload_types[ $mime ] ) ) continue;

                $ext = self::$allowed_upload_types[ $mime ];
                $new_name = bin2hex( random_bytes( 16 ) ) . '.' . $ext;

                if ( move_uploaded_file( $file_array['tmp_name'][$i], $target_dir . $new_name ) ) {
                    $saved_files[] = $target_url . $new_name;
                }
            }
        }
        return $saved_files;
    }
}
