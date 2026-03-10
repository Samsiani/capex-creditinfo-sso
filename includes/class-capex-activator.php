<?php

/**
 * Fired during plugin activation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Capex_Activator {

    /**
     * პლაგინის აქტივაციისას გასაშვები კოდი
     */
    public static function activate() {
        // უსაფრთხო საქაღალდის შექმნა დოკუმენტებისთვის
        self::create_secure_upload_folder();
    }

    /**
     * ვქმნით საქაღალდეს wp-content/uploads/capex_secure_docs
     * და ვდებთ შიგნით .htaccess ფაილს, რომელიც კრძალავს პირდაპირ წვდომას.
     */
    private static function create_secure_upload_folder() {
        $upload = wp_upload_dir();
        $upload_dir = $upload['basedir'];
        $secure_folder = $upload_dir . '/capex_secure_docs';

        // თუ საქაღალდე არ არსებობს, ვქმნით
        if ( ! is_dir( $secure_folder ) ) {
            wp_mkdir_p( $secure_folder );
        }

        // ვქმნით .htaccess ფაილს 'Deny from all' ბრძანებით (Apache სერვერებისთვის)
        $htaccess_file = $secure_folder . '/.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            $rules = "Order Deny,Allow\nDeny from all";
            file_put_contents( $htaccess_file, $rules );
        }

        // ვქმნით ცარიელ index.php-ს დამატებითი დაცვისთვის (Directory Browsing-ის წინააღმდეგ)
        $index_file = $secure_folder . '/index.php';
        if ( ! file_exists( $index_file ) ) {
            file_put_contents( $index_file, '<?php // Silence is golden' );
        }
    }
}