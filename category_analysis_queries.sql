-- SAP-WooCommerce Category Structure Analysis Queries
-- Execute these queries in your WordPress database to understand the current category structure

-- Query 1: Get all product categories with their hierarchy
SELECT 
    t.term_id,
    t.name as category_name,
    t.slug,
    tt.parent as parent_id,
    CASE 
        WHEN tt.parent = 0 THEN 'Main Category'
        ELSE 'Subcategory'
    END as category_level,
    tt.count as product_count
FROM wp_terms t
JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
WHERE tt.taxonomy = 'product_cat'
ORDER BY tt.parent, t.name;

-- Query 2: Get main categories (parent = 0) and their subcategories count
SELECT 
    main.term_id as main_id,
    main.name as main_category,
    main.slug as main_slug,
    COUNT(sub.term_id) as subcategory_count,
    GROUP_CONCAT(sub.name SEPARATOR ', ') as subcategories
FROM wp_terms main
JOIN wp_term_taxonomy main_tt ON main.term_id = main_tt.term_id
LEFT JOIN wp_term_taxonomy sub_tt ON main.term_id = sub_tt.parent
LEFT JOIN wp_terms sub ON sub_tt.term_id = sub.term_id
WHERE main_tt.taxonomy = 'product_cat' 
    AND main_tt.parent = 0
GROUP BY main.term_id, main.name, main.slug
ORDER BY main.name;

-- Query 3: Get subcategories with their parent information
SELECT 
    sub.term_id as sub_id,
    sub.name as subcategory_name,
    sub.slug as sub_slug,
    parent.term_id as parent_id,
    parent.name as parent_category,
    sub_tt.count as product_count
FROM wp_terms sub
JOIN wp_term_taxonomy sub_tt ON sub.term_id = sub_tt.term_id
JOIN wp_terms parent ON sub_tt.parent = parent.term_id
WHERE sub_tt.taxonomy = 'product_cat' 
    AND sub_tt.parent > 0
ORDER BY parent.name, sub.name;

-- Query 4: Check for products with multiple categories (to understand current assignment patterns)
SELECT 
    tr.object_id as product_id,
    COUNT(tr.term_taxonomy_id) as category_count,
    GROUP_CONCAT(t.name SEPARATOR ' | ') as assigned_categories
FROM wp_term_relationships tr
JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
JOIN wp_terms t ON tt.term_id = t.term_id
JOIN wp_posts p ON tr.object_id = p.ID
WHERE tt.taxonomy = 'product_cat'
    AND p.post_type IN ('product', 'product_variation')
    AND p.post_status = 'publish'
GROUP BY tr.object_id
HAVING category_count > 1
ORDER BY category_count DESC
LIMIT 20;

-- Query 5: Sample products and their current category assignments
SELECT 
    p.ID as product_id,
    p.post_title as product_name,
    p.post_type,
    GROUP_CONCAT(t.name SEPARATOR ' | ') as categories,
    pm_sku.meta_value as sku,
    pm_age.meta_value as sap_age,
    pm_bad.meta_value as sap_bad,
    pm_gilbad.meta_value as sap_gilbad
FROM wp_posts p
LEFT JOIN wp_term_relationships tr ON p.ID = tr.object_id
LEFT JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
LEFT JOIN wp_terms t ON tt.term_id = t.term_id
LEFT JOIN wp_postmeta pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
LEFT JOIN wp_postmeta pm_age ON p.ID = pm_age.post_id AND pm_age.meta_key = '_sap_u_em_age'
LEFT JOIN wp_postmeta pm_bad ON p.ID = pm_bad.post_id AND pm_bad.meta_key = '_sap_u_em_bad'
LEFT JOIN wp_postmeta pm_gilbad ON p.ID = pm_gilbad.post_id AND pm_gilbad.meta_key = '_sap_u_em_gilbad'
WHERE p.post_type IN ('product', 'product_variation')
    AND p.post_status IN ('publish', 'pending')
    AND pm_sku.meta_value IS NOT NULL
GROUP BY p.ID
ORDER BY p.ID DESC
LIMIT 20;

-- Query 6: Check existing SAP metadata fields to understand data availability
SELECT 
    pm.meta_key,
    COUNT(*) as field_count,
    COUNT(DISTINCT pm.meta_value) as unique_values,
    GROUP_CONCAT(DISTINCT LEFT(pm.meta_value, 50) SEPARATOR ' | ') as sample_values
FROM wp_postmeta pm
JOIN wp_posts p ON pm.post_id = p.ID
WHERE p.post_type IN ('product', 'product_variation')
    AND pm.meta_key LIKE '%sap%'
    AND pm.meta_key LIKE '%age%' OR pm.meta_key LIKE '%bad%' OR pm.meta_key LIKE '%gil%'
GROUP BY pm.meta_key
ORDER BY pm.meta_key;

-- Query 7: Analyze current "כללי" category usage
SELECT 
    COUNT(*) as products_in_general_category
FROM wp_term_relationships tr
JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
JOIN wp_terms t ON tt.term_id = t.term_id
JOIN wp_posts p ON tr.object_id = p.ID
WHERE tt.taxonomy = 'product_cat'
    AND t.name = 'כללי'
    AND p.post_type IN ('product', 'product_variation')
    AND p.post_status IN ('publish', 'pending');
