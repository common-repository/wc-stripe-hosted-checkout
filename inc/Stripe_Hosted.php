<?php

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Description of Stripe Hosted Checkout
 *
 * @author aquaninja
 */
class WC_Gateway_Stripe_Hosted extends WC_Payment_Gateway {

    /**
     * Whether or not logging is enabled
     *
     * @var bool
     */
    public static $log_enabled = false;

    /**
     * Logger instance
     *
     * @var WC_Logger
     */
    public static $log = false;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id = 'aqua_stripe_hosted';

        $this->has_fields = false;

        $this->order_button_text = __('Proceed to Payment', 'woocommerce');

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables.
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');

        $this->testmode = 'yes' === $this->get_option('testmode', 'no');
        $this->debug = 'yes' === $this->get_option('debug', 'no');

        $this->stripe_live_api_key = $this->get_option('stripe_live_api_key');
        $this->stripe_test_api_key = $this->get_option('stripe_test_api_key');

        $this->stripe_live_secret_key = $this->get_option('stripe_live_secret_key');
        $this->stripe_test_secret_key = $this->get_option('stripe_test_secret_key');

        $this->stripe_test_webhook_key = $this->get_option('stripe_test_webhook_key');
        $this->stripe_webhook_key = $this->get_option('stripe_webhook_key');

        $this->responseVal = '';
        $uploads = wp_upload_dir();
        $this->txn_log = $uploads['basedir'] . "/txn_log/payu";
        wp_mkdir_p($this->txn_log);

        if ($this->testmode) {
            /* translators: %s: Link to Payu sandbox testing guide page */
            //$this->description .= ' ' . sprintf(__('SANDBOX ENABLED. You can use sandbox testing accounts only. See the <a href="%s">Payumoney Sandbox Testing Guide</a> for more details.', 'woocommerce'), 'https://developer.payumoney.com/redirect/');
            $this->description = trim($this->description);
        }

        if (isset($_GET['aquaninja_callback']) && isset($_GET['results']) && esc_attr($_GET['aquaninja_callback']) == 1 && esc_attr($_GET['results']) != '') {
            $this->responseVal = $_GET['results'];
            add_filter('woocommerce_thankyou_order_received_text', array($this, 'thankyou_page'));
        }

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        //for IPN callback
        add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'validatePayment'));

        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
    }

    public function init_form_fields() {

        $this->form_fields = [
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Stripe Hosted checkout', 'woocommerce'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                'default' => __('Stripe Hosted Checkout', 'woocommerce'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Stripe Message', 'woocommerce'),
                'type' => 'textarea',
                'default' => 'Securely payment with Stripe'
            ),
            'stripe_live_api_key' => array(
                'title' => __('Production API Key', 'woocommerce'),
                'type' => 'password',
                'description' => __('Stripe Production API Key', 'woocommerce'),
                'desc_tip' => true,
            ),
            'stripe_live_secret_key' => array(
                'title' => __('Production Secret Key', 'woocommerce'),
                'type' => 'password',
                'description' => __('Stripe Production Secret Key', 'woocommerce'),
                'desc_tip' => true,
            ),
            'stripe_test_api_key' => array(
                'title' => __('Sandbox API Key', 'woocommerce'),
                'type' => 'password',
                'description' => __('Sandbox API Key for testing', 'woocommerce'),
                'desc_tip' => true,
            ),
            'stripe_test_secret_key' => array(
                'title' => __('Sandbox Secret Key', 'woocommerce'),
                'type' => 'password',
                'description' => __('Sandbox Secret Key', 'woocommerce'),
                'desc_tip' => true,
            ),
            'stripe_webhook_key' => array(
                'title' => __('Webhook Live Secret key', 'woocommerce'),
                'type' => 'text',
                'description' => __('Webhook LIve Secret key', 'woocommerce'),
                'desc_tip' => true,
            ),
            'stripe_test_webhook_key' => array(
                'title' => __('Webhook Test Secret key', 'woocommerce'),
                'type' => 'text',
                'description' => __('Webhook Test Secret key', 'woocommerce'),
                'desc_tip' => true,
            ),
            'testmode' => array(
                'title' => __('Stripe Hosted Checkout sandbox', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Stripe Checkout sandbox', 'woocommerce'),
                'default' => 'yes',
                /* translators: %s: URL */
                'description' => sprintf(__('Stripe Hosted Checkout sandbox can be used to test payments. Sign up for an <a href="%s">Account</a>.', 'woocommerce'), 'https://dashboard.stripe.com/register'),
            ),
            'debug' => array(
                'title' => __('Debug log', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable logging', 'woocommerce'),
                'default' => 'no'
            ),
        ];
    }

    /**
     * Process the payment and return the result.
     *
     * @param  int $order_id Order ID.
     * @return array
     */
    public function process_payment($order_id) {

        $order = wc_get_order($order_id);

        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }

    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        $stripe_session_id = isset($_GET['session_id']) ? $_GET['session_id'] : 0;

        if (!$stripe_session_id) {
            wc_add_notice('Error on payment: Stripe Checkout Session not found', 'error');
            wp_redirect($order->get_checkout_payment_url(false));
        }

        $paymentId = $this->validateStripeCheckout($stripe_session_id, $order_id);

        if (!$paymentId) {
            wc_add_notice('Error on payment: Stripe Checkout Session not vaild', 'error');
            wp_redirect($order->get_checkout_payment_url(false));
        }



        $order->payment_complete();
        $order->add_order_note("Stripe Payment ID : {$paymentId}");
    }

    /**
     * Receipt Page
     * */
    function receipt_page($order) {
        $this->woo_clear_cache();
        echo '<p>' . __('Thank you for your order, please click the button below to pay with Payumoney.', 'woocommerce') . '</p>';
        echo $this->create_session_and_redirection($order);
    }

    public function create_session_and_redirection($order_id) {
        global $woocommerce;

        $order = new WC_Order($order_id);
        $getLineItems = $this->getLineItems($order->get_id());

        $sessionData = [
            'payment_method_types' => ['card'],
            'client_reference_id' => $order_id,
            'customer_email' => $order->get_billing_email(),
            'line_items' => $getLineItems,
            'success_url' => add_query_arg('session_id', '{CHECKOUT_SESSION_ID}', $this->get_return_url($order)),
            'cancel_url' => wc_get_checkout_url(),
        ];


        //Create Session with Stripe
        $stripeKeys = $this->getStripeKeys();

        $apiKey = $stripeKeys['apiKey'];
        $secretKey = $stripeKeys['secretKey'];

        \Stripe\Stripe::setApiKey($secretKey);


        try {

            $session = \Stripe\Checkout\Session::create($sessionData);
        } catch (Exception $ex) {

            echo $ex->getMessage();
            exit;
        }
        $stripeButton = '<button type="button" id="aquaStripeRedirect"></button>';
        $initiate_script = '<script src="https://js.stripe.com/v3/"></script><script type="text/javascript">';
        $initiate_script .= "var stripe = Stripe('$apiKey');";
        $initiate_script .= 'jQuery(document).ready(function(){';
        $initiate_script .= 'stripe.redirectToCheckout({';
        $initiate_script .= "sessionId:'" . $session->id . "'";
        $initiate_script .= '}).then(function(result){';
        $initiate_script .= 'console.log(result)});';

        $initiate_script .= 'jQuery("body").block({'
                . 'message: "' . __('Thank you for your order. Redirecting you to Stripe to make payment. Please do not close or refresh the browser', 'woocommerce') . '",
									overlayCSS:
									{
										background: "#fff",
										opacity: 0.6
									},
									css: {
								        padding:        20,
								        textAlign:      "center",
								        color:          "#555",
								        border:         "3px solid #aaa",
								        backgroundColor:"#fff",
								        cursor:         "wait"'
                . '}})';
        $initiate_script .= '})';
        $initiate_script .= '</script>';

        return $initiate_script;
    }

    /**
     * Clear the cache data for browser 
     *
     * @since 1.0
     */
    private function woo_clear_cache() {
        header("Pragma: no-cache");
        header("Cache-Control: no-cache");
        header("Expires: 0");
    }

    private function getLineItems($order_id) {

        $order = new WC_Order($order_id);
        $lineItems = [];

        foreach ($order->get_items() as $item) {

            $eachItemPrice = $item->get_total() / $item->get_quantity();

            $data = [
                'name' => $item->get_name(),
                'description' => $item->get_name(),
                'amount' => $eachItemPrice * 100,
                'currency' => get_woocommerce_currency(),
                'quantity' => $item->get_quantity(),
            ];

            $thumbnailId = get_post_thumbnail_id($item->get_product_id());
            if ($thumbnailId) {
                $mainImg = wp_get_attachment_image_src($thumbnailId, 'full');
                $data['images'] = [$mainImg[0]];
            }

            $lineItems[] = $data;
        }
        if ($order->get_shipping_total() > 0) {
            $lineItems[] = [
                'name' => 'Shipping Cost',
                'description' => 'Shipping Cost',
                'amount' => $order->get_shipping_total() * 100,
                'currency' => get_woocommerce_currency(),
                'quantity' => 1,
            ];
        }
        return $lineItems;
    }

    public function validatePayment() {

        $stripeKeys = $this->getStripeKeys();
        \Stripe\Stripe::setApiKey($stripeKeys['secretKey']);

        $endpoint_secret = $stripeKeys['webhookSecret'];
        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $event = null;

        try {
            $event = \Stripe\Webhook::constructEvent(
                            $payload, $sig_header, $endpoint_secret
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            http_response_code(400);
            exit();
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            http_response_code(400);
            exit();
        }

        // Handle the checkout.session.completed event
        if ($event->type == 'checkout.session.completed') {
            $session = $event->data->object;

            // Fulfill the purchase...
            $order_id = $session->client_reference_id;
            $order = wc_get_order($order_id);
            $order->payment_complete();
            $order->add_order_note("Stripe Payment ID : {$session->payment_intent}");
        }

        http_response_code(200);
    }

    private function validateStripeCheckout($session_id, $order_id) {

        $stripeKeys = $this->getStripeKeys();

        \Stripe\Stripe::setApiKey($stripeKeys['secretKey']);

        //Retrive Session
        try {

            $session = \Stripe\Checkout\Session::retrieve($session_id);
            if ($session->client_reference_id == $order_id)
                return $session->payment_intent;
            else
                return false;
        } catch (Exception $ex) {
            return false;
        }
    }

    public function getStripeKeys() {

        $secretKey = ($this->testmode === true) ? $this->stripe_test_secret_key : $this->stripe_live_secret_key;
        $apiKey = ($this->testmode === true) ? $this->stripe_test_api_key : $this->stripe_live_api_key;
        $webhookSecret = ($this->testmode === true) ? $this->stripe_test_webhook_key : $this->stripe_webhook_key;

        return [
            'secretKey' => $secretKey,
            'apiKey' => $apiKey,
            'webhookSecret' => $webhookSecret
        ];
    }

}
