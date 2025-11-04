# WordPress DocuSign & Stripe Integration Plugin

A WordPress plugin that seamlessly integrates DocuSign electronic signatures with Stripe payment processing. This plugin automates the contract signing and payment workflow, allowing clients to sign agreements via DocuSign and immediately proceed to secure payment processing through Stripe.

## Built With

This plugin is built using the **[WP Plugin Boilerplate](https://github.com/d5b94396feba3/WP-Plugin-Boilerplate)** - a modern, object-oriented WordPress plugin boilerplate following WordPress coding standards. The boilerplate provides a clean, maintainable structure with separation of concerns, proper security measures, and WordPress best practices.

### Stripe SDK

This plugin uses the official **Stripe PHP SDK** for payment processing. The Stripe SDK is loaded via Composer and provides secure, reliable access to Stripe's payment processing APIs. The SDK handles all Stripe API communications, including:
- Product and Price creation
- Checkout Session management
- Payment verification
- Error handling and API response parsing

## Overview

This plugin provides a complete solution for businesses that need to:
- Send contracts electronically via DocuSign
- Collect signatures from clients and administrators
- Process payments immediately after contract signing
- Track envelope status and payment verification

## Features

### DocuSign Integration
- **JWT Authentication**: Secure authentication using JWT Grant flow with RSA-256 signing
- **Embedded Signing**: Generate one-time embedded signing URLs for clients
- **Multi-Recipient Signing**: Support for client and admin signatures with routing order
- **Envelope Management**: Create, send, and track DocuSign envelopes
- **Status Tracking**: Check envelope status and completion dates
- **Token Caching**: Efficient credential caching using WordPress transients (50-minute cache)

### Stripe Integration
- **Dynamic Product Creation**: Automatically creates Stripe products and prices for each contract
- **Checkout Sessions**: Secure payment processing via Stripe Checkout
- **Payment Verification**: Verify payment status and handle payment returns
- **Test/Live Mode Support**: Switch between test and live Stripe modes
- **Metadata Tracking**: Links Stripe payments to DocuSign envelopes via metadata

### Workflow Automation
- **Seamless Redirect**: Automatic redirect from DocuSign completion to Stripe payment
- **Data Persistence**: Temporary storage of envelope data during payment flow
- **Error Handling**: Comprehensive error handling with user-friendly messages
- **Configuration Validation**: Validates all required settings before processing

## Requirements

### PHP Requirements
- PHP 7.4 or higher
- OpenSSL extension (required for JWT signing)
- WordPress 5.0 or higher

### WordPress Requirements
- WordPress 5.0+
- Active WordPress installation with admin access

### External Services
- DocuSign Developer Account
- Stripe Account (Test and/or Live keys)

### Dependencies
- **Stripe PHP SDK**: Official Stripe PHP library for payment processing
  - Installed via Composer: `composer require stripe/stripe-php`
  - Minimum version: 7.0.0 (recommended: latest stable version)
  - Documentation: [Stripe PHP SDK Documentation](https://stripe.com/docs/api/php)

## Installation

1. **Upload Plugin Files**
   - Upload the plugin files to `/wp-content/plugins/wp-docusign-stripe/`

2. **Install Dependencies**
   ```bash
   composer install
   ```
   This will install the Stripe PHP SDK (`stripe/stripe-php`) in the `vendor/` directory. The Stripe SDK is required for all payment processing functionality.
   
   **Note**: If you don't have a `composer.json` file yet, create one and add:
   ```json
   {
       "require": {
           "stripe/stripe-php": "^7.0"
       }
   }
   ```

3. **Activate Plugin**
   - Navigate to WordPress Admin → Plugins
   - Activate "WordPress DocuSign & Stripe Integration"

4. **Configure Plugin Settings**
   - Go to plugin settings page
   - Configure DocuSign credentials
   - Configure Stripe API keys

## Configuration

### DocuSign Configuration

The following constants must be defined in your `wp-config.php` or plugin settings:

```php
// DocuSign Integration Key (from DocuSign Developer Console)
define('DS_INTEGRATION_KEY', 'your-integration-key');

// DocuSign User ID (Impersonation User - the user to impersonate)
define('DS_USER_ID', 'your-user-id');

// DocuSign Auth Server (Account Server)
// For demo: https://account-d.docusign.com
// For production: https://account.docusign.com
define('DS_AUTH_SERVER', 'https://account-d.docusign.com');

// DocuSign Payment Gateway Account ID (optional, if using DocuSign payment gateway)
define('DS_GATEWAY_ACCOUNT_ID', 'your-gateway-account-id');

// Admin Email (for signing and receiving notifications)
define('DOCUSIGN_ADMIN_EMAIL', 'admin@example.com');

// Admin Name (for signing)
define('DOCUSIGN_ADMIN_NAME', 'Admin Name');
```

### DocuSign Private Key

The plugin requires a private key for JWT signing. This should be stored securely and retrieved via the `Plugin_Admin` class `get_docusign_private_key()` method.

### Stripe Configuration

Configure Stripe settings via WordPress options:

```php
// Stripe Mode: 'test' or 'live'
update_option('stripe_mode', 'test');

// Test Mode Keys
update_option('stripe_test_secret_key', 'sk_test_...');
update_option('stripe_test_publishable_key', 'pk_test_...');

// Live Mode Keys
update_option('stripe_live_secret_key', 'sk_live_...');
update_option('stripe_live_publishable_key', 'pk_live_...');
```

## Usage

### Basic Workflow

1. **Initialize Classes**
   ```php
   $plugin_slug = 'your-plugin-slug';
   $docusign = new Plugin_DocuSign_Contract($plugin_slug);
   ```

2. **Generate eSignature**
   ```php
   $data = [
       'contact-name' => 'John Doe',
       'company-name' => 'Acme Corp',
       'contact-email' => 'john@acme.com',
       'payment_currency' => 'USD'
   ];
   
   $payment_amount = 5000; // Amount in cents
   $html_contract = '<html>...</html>'; // Your contract HTML
   
   $result = $docusign->generate_eSignature($data, $payment_amount, $html_contract);
   
   if ($result['success']) {
       $signing_link = $result['signing_link'];
       $envelope_id = $result['envelope_id'];
       // Redirect user to signing_link
   }
   ```

3. **Handle DocuSign Completion**
   ```php
   // This is typically called via AJAX or redirect handler
   $docusign->handle_docusign_completion();
   // Automatically redirects to Stripe payment page
   ```

4. **Handle Payment Return**
   ```php
   $stripe = new Plugin_Stripe_Processor($plugin_slug);
   $payment_result = $stripe->handle_payment_return();
   
   if ($payment_result === true) {
       // Payment successful
   } elseif ($payment_result === false) {
       // Payment cancelled
   }
   ```

### Advanced Usage

#### Check Envelope Status
```php
$status = $docusign->get_envelope_status($envelope_id);
if (!is_wp_error($status)) {
    echo "Status: " . $status['status'];
    echo "Completed: " . $status['completed_date'];
}
```

#### Verify Payment Status
```php
$verification = $stripe->verify_payment_status($session_id);
if (!is_wp_error($verification)) {
    echo "Payment Status: " . $verification['status'];
    echo "Amount: " . $verification['amount_total'];
}
```

#### Test Stripe Connection
```php
$test_result = $stripe->test_connection();
if ($test_result['success']) {
    echo "Connected in " . $test_result['mode'] . " mode";
}
```

## Class Reference

### Plugin_DocuSign_Contract

Main class for handling DocuSign integration.

#### Methods

- `validate_docusign_config()` - Validates all required DocuSign configuration
- `generate_eSignature($data, $payment_amount, $html_contract)` - Generates and sends DocuSign envelope
- `handle_docusign_completion()` - Handles completion callback and redirects to Stripe
- `get_envelope_status($envelope_id)` - Retrieves envelope status from DocuSign
- `get_envelope_data($envelope_id)` - Retrieves stored envelope data

#### Private Methods

- `generate_signed_jwt()` - Creates JWT token for authentication
- `get_docusign_auth_details()` - Retrieves authentication credentials (cached)
- `send_envelope_to_docusign()` - Sends envelope to DocuSign API
- `generate_embedded_signing_link()` - Creates embedded signing URL
- `build_envelope_definition()` - Constructs envelope definition array

### Plugin_Stripe_Processor

Main class for handling Stripe payment processing.

#### Methods

- `create_checkout_session($company_name, $amount_cents, $currency, $client_email, $envelope_id)` - Creates Stripe checkout session
- `create_immediate_payment_redirect(...)` - Creates payment session and returns URL
- `handle_payment_return()` - Handles payment return from Stripe
- `verify_payment_status($session_id)` - Verifies payment completion
- `test_connection()` - Tests Stripe API connection
- `get_stripe_mode()` - Returns current Stripe mode
- `get_publishable_key()` - Returns publishable key for current mode

#### Private Methods

- `validate_stripe_config()` - Validates Stripe configuration
- `validate_payment_data()` - Validates payment amount and currency
- `create_stripe_product_and_price()` - Creates product and price objects
- `create_stripe_checkout_session()` - Creates checkout session

## Contract Template Requirements

The HTML contract must include anchor strings for signature and date fields:

- `/s1/` - Admin signature anchor
- `/s2/` - Client signature anchor
- `/d1/` - Admin date signed anchor
- `/d2/` - Client date signed anchor

Example:
```html
<div>
    <p>Admin Signature: <span>/s1/</span></p>
    <p>Date: <span>/d1/</span></p>
    <p>Client Signature: <span>/s2/</span></p>
    <p>Date: <span>/d2/</span></p>
</div>
```

## Error Handling

The plugin uses WordPress `WP_Error` objects for error handling. Always check for errors:

```php
if (is_wp_error($result)) {
    $error_message = $result->get_error_message();
    $error_code = $result->get_error_code();
    // Handle error
}
```

### Common Error Codes

**DocuSign Errors:**
- `docusign_config_missing` - Required configuration missing
- `docusign_consent_required` - User consent required for JWT authentication
- `docusign_token_error` - Token retrieval failed
- `envelope_send_error` - Envelope sending failed

**Stripe Errors:**
- `stripe_config_error` - Stripe configuration missing
- `stripe_amount_error` - Invalid payment amount
- `stripe_currency_error` - Invalid currency code
- `payment_not_paid` - Payment not completed

## Security Considerations

1. **Private Key Storage**: Store DocuSign private keys securely. Never commit them to version control.

2. **API Keys**: Store Stripe API keys in WordPress options or environment variables, never in code.

3. **Input Sanitization**: All user inputs are sanitized using WordPress functions:
   - `sanitize_text_field()`
   - `sanitize_email()`
   - `sanitize_textarea_field()`

4. **Nonce Verification**: Implement nonce verification for AJAX requests and form submissions.

5. **HTTPS**: Always use HTTPS in production for secure API communication.

## Troubleshooting

### DocuSign Consent Required

If you receive a consent error, visit the consent URL provided in the error message to grant permissions.

### Token Caching Issues

Clear WordPress transients if authentication issues persist:
```php
delete_transient('docusign_auth_' . md5(DS_USER_ID));
```

### Stripe Library Not Found

Ensure the Stripe PHP SDK is installed via Composer:
```bash
composer require stripe/stripe-php
```

Or if you have a `composer.json` file:
```bash
composer install
```

The Stripe SDK must be located at `vendor/stripe/stripe-php/init.php`. The plugin automatically loads it from `MY_PLUGIN_PATH . 'vendor/stripe/stripe-php/init.php'`.

**Verify Stripe SDK Installation:**
```php
// Check if Stripe SDK is loaded
if (class_exists('\Stripe\Stripe')) {
    echo "Stripe SDK loaded successfully";
} else {
    echo "Stripe SDK not found - run 'composer install'";
}
```

### OpenSSL Extension Missing

Install OpenSSL extension for PHP:
```bash
# Ubuntu/Debian
sudo apt-get install php-openssl

# macOS (Homebrew)
brew install php-openssl
```

## File Structure

This plugin follows the structure pattern from the [WP Plugin Boilerplate](https://github.com/d5b94396feba3/WP-Plugin-Boilerplate):

```
wp-docusign-stripe/
├── includes/                       # Core PHP classes
│   ├── docusign/
│   │   └── classs-docusign-contract.php    # DocuSign integration class
│   └── stripe/
│       └── class-stripe-processor.php      # Stripe payment processing class
├── vendor/                         # Composer dependencies
│   └── stripe/
│       └── stripe-php/             # Official Stripe PHP SDK
├── admin/                          # Backend functionality (if using boilerplate structure)
├── public/                         # Frontend functionality (if using boilerplate structure)
├── assets/                         # CSS, JS, and images (if using boilerplate structure)
└── README.md                       # This file
```

### Plugin Architecture

Built on the **WP Plugin Boilerplate** foundation, this plugin maintains:
- **Object-Oriented Architecture**: Clean, maintainable code structure
- **Separation of Concerns**: Admin and public functionality separated
- **WordPress Standards**: Follows WordPress coding standards and best practices
- **Security Ready**: Built-in security measures and data sanitization
- **Hook Management**: Centralized WordPress hook system

## Dependencies & Credits

### WP Plugin Boilerplate

This plugin is built using the **[WP Plugin Boilerplate](https://github.com/d5b94396feba3/WP-Plugin-Boilerplate)** by [d5b94396feba3](https://github.com/d5b94396feba3). The boilerplate provides:

- Modern, object-oriented WordPress plugin structure
- One-file setup for plugin renaming
- WordPress coding standards compliance
- Security features and best practices
- Admin and public separation
- Hook management system

**Learn more**: [WP Plugin Boilerplate Repository](https://github.com/d5b94396feba3/WP-Plugin-Boilerplate)

### Stripe PHP SDK

This plugin uses the official **[Stripe PHP SDK](https://github.com/stripe/stripe-php)** for all payment processing operations. The SDK provides:

- Secure API communication with Stripe
- Product and Price management
- Checkout Session creation and management
- Payment verification and status checking
- Comprehensive error handling
- Automatic API version management

**Documentation**: [Stripe PHP SDK Documentation](https://stripe.com/docs/api/php)  
**Repository**: [stripe/stripe-php on GitHub](https://github.com/stripe/stripe-php)

### Stripe SDK Features Used

The plugin utilizes the following Stripe SDK capabilities:

- **Product Management**: Dynamic creation of Stripe products for each contract
- **Price Management**: Automatic price creation with metadata linking to DocuSign envelopes
- **Checkout Sessions**: Secure payment collection via Stripe Checkout
- **Session Verification**: Payment status verification and validation
- **Metadata Tracking**: Linking Stripe payments to DocuSign envelopes via metadata
- **Error Handling**: Comprehensive error handling with Stripe exception classes

## License

This project is licensed under the **GPL-2.0+ License** - see the [GPL v2 license](http://www.gnu.org/licenses/gpl-2.0.txt) for details.

## Support

For issues, questions, or contributions:

- **GitHub Issues**: [Report an issue](https://github.com/d5b94396feba3/wp-docusign-stripe/issues)
- **Repository**: [https://github.com/d5b94396feba3/wp-docusign-stripe](https://github.com/d5b94396feba3/wp-docusign-stripe)

When reporting issues, please include:
- WordPress version
- PHP version
- Plugin version
- Steps to reproduce the issue
- Any error messages or logs

### Related Resources

- [WP Plugin Boilerplate Documentation](https://github.com/d5b94396feba3/WP-Plugin-Boilerplate)
- [Stripe PHP SDK Documentation](https://stripe.com/docs/api/php)
- [Stripe API Reference](https://stripe.com/docs/api)
- [DocuSign API Documentation](https://developers.docusign.com/docs/esign-rest-api/)

## Changelog

### Version 1.0.0
- Initial release
- DocuSign JWT authentication
- Stripe payment integration
- Embedded signing workflow
- Payment verification

## Contributing

Contributions are welcome! If you'd like to contribute to this project:

1. **Fork the repository** on GitHub
2. **Create a feature branch** from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```
3. **Make your changes** following WordPress coding standards
4. **Test your changes** thoroughly
5. **Commit your changes** with clear commit messages:
   ```bash
   git commit -m "Add: Description of your changes"
   ```
6. **Push to your fork**:
   ```bash
   git push origin feature/your-feature-name
   ```
7. **Submit a Pull Request** with a clear description of your changes

### Coding Standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- Use meaningful variable and function names
- Add comments for complex logic
- Ensure all functions are properly documented
- Test your changes before submitting

### Pull Request Guidelines

- Keep PRs focused on a single feature or fix
- Include tests if applicable
- Update documentation if needed
- Ensure your code follows the existing code style
