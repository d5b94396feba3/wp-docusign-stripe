<?php
defined( 'ABSPATH' ) || exit;
/**
 * Handles automatic sending of a DocuSign contract using the JWT Grant flow.
 * Requires PHP's 'openssl' extension for cryptographic signing.
 */
class Plugin_DocuSign_Contract {

    private $plugin_slug;
    private $stripe_processor;

    public function __construct($plugin_slug) {
        $this->plugin_slug = $plugin_slug;
        $this->stripe_processor = new Plugin_Stripe_Processor($this->plugin_slug);
    }

    /**
     * Validates that all required DocuSign configuration constants are set and non-empty.
     * @return true|WP_Error Returns true if all configuration is valid, or WP_Error otherwise.
     */
    public function validate_docusign_config() {
        $required_settings = [
            'DS_INTEGRATION_KEY'    => 'DocuSign Integrator Key',
            'DS_USER_ID'            => 'DocuSign User ID (Impersonation User)',
            'DS_AUTH_SERVER'        => 'DocuSign Auth Server (Account Server)',
            'DS_GATEWAY_ACCOUNT_ID' => 'DocuSign Payment Gateway Account ID',
            'DOCUSIGN_ADMIN_EMAIL'  => 'DocuSign Admin Email (Sender/Recipient)',
            'DOCUSIGN_ADMIN_NAME'   => 'DocuSign Admin Name (Sender/Recipient)',
        ];

        $missing_settings = [];
        foreach ($required_settings as $const_name => $setting_name) {
            // Check if the constant exists and is not empty
            if (!defined($const_name) || empty(constant($const_name))) {
                $missing_settings[] = $setting_name;
            }
        }

        if (!empty($missing_settings)) {
            $error_message = "DocuSign Configuration Error: The following required settings are missing or empty: " . implode(', ', $missing_settings) . ". Please check your plugin settings.";
            return new WP_Error('docusign_config_missing', $error_message);
        }

        return true;
    }

    /**
     * Handle DocuSign completion and redirect to Stripe
     */
    public function handle_docusign_completion() {
        
        $envelope_id = $_GET['envelope_id'] ?? $_GET['envelopeId'] ?? $_GET['source_envelope_id'] ?? '';
        
        if (empty($envelope_id)) {
            
            wp_die('No envelope ID provided. Please contact support.');
            return;
        }
        
        $envelope_id = sanitize_text_field($envelope_id);
        
        $envelope_data = $this->get_envelope_data($envelope_id);
        
        if (!$envelope_data) {
            
            $company_name = urldecode($_GET['company'] ?? '');
            $amount = floatval($_GET['amount'] ?? 0);
            $currency = sanitize_text_field($_GET['currency'] ?? 'USD');
            $client_email = sanitize_email($_GET['client_email'] ?? '');
            
            if (empty($company_name) || $amount <= 0) {
                
                wp_die('Contract data not found. Please contact support.');
                return;
            }
            
            $envelope_data = [
                'company_name' => $company_name,
                'amount' => $amount,
                'currency' => $currency,
                'client_email' => $client_email
            ];
        }
        
        $payment_url =  $this->stripe_processor->create_immediate_payment_redirect(
            $envelope_data['company_name'],
            $envelope_data['amount'],
            $envelope_data['currency'],
            $envelope_data['client_email'],
            $envelope_id
        );

        if (!is_wp_error($payment_url)) {
            
            wp_redirect($payment_url);
            exit;
        } else {
            
            wp_die(
                '<h1>Payment Setup Error</h1>' .
                '<p>Unable to create payment session. Please contact support.</p>' .
                '<p><strong>Error:</strong> ' . esc_html($payment_url->get_error_message()) . '</p>' .
                '<p><a href="' . home_url() . '">Return to Homepage</a></p>'
            );
        }
    }


    /**
     * Helper function to URL-safe Base64 encode data (no padding).
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Generates a cryptographically signed JWT for the DocuSign API.
     */
    private function generate_signed_jwt() {
        if (!extension_loaded('openssl')) {
            return new WP_Error('jwt_error', 'OpenSSL extension is required for cryptographic signing (JWT).');
        }
        
        $header = [
            'typ' => 'JWT',
            'alg' => 'RS256'
        ];
        $header_encoded = $this->base64url_encode(json_encode($header));

        $now = time();
        $claims = [
            'iss' => DS_INTEGRATION_KEY,
            'sub' => DS_USER_ID,
            'iat' => $now,
            'exp' => $now + 3600,
            'aud' => str_replace('https://', '', DS_AUTH_SERVER),
            'scope' => 'signature impersonation'
        ];
        $claims_encoded = $this->base64url_encode(json_encode($claims));

        $signature_input = "{$header_encoded}.{$claims_encoded}";

        $admin_instance = new Plugin_Admin($this->plugin_slug);
        $private_key = $admin_instance->get_docusign_private_key();
                
        if (is_wp_error($private_key)) {
            
            return $private_key;
        }

        $success = openssl_sign($signature_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
        if (!$success) {
            return new WP_Error('jwt_error', 'Failed to cryptographically sign the JWT payload.');
        }
        
        $signature_encoded = $this->base64url_encode($signature);

        return "{$signature_input}.{$signature_encoded}";
    }

    /**
     * Authenticates with DocuSign using the JWT Grant flow to get dynamic credentials,
     * utilizing WordPress Transients for token caching.
     * @return array|WP_Error The array of authentication details or a WP_Error object.
     */
    private function get_docusign_auth_details() {
        $cache_key = 'docusign_auth_' . md5(DS_USER_ID);
        
        // 1. Attempt to retrieve cached credentials
        if (false !== ($cached_auth = get_transient($cache_key))) {
            return $cached_auth;
        }

        // 2. Fetch the Access Token using the JWT
        $token_result = $this->fetch_token_from_jwt();
        if (is_wp_error($token_result)) {
            // Check for and handle the consent error directly
            if ($token_result->get_error_code() === 'docusign_consent_required') {
                return $token_result;
            }
            return new WP_Error('docusign_token_error', 'Token retrieval failed: ' . $token_result->get_error_message());
        }
        $access_token = $token_result['access_token'];

        // 3. Fetch user account info
        $auth_details = $this->fetch_user_info($access_token);
        if (is_wp_error($auth_details)) {
            return $auth_details;
        }

        // 4. Cache the results for 50 minutes (3000 seconds)
        set_transient($cache_key, $auth_details, 3000);

        return $auth_details;
    }


    /**
     * Executes the JWT Grant flow API call to retrieve the access token.
     * @return array|WP_Error The token result array or a WP_Error object.
     */
    private function fetch_token_from_jwt() {
        $signed_jwt = $this->generate_signed_jwt();
        if (is_wp_error($signed_jwt)) {
            return $signed_jwt;
        }

        $token_url = DS_AUTH_SERVER . '/oauth/token';
        $token_body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $signed_jwt
        ]);

        $token_response = wp_remote_post($token_url, [
            'method'    => 'POST',
            'headers'   => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body'      => $token_body,
            'timeout'   => 15,
        ]);

        if (is_wp_error($token_response)) {
            return new WP_Error('docusign_token_api_fail', $token_response->get_error_message());
        }

        $token_body = wp_remote_retrieve_body($token_response);
        $token_result = json_decode($token_body, true);

        if (!isset($token_result['access_token'])) {
            $docu_error = $token_result['error'] ?? 'Unknown Error';
            $docu_desc = $token_result['error_description'] ?? 'No description provided.';
            
            // Check specifically for the 'consent required' error
            if ($docu_error === 'consent_required' || $docu_error === 'invalid_grant') {
                
                // Construct the DocuSign consent URL
                $redirect_uri = admin_url();
                $consent_url = DS_AUTH_SERVER . '/oauth/auth?' . http_build_query([
                    'response_type' => 'code',
                    'scope'         => 'signature impersonation',
                    'client_id'     => DS_INTEGRATION_KEY,
                    'redirect_uri'  => $redirect_uri,
                ]);

                $consent_message = "JWT Authorization Failed. The DocuSign Admin user must grant consent to this application.";
                $consent_message .= "<br>Please visit the following URL once to grant permission: ";
                $consent_message .= "<br><a href='" . esc_url($consent_url) . "' target='_blank'>Grant DocuSign Consent</a>";
                
                return new WP_Error('docusign_consent_required', $consent_message);
            }
            
            $error_message = "Error: {$docu_error} | Description: {$docu_desc}";
            return new WP_Error('docusign_token_fail', 'Access Token retrieval failed: ' . $error_message);
        }

        return $token_result;
    }

    /**
     * Fetches user account information (base_path and account_id) using the access token.
     * @param string $access_token The valid DocuSign access token.
     * @return array|WP_Error The authentication details array or a WP_Error object.
     */
    private function fetch_user_info(string $access_token) {
        $userinfo_url = DS_AUTH_SERVER . '/oauth/userinfo';
        $userinfo_response = wp_remote_get($userinfo_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($userinfo_response)) {
            return new WP_Error('docusign_userinfo_error', 'Failed to connect to DocuSign UserInfo endpoint: ' . $userinfo_response->get_error_message());
        }
        $userinfo_body = wp_remote_retrieve_body($userinfo_response);
        
        $userinfo_result = json_decode($userinfo_body, true);

        if (empty($userinfo_result['accounts'])) {
            return new WP_Error('docusign_userinfo_error', 'Failed to retrieve Docusign account details.');
        }

        // Iterate to find the default account for the JWT user
        $selected_account = null;
        foreach ($userinfo_result['accounts'] as $account) {
            if (isset($account['is_default']) && $account['is_default']) {
                $selected_account = $account;
                break;
            }
        }
        
        // Fallback: If no account is explicitly marked as default, use the first one.
        if (is_null($selected_account)) {
            $selected_account = $userinfo_result['accounts'][0];
        }

        $base_path = $selected_account['base_uri'] . '/restapi';
        $account_id = $selected_account['account_id'];
        
        return [
            'access_token' => $access_token,
            'base_path' => $base_path,
            'account_id' => $account_id
        ];
    }


    /**
     * Store envelope data temporarily for payment flow
     */
    private function store_envelope_data($envelope_id, $data) {
        set_transient('docusign_envelope_' . $envelope_id, $data, 24 * HOUR_IN_SECONDS);
        
    }

    /**
     * Retrieve envelope data
     */
    public function get_envelope_data($envelope_id) {
        $data = get_transient('docusign_envelope_' . $envelope_id);
        
        return $data;
    }

    public function generate_eSignature(array $data, int $payment_amount, string $html_contract): array {
        
        // 0. CHECK CONFIGURATION FIRST
        $config_check = $this->validate_docusign_config();
        if (is_wp_error($config_check)) {
            return [
                'success' => false,
                'message' => $config_check->get_error_message(),
                'signing_link' => null,
                'stripe_redirect_url' => null,
                'envelope_id' => 'N/A'
            ];
        }
        
        // 1. Prepare and Sanitize Variables
        $vars = $this->prepare_signing_vars($data, $payment_amount);

        // 2. Get Authentication Details
        $ds_auth = $this->get_docusign_auth_details();
        if (is_wp_error($ds_auth)) {
            return [
                'success' => false,
                'message' => "FATAL ERROR: DocuSign authentication failed. " . $ds_auth->get_error_message(),
                'signing_link' => null,
                'stripe_redirect_url' => null,
                'envelope_id' => 'N/A'
            ];
        }

        try {
            // 3. Send the Envelope to DocuSign
            $envelope_id = $this->send_envelope_to_docusign($ds_auth, $vars, $html_contract);
            if (is_wp_error($envelope_id)) {
                $error_data = $envelope_id->get_error_data(); 
                return [
                    'success' => false,
                    'message' => $envelope_id->get_error_message(),
                    'signing_link' => null,
                    'stripe_redirect_url' => null,
                    'envelope_id' => $error_data['envelope_id'] ?? 'N/A'
                ];
            }

            // 4. Store Data for Completion Hook
            $this->store_envelope_data($envelope_id, $vars['transient_data']);

            // 5. Generate the payemnt Link
            $stripe_redirect_url = admin_url('admin-ajax.php?action=docusign_complete&envelope_id=' . $envelope_id);

            // 6. Generate the Embedded Signing Link
            $signing_link = $this->generate_embedded_signing_link($ds_auth, $envelope_id, $vars, $stripe_redirect_url);
            
            if (is_wp_error($signing_link)) {
                return [
                    'success' => false,
                    'message' => $signing_link->get_error_message(),
                    'signing_link' => null,
                    'stripe_redirect_url' => null,
                    'envelope_id' => $envelope_id
                ];
            }

            return [
                'success' => true,
                'message' => "The retainer agreement has been successfully sent to {$vars['client_email']} via DocuSign.",
                'signing_link' => $signing_link,
                'envelope_id' => $envelope_id,
                'stripe_redirect_url' => $stripe_redirect_url
            ];

        } catch (\Exception $e) {
            $error_msg = $e->getMessage();
            error_log('DocuSign Process Exception: ' . $error_msg);
            return [
                'success' => false,
                'message' => "WARNING: A critical error occurred during contract sending: " . $error_msg,
                'signing_link' => null,
                'envelope_id' => 'N/A'
            ];
        }
    }

    /**
     * Prepares and sanitizes all necessary variables for the DocuSign envelope.
     */
    private function prepare_signing_vars(array $data, int $payment_amount): array {
        $vars = [];
        $vars['client_name'] = sanitize_text_field($data['contact-name']) ?? 'Client Contact';
        $vars['company_name'] = sanitize_text_field($data['company-name']) ?? 'Client Company';
        $vars['client_email'] = sanitize_email($data['contact-email']);
        $vars['client_recipient_id'] = "1";
        $vars['admin_email'] = DOCUSIGN_ADMIN_EMAIL;
        $vars['admin_name'] = DOCUSIGN_ADMIN_NAME;
        $vars['payment_currency'] = sanitize_text_field($data['payment_currency'] ?? 'USD');
        $vars['payment_amount'] = $payment_amount;

        $vars['transient_data'] = [
            'company_name' => $vars['company_name'],
            'amount' => $vars['payment_amount'],
            'currency' => $vars['payment_currency'],
            'client_email' => $vars['client_email'],
            'client_name' => $vars['client_name']
        ];
        

        return $vars;
    }

    /**
     * Defines and sends the envelope via the DocuSign API.
     * @return string|WP_Error Envelope ID on success, WP_Error on failure.
     */
    private function send_envelope_to_docusign(array $ds_auth, array $vars, string $html_contract) {
        if (isset($vars['error'])) {
            return new WP_Error('config_error', $vars['error']);
        }

        $base64_contract = base64_encode($html_contract);
        $ds_url = "{$ds_auth['base_path']}/v2.1/accounts/{$ds_auth['account_id']}/envelopes";
        
        $envelope_definition = $this->build_envelope_definition($vars, $base64_contract); 
        
        $ds_response = wp_remote_post($ds_url, [
            'method'    => 'POST',
            'headers'   => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $ds_auth['access_token'],
            ],
            'body'      => json_encode($envelope_definition),
            'timeout'   => 190, 
        ]);

        if (is_wp_error($ds_response)) {
            $error_msg = $ds_response->get_error_message();
            return new WP_Error('api_connect_error', "WARNING: Contract failed to connect to DocuSign. Error: " . $error_msg);
        } 
        
        $ds_body = wp_remote_retrieve_body($ds_response);
        $ds_result = json_decode($ds_body, true);

        $envelope_id = $ds_result['envelopeId'] ?? 'N/A';
        
        if (isset($ds_result['errorCode']) || $envelope_id === 'N/A') {
            $error_message = $ds_result['message'] ?? 'DocuSign API did not return an Envelope ID.';
            error_log('DocuSign API Error: ' . ($ds_result['errorCode'] ?? $error_message) . ' | Full response: ' . $ds_body);
            return new WP_Error('envelope_send_error', 
                "WARNING: Contract failed to send to DocuSign. Error: " . ($ds_result['errorCode'] ?? $error_message),
                ['envelope_id' => $envelope_id] // Pass envelope_id in data for error handling
            );
        }
        
        error_log("DocuSign Envelope Sent successfully. ID: {$envelope_id}");
        return $envelope_id;
    }

    /**
     * Helper to separate the large envelope array definition.
     * @param array $vars Sanitized and prepared signing variables.
     * @param string $base64_contract Base64 encoded contract HTML content.
     * @return array The complete DocuSign envelope definition array.
     */
    private function build_envelope_definition(array $vars, string $base64_contract): array {
        
        $client_email_body = "Your contract is ready! Please complete the required signature field, then click Finish to finalize the agreement and proceed immediately to the secure payment page. Contact us if you have any questions.";
        $admin_email_body = "Admin copy: Please review and sign the agreement for {$vars['company_name']}.";
        
        return [
            "emailSubject" => "Services Agreement for {$vars['company_name']}",
            "status" => "sent",
            "documents" => [
                [
                    "documentBase64" => $base64_contract,
                    "name" => "Agreement ({$vars['company_name']})",
                    "documentId" => "1",
                    "fileExtension" => "html"
                ]
            ],
            "recipients" => [
                "signers" => [
                    // Client Recipient (Recipient ID 1, Routing Order 1)
                    [ 
                        "email" => $vars['client_email'],
                        "name" => $vars['client_name'],
                        "recipientId" => "1",
                        "routingOrder" => "1", // Client signs first
                        "deliveryMethod" => "email",
                        "clientUserId" => $vars['client_recipient_id'],
                        "accessCode" => "",
                        "idCheckConfigurationName" => null,
                        "requireIdCheck" => "false",
                        "emailNotification" => [
                            "emailSubject" => "ACTION REQUIRED: Please Review and Sign Your {$vars['company_name']} Agreement",
                            "emailBody" => $client_email_body,
                            "supportedLanguage" => "en",
                        ], 
                        "tabs" => [
                            "signHereTabs" => [
                                [
                                    "anchorString" => "/s2/", // Client's Anchor
                                    "documentId" => "1", 
                                    "required" => true,
                                ]
                            ],
                            "dateSignedTabs" => [
                                [
                                    "anchorString" => "/d2/", // Client's Anchor
                                    "documentId" => "1", 
                                    "required" => true,
                                ]
                            ],
                        ]
                    ],
                    // Admin Recipient (Recipient ID 2, Routing Order 2)
                    [
                        "email" => $vars['admin_email'],
                        "name" => $vars['admin_name'],
                        "recipientId" => "2",
                        "routingOrder" => "2", // Admin signs second
                        "deliveryMethod" => "email",
                        "emailNotification" => [
                            "emailSubject" => "Envelope Sent: {$vars['company_name']} Agreement (Admin Copy)",
                            "supportedLanguage" => "en",
                            "emailBody" => $admin_email_body,
                        ],
                        "tabs" => [
                            "signHereTabs" => [
                                [
                                    "anchorString" => "/s1/", // Admin's Anchor
                                    "documentId" => "1", 
                                    "required" => true
                                ]
                            ],
                            "dateSignedTabs" => [
                                [
                                    "anchorString" => "/d1/", // Admin's Anchor
                                    "documentId" => "1", 
                                    "required" => true
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Generates the one-time embedded signing URL for the client.
     * @return string|WP_Error The signing URL on success, WP_Error on failure.
     */
    private function generate_embedded_signing_link(array $ds_auth, string $envelope_id, array $vars, string $return_url) {
        $view_url = "{$ds_auth['base_path']}/v2.1/accounts/{$ds_auth['account_id']}/envelopes/{$envelope_id}/views/recipient";

        $view_body_data = [
            'authenticationMethod' => 'none', 
            'clientUserId' => $vars['client_recipient_id'],
            'email' => $vars['client_email'], 
            'userName' => $vars['client_name'],
            'returnUrl' => $return_url // Stripe redirect
        ];

        $view_response = wp_remote_post($view_url, [
            'method'    => 'POST',
            'headers'   => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $ds_auth['access_token'],
            ],
            'body'      => json_encode($view_body_data),
            'timeout'   => 190,
        ]);

        $response_code = wp_remote_retrieve_response_code($view_response);
        
        if (!is_wp_error($view_response) && $response_code === 201) {
            $view_result = json_decode(wp_remote_retrieve_body($view_response), true);
            $signing_link = $view_result['url'] ?? null;

            if ($signing_link) {
                return $signing_link;
            } else {
                return new WP_Error('link_gen_fail', 'Failed to generate embedded signing link.');
            }
        } else {
            $error_body = wp_remote_retrieve_body($view_response);
            error_log("DocuSign Recipient View API failed. Code: {$response_code}. Body: {$error_body}");
            return new WP_Error('recipient_view_fail', "DocuSign Recipient View API failed. Please check logs for details.");
        }
    }

    /**
     * Get envelope status from DocuSign
     */
    public function get_envelope_status($envelope_id) {
        $ds_auth = $this->get_docusign_auth_details();
        
        if (is_wp_error($ds_auth)) {
            return $ds_auth;
        }

        try {
            $status_url = "{$ds_auth['base_path']}/v2.1/accounts/{$ds_auth['account_id']}/envelopes/{$envelope_id}";
            
            $status_response = wp_remote_get($status_url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $ds_auth['access_token'],
                ],
                'timeout' => 15,
            ]);

            if (is_wp_error($status_response)) {
                return $status_response;
            }

            $status_body = wp_remote_retrieve_body($status_response);
            $status_result = json_decode($status_body, true);

            return [
                'status' => $status_result['status'] ?? 'unknown',
                'created_date' => $status_result['createdDateTime'] ?? '',
                'sent_date' => $status_result['sentDateTime'] ?? '',
                'completed_date' => $status_result['completedDateTime'] ?? ''
            ];

        } catch (\Exception $e) {
            
            return new WP_Error('status_check_error', 'Failed to check envelope status: ' . $e->getMessage());
        }
    }
}
