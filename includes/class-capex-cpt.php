<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Capex_CPT {

    public function init() {
        add_action( 'init', array( $this, 'register_forms_cpt' ) );
        add_action( 'init', array( $this, 'register_entries_cpt' ) );

        // ფორმების ცხრილის სვეტები (Forms List)
        add_filter( 'manage_capex_form_posts_columns', array( $this, 'set_form_columns' ) );
        add_action( 'manage_capex_form_posts_custom_column', array( $this, 'render_form_columns' ), 10, 2 );

        // განაცხადების ცხრილის სვეტები (Entries List)
        add_filter( 'manage_capex_entry_posts_columns', array( $this, 'set_entry_columns' ) );
        add_action( 'manage_capex_entry_posts_custom_column', array( $this, 'render_entry_columns' ), 10, 2 );

        // Bold unread entry rows
        add_filter( 'post_class', array( $this, 'add_unread_row_class' ), 10, 3 );
        add_action( 'admin_head', array( $this, 'entry_list_styles' ) );
    }

    /**
     * 1. ფორმების რეგისტრაცია (Form Builder Post Type)
     */
    public function register_forms_cpt() {
        $labels = array(
            'name'               => 'ფორმები',
            'singular_name'      => 'ფორმა',
            'menu_name'          => 'Capex Forms',
            'add_new'            => 'ახალი ფორმა',
            'add_new_item'       => 'ახალი ფორმის დამატება',
            'edit_item'          => 'ფორმის რედაქტირება',
            'all_items'          => 'ყველა ფორმა',
            'search_items'       => 'ფორმების ძებნა',
        );

        $args = array(
            'labels'              => $labels,
            'public'              => false, // პირდაპირი ლინკით არ იხსნება (მხოლოდ შორთკოდით)
            'show_ui'             => true,
            'show_in_menu'        => true,  // ცალკე მენიუ
            'menu_icon'           => 'dashicons-clipboard',
            'supports'            => array( 'title' ), // კონტენტს ჩვენი ბილდერი მართავს
            'capability_type'     => 'post',
        );

        register_post_type( 'capex_form', $args );
    }

    /**
     * 2. განაცხადების რეგისტრაცია (Entries Post Type)
     */
    public function register_entries_cpt() {
        $labels = array(
            'name'               => 'განაცხადები',
            'singular_name'      => 'განაცხადი',
            'menu_name'          => 'განაცხადები', // ეს მოგვიანებით Submenu-ში გადავა
            'search_items'       => 'განაცხადის ძებნა',
            'not_found'          => 'განაცხადები არ არის',
        );

        $args = array(
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=capex_form', // ვაგდებთ "Capex Forms" მენიუს ქვეშ
            'menu_icon'           => 'dashicons-money-alt',
            'supports'            => array( 'title' ),
            'capabilities'        => array( 'create_posts' => 'do_not_allow' ), // ხელით შექმნის აკრძალვა
            'map_meta_cap'        => true,
        );

        register_post_type( 'capex_entry', $args );
    }

    /**
     * ფორმების ცხრილის სვეტები (ID, Shortcode, Entries Count)
     */
    public function set_form_columns( $columns ) {
        $new_columns = array(
            'cb'        => $columns['cb'],
            'title'     => 'სათაური',
            'shortcode' => 'შორთკოდი',     // [capex_form id="123"]
            'count'     => 'განაცხადები',  // რამდენი განაცხადია შემოსული
            'date'      => 'თარიღი'
        );
        return $new_columns;
    }

    public function render_form_columns( $column, $post_id ) {
        switch ( $column ) {
            case 'shortcode':
                echo '<code style="user-select:all;">[capex_form id="' . $post_id . '"]</code>';
                break;

            case 'count':
                // ვითვლით რამდენი განაცხადია ამ ფორმაზე
                $count_query = new WP_Query( array(
                    'post_type'      => 'capex_entry',
                    'meta_key'       => '_capex_form_id',
                    'meta_value'     => $post_id,
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'post_status'    => 'publish' // ან 'any'
                ));
                echo '<a href="' . admin_url('edit.php?post_type=capex_entry&form_id=' . $post_id) . '">' . $count_query->found_posts . '</a>';
                break;
        }
    }

    /**
     * განაცხადების ცხრილის სვეტები
     */
    public function set_entry_columns( $columns ) {
        $new_columns = array(
            'cb'            => $columns['cb'],
            'title'         => 'განაცხადი',
            'full_name'     => 'სახელი / გვარი',
            'form_source'   => 'ფორმის წყარო',
            'status'        => 'სტატუსი',
            'date'          => 'შევსების დრო'
        );
        return $new_columns;
    }

    public function render_entry_columns( $column, $post_id ) {
        switch ( $column ) {
            case 'full_name':
                $entry_data = get_post_meta( $post_id, '_capex_entry_data', true );
                $form_id    = get_post_meta( $post_id, '_capex_form_id', true );
                $name = '';
                $surname = '';
                if ( ! empty( $entry_data ) && $form_id ) {
                    $structure_json = get_post_meta( $form_id, '_capex_form_structure', true );
                    $structure      = json_decode( $structure_json, true );
                    if ( ! empty( $structure ) && is_array( $structure ) ) {
                        foreach ( $structure as $step ) {
                            if ( empty( $step['fields'] ) ) continue;
                            foreach ( $step['fields'] as $field ) {
                                if ( ! empty( $field['sso_map'] ) && ! empty( $field['id'] ) ) {
                                    if ( $field['sso_map'] === 'name' && isset( $entry_data[ $field['id'] ] ) ) {
                                        $name = $entry_data[ $field['id'] ];
                                    }
                                    if ( $field['sso_map'] === 'surname' && isset( $entry_data[ $field['id'] ] ) ) {
                                        $surname = $entry_data[ $field['id'] ];
                                    }
                                }
                            }
                        }
                    }
                }
                $full = trim( $name . ' ' . $surname );
                echo $full ? esc_html( $full ) : '—';
                break;

            case 'form_source':
                $form_id = get_post_meta( $post_id, '_capex_form_id', true );
                if ( $form_id ) {
                    echo '<a href="' . get_edit_post_link( $form_id ) . '">' . get_the_title( $form_id ) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'status':
                $is_read = get_post_meta( $post_id, '_capex_entry_read', true );
                if ( $is_read ) {
                    echo '<span style="color:#8c8f94;">&#9654; ნანახია</span>';
                } else {
                    echo '<span style="color:#d63638; font-weight:700;">&#9679; ახალი</span>';
                }
                break;
        }
    }

    /**
     * Add CSS class to unread entry rows.
     */
    public function add_unread_row_class( $classes, $class, $post_id ) {
        if ( get_post_type( $post_id ) === 'capex_entry' ) {
            if ( ! get_post_meta( $post_id, '_capex_entry_read', true ) ) {
                $classes[] = 'capex-unread';
            }
        }
        return $classes;
    }

    /**
     * Inline styles for entry list table.
     */
    public function entry_list_styles() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-capex_entry' ) {
            return;
        }
        echo '<style>
            .capex-unread td { font-weight: 600 !important; }
            .capex-unread { background: #fef8ee !important; }
        </style>';
    }
}