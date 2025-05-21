# Installation Guide for MyWorks QuickBooks Cost Adjuster

This guide will walk you through the process of installing and configuring the MyWorks QuickBooks Cost Adjuster plugin.

## Prerequisites

Before installing this plugin, make sure you have:

1. WordPress 5.3 or higher
2. PHP 7.0 or higher
3. WooCommerce 3.0.0 or higher
4. MyWorks Sync for QuickBooks Online installed and configured

## Installation Methods

### Method 1: Installation via WordPress Admin Panel

1. Log in to your WordPress admin panel
2. Navigate to **Plugins > Add New**
3. Click the **Upload Plugin** button at the top of the page
4. Click **Choose File** and select the downloaded zip file
5. Click **Install Now**
6. After installation completes, click **Activate Plugin**

### Method 2: Manual Installation

1. Download the plugin zip file
2. Extract the zip file to your computer
3. Upload the extracted folder to your `/wp-content/plugins/` directory via FTP
4. Log in to your WordPress admin panel
5. Navigate to **Plugins**
6. Find "MyWorks QuickBooks Cost Adjuster" in the list and click **Activate**

## Configuration

After installation, follow these steps to configure the plugin:

1. Navigate to **WooCommerce > QB Cost Adjuster**
2. Check the **Enable Cost Adjustment** checkbox to enable the functionality
3. Set your desired **Cost Multiplier** (default is 1.65)
4. Click **Save Changes**

## How It Works

Once enabled, the plugin will:

1. Intercept product data before it's sent to QuickBooks
2. Calculate the cost based on the formula: `Cost = Regular Price / Multiplier`
3. Update the cost field in the data sent to QuickBooks
4. Log the adjustment for future reference

## Testing Your Installation

To verify that your installation is working correctly:

1. Update a product's regular price in WooCommerce
2. Manually sync the product with QuickBooks using MyWorks Sync
3. Check that the cost in QuickBooks has been adjusted correctly
4. View the sync logs in **WooCommerce > QB Cost Adjuster > Logs**

## Troubleshooting

If you encounter issues with the plugin:

1. Check the plugin's logs at **WooCommerce > QB Cost Adjuster > Logs**
2. Verify that WooCommerce and MyWorks Sync are properly configured
3. Check that your products have valid regular prices set
4. Confirm that your QuickBooks connection is working properly

## Support

If you need further assistance, please contact support at [support@myworks.software](mailto:support@myworks.software) with the following information:

- WordPress version
- WooCommerce version
- MyWorks Sync for QuickBooks Online version
- MyWorks QB Cost Adjuster version
- Any relevant error messages from the logs 