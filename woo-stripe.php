<?php
/**
 * Plugin Name: Hosted Checkout For WC Stripe
 * Description: Supports for stripe hosted checkout in Woocommerce
 * Author: Aquaninjas
 * Author URI: http://theaquarious.com/
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/*Define Plugin PATH & URL*/
if(!defined('WOO_STRIPE_HOSTED_PLUGIN_PATH'))
    define ('WOO_STRIPE_HOSTED_PLUGIN_PATH', plugin_dir_path(__FILE__));

if(!defined('WOO_STRIPE_HOSTED_PLUGIN_URL'))
    define ('WOO_STRIPE_HOSTED_PLUGIN_URL', plugin_dir_url(__FILE__));

class Woo_StripeHosted_Init 
{
    
    public function __construct()
    {
        $this->initPaymentGateways();
        add_filter('woocommerce_payment_gateways', [$this, 'loadPaymentGateways']);
    }
    
    protected function initPaymentGateways()
    {   
        include_once WOO_STRIPE_HOSTED_PLUGIN_PATH .'vendor/autoload.php';
        include_once WOO_STRIPE_HOSTED_PLUGIN_PATH.'inc'.DIRECTORY_SEPARATOR.'Stripe_Hosted.php';
        
    }
    
    public function loadPaymentGateways($methods)
    {
        $methods[] = 'WC_Gateway_Stripe_Hosted'; 
        return $methods;
    }        
    
}

if(!function_exists('woo_stripehosted_loadded'))
{
    function woo_stripehosted_loadded()
    {
        //fire off object
        new Woo_StripeHosted_Init();
    }
}    

add_action('plugins_loaded', 'woo_stripehosted_loadded', 100);