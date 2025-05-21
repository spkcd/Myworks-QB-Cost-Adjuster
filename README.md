# MyWorks QuickBooks Cost Adjuster for WooCommerce

A WordPress plugin that automatically adjusts product costs before syncing to QuickBooks using the MyWorks Sync plugin.

## Description

MyWorks QB Cost Adjuster extends the functionality of the MyWorks Sync for QuickBooks Online plugin by automatically calculating and adjusting product costs before they are synced to QuickBooks. This is particularly useful for merchants who need to adjust their product costs for accounting purposes.

The plugin calculates product costs by dividing the product's regular price by a configurable multiplier (default: 1.65). This ensures that your QuickBooks records reflect appropriate cost values, allowing for more accurate financial reporting.

## Features

- **Automatic Cost Calculation**: Calculate costs based on product price and a configurable multiplier
- **Support for Product Variations**: Adjusts costs for both simple and variable products
- **Configuration Options**: Enable/disable functionality and customize the cost multiplier
- **Detailed Logging**: Comprehensive logging of all cost adjustments and sync operations
- **Admin Interface**: User-friendly settings page, logs viewer, and sync reports
- **Bulk Operations**: Force update all product costs with a single click
- **WooCommerce Integration**: Seamlessly integrates with WooCommerce 
- **Error Handling**: Robust error tracking and reporting

## Requirements

- WordPress 5.3+
- PHP 7.0+
- WooCommerce 3.0.0+
- MyWorks Sync for QuickBooks Online

## Installation

1. Upload the plugin files to the `/wp-content/plugins/myworks-qb-cost-adjuster` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to WooCommerce > QB Cost Adjuster to configure the plugin settings.

## Configuration

1. Enable the plugin by checking the "Enable Cost Adjustment" box.
2. Set your desired cost multiplier (default is 1.65).
3. Save your settings.

The cost for each product will be calculated as:
```
Cost = Regular Price / Multiplier
```

For example, with the default multiplier of 1.65 and a product price of $49.50, the calculated cost would be $30.00.

## Usage

Once configured, the plugin works automatically whenever products are synced to QuickBooks via MyWorks Sync. 

You can also:

- View detailed logs of all cost adjustments
- Generate sync reports
- Force update all product costs
- View product-specific sync information in product edit screens

## Frequently Asked Questions

### How is the cost calculated?

The cost is calculated by dividing the product's regular price by the configured multiplier.

### Does this work with variable products?

Yes, the plugin supports both simple and variable products.

### Will this plugin work without MyWorks Sync for QuickBooks Online?

No, this plugin is specifically designed to extend the functionality of MyWorks Sync for QuickBooks Online and requires it to be installed and active.

## Support

For support, please contact [support@myworks.software](mailto:support@myworks.software).

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by [SPARKWEB Studio](https://sparkwebstudio.com) for [MyWorks Software](https://myworks.software). 