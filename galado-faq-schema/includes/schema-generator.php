<?php
if (!defined('ABSPATH')) exit;

/**
 * Output FAQPage JSON-LD schema in wp_head
 */
function gfaq_output_schema() {
    if (is_admin() || !is_singular()) return;

    $post_id  = get_the_ID();
    $settings = get_option('gfaq_settings', []);
    $post_type = get_post_type($post_id);

    // Check if enabled for this post type
    $enabled_types = isset($settings['post_types']) ? $settings['post_types'] : ['page', 'post', 'product'];
    if (!in_array($post_type, $enabled_types)) return;

    // Check per-page toggle
    $page_enabled = get_post_meta($post_id, '_gfaq_enabled', true);
    if ($page_enabled === 'no') return;

    // Collect FAQs
    $faqs = [];

    // 1. Manual FAQs from meta box
    $manual = get_post_meta($post_id, '_gfaq_manual_faqs', true);
    if (is_array($manual)) {
        foreach ($manual as $faq) {
            if (!empty($faq['question']) && !empty($faq['answer'])) {
                $faqs[] = [
                    'question' => sanitize_text_field($faq['question']),
                    'answer'   => wp_strip_all_tags($faq['answer']),
                ];
            }
        }
    }

    // 2. Auto-detect from content
    $auto_detect = isset($settings['auto_detect']) ? $settings['auto_detect'] : 1;
    if ($auto_detect) {
        $content = get_the_content(null, false, $post_id);
        $content = apply_filters('the_content', $content);
        $detected = gfaq_detect_faqs($content);
        $faqs = array_merge($faqs, $detected);
    }

    // Deduplicate by question text
    $seen = [];
    $unique_faqs = [];
    foreach ($faqs as $faq) {
        $key = strtolower(trim($faq['question']));
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique_faqs[] = $faq;
        }
    }

    if (empty($unique_faqs)) return;

    // Build JSON-LD
    $schema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => [],
    ];

    foreach ($unique_faqs as $faq) {
        $schema['mainEntity'][] = [
            '@type' => 'Question',
            'name'  => $faq['question'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $faq['answer'],
            ],
        ];
    }

    echo "\n<!-- GALADO FAQ Schema Generator -->\n";
    echo '<script type="application/ld+json">' . "\n";
    echo wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo "\n</script>\n";
}

/**
 * Auto-detect FAQ patterns from HTML content
 */
function gfaq_detect_faqs($html) {
    $faqs = [];
    if (empty($html)) return $faqs;

    // Suppress HTML parsing errors
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFIX);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);

    // Pattern 1: <details><summary>Q</summary>A</details>
    $details = $xpath->query('//details');
    foreach ($details as $detail) {
        $summary = $detail->getElementsByTagName('summary')->item(0);
        if ($summary) {
            $question = trim($summary->textContent);
            // Get answer: everything after summary
            $answer = '';
            foreach ($detail->childNodes as $child) {
                if ($child !== $summary) {
                    $answer .= trim($child->textContent) . ' ';
                }
            }
            $answer = trim($answer);
            if ($question && $answer) {
                $faqs[] = ['question' => $question, 'answer' => $answer];
            }
        }
    }

    // Pattern 2: Flatsome/UX Builder accordion items
    // <div class="accordion-item"> or <li class="accordion-item">
    $accordion_items = $xpath->query('//*[contains(@class, "accordion-item") or contains(@class, "accordion-inner")]');
    foreach ($accordion_items as $item) {
        $title_el = $xpath->query('.//*[contains(@class, "accordion-title") or contains(@class, "toggle-title")]', $item)->item(0);
        $content_el = $xpath->query('.//*[contains(@class, "accordion-content") or contains(@class, "toggle-content") or contains(@class, "accordion-inner")]', $item)->item(0);
        if ($title_el && $content_el) {
            $q = trim($title_el->textContent);
            $a = trim($content_el->textContent);
            if ($q && $a) {
                $faqs[] = ['question' => $q, 'answer' => $a];
            }
        }
    }

    // Pattern 3: WordPress accordion block
    $wp_accordions = $xpath->query('//*[contains(@class, "wp-block-details")]');
    foreach ($wp_accordions as $acc) {
        $summary = $acc->getElementsByTagName('summary')->item(0);
        if ($summary) {
            $q = trim($summary->textContent);
            $a = '';
            foreach ($acc->childNodes as $child) {
                if ($child !== $summary) {
                    $a .= trim($child->textContent) . ' ';
                }
            }
            $a = trim($a);
            if ($q && $a) {
                $faqs[] = ['question' => $q, 'answer' => $a];
            }
        }
    }

    // Pattern 4: Elementor accordion
    $el_items = $xpath->query('//*[contains(@class, "elementor-accordion-item")]');
    foreach ($el_items as $item) {
        $title_el = $xpath->query('.//*[contains(@class, "elementor-accordion-title")]', $item)->item(0);
        $content_el = $xpath->query('.//*[contains(@class, "elementor-tab-content")]', $item)->item(0);
        if ($title_el && $content_el) {
            $q = trim($title_el->textContent);
            $a = trim($content_el->textContent);
            if ($q && $a) {
                $faqs[] = ['question' => $q, 'answer' => $a];
            }
        }
    }

    // Pattern 5: FAQ containers with class patterns
    $faq_containers = $xpath->query('//*[contains(@class, "faq-item") or contains(@class, "faq_item") or contains(@class, "faq-block")]');
    foreach ($faq_containers as $item) {
        $q_el = $xpath->query('.//*[contains(@class, "faq-question") or contains(@class, "faq_question") or contains(@class, "faq-title")]', $item)->item(0);
        $a_el = $xpath->query('.//*[contains(@class, "faq-answer") or contains(@class, "faq_answer") or contains(@class, "faq-content")]', $item)->item(0);
        if ($q_el && $a_el) {
            $q = trim($q_el->textContent);
            $a = trim($a_el->textContent);
            if ($q && $a) {
                $faqs[] = ['question' => $q, 'answer' => $a];
            }
        }
    }

    // Pattern 6: H3 or H4 followed by P (common FAQ pattern)
    // Only if no other patterns detected and content looks FAQ-like
    if (empty($faqs)) {
        $headings = $xpath->query('//h3|//h4');
        foreach ($headings as $heading) {
            $q = trim($heading->textContent);
            // Check if it looks like a question
            if (strpos($q, '?') !== false || preg_match('/^(how|what|why|when|where|can|do|does|is|are|will|should)/i', $q)) {
                $next = $heading->nextSibling;
                $a = '';
                while ($next) {
                    if ($next->nodeType === XML_ELEMENT_NODE) {
                        $tag = strtolower($next->nodeName);
                        if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) break;
                        $a .= trim($next->textContent) . ' ';
                    }
                    $next = $next->nextSibling;
                }
                $a = trim($a);
                if ($q && $a && strlen($a) > 20) {
                    $faqs[] = ['question' => $q, 'answer' => $a];
                }
            }
        }
    }

    return $faqs;
}
