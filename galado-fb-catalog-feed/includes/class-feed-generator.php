<?php
/**
 * Per-item builder and serializers for the Facebook catalog feed.
 *
 * This class only ever touches ONE product at a time. There is no
 * full-catalog loop here — batching across the catalog is the job of
 * GFBF_Feed_Builder, which runs in the background via WP-Cron.
 */

if (!defined('ABSPATH')) exit;

class GFBF_Feed_Generator {

    /** @var array */
    private $settings;

    public function __construct($settings = []) {
        $this->settings = is_array($settings) ? $settings : [];
    }

    // =========================================================================
    // SERIALIZERS
    // =========================================================================

    public function xml_header() {
        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $out .= '<channel>' . "\n";
        $out .= '<title>' . $this->esc_xml(get_bloginfo('name') . ' — Facebook Catalog') . '</title>' . "\n";
        $out .= '<link>' . $this->esc_xml(home_url('/')) . '</link>' . "\n";
        $out .= '<description>' . $this->esc_xml('WooCommerce product feed for Meta Commerce Manager') . '</description>' . "\n";
        return $out;
    }

    public function xml_footer() {
        return '</channel>' . "\n" . '</rss>' . "\n";
    }

    public function csv_columns() {
        return [
            'id', 'title', 'description', 'availability', 'condition',
            'price', 'sale_price', 'link', 'image_link', 'additional_image_link',
            'brand', 'google_product_category', 'product_type', 'item_group_id',
        ];
    }

    /**
     * Write the CSV column header to an open file handle.
     */
    public function write_csv_header($handle) {
        fputcsv($handle, $this->csv_columns());
    }

    /**
     * Write one row to an open CSV file handle.
     */
    public function write_csv_row($handle, array $row) {
        $line = [];
        foreach ($this->csv_columns() as $col) {
            $value = $row[$col] ?? '';
            if ($col === 'additional_image_link' && is_array($value)) {
                $value = implode(',', $value);
            }
            $line[] = $value;
        }
        fputcsv($handle, $line);
    }

    /**
     * Serialize one row array to an XML <item> block.
     */
    public function row_to_xml(array $row) {
        $xml = "<item>\n";
        foreach ($row as $key => $value) {
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
        return $xml;
    }

    // =========================================================================
    // ROW BUILDING (one product at a time)
    // =========================================================================

    /**
     * Build feed rows for a single product: one row for a simple product,
     * or one row per variation for a variable product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows_for_product($product) {
        $exclude_cats = isset($this->settings['exclude_cats']) && is_array($this->settings['exclude_cats'])
            ? array_map('intval', $this->settings['exclude_cats'])
            : [];
        $include_variations = !empty($this->settings['include_variations']);
        $brand    = isset($this->settings['brand']) ? (string) $this->settings['brand'] : 'GALADO';
        $currency = get_woocommerce_currency();

        if (!$this->is_product_eligible($product, $exclude_cats)) {
            return [];
        }

        $rows = [];

        if ($product->is_type('variable') && $include_variations) {
            $group_id = (string) $product->get_id();
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation || !$variation->exists()) {
                    continue;
                }
                $row = $this->build_item($variation, $product, $brand, $currency, $group_id);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        } else {
            $row = $this->build_item($product, null, $brand, $currency, '');
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
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

    // =========================================================================
    // DIAGNOSTICS (lightweight — direct count queries, tiny sample)
    // =========================================================================

    /**
     * Plain-text diagnostic. Uses direct count queries and loads only a small
     * product sample, so it stays fast even on a large catalog.
     */
    public function diagnose() {
        global $wpdb;

        $lines = [];
        $lines[] = '=== GALADO Facebook Catalog Feed — diagnostics ===';
        $lines[] = 'include_variations: ' . (!empty($this->settings['include_variations']) ? 'yes' : 'no');
        $lines[] = 'currency: ' . get_woocommerce_currency();
        $lines[] = '';

        $published_products = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'"
        );
        $published_variations = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product_variation' AND post_status = 'publish'"
        );
        $products_no_image = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
               ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = '0')"
        );

        $lines[] = 'Published products (DB count): ' . $published_products;
        $lines[] = 'Published variations (DB count): ' . $published_variations;
        $lines[] = 'Published products with NO featured image: ' . $products_no_image;
        $lines[] = '';

        $sample_ids = wc_get_products([
            'status' => 'publish',
            'limit'  => 5,
            'return' => 'ids',
        ]);
        $lines[] = 'wc_get_products(status=publish, limit=5) => ' . count($sample_ids)
            . ' ids: ' . implode(', ', array_map('strval', $sample_ids));
        $lines[] = '';

        foreach ($sample_ids as $id) {
            $p = wc_get_product($id);
            if (!$p) {
                $lines[] = "#$id => wc_get_product() returned false";
                continue;
            }
            $rows = $this->rows_for_product($p);
            $lines[] = sprintf(
                '#%d "%s" | type=%s price=%s image_id=%s children=%d => %d feed row(s)',
                $p->get_id(),
                $this->truncate($p->get_name(), 40),
                $p->get_type(),
                var_export($p->get_price(), true),
                var_export($p->get_image_id(), true),
                count($p->get_children()),
                count($rows)
            );
        }

        return implode("\n", $lines);
    }
}
