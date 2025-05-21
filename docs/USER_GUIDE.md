# User Guide for MyWorks QuickBooks Cost Adjuster

This user guide provides detailed information on how to use the MyWorks QuickBooks Cost Adjuster plugin effectively.

## Table of Contents

1. [Overview](#overview)
2. [Settings](#settings)
3. [Cost Calculation](#cost-calculation)
4. [Logs and Reports](#logs-and-reports)
5. [Bulk Operations](#bulk-operations)
6. [Troubleshooting](#troubleshooting)
7. [Frequently Asked Questions](#frequently-asked-questions)

## Overview

MyWorks QuickBooks Cost Adjuster automatically calculates and adjusts product costs before they are synced to QuickBooks using the MyWorks Sync for QuickBooks Online plugin. This ensures that your QuickBooks records reflect appropriate cost values for accurate financial reporting.

The plugin works by intercepting product data during the sync process and applying a cost calculation based on the regular price of each product and a configurable multiplier.

## Settings

Access the plugin settings at **WooCommerce > QB Cost Adjuster**.

The settings page includes:

- **Enable Cost Adjustment**: Turn the cost adjustment functionality on or off
- **Cost Multiplier**: Set the divisor used to calculate costs from regular prices (default: 1.65)

### Cost Multiplier Explained

The cost multiplier determines how the product cost is calculated from the regular price. The formula is:

```
Cost = Regular Price / Multiplier
```

For example:
- If a product's regular price is $49.50 and the multiplier is 1.65, the calculated cost would be $30.00 ($49.50 ÷ 1.65)
- If a product's regular price is $100.00 and the multiplier is 2.00, the calculated cost would be $50.00 ($100.00 ÷ 2.00)

Choose a multiplier that reflects your business's typical markup structure.

## Cost Calculation

### How Costs Are Calculated

1. When a product is synced to QuickBooks, the plugin retrieves the product's regular price
2. The regular price is divided by the configured multiplier
3. The result is rounded to 2 decimal places
4. This calculated cost is used in the data sent to QuickBooks

### Supported Product Types

The plugin works with:

- Simple products
- Variable products and their variations

### Cost Fields Updated

The following cost-related fields are updated:

- QuickBooks cost field (`_qb_p_cost`)
- WooCommerce Cost of Goods cost field (`_wc_cog_cost`) if the plugin is active
- Standard tracking cost field (`_mwqbca_cost`)
- Standard purchase cost field (`_purchase_cost`)

## Logs and Reports

### Sync Logs

View sync logs at **WooCommerce > QB Cost Adjuster > Logs**. These logs show:

- Timestamp of each adjustment
- Product information
- Regular price
- Applied multiplier
- Calculated cost
- Status of the adjustment (success, error, warning, info)

### Sync Reports

Access sync reports at **WooCommerce > QB Cost Adjuster > Sync Report**. The reports provide:

- Summary of recent cost adjustments
- API transaction logs
- Detailed variation processing logs

### Log Management

You can:

- Clear logs to free up database space
- Export logs for backup or analysis
- Filter logs by various criteria

## Bulk Operations

### Force Update All Products

1. Go to **WooCommerce > QB Cost Adjuster**
2. Click the **Force Update All Products** button
3. Confirm the operation
4. Monitor progress in the displayed progress bar

This operation:
- Recalculates costs for all products based on the current multiplier
- Updates all cost-related fields in the database
- Does not trigger an immediate sync to QuickBooks (use MyWorks Sync for that)

## Troubleshooting

### Common Issues

1. **Missing Costs in QuickBooks**
   - Check if the plugin is enabled
   - Verify that products have valid regular prices
   - Ensure MyWorks Sync is properly configured

2. **Incorrect Costs**
   - Check the multiplier value in settings
   - Verify that the product's regular price is correct

3. **Sync Errors**
   - Check the logs for specific error messages
   - Verify QuickBooks connection status

### Diagnostic Information

To help with troubleshooting, collect:

1. Plugin logs from **WooCommerce > QB Cost Adjuster > Logs**
2. Your WordPress, WooCommerce, and plugin versions
3. Cost multiplier setting
4. Sample product information (price and expected cost)

## Frequently Asked Questions

### How do I know if the plugin is working?

Check the sync logs after syncing a product. You should see entries showing the cost adjustment calculation. You can also verify in QuickBooks that the product costs have been updated.

### Can I use different multipliers for different products?

The current version uses a global multiplier for all products. You may consider using custom meta fields and extending the plugin for product-specific multipliers.

### Will this affect my product prices in WooCommerce?

No, the plugin only affects the cost data sent to QuickBooks. It does not modify any prices or costs in WooCommerce itself.

### What happens if a product has no regular price set?

If a product doesn't have a valid regular price, the cost will not be adjusted, and an error will be logged. Make sure all products have valid regular prices.

### Can I temporarily disable cost adjustment?

Yes, simply uncheck the "Enable Cost Adjustment" option in the settings. You can re-enable it anytime without losing your configuration. 