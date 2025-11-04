<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles Stripe product creation and payment session generation
 * Requires Stripe PHP Library loaded via Composer
 */
class Plugin_Stripe_Processor {

    private $plugin_slug;
    private $stripe_mode;
    private $stripe_secret_key;
    private $stripe_publishable_key;

    public function __construct($plugin_slug) {
        $this->plugin_slug = $plugin_slug;
        
        $this->load_stripe_library();
        
        $this->initialize_stripe();
    }

    /**
     * Load Stripe PHP library from vendor directory
     */
    private function load_stripe_library() {
        $stripe_lib_path = MY_PLUGIN_PATH . 'vendor/stripe/stripe-php/init.php';
        
        if (file_exists($stripe_lib_path)) {
            require_once $stripe_lib_path;
        } else {
            error_log('Stripe PHP library not found at: ' . $stripe_lib_path);
        }
    }

    /**
     * Initialize Stripe with current mode settings
     */
    private function initialize_stripe() {
        $this->stripe_mode = get_option('stripe_mode', 'test');
        
        if ($this->stripe_mode === 'live') {
            $this->stripe_secret_key = get_option('stripe_live_secret_key');
            $this->stripe_publishable_key = get_option('stripe_live_publishable_key');
        } else {
            $this->stripe_secret_key = get_option('stripe_test_secret_key');
            $this->stripe_publishable_key = get_option('stripe_test_publishable_key');
        }

        if (!empty($this->stripe_secret_key) && class_exists('\Stripe\Stripe')) {
            \Stripe\Stripe::setApiKey($this->stripe_secret_key);
        } else {
            error_log('Stripe SDK not loaded or secret key missing. Cannot process payments.');
        }
    }

    /**
     * Get current Stripe mode
     */
    public function get_stripe_mode() {
        return $this->stripe_mode;
    }

    /**
     * Get publishable key for current mode
     */
    public function get_publishable_key() {
        return $this->stripe_publishable_key;
    }

    /**
     * Validate Stripe configuration
     */
    private function validate_stripe_config() {
        if (empty($this->stripe_secret_key)) {
            $mode_display = $this->stripe_mode === 'live' ? 'Live' : 'Test';
            return new WP_Error('stripe_config_error', "Stripe {$mode_display} Secret Key is not configured.");
        }

        if (empty($this->stripe_publishable_key)) {
            $mode_display = $this->stripe_mode === 'live' ? 'Live' : 'Test';
            return new WP_Error('stripe_config_error', "Stripe {$mode_display} Publishable Key is not configured.");
        }

        return true;
    }

    /**
     * Dynamically creates a Stripe Product, Price, and Checkout Session.
     * @return string|WP_Error The Stripe Session URL on success, WP_Error on failure.
     */
    public function create_checkout_session(string $company_name, int $amount_cents, string $currency, string $client_email, string $envelope_id) {
        
        $config_check = $this->validate_stripe_config();
        if (is_wp_error($config_check)) {
            return $config_check;
        }

        $validation_result = $this->validate_payment_data($amount_cents, $currency);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }
        $currency_for_stripe = $validation_result['currency_for_stripe'];
        
        $product_name = "IT Services Agreement - {$company_name}";
        
        try {
            $price_id = $this->create_stripe_product_and_price(
                $product_name,
                $company_name,
                $amount_cents,
                $currency_for_stripe,
                $envelope_id
            );
            
            if (is_wp_error($price_id)) {
                return $price_id;
            }

            $session_url = $this->create_stripe_checkout_session(
                $price_id,
                $company_name,
                $client_email,
                $envelope_id
            );
            
            return $session_url;

        } catch (\Exception $e) {
            return new WP_Error('stripe_api_error', 'Stripe payment link creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Validates and normalizes the amount and currency inputs.
     * @return array|WP_Error Array with normalized currency or WP_Error.
     */
    private function validate_payment_data(int $amount_cents, string $currency) {
        if ($amount_cents <= 0) {
            return new WP_Error('stripe_amount_error', 'Payment amount must be greater than zero. Calculated amount was: 0.');
        }

        $validated_currency = strtoupper(sanitize_text_field($currency));
        
        if (!preg_match('/^[A-Z]{3}$/', $validated_currency)) {
            return new WP_Error('stripe_currency_error', "Invalid currency code provided: {$currency}. Must be a 3-letter ISO code (e.g., USD).");
        }

        return [
            'currency_for_stripe' => strtolower($validated_currency),
            'validated_currency' => $validated_currency,
        ];
    }


    /**
     * Creates a new temporary Stripe Product and Price object.
     * @return string|WP_Error The Price ID on success, WP_Error on failure.
     */
    private function create_stripe_product_and_price(string $product_name, string $company_name, int $amount_cents, string $currency_for_stripe, string $envelope_id) {
        
        $product = \Stripe\Product::create([
            'name' => $product_name,
            'description' => "Services Agreement for {$company_name}",
            'metadata' => [
                'docusign_envelope_id' => $envelope_id,
                'company_name' => $company_name,
                'created_via' => 'docusign_integration',
                'stripe_mode' => $this->stripe_mode
            ]
        ]);

        $price = \Stripe\Price::create([
            'unit_amount' => $amount_cents, 
            'currency' => $currency_for_stripe,
            'product' => $product->id,
            'metadata' => [
                'company_name' => $company_name,
                'envelope_id' => $envelope_id,
                'stripe_mode' => $this->stripe_mode
            ]
        ]);

        return $price->id;
    }

    /**
     * Creates the Stripe Checkout Session URL.
     * @return string The Session URL on success.
     */
    private function create_stripe_checkout_session(string $price_id, string $company_name, string $client_email, string $envelope_id): string {
        
        $success_url = add_query_arg([
            'payment_status' => 'success',
            'envelope_id' => $envelope_id,
            'session_id' => '{CHECKOUT_SESSION_ID}'
        ], home_url('/payment-success/'));
        
        $cancel_url = add_query_arg([
            'payment_status' => 'cancelled', 
            'envelope_id' => $envelope_id
        ], home_url('/payment-cancelled/'));
        
        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $price_id,
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
            'customer_email' => $client_email,
            'metadata' => [
                'docusign_envelope_id' => $envelope_id,
                'company_name' => $company_name,
                'client_email' => $client_email,
                'stripe_mode' => $this->stripe_mode
            ],
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'required',
            'custom_text' => [
                'submit' => [
                    'message' => 'Thank you for your business! After payment, you will receive your service activation details via email.'
                ]
            ]
        ]);

        return $session->url;
    }
    
    /**
     * Verify payment status for a session
     */
    public function verify_payment_status($session_id) {
        try {
            $session = \Stripe\Checkout\Session::retrieve($session_id);

            if ($session->payment_status !== 'paid') {
                return new WP_Error('payment_not_paid', 'Payment was not successfully completed. Status: ' . $session->payment_status);
            }
            
            return [
                'status' => $session->payment_status,
                'amount_total' => $session->amount_total,
                'currency' => $session->currency,
                'customer_email' => $session->customer_email ?? ($session->customer_details->email ?? ''),
                'payment_intent' => $session->payment_intent,
                'stripe_mode' => $this->stripe_mode
            ];
        } catch (\Exception $e) {
            return new WP_Error('payment_verification_error', 'Could not verify payment status: ' . $e->getMessage());
        }
    }

    /**
     * Handles the client's return from the Stripe checkout page.
     * Verifies the payment status or handles critical errors.
     *
     * @return bool|null True on successful payment, FALSE on cancellation, or halts execution on error/redirect.
     */
    public function handle_payment_return() {
        $session_id = sanitize_text_field($_GET['session_id'] ?? '');
        $status = sanitize_text_field($_GET['payment_status'] ?? '');
        $envelope_id = sanitize_text_field($_GET['envelope_id'] ?? '');

        if ($status === 'success' && !empty($session_id)) {
            
            $verification_result = $this->verify_payment_status($session_id);

            if (is_wp_error($verification_result)) {
                $error_message = $verification_result->get_error_message();
                
                $error_url = add_query_arg([
                    'error_code' => 'verification_failed',
                    'message' => urlencode($error_message),
                    'envelope_id' => $envelope_id
                ], home_url('/payment-cancelled/')); 
                
                wp_safe_redirect($error_url);
                exit; 
            }
            
            return true; 

        } 
        else if ($status === 'cancelled') {
            
            return false; 
            
        } 
        else {
            
            wp_die(
                '<h1>Payment Error</h1>' .
                '<p>Missing essential payment data (Session ID or Status). Please contact support.</p>' .
                '<p><a href="' . esc_url(home_url('/')) . '">Return to Homepage</a></p>'
            );
        }
    }

    /**
     * Create payment session and return URL for immediate redirect
     */
    public function create_immediate_payment_redirect($company_name, $amount, $currency, $client_email, $envelope_id) {
        $amount_cents = intval($amount * 100);
        
        $session_url = $this->create_checkout_session(
            $company_name, 
            $amount_cents, 
            $currency, 
            $client_email, 
            $envelope_id
        );
        
        if (is_wp_error($session_url)) {
            return $session_url;
        }
        
        return $session_url;
    }

    /**
     * Test Stripe connection
     */
    public function test_connection() {
        try {
            $balance = \Stripe\Balance::retrieve();
            return [
                'success' => true,
                'mode' => $this->stripe_mode,
                'message' => 'Stripe connection successful in ' . $this->stripe_mode . ' mode'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'mode' => $this->stripe_mode,
                'message' => 'Stripe connection failed: ' . $e->getMessage()
            ];
        }
    }
}