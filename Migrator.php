<?php

class Migrator
{
    private PDO $pdoWC;
    private PDO $pdoOC;

    private array $productsWC = [];
    private array $categoriesWC = [];
    private array $attributeWC = [];
    private array $tagsWC = [];

    private array $productsOC = [];
    private array $categoriesOC = [];
    private array $attributeOC = [];
    private array $tagsOC = [];

    public function migrate($credentials)
    {
        list($this->pdoWC, $this->pdoOC) = DB::initialize($credentials['wc'], $credentials['oc']);
        // Get
        $this->getProducts();

        // Process
        $this->processProducts();

        // Insert


        // Check
        $this->getImportedProducts();
    }

#region GET_DATA
    private function getProducts()
    {
        $sql = '
SELECT 
p.ID, 
p.post_date as date_added, 
p.post_modified as date_modified,
p.menu_order as sort_order,
if(p.post_status = \'publish\',1,0) as status,
p.post_content as "description/description", 
p.post_title as "description/name"
FROM wp_posts p
WHERE post_type = \'product\'
AND post_status <> \'auto-draft\'
';

        $stmt = $this->pdoWC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $row['meta'] = $this->getProductMeta($row['ID']);
            $row['review'] = $this->getProductReview($row['ID']);
            $row['taxonomies'] = $this->getTaxonomies($row['ID']);
            $row['images'] = $this->getImages($row['ID']);
            $this->productsWC[$row['ID']] = $row;
        }
    }

    private function getProductMeta($id)
    {
        $sql = "
SELECT meta_key, meta_value
FROM wp_postmeta pm
WHERE $id = pm.post_id
";
        $stmt = $this->pdoWC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return false;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $k => $v) {
            $rows[$v['meta_key']] = $v['meta_value'];
            unset($rows[$k]);
        }
        return $rows;
    }

    private function getProductReview($id)
    {

        $sql = "
SELECT 
    comment_ID as review_id,
    comment_post_ID as product_id,
    comment_author as author,
    comment_content as text,
    comment_approved as status,
    comment_date as date_added,
    comment_date as date_modified
FROM wp_comments c
WHERE $id = c.comment_post_ID
AND c.comment_type = 'review'
";
        $stmt = $this->pdoWC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return false;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
//            $row['customer_id'] = 0;
            $row += $this->getReviewMeta($row['review_id']);
        }
        return $rows;
    }

    private function getReviewMeta($id)
    {
        $sql = "
SELECT meta_key, meta_value
FROM wp_commentmeta cm
WHERE $id = cm.comment_id
";
        $stmt = $this->pdoWC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return false;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $k => $v) {
            if ($v['meta_key'] == 'rating') {
                $rows['rating'] = $v['meta_value'];
                unset($rows[$k]);
                continue;
            }
            $rows['/meta/' . $v['meta_key']] = $v['meta_value'];
            unset($rows[$k]);
        }
        return $rows;
    }

    private function getTaxonomies($id, $isProductId = true)
    {
        $fromWhere = $isProductId
            ? "FROM wp_term_relationships tr,
     wp_term_taxonomy tt,
     wp_terms t
WHERE $id = tr.object_id
  AND tr.term_taxonomy_id = tt.term_taxonomy_id
  AND tt.term_id = t.term_id"
            : "FROM wp_term_taxonomy tt,
     wp_terms t
WHERE t.term_id = tt.term_id
  AND tt.term_id = $id";

        $sql = "
SELECT tt.*, t.*
$fromWhere
";
        $stmt = $this->pdoWC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return false;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if ($row['parent'] != 0) {
                $rows[] = $this->getTaxonomies($row['parent'], false)[0];
            }
            if (!$isProductId) {
                $row += $this->getTaxonomyMeta($row['term_id']);
            }
        }

        return $rows;
    }

    private function getTaxonomyMeta($id)
    {
        $sql = "
SELECT meta_key, meta_value
FROM wp_termmeta tm
WHERE $id = tm.term_id
";
        $stmt = $this->pdoWC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return false;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $k => $v) {
            $rows['/meta/' . $v['meta_key']] = $v['meta_value'];
            unset($rows[$k]);
        }
        return $rows;
    }

    private function getImages($id)
    {
        $sql = "
SELECT p.ID,
       REPLACE(p.guid, CONCAT((SELECT option_value FROM wp_options WHERE option_name = 'home'), '/'), '') as guid
FROM wp_posts p
WHERE $id = p.post_parent
  AND p.post_type = 'attachment'
";
        $stmt = $this->pdoWC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return false;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $k => $v) {
            $rows[$v['ID']] = $v['guid'];
            unset($rows[$k]);
        }

        return $rows;
    }
    // TODO GET CATEGORY PARENT(S)
    // TODO GET CATEGORY IMAGE

    private function getWCAttributeLabel($name)
    {
        $sql = "
SELECT attribute_label
FROM wp_woocommerce_attribute_taxonomies wcat
WHERE '$name' = wcat.attribute_name
";
        $stmt = $this->pdoWC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return false;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows[0]['attribute_label'];
    }

#endregion

#region PROCESS_DATA

    private function processProducts()
    {
        foreach ($this->productsWC as $rowId => &$row) {
            $row['tax_class_id'] = $row['meta']['_downloadable'] == 'yes' ? 10 : 0;
            $row['sku'] = $row['meta']['_sku'];
            $row['quantity'] = $row['meta']['_stock'];
            $row['price'] = $row['meta']['_regular_price'];
            $row['special/price'] = $row['meta']['_sale_price'];
            $row['weight_class_id'] = 1; // KG
            $row['weight'] = $row['meta']['_weight'];
            $row['length_class_id'] = 1; // CM
            $row['length'] = $row['meta']['_length'];
            $row['weight'] = $row['meta']['_width'];
            $row['height'] = $row['meta']['_height'];
            $row['subtract'] = 1;
            $row['image'] = $row['images'][$row['meta']['_thumbnail_id']];

            $relatedProducts = unserialize($row['meta']['_crosssell_ids']);
            if (is_array($relatedProducts)) {
                foreach ($relatedProducts as $relatedProductId) {
                    $row['related'][] = ['product_id' => $rowId, 'related_id' => $relatedProductId];
                }
            }

            $productGallery = explode(',', $row['meta']['_product_image_gallery']);
            if (is_array($productGallery)) {
                foreach ($productGallery as $imageId) {
                    $row['image/'][] = ['product_image_id' => $imageId, 'image' => $row['images'][$imageId], 'product_id' => $rowId, 'sort_order' => 0];
                }
            }

            $this->attributeWC[$rowId] = unserialize($row['meta']['_product_attributes']);
//            if (is_array($productAttributes)) {
//                foreach ($productAttributes as $attribute) {
//                    $row['image/'][] = ['product_image_id' => $imageId, 'image' => $row['images'][$imageId], 'product_id' => $rowId, 'sort_order' => 0];
//                }
//            }
            $this->processProductsTaxonomies($row['taxonomies'], $rowId);


            unset($row['meta']['_downloadable']);
            unset($row['meta']['_tax_status']);
            unset($row['meta']['_tax_class']);
            unset($row['meta']['_manage_stock']);
            unset($row['meta']['_backorders']);
            unset($row['meta']['_sold_individually']);
            unset($row['meta']['_virtual']);
            unset($row['meta']['_downloadable']);
            unset($row['meta']['_download_limit']);
            unset($row['meta']['_download_expiry']);
            unset($row['meta']['_stock']);
            unset($row['meta']['_sku']);
            unset($row['meta']['_edit_lock']);
            unset($row['meta']['total_sales']);
            unset($row['meta']['_stock_status']);
            unset($row['meta']['_edit_last']);
            unset($row['meta']['_product_version']);
            unset($row['meta']['_upsell_ids']);
            unset($row['meta']['_regular_price']);
            unset($row['meta']['_sale_price']);
            unset($row['meta']['_price']);
            unset($row['meta']['_weight']);
            unset($row['meta']['_length']);
            unset($row['meta']['_width']);
            unset($row['meta']['_height']);
            unset($row['meta']['_crosssell_ids']);
            unset($row['meta']['_thumbnail_id']);
            unset($row['meta']['_purchase_note']);
            unset($row['meta']['_product_image_gallery']);
            unset($row['meta']['_wc_average_rating']);
            unset($row['meta']['_wc_review_count']);
            unset($row['meta']['_wc_rating_count']);
            unset($row['meta']['_product_attributes']);
//            unset($row['meta']);
            unset($row['images']);
            unset($row['taxonomies']);
        }
    }

    private function processProductsTaxonomies(&$taxonomies, $pID)
    {
        foreach ($taxonomies as &$taxonomy) {
            if ($taxonomy['taxonomy'] == 'product_cat') {
                $this->categoriesWC[$pID][] = $taxonomy;
            } else if ($taxonomy['taxonomy'] == 'product_tag') {
                $this->tagsWC[$pID][] = $taxonomy;
            } else if (strpos($taxonomy['taxonomy'], 'pa_') === 0) {
                $this->attributeWC[$pID][$taxonomy['taxonomy']]['value'] = $taxonomy['name'];
                $this->attributeWC[$pID][$taxonomy['taxonomy']]['name'] = $this->getWCAttributeLabel(substr($taxonomy['taxonomy'], 3));
            }
        }
    }

#endregion

#region INSERT_DATA

#endregion

#region SHOW_RESULT

    private function getImportedProducts()
    {
        $sql = '
SELECT 
product_id,
date_added,
date_modified,
sort_order,
status,
tax_class_id,
p.*
FROM oc_product p
WHERE product_id = 51
';
        $stmt = $this->pdoOC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $prefix = 'oc_product_';
        foreach ($rows as $row) {
//            $row['manufacturer'] = $this->getIPTables($row['manufacturer_id'], 'oc_manufacturer', '*', 't.manufacturer_id = $id');
            $row['_review'] = $this->getIPTables($row['product_id'], 'oc_review');
            $row['_attribute'] = $this->getIPTables($row['product_id'], $prefix . 'attribute');
            $row['_description'] = $this->getIPTables($row['product_id'], $prefix . 'description');
            $row['_discount'] = $this->getIPTables($row['product_id'], $prefix . 'discount');
//            $row['_filter'] = $this->getIPTables($row['product_id'], $prefix . 'filter');
            $row['_image'] = $this->getIPTables($row['product_id'], $prefix . 'image');
//            $row['_option'] = $this->getIPTables($row['product_id'], $prefix . 'option');
//            $row['_option_value'] = $this->getIPTables($row['product_id'], $prefix . 'option_value');
//            $row['_recurring'] = $this->getIPTables($row['product_id'], $prefix . 'recurring');
            $row['_related'] = $this->getIPTables($row['product_id'], $prefix . 'related');
//            $row['_reward'] = $this->getIPTables($row['product_id'], $prefix . 'reward');
            $row['_special'] = $this->getIPTables($row['product_id'], $prefix . 'special');
            $row['_to_category'] = $this->getIPTables($row['product_id'], $prefix . 'to_category');
//            $row['_to_download'] = $this->getIPTables($row['product_id'], $prefix . 'to_download');
            $row['_to_layout'] = $this->getIPTables($row['product_id'], $prefix . 'to_layout');
            $row['_to_store'] = $this->getIPTables($row['product_id'], $prefix . 'to_store');
            $this->getImportedCategories($row['product_id']);

            unset($row['upc']);
            unset($row['ean']);
            unset($row['jan']);
            unset($row['isbn']);
            unset($row['mpn']);
            unset($row['location']);
            unset($row['manufacturer_id']);
            unset($row['shipping']);
            unset($row['points']);
            unset($row['subtract']);
            unset($row['minimum']);
            unset($row['viewed']);

            $this->productsOC[$row['product_id']] = $row;
        }
    }

    private function getIPTables($id, $table, $select = '*', $where = '')
    {
        $where = empty($where)
            ? "t.product_id = " . $id
            : str_replace('$id', $id, $where);

        $sql = "
SELECT $select
FROM $table t
WHERE $where
";
        $stmt = $this->pdoOC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return false;
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getImportedCategories($id, $isProductId = true)
    {
        $fromWhere = $isProductId
            ? "FROM oc_category c, oc_product_to_category ptc
WHERE ptc.product_id = $id
AND c.category_id = ptc.category_id"
            : "FROM oc_category c
WHERE c.category_id = $id";
        $sql = "
SELECT 
c.*
$fromWhere
";
        $stmt = $this->pdoOC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if ($row['parent_id'] != 0) {
                $rows[] = $this->getImportedCategories($row['parent_id'], false);
            }
        }
        if (!$isProductId) {
            return $rows[0];
        }
        $this->categoriesOC[$id] = $rows;
    }

    public function listAll($isCLI, $show = 'a')
    {
        #region WC
        echo $isCLI
            ? "products WC\n" . str_repeat("_", 10) . "\n"
            : "<div style='width: 49%; display: inline-block; margin-right: 2%;'><details " . (strpos($show, 'p') !== false || strpos($show, 'a') !== false ? 'open' : '') . "><summary>products WC</summary><hr/><pre style='white-space: pre-wrap;word-wrap: break-word;'>";
        print_r($this->productsWC);
        echo $isCLI
            ? "\n"
            : "</pre></details>";


        echo $isCLI
            ? "categories WC\n" . str_repeat("_", 10) . "\n"
            : "<details " . (strpos($show, 'c') !== false || strpos($show, 'a') !== false ? 'open' : '') . "><summary>categories WC</summary><hr/><pre style='white-space: pre-wrap;word-wrap: break-word;'>";
        print_r($this->categoriesWC);
        echo $isCLI
            ? "\n"
            : "</pre></details>";


        echo $isCLI
            ? "tags WC\n" . str_repeat("_", 10) . "\n"
            : "<details " . (strpos($show, 't') !== false || strpos($show, 'a') !== false ? 'open' : '') . "><summary>tags WC</summary><hr/><pre style='white-space: pre-wrap;word-wrap: break-word;'>";
        print_r($this->tagsWC);
        echo $isCLI
            ? "\n"
            : "</pre></details>";


        echo $isCLI
            ? "attribute WC\n" . str_repeat("_", 10) . "\n"
            : "<details " . (strpos($show, 'm') !== false || strpos($show, 'a') !== false ? 'open' : '') . "><summary>attribute WC</summary><hr/><pre style='white-space: pre-wrap;word-wrap: break-word;'>";
        print_r($this->attributeWC);
        echo $isCLI
            ? "\n"
            : "</pre></details></div>";
        #endregion

        #region OC
        echo $isCLI
            ? "products OC\n" . str_repeat("_", 10) . "\n"
            : "<div style='width: 49%; display: inline-block;float:right;'><details " . (strpos($show, 'p') !== false || strpos($show, 'a') !== false ? 'open' : '') . "><summary>products OC</summary><hr/><pre style='white-space: pre-wrap;word-wrap: break-word;'>";
        print_r($this->productsOC);
        echo $isCLI
            ? "\n"
            : "</pre></details>";


        echo $isCLI
            ? "categories OC\n" . str_repeat("_", 10) . "\n"
            : "<details " . (strpos($show, 'c') !== false || strpos($show, 'a') !== false ? 'open' : '') . "><summary>categories OC</summary><hr/><pre style='white-space: pre-wrap;word-wrap: break-word;'>";
        print_r($this->categoriesOC);
        echo $isCLI
            ? "\n"
            : "</pre></details>";


        echo $isCLI
            ? "tags OC\n" . str_repeat("_", 10) . "\n"
            : "<details " . (strpos($show, 't') !== false || strpos($show, 'a') !== false ? 'open' : '') . "><summary>tags OC</summary><hr/><pre style='white-space: pre-wrap;word-wrap: break-word;'>";
        print_r($this->tagsOC);
        echo $isCLI
            ? "\n"
            : "</pre></details>";


        echo $isCLI
            ? "attribute OC\n" . str_repeat("_", 10) . "\n"
            : "<details " . (strpos($show, 'm') !== false || strpos($show, 'a') !== false ? 'open' : '') . "><summary>attribute OC</summary><hr/><pre style='white-space: pre-wrap;word-wrap: break-word;'>";
        print_r($this->attributeOC);
        echo $isCLI
            ? "\n"
            : "</pre></details></div>";
        #endregion
    }
#endregion

}



