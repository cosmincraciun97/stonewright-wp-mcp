---
title: Menu Cart widget
source_url: https://elementor.com/help/menu-cart-widget-pro/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:menu-cart]
related_widgets: [nav-menu, woocommerce-cart, woocommerce-checkout]
---

## Purpose
The Menu Cart widget (Elementor Pro + WooCommerce) displays a shopping cart icon with item count and optional subtotal in the header navigation, linking to the cart page or opening a mini-cart sidebar drawer. It is the standard pattern for WooCommerce store headers built with Elementor's Theme Builder.

## Use this when
- Building a custom WooCommerce store header via Elementor Theme Builder
- Placing a cart icon in the nav menu next to the Nav Menu widget
- Implementing a mini-cart slide-out panel triggered by the cart icon click
- Showing live cart item count that updates via AJAX as products are added

## Settings highlights
- **Cart Icon**: choose icon from library; default is shopping bag/cart
- **Item Count**: toggle display of item count badge on the icon
- **Subtotal**: show/hide cart subtotal text next to icon
- **Cart Indicator**: badge position (top-right, inline)
- **Open Cart Behavior**: link to cart page, or open mini-cart side panel
- **Mini-Cart**: toggle slide-in panel with cart items summary
- **Mini-Cart Width**: px or % width of the slide panel
- **Icon Size / Color** / **Count Badge Color**: full styling controls per state
- **Typography** for subtotal text

## Limits / gotchas
- Requires both Elementor Pro and WooCommerce plugin active
- AJAX cart count update requires WooCommerce's built-in AJAX hooks — may conflict with aggressive caching if page caching captures the HTML with a specific count
- Mini-cart panel z-index may conflict with fixed/sticky headers — adjust z-index in Custom CSS
- Guest checkout carts rely on WooCommerce session cookies; some cookie blockers prevent correct count display
