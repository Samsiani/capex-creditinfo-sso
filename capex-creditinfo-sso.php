<?php
/**
 * Plugin Name: Capex CreditInfo SSO & Loan Manager
 * Plugin URI:  https://capexcredit.ge
 * Description: ფორმების კონსტრუქტორი (Form Builder), განაცხადების მართვა და MyCreditinfo ინტეგრაცია.
 * Version:     2.6.1
 * Author:      Capex Dev Team
 * Text Domain: capex-sso
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; 
}

// კონსტანტები
define( 'CAPEX_VERSION', '2.6.1' );
define( 'CAPEX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CAPEX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// კლასების ჩატვირთვა
require_once CAPEX_PLUGIN_DIR . 'includes/class-capex-activator.php';
require_once CAPEX_PLUGIN_DIR . 'includes/class-capex-cpt.php'; // ახალი ფაილი CPT-ებისთვის
require_once CAPEX_PLUGIN_DIR . 'includes/class-capex-sso.php';
require_once CAPEX_PLUGIN_DIR . 'includes/class-capex-updater.php';
require_once CAPEX_PLUGIN_DIR . 'admin/class-capex-admin.php';
require_once CAPEX_PLUGIN_DIR . 'public/class-capex-public.php';

// Auto-update from GitHub releases.
new Capex_Updater( __FILE__, 'Samsiani', 'capex-creditinfo-sso', CAPEX_VERSION );

/**
 * პლაგინის მთავარი კლასი
 */
class Capex_CreditInfo_Core {

    public function run() {
        
        // CPT (Post Types) ინიციალიზაცია
        $cpt = new Capex_CPT();
        $cpt->init();

        // ადმინ პანელი
        if ( is_admin() ) {
            $admin = new Capex_Admin();
            $admin->init();
        }

        // ფრონტენდი
        $public = new Capex_Public();
        $public->init();
    }
}

/**
 * აქტივაციის ჰუკი
 */
function activate_capex_plugin() {
    Capex_Activator::activate();
    
    // CPT-ების რეგისტრაცია აქტივაციისას, რომ rewrite rules განახლდეს
    require_once CAPEX_PLUGIN_DIR . 'includes/class-capex-cpt.php';
    $cpt = new Capex_CPT();
    $cpt->register_forms_cpt();
    $cpt->register_entries_cpt();
    
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'activate_capex_plugin' );

/**
 * გაშვება
 */
function run_capex_plugin() {
    $plugin = new Capex_CreditInfo_Core();
    $plugin->run();
}
run_capex_plugin();