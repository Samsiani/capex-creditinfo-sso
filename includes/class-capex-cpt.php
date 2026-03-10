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
            'title'         => 'მომხმარებელი / ID',
            'form_source'   => 'ფორმის წყარო',
            'status'        => 'სტატუსი',
            'date'          => 'შევსების დრო'
        );
        return $new_columns;
    }

    public function render_entry_columns( $column, $post_id ) {
        switch ( $column ) {
            case 'form_source':
                $form_id = get_post_meta( $post_id, '_capex_form_id', true );
                if ( $form_id ) {
                    echo '<a href="' . get_edit_post_link( $form_id ) . '">' . get_the_title( $form_id ) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'status':
                // მომავალში შეგვიძლია სტატუსების სისტემა დავამატოთ (New, Read, etc.)
                echo '<span class="dashicons dashicons-yes" style="color:green"></span> მიღებულია';
                break;
        }
    }
}