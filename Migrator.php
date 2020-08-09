<?php

class Migrator
{
    private PDO $pdoWC;
    private PDO $pdoOC;

    private array $productsWC = [];
    private array $categoriesWC = [];
    private array $attributeWC = [];
    private array $reviewWC = [];

    private array $productsOC = [];
    private array $categoriesOC = [];
    private array $attributeOC = [];
    private array $reviewOC = [];

    private array $importedIds = [];

    private string $manufacturerName = "Migrated from WooCommerce";
    private string $attributeGroupName = "Attributes";
    private string $reviewAuthorName = "Reviewer";
    private int $layoutId = 0;
    private int $storeId = 0;
    private int $defaultLanguage = 1;
    private int $reviewUserId = 0;

    public function migrate($credentials)
    {
        list($this->pdoWC, $this->pdoOC) = DB::initialize($credentials['wc'], $credentials['oc']);
        // Get
        $this->getProducts();

        // Process
        $this->processProducts();

        // Insert
        $this->InsertProducts();

        // Check
        $this->getImportedProducts();
    }

#region GET_DATA

    private function getProducts()
    {
        $sql = '
SELECT 
p.ID as product_id, 
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
            $row['meta'] = $this->getProductMeta($row['product_id']);
            $row['review'] = $this->getProductReview($row['product_id']);
            $row['taxonomies'] = $this->getTaxonomies($row['product_id']);
            $row['images'] = $this->getImages($row['product_id']);
            $this->productsWC[$row['product_id']] = $row;
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
        foreach ($rows as $k => $row) {
            $rows[$row['review_id']] = $row;
            $rows[$row['review_id']] += $this->getReviewMeta($row['review_id']);
            unset($rows[$k]);
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

    private function getImages($id, $isImageId = false)
    {
        $where = $isImageId
            ? "$id = p.ID"
            : "$id = p.post_parent";
        $sql = "
SELECT p.ID,
       REPLACE(p.guid, CONCAT((SELECT option_value FROM wp_options WHERE option_name = 'home'), '/'), '') as guid
FROM wp_posts p
WHERE $where
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
            $row['width'] = $row['meta']['_width'];
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
            $row['description/tags'] = $this->processProductsTaxonomies($row['taxonomies'], $rowId);
            $this->reviewWC[$rowId] = $row['review'];
            $this->processProductsAttributes($rowId);


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
            unset($row['meta']);
            unset($row['images']);
            unset($row['taxonomies']);
            unset($row['review']);
        }
    }

    private function processProductsTaxonomies($taxonomies, $pID)
    {
        $tags = [];
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy['taxonomy'] == 'product_cat') {
                unset($taxonomy['term_taxonomy_id']);
                unset($taxonomy['taxonomy']);
                unset($taxonomy['description']);
                unset($taxonomy['count']);
                unset($taxonomy['term_group']);
                unset($taxonomy['/meta/order']);
                unset($taxonomy['/meta/display_type']);
                unset($taxonomy['/meta/product_count_product_cat']);
                if (isset($taxonomy['/meta/thumbnail_id'])) {
                    $taxonomy['image'] = $this->getImages($taxonomy['/meta/thumbnail_id'], true)[$taxonomy['/meta/thumbnail_id']];
                }
                unset($taxonomy['/meta/thumbnail_id']);
                unset($taxonomy['slug']);
                $this->categoriesWC[$pID][$taxonomy['term_id']] = $taxonomy;
            } else if ($taxonomy['taxonomy'] == 'product_tag') {
                $tags[] = $taxonomy['name'];
            } else if (strpos($taxonomy['taxonomy'], 'pa_') === 0) {
                $this->attributeWC[$pID][$taxonomy['taxonomy']]['value'] = $taxonomy['name'];
                $this->attributeWC[$pID][$taxonomy['taxonomy']]['name'] = $this->getWCAttributeLabel(substr($taxonomy['taxonomy'], 3));
            }
        }
        return implode(', ', $tags);
    }

    private function processProductsAttributes($pID)
    {
        $i = 0;
        foreach ($this->attributeWC[$pID] as $k => $attribute) {
            $attribute['ad_name'] = $attribute['name'];
            $attribute['pa_text'] = $attribute['value'];

            unset($attribute['name']);
            unset($attribute['value']);
            unset($attribute['position']);
            unset($attribute['is_visible']);
            unset($attribute['is_variation']);
            unset($attribute['is_taxonomy']);

            unset($this->attributeWC[$pID][$k]);
            $this->attributeWC[$pID][$i] = $attribute;
            $i++;
        }
    }

    private function getCategoryPaths($pID, $id, $currentId = null)
    {
        $parent_id = $this->categoriesWC[$pID][$id]['parent'];
        if ($parent_id == 0) {
            return [
                [
                    $currentId
                        ? $this->importedIds['categories'][$pID][$currentId]
                        : $this->importedIds['categories'][$pID][$id],
                    $this->importedIds['categories'][$pID][$id],
                    0
                ]
            ];
        } else {
            $parentPaths = $this->getCategoryPaths($pID, $parent_id, $id);
            array_unshift($parentPaths,
                [
                    $currentId
                        ? $this->importedIds['categories'][$pID][$currentId]
                        : $this->importedIds['categories'][$pID][$id],
                    $this->importedIds['categories'][$pID][$id],
                    $parentPaths[0][2] + 1
                ]
            );
            return $parentPaths;
        }
    }

    // TODO find discount info

#endregion

#region INSERT_DATA

    private function InsertManufacturerIfNotExists()
    {
// get from db if exists
        $sql = "
SELECT manufacturer_id
FROM oc_manufacturer
WHERE name = '$this->manufacturerName'
LIMIT 1
;
";
        $stmt = $this->pdoOC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return;
        }
        $id = $stmt->fetchAll(PDO::FETCH_COLUMN)[0];
        if (!isset($id) || !is_numeric($id)) {
            // Insert new one
            $sql = '
INSERT INTO oc_manufacturer (
sort_order,
name
)
VALUES (
0,
\'' . $this->manufacturerName . '\'
);
';
            $stmt = $this->pdoOC->query($sql);
            if (!$stmt) {
                print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                return;
            }

            $id = $this->pdoOC->lastInsertId();


            $sql = 'INSERT INTO oc_manufacturer_to_store VALUES (' . $id . ',' . $this->storeId . ');';
            $stmt = $this->pdoOC->query($sql);
            if (!$stmt) {
                print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                return;
            }
        }
        // save
        $this->importedIds['manufacturer'] = $id;
    }

    private function InsertProducts()
    {
//        foreach ($this->productsWC as $product) {
//            $pID = $product['product_id'];
        $pID = 11;
        $this->InsertManufacturerIfNotExists();
        $this->InsertAttributesGroupIfNotExists();
        $this->InsertProductAttributesIfNotExists($pID);
        $this->InsertProductCategoriesIfNotExists($pID);
        $this->InsertIntoProduct_Tables($pID);
        $this->InsertIntoProduct_to_Tables($pID);

        $sql = '
INSERT INTO oc_product (
isbn,
model,
stock_status_id,
manufacturer_id,
mpn,
sku,
location,
date_added,
upc,
ean,
jan,
tax_class_id,
date_modified,

sort_order,
status,
quantity,
image,
price,
date_available,
weight,
weight_class_id,
length,
width,
height,
length_class_id
)
VALUES (
\'\',
\'' . $this->productsWC[$pID]['description/name'] . '\',
6,
\'' . $this->importedIds['manufacturer'] . '\', 
\'\',
\'' . $this->productsWC[$pID]['sku'] . '\',
\'\',
\'' . $this->productsWC[$pID]['date_added'] . '\',
\'\',
\'\',
\'\',
\'' . $this->productsWC[$pID]['tax_class_id'] . '\',
\'' . $this->productsWC[$pID]['date_modified'] . '\',

\'' . $this->productsWC[$pID]['sort_order'] . '\',
\'' . $this->productsWC[$pID]['status'] . '\',
\'' . $this->productsWC[$pID]['quantity'] . '\',
\'' . $this->productsWC[$pID]['image'] . '\',
\'' . $this->productsWC[$pID]['price'] . '\',
\'' . $this->productsWC[$pID]['date_added'] . '\',
\'' . $this->productsWC[$pID]['weight'] . '\',
\'' . $this->productsWC[$pID]['weight_class_id'] . '\',
\'' . $this->productsWC[$pID]['length'] . '\',
\'' . $this->productsWC[$pID]['width'] . '\',
\'' . $this->productsWC[$pID]['height'] . '\',
\'' . $this->productsWC[$pID]['length_class_id'] . '\'
);
';
        $stmt = $this->pdoOC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return;
        }

        $this->importedIds['products'][$pID] = $this->pdoOC->lastInsertId();

        $this->InsertProductReviews($pID);
//        }
    }

    private function InsertAttributesGroupIfNotExists()
    {
// get from db if exists
        $sql = "
SELECT attribute_group_id
FROM oc_attribute_group_description
WHERE name = '$this->attributeGroupName'
LIMIT 1
;
";
        $stmt = $this->pdoOC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return;
        }
        $id = $stmt->fetchAll(PDO::FETCH_COLUMN)[0];
        if (!isset($id) || !is_numeric($id)) {
            // Insert new one
            $sql = '
INSERT INTO oc_attribute_group (sort_order) VALUES (0);
';
            $stmt = $this->pdoOC->query($sql);
            if (!$stmt) {
                print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                return;
            }

            $id = $this->pdoOC->lastInsertId();


            $sql = '
INSERT INTO oc_attribute_group_description 
VALUES (
' . $id . ',
' . $this->defaultLanguage . ',
\'' . $this->attributeGroupName . '\'
);
';
            $stmt = $this->pdoOC->query($sql);
            if (!$stmt) {
                print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                return;
            }
        }
        // save
        $this->importedIds['attribute_group'] = $id;
    }

    private function InsertProductAttributesIfNotExists($pID)
    {
        foreach ($this->attributeWC[$pID] as $attribute) {
// get from db if exists
            $sql = "
SELECT attribute_id
FROM oc_attribute_description
WHERE name = '" . $attribute['ad_name'] . "'
LIMIT 1
;
";
            $stmt = $this->pdoOC->query($sql);
            if (!$stmt) {
                print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                return;
            }
            $id = $stmt->fetchAll(PDO::FETCH_COLUMN)[0];

            if (!isset($id) || !is_numeric($id)) {
                // Insert new one
                $sql = '
INSERT INTO oc_attribute (attribute_group_id, sort_order) VALUES (' . $this->importedIds['attribute_group'] . ',0);
';
                $stmt = $this->pdoOC->query($sql);
                if (!$stmt) {
                    print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                    return;
                }

                $id = $this->pdoOC->lastInsertId();


                $sql = '
INSERT INTO oc_attribute_description 
VALUES (
' . $id . ',
' . $this->defaultLanguage . ',
\'' . $attribute['ad_name'] . '\'
);
';
                $stmt = $this->pdoOC->query($sql);
                if (!$stmt) {
                    print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                    return;
                }
            }
            // save
            $this->importedIds['attributes'][$pID][] = $id;
        }
    }

    private function InsertProductCategoriesIfNotExists($pID)
    {
        foreach (array_reverse($this->categoriesWC[$pID]) as $category) {
            $newCategory = false;
// get from db if exists
            $sql = "
SELECT category_id
FROM oc_category_description
WHERE name = '" . $category['name'] . "'
LIMIT 1
;
";
            $stmt = $this->pdoOC->query($sql);
            if (!$stmt) {
                print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                return;
            }
            $id = $stmt->fetchAll(PDO::FETCH_COLUMN)[0];

            if (!isset($id) || !is_numeric($id)) {
                $newCategory = true;
                // Insert new one
                $parent_id = $category['parent'] == 0
                    ? 0
                    : $this->importedIds['categories'][$pID][$category['parent']];
                $sql = '
INSERT INTO oc_category (
status
, date_added
, date_modified
, `column`
, top

, image
, parent_id
) 
VALUES (
1
, \'' . date('Y-m-d H:i:s') . '\'
, \'' . date('Y-m-d H:i:s') . '\'
, 0
, 0

, \'' . $category['image'] . '\'
, ' . $parent_id . '
);
';
                $stmt = $this->pdoOC->query($sql);
                if (!$stmt) {
                    print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                    return;
                }

                $id = $this->pdoOC->lastInsertId();

                $sql = '
INSERT INTO oc_category_description 
VALUES (
' . $id . ',
' . $this->defaultLanguage . ',
\'' . $category['name'] . '\',
\'\',
\'' . $category['name'] . '\',
\'\',
\'\'
);
';
                $stmt = $this->pdoOC->query($sql);
                if (!$stmt) {
                    print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                    return;
                }
            }
            // save
            $this->importedIds['categories'][$pID][$category['term_id']] = $id;
            if ($newCategory) {
                // add paths
                $categoryPaths = $this->getCategoryPaths($pID, $category['term_id']);
                $valuesArr = [];
                foreach ($categoryPaths as $categoryPath) {
                    $valuesArr[] = '(' . implode(', ', $categoryPath) . ')';
                }
                $values = implode(',', $valuesArr);
                $sql = "
INSERT INTO oc_category_path 
VALUES $values;
";
                $stmt = $this->pdoOC->query($sql);
                if (!$stmt) {
                    print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                    return;
                }

                // add to store
                $sql = '
INSERT INTO oc_category_to_store 
VALUES (
' . $id . ',
' . $this->storeId . '
);
';
                $stmt = $this->pdoOC->query($sql);
                if (!$stmt) {
                    print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                    return;
                }


                // add to layout
                $sql = '
INSERT INTO oc_category_to_layout 
VALUES (
' . $id . ',
' . $this->storeId . ',
' . $this->layoutId . '
);
';
                $stmt = $this->pdoOC->query($sql);
                if (!$stmt) {
                    print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                    return;
                }
            }
        }
    }

    private function InsertProductReviews($pID)
    {
        foreach ($this->reviewWC[$pID] as $review) {
            $sql = '
INSERT INTO oc_review (
author, rating, date_added, date_modified, product_id, text, customer_id, status
)
VALUES (
\'' . $this->reviewAuthorName . '\',
\'' . $review['rating'] . '\',
\'' . date('Y-m-d H:i:s') . '\',
\'' . date('Y-m-d H:i:s') . '\',
\'' . $this->importedIds['products'][$pID] . '\',
\'' . $review['text'] . '\',
\'' . $this->reviewUserId . '\',
\'' . 1 . '\'
);
';
            echo $sql;
            $stmt = $this->pdoOC->query($sql);
            if (!$stmt) {
                print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
                return;
            }
            // save
            $this->importedIds['reviews'][$pID][$review['review_id']] = $this->pdoOC->lastInsertId();
        }
    }

    private function InsertIntoProduct_to_Tables($pID)
    {
        return $pID;
    }

    private function InsertIntoProduct_Tables($pID)
    {
        return $pID;
    }



// DONE Product
// TODO Product_attribute
// TODO Product_description
// TODO Product_image
// TODO Product_related
// TODO Product_to_category
// TODO Product_to_layout
// TODO Product_to_store
//
// DONE review
//
// DONE Catgeory
// DONE Catgeory_description
// DONE Catgeory_path
// DONE Catgeory_to_layout
// DONE Catgeory_to_store
//
// DONE Attribute
// DONE Attribute_description
// DONE Attribute_group
// DONE Attribute_group_description
//
// DONE Manufacturer
// DONE Manufacturer_to_store

// TODO Move images
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
WHERE product_id >= 51
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
//            $row['_attribute'] = $this->getIPTables($row['product_id'], $prefix . 'attribute');
            $row['_description'] = $this->getIPTables($row['product_id'], $prefix . 'description');
//            $row['_discount'] = $this->getIPTables($row['product_id'], $prefix . 'discount');
//            $row['_filter'] = $this->getIPTables($row['product_id'], $prefix . 'filter');
            $row['_image'] = $this->getIPTables($row['product_id'], $prefix . 'image');
//            $row['_option'] = $this->getIPTables($row['product_id'], $prefix . 'option');
//            $row['_option_value'] = $this->getIPTables($row['product_id'], $prefix . 'option_value');
//            $row['_recurring'] = $this->getIPTables($row['product_id'], $prefix . 'recurring');
            $row['_related'] = $this->getIPTables($row['product_id'], $prefix . 'related');
//            $row['_reward'] = $this->getIPTables($row['product_id'], $prefix . 'reward');
//            $row['_special'] = $this->getIPTables($row['product_id'], $prefix . 'special');
            $row['_to_category'] = $this->getIPTables($row['product_id'], $prefix . 'to_category');
//            $row['_to_download'] = $this->getIPTables($row['product_id'], $prefix . 'to_download');
            $row['_to_layout'] = $this->getIPTables($row['product_id'], $prefix . 'to_layout');
            $row['_to_store'] = $this->getIPTables($row['product_id'], $prefix . 'to_store');
            $this->getImportedCategories($row['product_id']);
            $this->getImportedReviews($row['product_id']);
            $this->getImportedAttributes($row['product_id']);

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

    private function getImportedReviews($id)
    {
        $sql = "
SELECT 
*
FROM oc_review r
WHERE product_id = $id
";
        $stmt = $this->pdoOC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


        $this->reviewOC[$id] = $rows;
    }

    private function getImportedAttributes($id)
    {
        $sql = "
SELECT 
a.attribute_id
,a.attribute_group_id
,ad.name as ad_name
,agd.name as adg_name
,pa.text as pa_text 
FROM
oc_attribute a
,oc_attribute_description ad
,oc_attribute_group ag
,oc_attribute_group_description agd
,oc_product_attribute pa
WHERE a.attribute_id = ad.attribute_id
  AND a.attribute_group_id = agd.attribute_group_id
  AND a.attribute_id = pa.attribute_id
  AND a.attribute_group_id = ag.attribute_group_id
  AND pa.product_id = $id
";
        $stmt = $this->pdoOC->query($sql);
        if (!$stmt) {
            print "Error occurred. Around line " . __LINE__ . " in " . __FUNCTION__ . " in " . __FILE__ . "\n";
            return;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

//        foreach ($rows as $row) {
//            if ($row['parent_id'] != 0) {
//                $rows[] = $this->getImportedCategories($row['parent_id'], false);
//            }
//        }
//        if (!$isProductId) {
//            return $rows[0];
//        }
        $this->attributeOC[$id] = $rows;
    }

    public function listAll($isCLI, $show = 'a')
    {

        echo $isCLI
            ? "Imported IDs\n" . str_repeat("_", 10) . "\n"
            : "<div style='font-size: 10px;'><pre style='white-space: pre-wrap;word-wrap: break-word;'>";
        print_r($this->importedIds);
        echo $isCLI
            ? "\n"
            : "</pre></div>";

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
            ? "review WC\n" . str_repeat("_", 10) . "\n"
            : "<details " . (strpos($show, 'r') !== false || strpos($show, 'a') !== false ? 'open' : '') . "><summary>review WC</summary><hr/><pre style='white-space: pre-wrap;word-wrap: break-word;'>";
        print_r($this->reviewWC);
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
            ? "review OC\n" . str_repeat("_", 10) . "\n"
            : "<details " . (strpos($show, 'r') !== false || strpos($show, 'a') !== false ? 'open' : '') . "><summary>review OC</summary><hr/><pre style='white-space: pre-wrap;word-wrap: break-word;'>";
        print_r($this->reviewOC);
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


// TODO table prefix
// TODO phpDoc
// TODO Refactor
}



