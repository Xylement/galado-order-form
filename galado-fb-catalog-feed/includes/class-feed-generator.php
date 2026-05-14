<?php
/**
 * Builds a Facebook/Meta-spec product feed from the WooCommerce catalog.
 *
 * One row per simple product, or one row per variation (with a shared
 * item_group_id) for variable products.
 */

if (!defined('ABSPATH')) exit;

class GFBF_Feed_Generator {

    /** @var array */
    private $settings;

    public function __construct($settings = []) {
        $this->settings = is_array($settings) ? $settings : [];
    }

    /**
     * Build the RSS 2.0 XML feed — Facebook's preferred format.
     */
    public function build_xml() {
        $items = $this->collect_items();

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . $this->esc_xml(get_bloginfo('name') . ' — Facebook Catalog') . '</title>' . "\n";
        $xml .= '<link>' . $this->esc_xml(home_url('/')) . '</link>' . "\n";
        $xml .= '<description>' . $this->esc_xml('WooCommerce product feed for Meta Commerce Manager') . '</description>' . "\n";

        foreach ($items as $item) {
            $xml .= "<item>\n";
            foreach ($item as $key => $value) {
                if ($key === 'additional_image_link' && is_array($value)) {
                    foreach ($value as $img) {
                        $xml .= '  <g:additional_image_link>' . $this->esc_xml($img) . '</g:additional_image_link>' . "\n";
                    }
                    continue;
                }
                if ($value === '' || $value === null) {
                    continue;
                }
                $xml .= '  <g:' . $key . '>' . $this->esc_xml($value) . '</g:' . $key . '>' . "\n";
            }
            $xml .= "</item>\n";
        }

        $xml .= '</channel>' . "\n" . '</rss>' . "\n";
        return $xml;
    }

    /**
     * Build the CSV feed — accepted by Commerce Manager as a one-off upload.
     */
    public function build_csv() {
        $items = $this->collect_items();

        $columns = [
            'id', 'title', 'description', 'availability', 'condition',
            'price', 'sale_price', 'link', 'image_link', 'additional_image_link',
            'brand', 'google_product_category', 'product_type', 'item_group_id',
        ];

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $columns);

        foreach ($items as $item) {
            $row = [];
            foreach ($columns as $col) {
                $value = $item[$col] ?? '';
                if ($col === 'additional_image_link' && is_array($value)) {
                    $value = implode(',', $value);
                }
                $row[] = $value;
            }
            fputcsv($fh, $row);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /**
     * Count feed items — used by the admin screen.
     */
    public function count_feed_items() {
        return count($this->collect_items());
    }

    /**
     * Collect every feed row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collect_items() {
        $exclude_cats = isset($this->settings['exclude_cats']) && is_array($this->settings['exclude_cats'])
            ? array_map('intval', $this->settings['exclude_cats'])
            : [];
        $include_variations = !empty($this->settings['include_variations']);
        $brand    = isset($this->settings['brand']) ? (string) $this->settings['brand'] : 'GALADO';
        $currency = get_woocommerce_currency();

        $items = [];
        $page  = 1;

        do {
            $products = wc_get_products([
                'status'  => 'publish',
                'limit'   => 100,
                'page'    => $page,
                'orderby' => 'ID',
                'order'   => 'ASC',
                'return'  => 'objects',
            ]);

            if (empty($products)) {
                break;
            }

            foreach ($products as $product) {
                if (!$this->is_product_eligible($product, $exclude_cats)) {
                    continue;
                }

                if ($product->is_type('variable') && $include_variations) {
                    $group_id = (string) $product->get_id();
                    foreach ($product->get_children() as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if (!$variation || !$variation->exists()) {
                            continue;
                        }
                        $row = $this->build_item($variation, $product, $brand, $currency, $group_id);
                        if ($row !== null) {
                            $items[] = $row;
                        }
                    }
                } else {
                    $row = $this->build_item($product, null, $brand, $currency, '');
                    if ($row !== null) {
                        $items[] = $row;
                    }
                }
            }

            $page++;
        } while (count($products) === 100);

        return $items;
    }

    /**
     * Whether a product belongs in the feed.
     */
    private function is_product_eligible($product, $exclude_cats) {
        if (!$product || !$product->exists()) {
            return false;
        }
        if ($product->get_status() !== 'publish') {
            return false;
        }
        if ($product->get_catalog_visibility() === 'hidden') {
            return false;
        }
        if (!empty($exclude_cats)) {
            $cat_ids = $product->get_category_ids();
            if (array_intersect($cat_ids, $exclude_cats)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Build a single feed row for a product or variation.
     *
     * @return array<string, mixed>|null  Null when the item lacks a price or image.
     */
    private function build_item($product, $parent, $brand, $currency, $group_id) {
        $price = $this->get_price($product);
        if ($price === null) {
            return null; // Facebook requires a price.
        }

        $sku = $product->get_sku();
        $id  = $sku !== '' ? $sku : 'wc_' . $product->get_id();

        // Title — for variations, append the variation attributes.
        if ($parent) {
            $title = $parent->get_name();
            $attrs = $this->variation_attributes_label($product);
            if ($attrs !== '') {
                $title .= ' - ' . $attrs;
            }
        } else {
            $title = $product->get_name();
        }

        // Description — fall back through variation, parent, short descriptions.
        $desc_source = $product->get_description();
        if ($desc_source === '' && $parent) {
            $desc_source = $parent->get_description();
        }
        if ($desc_source === '') {
            $desc_source = $product->get_short_description();
        }
        if ($desc_source === '' && $parent) {
            $desc_source = $parent->get_short_description();
        }
        $description = $this->clean_text($desc_source);
        if ($description === '') {
            $description = $title; // Facebook requires a non-empty description.
        }

        // Primary image — fall back to the parent product image.
        $image_id = $product->get_image_id();
        if (!$image_id && $parent) {
            $image_id = $parent->get_image_id();
        }
        $image_link = $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';
        if (!$image_link) {
            return null; // Facebook requires an image.
        }

        // Gallery images (capped — Facebook allows up to 20 additional).
        $gallery = [];
        $gallery_source = $parent ? $parent : $product;
        foreach ($gallery_source->get_gallery_image_ids() as $gid) {
            $url = wp_get_attachment_image_url($gid, 'full');
            if ($url) {
                $gallery[] = $url;
            }
            if (count($gallery) >= 10) {
                break;
            }
        }

        // Pricing — surface sale price separately so Facebook shows the strike-through.
        $regular = $product->get_regular_price();
        $sale    = $product->get_sale_price();
        $price_str      = $this->format_price($price, $currency);
        $sale_price_str = '';
        if ($sale !== '' && $regular !== '' && (float) $sale < (float) $regular) {
            $price_str      = $this->format_price((float) $regular, $currency);
            $sale_price_str = $this->format_price((float) $sale, $currency);
        }

        // Link — deep-link straight to the chosen variation.
        if ($parent) {
            $link = add_query_arg($product->get_variation_attributes(), $parent->get_permalink());
        } else {
            $link = $product->get_permalink();
        }

        return [
            'id'                      => $id,
            'title'                   => $this->truncate($title, 150),
            'description'             => $this->truncate($description, 5000),
            'availability'            => $product->is_in_stock() ? 'in stock' : 'out of stock',
            'condition'               => 'new',
            'price'                   => $price_str,
            'sale_price'              => $sale_price_str,
            'link'                    => $link,
            'image_link'              => $image_link,
            'additional_image_link'   => $gallery,
            'brand'                   => $brand,
            'google_product_category' => '',
            'product_type'            => $this->category_path($parent ? $parent : $product),
            'item_group_id'           => $group_id,
        ];
    }

    /**
     * Get a usable numeric price, or null when there isn't one.
     */
    private function get_price($product) {
        $price = $product->get_price();
        if ($price === '' || $price === null) {
            return null;
        }
        $price = (float) $price;
        return $price > 0 ? $price : null;
    }

    private function format_price($amount, $currency) {
        return number_format((float) $amount, 2, '.', '') . ' ' . $currency;
    }

    private function variation_attributes_label($variation) {
        $bits = [];
        foreach ($variation->get_variation_attributes() as $value) {
            if ($value !== '') {
                $bits[] = ucwords(str_replace('-', ' ', (string) $value));
            }
        }
        return implode(', ', $bits);
    }

    private function category_path($product) {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (!$terms || is_wp_error($terms)) {
            return '';
        }
        return implode(' > ', wp_list_pluck($terms, 'name'));
    }

    private function clean_text($html) {
        $text = wp_strip_all_tags((string) $html, true);
        $text = preg_replace('/\s+/', ' ', (string) $text);
        return trim((string) $text);
    }

    private function truncate($text, $limit) {
        $text = (string) $text;
        if (function_exists('mb_strlen')) {
            if (mb_strlen($text) > $limit) {
                return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
            }
            return $text;
        }
        if (strlen($text) > $limit) {
            return rtrim(substr($text, 0, $limit - 1)) . '…';
        }
        return $text;
    }

    private function esc_xml($value) {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
