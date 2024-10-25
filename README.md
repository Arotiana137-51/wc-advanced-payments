#This project is still in development.It doesn't have complete files yet

# Advanced WooCommerce Payment Gateways

An advanced WordPress plugin that integrates Stripe and PayPal payment gateways with WooCommerce, supporting both one-time payments and subscriptions.

## Features

- ✨ Stripe Integration with subscription support
- 💳 PayPal Integration with subscription management
- 🔄 Automatic refund processing
- 🔒 Secure payment processing
- 📦 Customer profile management
- 🪝 Webhook support
- 📊 Payment logging and tracking
- 💰 Multi-currency support

## Requirements

- PHP 7.4 or higher
- WordPress 5.8 or higher
- WooCommerce 6.0 or higher
- SSL Certificate (required for payment processing)
- Composer (for dependency management)

## Dependencies

- stripe/stripe-php: ^10.0
- paypal/paypal-checkout-sdk: ^1.0
- WooCommerce Subscriptions plugin (optional, for subscription features)

## Installation

1. **Clone the repository**
```bash
git clone https://github.com/your-username/wc-advanced-payments.git
```

2. **Install dependencies**
```bash
composer install
```

3. **Upload to WordPress**
   - Upload the entire plugin directory to `/wp-content/plugins/`
   - Activate the plugin through the 'Plugins' menu in WordPress

## Configuration

### Stripe Setup
1. Go to WooCommerce > Settings > Payments
2. Click on "Stripe Custom"
3. Enable the payment method
4. Enter your Stripe API keys
   - Test keys for development
   - Live keys for production
5. Configure webhook URL in Stripe dashboard:
   `https://your-site.com/wc-api/stripe-webhook`

### PayPal Setup
1. Go to WooCommerce > Settings > Payments
2. Click on "PayPal Custom"
3. Enable the payment method
4. Enter your PayPal API credentials
   - Sandbox credentials for testing
   - Live credentials for production
5. Configure webhook URL in PayPal dashboard:
   `https://your-site.com/wc-api/paypal-webhook`

## Project Structure
```
wc-advanced-payments/
├── assets/
│   ├── css/
│   │   └── frontend.css
│   └── js/
│       ├── stripe-handler.js
│       └── paypal-handler.js
├── includes/
│   ├── gateways/
│   │   ├── class-wc-gateway-stripe-custom.php
│   │   └── class-wc-gateway-paypal-custom.php
│   ├── admin/
│   │   └── settings.php
│   ├── webhooks/
│   │   ├── class-stripe-webhook-handler.php
│   │   └── class-paypal-webhook-handler.php
│   └── helpers/
│       └── class-payment-helper.php
├── templates/
│   ├── stripe/
│   │   └── payment-form.php
│   └── paypal/
│       └── button.php
├── languages/
│   └── wc-advanced-payments.pot
├── vendor/
├── .gitignore
├── composer.json
├── index.php
├── LICENSE
├── README.md
└── wc-advanced-payments.php
```

## Development

### Local Development Setup
1. Set up a local WordPress development environment
2. Configure test API keys for both Stripe and PayPal
3. Enable debug mode in `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

### Running Tests
```bash
composer run test
```

### Coding Standards
This project follows WordPress Coding Standards. Run PHPCS:
```bash
composer run phpcs
```

## Deployment

1. Update version number in:
   - `wc-advanced-payments.php`
   - `readme.txt`
   - `composer.json`

2. Build for production:
```bash
composer run build
```

3. Create release archive excluding development files:
```bash
composer run package
```

## Contributing

1. Fork the repository
2. Create your feature branch: `git checkout -b feature/amazing-feature`
3. Commit your changes: `git commit -m 'Add amazing feature'`
4. Push to the branch: `git push origin feature/amazing-feature`
5. Open a Pull Request

## Security

- Always use HTTPS in production
- Keep WordPress, WooCommerce, and all plugins updated
- Follow security best practices for handling payment data
- Never store sensitive payment information

## Support

For support, please:
1. Check the [documentation](link-to-docs)
2. Search [existing issues](link-to-issues)
3. Create a new issue if needed

## License

This project is licensed under the GPL v2 or later - see the [LICENSE](LICENSE) file for details.

## Credits

Created by Arotiana Randrianasolo
- [Portfolio](https://aportfolio.vercel.app)
- [LinkedIn](https://www.linkedin.com/in/arotiana-randrianasolo)

## Changelog

### 1.0.0 (2024-03-25)
- Initial release
- Stripe integration with subscription support
- PayPal integration with subscription support
- Basic refund processing
- Webhook handling