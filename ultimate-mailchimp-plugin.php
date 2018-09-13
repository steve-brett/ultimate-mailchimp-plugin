<?php
/**
 * Plugin Name:     Ultimate MailChimp Plugin
 * Plugin URI:      atomicsmash.co.uk
 * Description:     Sync to MailChimp like a pro
 * Author:          atomicsmash.co.uk
 * Author URI:      atomicsmash.co.uk
 * Text Domain:     testing
 * Domain Path:     /languages
 * Version:         0.0.1
 */

if (!defined('ABSPATH')) exit; //Exit if accessed directly

// If autoload exists... autoload it baby... (this is for plugin developemnt and sites not using composer to pull plugins)
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require __DIR__ . '/vendor/autoload.php';
}

require __DIR__ . '/lib/cli.php';

use \DrewM\MailChimp\MailChimp;
use \DrewM\MailChimp\Batch;
use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;

//ASTODO There needs to be a plugin version number saved to the database when activated, this will be useful for future plugin updates

class UltimateMailChimpPlugin {

    function __construct() {

        $ultimate_mailchimp_cli = new UltimateMailChimpPluginCLI;

        // Setup CLI commands
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            if ( defined( 'ULTIMATE_MAILCHIMP_API_KEY' ) && defined( 'ULTIMATE_MAILCHIMP_LIST_ID' ) ) {
                // WP_CLI::add_command( 'ultimate-mailchimp sync-marketing-permissions-fields', array( $ultimate_mailchimp_cli, 'sync_marketing_permission_fields' ) );
                WP_CLI::add_command( 'ultimate-mailchimp sync-users', array( $ultimate_mailchimp_cli, 'sync_users_with_mailchimp' ) );
                WP_CLI::add_command( 'ultimate-mailchimp show-batches', array( $ultimate_mailchimp_cli, 'get_batches' ) );
            }else{
                WP_CLI::add_command( 'ultimate-mailchimp please-setup-plugin', array( $ultimate_mailchimp_cli, 'setup_warning' ) );
            }
        }


        if ( defined( 'ULTIMATE_MAILCHIMP_API_KEY' ) && defined( 'ULTIMATE_MAILCHIMP_LIST_ID' ) ) {

            // add_action( 'user_register', array( $this, 'new_user_created' ), 10, 1 );

            //ASTODO there needs to be a check to make sure WooCommerce is available
            add_action( 'woocommerce_review_order_before_payment', array( $this, 'add_woocommerce_checkout_custom_fields' ) );
            add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'update_user_after_order' ), 10, 2 );

        }

    }

    public function connect_to_mailchimp(){
        if ( defined( 'ULTIMATE_MAILCHIMP_API_KEY' ) && defined( 'ULTIMATE_MAILCHIMP_LIST_ID' ) ) {
            $this->MailChimp = new \DrewM\MailChimp\MailChimp( ULTIMATE_MAILCHIMP_API_KEY );
        }else{
            WP_CLI::error( "Constants are not defined" );
        }
    }


    //ASTODO There is a duplicate in the cli
    //ASTODO This might be better as a setup step?
    public function update_communication_preference_options() {

        $this->connect_to_mailchimp();

        // Get the first member that exists, we only need one to find the marketing field information.
        $result = $this->MailChimp->get( "lists/" . ULTIMATE_MAILCHIMP_LIST_ID . "/members", [
           'count' => 1
        ]);

        if( $this->MailChimp->success() ) {

            if( count( $result['members'] ) > 0 ){

                if( count( $result['members'][0]['marketing_permissions'] ) > 0 ){

                    $fields = array();

                    foreach( $result['members'][0]['marketing_permissions'] as $key => $field ){

                        $fields[$key]['marketing_permission_id'] = $field['marketing_permission_id'];
                        $fields[$key]['text'] = $field['text'];

                    }

                    update_option( 'um_communication_permission_fields', $fields, 0 );

                    return $fields;

                }else{
                    // WP_CLI::line( "NO marketing permission fields found :(" );
                }


            }else{
                // WP_CLI::error( "NO marketing permission fields found :(" );
            }

        } else {
            // WP_CLI::error( "There was an issue connecting to MailChimp" );
        }

    }


    public function get_merge_fields( $user_id ){

        $merge_fields = array();

        if( isset( $_POST['billing_first_name'] ) ){
            $merge_fields['FNAME'] = sanitize_text_field( $_POST['billing_first_name'] );
        }else{
            $merge_fields['FNAME'] = "";
        }

        if( isset( $_POST['billing_last_name'] ) ){
            $merge_fields['LNAME'] = sanitize_text_field( $_POST['billing_last_name'] );
        }else{
            $merge_fields['LNAME'] = "";
        }

        // Get extra fields defined by the user
        $merge_fields = apply_filters( 'ul_mc_custom_merge_fields', $merge_fields, $user_id );

        return $merge_fields;

    }


    //ASTODO seperate this out so it's easier to use with out an order needed
    private function update_single_user( $order_id = 0, $user_status = 'subscribed', $marketing_preferences = array() ){

        $order = wc_get_order( $order_id );

        // Get the custumer ID
        $user_id = get_current_user_id();

        $date = new DateTime();

        $this->connect_to_mailchimp();

        // $user = get_userdata( $user_id );

        if( isset( $_POST['billing_email'] ) ){
            $billing_email = sanitize_email( $_POST['billing_email'] );
        }else{
            return false;
        }


        //ASTODO This should be in it's own method $this->log();
        if ( defined('ULTIMATE_MAILCHIMP_LOGGING') ) {
            // Create the logger
            $logger = new Logger( 'ultimate_mailchimp' );
            // ASTODO hash the filename by date
            $uploads_directory = wp_upload_dir();
            $logger->pushHandler(new StreamHandler( $uploads_directory['basedir'] .'/ultimate-mailchimp.log', Logger::DEBUG));
            $logger->info( '-------- Order placed --------' );
            $logger->info( 'Order ID: ' . $order_id );
            // $logger->info( 'Merge fields: ', $merge_fields );
            $logger->info( 'Timestamp: '. $date->getTimestamp() );

        }


        $subscriber_hash = $this->MailChimp->subscriberHash( $billing_email );

        // Setup default data
        $subscription_data = array(
           'email_address' => $billing_email,
           'status' => $user_status,
           'timestamp_opt' => (string)$date->getTimestamp()
        );



        if( $marketing_preferences != "" ){
            $subscription_data['marketing_permissions'] = $marketing_preferences;
        }

        // Get standard first and last name merge fields, then any extras
        $subscription_data['merge_fields'] = $this->get_merge_fields( $user_id );

        // Use PUT to insert or update a record
        $result = $this->MailChimp->put( "lists/" . ULTIMATE_MAILCHIMP_LIST_ID . "/members/$subscriber_hash", $subscription_data);

        //ASTODO get this into a $this->log file
        if ( defined('ULTIMATE_MAILCHIMP_LOGGING') ) {

            $logger->info( 'Updating this Email address: ' . $billing_email );
            // $logger->info( 'Updating to these Merge fields', $merge_fields );
            $logger->info( 'Updating to this status: ' . $user_status );

            if( $this->MailChimp->success() ) {
                $logger->info( 'Mailchimp sync SUCCESS' );
                $logger->info( 'Mailchimp response - status: '. $result['status'] );
                $logger->info( 'Mailchimp response - merge fields', $result['merge_fields'] );
                echo "success";
            } else {
                $logger->info( 'Mailchimp error: ' . $this->MailChimp->getLastError() );
                $logger->info( 'Mailchimp response: ' , $this->MailChimp->getLastResponse() );
                echo "error";

            }
        }


        if ( defined('ULTIMATE_MAILCHIMP_DEBUG') ) {
            die( 'ULTIMATE_MAILCHIMP_DEBUG mode is enabled! Mailchimp has been updated but your order has not gone through' );
        };

    }

    /**
     *
     * WooCommerce integration
     *
     */


    public function add_woocommerce_checkout_custom_fields( $checkout ) {

        //ASTODO add logic to detect if the user is current signed up to the newsletter

        if ( defined('ULTIMATE_MAILCHIMP_GDPR_FIELDS') && ULTIMATE_MAILCHIMP_GDPR_FIELDS == true ) {
            $permission_fields = get_option( 'um_communication_permission_fields' );

            if( $permission_fields == "" ){
                $permission_fields = $this->update_communication_preference_options();
            }
        }else{
            $permission_fields = "";
        }

        $newsletter_title = apply_filters( 'ul_mc_checkout_title', 'Marketing Permissions' );

        $checkbox_label = apply_filters( 'ul_mc_checkout_checkbox_label', 'Subscribe to the MailChimp newsletter' );

        $paragraph_one = apply_filters( 'ul_mc_checkout_paragraph_one', 'We use MailChimp as our marketing automation platform. By clicking below to submit this form, you acknowledge that the information you provide will be transferred to MailChimp for processing in accordance with their Privacy Policy and Terms. We will use the information you provide on this form to be in touch with you and to provide updates and marketing. Please let us know all the ways you would like to hear from us:' );

        $paragraph_two = apply_filters( 'ul_mc_checkout_paragraph_two', 'You can change your mind at any time by clicking the unsubscribe link in the footer of any email you receive from us, or by contacting us directly. We will treat your information with respect. For more information about our privacy practices please visit our website. By clicking below, you agree that we may process your information in accordance with these terms.' );

        echo '<div id="ultimate_mc_wc_signup"><h2>' . __( $newsletter_title ) . '</h2>';

            echo "<p>$paragraph_one</p>";

            // If markerting permission are set, show those fields
            if( $permission_fields == "" ){

                woocommerce_form_field( 'ultimate_mc_wc_checkbox', array(
                    'type'          => 'checkbox',
                    'class'         => array( 'input-checkbox' ),
                    'label'         => __( $checkbox_label ),
                    'required'  => false,
                ), 0);

            }else{

                foreach( $permission_fields as $permission_field ){

                    woocommerce_form_field( 'ultimate_mc_wc_checkbox__' . $permission_field['marketing_permission_id'], array(
                        'type'          => 'checkbox',
                        'class'         => array( 'input-checkbox' ),
                        'label'         => $permission_field['text'],
                        'required'  => false,
                    ), 0);

                }
            }

            echo "<p>$paragraph_two</p>";

        echo '</div>';

    }


    function update_user_after_order( $order_id, $data ) {

        $user_status = 'unsubscribed';  // options: subscribed - unsubscribed - cleaned - pending
        $permission_fields = "";

        if ( defined('ULTIMATE_MAILCHIMP_GDPR_FIELDS') && ULTIMATE_MAILCHIMP_GDPR_FIELDS == true ) {

            $permission_fields = get_option( 'um_communication_permission_fields' );

            if( count($permission_fields) > 0 ){
                foreach( $permission_fields as $key => $permission_field ){

                    if( isset( $_POST['ultimate_mc_wc_checkbox__' . $permission_field['marketing_permission_id']] ) ){
                        $permission_fields[$key]['enabled'] = true;

                        if ( defined('ULTIMATE_MAILCHIMP_DOUBLE_OPTIN') && ULTIMATE_MAILCHIMP_DOUBLE_OPTIN == false ) {
                            $user_status = 'subscribed';
                        }else{
                            $user_status = 'pending';
                        }

                    }else{
                        $permission_fields[$key]['enabled'] = false;
                    }
                }
            }

        }else{

            // If $_POST['ultimate_mc_wc_checkbox'] exists, it was ticked. There is on false value
            if ( ! empty( $_POST['ultimate_mc_wc_checkbox'] ) ) {

                if ( defined('ULTIMATE_MAILCHIMP_DOUBLE_OPTIN') && ULTIMATE_MAILCHIMP_DOUBLE_OPTIN == false ) {
                    $user_status = 'subscribed';
                }else{
                    $user_status = 'pending';
                }

            }

        }

        // Only send user to MailChimp if they are subscribed or pending
        // if( $user_status == 'subscribed' || $user_status == 'pending'){
        // }

        $this->update_single_user( $order_id, $user_status, $permission_fields); // options: subscribed - unsubscribed - cleaned - pending


        // die('-');


    }

    //ASTODO migrate this to human_time_diff https://codex.wordpress.org/Function_Reference/human_time_diff
    private function time_ago( $datetime, $full = false ){

        $now = new DateTime;
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = array(
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        );
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';

    }

}

$ultimate_mailchimp_plugin = new UltimateMailChimpPlugin;
