<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class KbizMigrationProductImporter
{
    private const ENTITY = 'products';
    private const BATCH_SIZE = 5;
    private const MAX_IMAGE_BYTES = 10485760;

    private $client;
    private $sourceKey;
    private $languages;
    private $sourceLanguages;
    private $shopId;

    public function __construct(KbizMigrationSourceWebserviceClient $client, $sourceUrl)
    {
        $this->client = $client;
        $this->sourceKey = hash('sha256', rtrim($sourceUrl, '/'));
        $this->languages = Language::getLanguages(false);
        $this->shopId = (int) Context::getContext()->shop->id;
        $this->sourceLanguages = $this->loadSourceLanguages();
    }

    public function runNextBatch()
    {
        if (!KbizMigrationCategoryImporter::installDatabase()) {
            throw new RuntimeException('Could not prepare the migration progress tables.');
        }
        $state = $this->getState();
        $totalCount = (int) $state['total_count'];
        if ($totalCount === 0) {
            $totalCount = $this->client->countResource('products');
            $this->saveTotalCount($totalCount);
        }
        $cursor = (int) $state['last_source_id'];
        if ($totalCount === 0) {
            $this->saveState(0, true);
            return [
                'done' => true,
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
            ];
        }
        if ($state['is_complete']) {
            return [
                'done' => true,
                'processed' => 0,
                'imported' => $this->countMappings('imported'),
                'skipped' => $this->countMappings('skipped'),
            ];
        }

        $response = $this->client->getResource('products', [
            'display' => 'full',
            'sort' => '[id_ASC]',
            'filter' => ['id' => '>[' . $cursor . ']'],
            'limit' => '0,' . (self::BATCH_SIZE + 1),
        ]);
        $items = self::extractProductItems($response);
        if (!$items && $cursor === 0) {
            throw new RuntimeException(
                'The source products endpoint returned no products. Check that the source API key has GET permission for products.'
            );
        }

        $hasMore = count($items) > self::BATCH_SIZE;
        if ($hasMore) {
            $items = array_slice($items, 0, self::BATCH_SIZE);
        }

        $processed = 0;
        foreach ($items as $item) {
            $sourceId = $this->getSourceId($item);
            if ($sourceId <= $cursor) {
                throw new RuntimeException('The source product list is not ordered by increasing ID.');
            }

            $detail = $this->loadSourceProduct($sourceId);
            $mapping = $this->getMapping(self::ENTITY, $sourceId);
            if ($mapping && $mapping['status'] === 'skipped') {
                $cursor = $sourceId;
                ++$processed;
                continue;
            }

            if (!$mapping) {
                $reference = isset($detail->reference) ? trim((string) $detail->reference) : '';
                $existingId = $reference !== '' ? $this->findDestinationProductByReference($reference) : 0;
                if ($existingId > 0) {
                    $this->saveMapping(self::ENTITY, $sourceId, $existingId, 'skipped');
                    $cursor = $sourceId;
                    ++$processed;
                    continue;
                }
            }

            $this->importProduct($sourceId, $detail, $mapping);
            $cursor = $sourceId;
            ++$processed;
        }

        $done = !$hasMore;
        $this->saveState($cursor, $done);

        return [
            'done' => $done,
            'processed' => $processed,
            'imported' => $this->countMappings('imported'),
            'skipped' => $this->countMappings('skipped'),
        ];
    }

    public static function extractProductItems(SimpleXMLElement $response)
    {
        $items = [];
        if (isset($response->products->product)) {
            foreach ($response->products->product as $product) {
                $items[] = $product;
            }
        }

        return $items;
    }

    public function getProgress()
    {
        if (!KbizMigrationCategoryImporter::installDatabase()) {
            throw new RuntimeException('Could not prepare the migration progress tables.');
        }
        $state = $this->getState();
        $totalCount = (int) $state['total_count'];
        if ($totalCount === 0) {
            $totalCount = $this->client->countResource('products');
            $this->saveTotalCount($totalCount);
        }
        $imported = $this->countMappings('imported');
        $skipped = $this->countMappings('skipped');
        $processed = min($totalCount, $imported + $skipped);

        return [
            'last_source_id' => (int) $state['last_source_id'],
            'done' => (bool) $state['is_complete'] && $processed >= $totalCount,
            'imported' => $imported,
            'skipped' => $skipped,
            'total' => $totalCount,
            'processed' => $processed,
            'remaining' => max(0, $totalCount - $processed),
        ];
    }

    public static function getSavedProgress($sourceUrl)
    {
        $sourceKey = hash('sha256', rtrim($sourceUrl, '/'));
        $state = Db::getInstance()->getRow(
            'SELECT `last_source_id`, `is_complete` FROM `' . _DB_PREFIX_ . 'kbizmigration_state`
            WHERE `source_key` = \'' . pSQL($sourceKey) . '\'
                AND `entity` = \'' . self::ENTITY . '\''
        );
        if (!$state) {
            return null;
        }

        $counts = [];
        foreach (['imported', 'skipped'] as $status) {
            $counts[$status] = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'kbizmigration_map`
                WHERE `source_key` = \'' . pSQL($sourceKey) . '\'
                    AND `entity` = \'' . self::ENTITY . '\'
                    AND `status` = \'' . pSQL($status) . '\''
            );
        }

        return [
            'last_source_id' => (int) $state['last_source_id'],
            'done' => (bool) $state['is_complete'],
            'imported' => $counts['imported'],
            'skipped' => $counts['skipped'],
        ];
    }

    private function importProduct($sourceId, $item, $mapping)
    {
        if ($mapping && $mapping['status'] === 'imported') {
            $destinationId = (int) $mapping['destination_id'];
            $product = new Product($destinationId);
            if (!Validate::isLoadedObject($product)) {
                $product = new Product();
                $product->id_shop_list = [$this->shopId];
                $product->id_shop_default = $this->shopId;
            }
        } else {
            $product = new Product();
            $product->id_shop_list = [$this->shopId];
            $product->id_shop_default = $this->shopId;
        }

        $this->setProductFields($product, $item);
        if (Validate::isLoadedObject($product)) {
            if (!$product->associateTo($this->shopId) || !$product->update()) {
                throw new RuntimeException('Could not update destination product for source product ' . (int) $sourceId . '.');
            }
        } elseif (!$product->add() || !(int) $product->id) {
            throw new RuntimeException(
                'PrestaShop could not create source product ' . (int) $sourceId . '. Check product validation and shop configuration.'
            );
        }

        $destinationId = (int) $product->id;
        if ($mapping && $mapping['status'] === 'imported') {
            $this->updateMappingDestination(self::ENTITY, $sourceId, $destinationId);
        } else {
            $this->saveMapping(self::ENTITY, $sourceId, $destinationId, 'imported');
        }

        $categoryIds = $this->mapProductCategories($item);
        $sourceDefaultCategoryId = isset($item->id_category_default) ? (int) $item->id_category_default : 0;
        $defaultCategoryMapping = $sourceDefaultCategoryId > 0
            ? $this->getMapping('categories', $sourceDefaultCategoryId)
            : null;
        $defaultCategoryId = $defaultCategoryMapping ? (int) $defaultCategoryMapping['destination_id'] : 0;
        if (!$defaultCategoryId || !in_array($defaultCategoryId, $categoryIds, true)) {
            $defaultCategoryId = (int) reset($categoryIds);
        }
        if ((int) $product->id_category_default !== $defaultCategoryId) {
            $product->id_category_default = $defaultCategoryId;
            if (!$product->update()) {
                throw new RuntimeException('Could not save the default category for destination product ' . $destinationId . '.');
            }
        }
        if (!$product->updateCategories($categoryIds)) {
            throw new RuntimeException('Could not associate source categories with destination product ' . $destinationId . '.');
        }

        $sourceCombinations = $this->sourceCombinationIds($item);
        $stockByCombination = $this->loadSourceStock($item);
        if ($sourceCombinations) {
            $product->product_type = \PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_COMBINATIONS;
            if (!$product->update()) {
                throw new RuntimeException('Could not set the combination product type for destination product ' . $destinationId . '.');
            }
            $this->importCombinations($product, $sourceCombinations, $stockByCombination);
        } else {
            StockAvailable::setQuantity(
                $destinationId,
                0,
                $this->sourceQuantity($stockByCombination, 0),
                $this->shopId,
                false
            );
        }

        $this->importImages($product, $sourceId, $item);
    }

    private function setProductFields(Product $product, $item)
    {
        $nameValues = $this->readTranslations(isset($item->name) ? $item->name : null);
        if (!$nameValues) {
            throw new RuntimeException('The source product has no translatable name.');
        }

        $names = $this->mapTranslations($nameValues, true);
        $product->name = $names;
        $product->link_rewrite = $this->mapTranslations(
            $this->readTranslations(isset($item->link_rewrite) ? $item->link_rewrite : null),
            false,
            $names,
            true
        );
        $product->description = $this->mapTranslations(
            $this->readTranslations(isset($item->description) ? $item->description : null),
            false
        );
        $product->description_short = $this->mapTranslations(
            $this->readTranslations(isset($item->description_short) ? $item->description_short : null),
            false
        );
        $product->reference = isset($item->reference) ? trim((string) $item->reference) : '';
        $product->price = $this->readDecimal(isset($item->price) ? (string) $item->price : '0');
        $product->wholesale_price = $this->readDecimal(isset($item->wholesale_price) ? (string) $item->wholesale_price : '0');
        $product->weight = $this->readDecimal(isset($item->weight) ? (string) $item->weight : '0');
        $product->width = $this->readDecimal(isset($item->width) ? (string) $item->width : '0');
        $product->height = $this->readDecimal(isset($item->height) ? (string) $item->height : '0');
        $product->depth = $this->readDecimal(isset($item->depth) ? (string) $item->depth : '0');
        $product->ean13 = isset($item->ean13) ? (string) $item->ean13 : '';
        $product->isbn = isset($item->isbn) ? (string) $item->isbn : '';
        $product->upc = isset($item->upc) ? (string) $item->upc : '';
        $product->mpn = isset($item->mpn) ? (string) $item->mpn : '';
        $product->active = isset($item->active) ? (int) $item->active : 1;
        $product->available_for_order = isset($item->available_for_order) ? (int) $item->available_for_order : 1;
        $product->show_price = isset($item->show_price) ? (int) $item->show_price : 1;
        $product->visibility = $this->allowedValue(
            isset($item->visibility) ? (string) $item->visibility : '',
            ['both', 'catalog', 'search', 'none'],
            'both'
        );
        $product->condition = $this->allowedValue(
            isset($item->condition) ? (string) $item->condition : '',
            ['new', 'used', 'refurbished'],
            'new'
        );
        $product->minimal_quantity = max(1, isset($item->minimal_quantity) ? (int) $item->minimal_quantity : 1);
        $product->id_tax_rules_group = $this->getDefaultTaxRulesGroupId();
        $product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
        $product->product_type = $this->sourceCombinationIds($item)
            ? \PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_COMBINATIONS
            : \PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_STANDARD;
    }

    private function mapProductCategories($item)
    {
        $categoryIds = [];
        if (isset($item->associations->categories->category)) {
            foreach ($item->associations->categories->category as $sourceCategory) {
                $sourceCategoryId = $this->getSourceId($sourceCategory);
                if ($sourceCategoryId <= 0) {
                    continue;
                }
                $mapping = $this->getMapping('categories', $sourceCategoryId);
                if (!$mapping) {
                    throw new RuntimeException(
                        'Source category ' . $sourceCategoryId . ' has no destination mapping. Complete category import before importing products.'
                    );
                }
                $category = new Category((int) $mapping['destination_id']);
                if (!Validate::isLoadedObject($category)) {
                    throw new RuntimeException(
                        'Destination category for source category ' . $sourceCategoryId . ' is missing. Run the category import stage again.'
                    );
                }
                if (!$category->associateTo($this->shopId)) {
                    throw new RuntimeException('Could not associate destination category ' . (int) $category->id . ' with this shop.');
                }
                $destinationCategoryId = (int) $category->id;
                if ($destinationCategoryId === (int) Configuration::get('PS_ROOT_CATEGORY')) {
                    $destinationCategoryId = (int) Configuration::get('PS_HOME_CATEGORY');
                }
                $categoryIds[] = $destinationCategoryId;
            }
        }

        if (!$categoryIds) {
            $categoryIds[] = (int) Configuration::get('PS_HOME_CATEGORY');
        }

        return array_values(array_unique($categoryIds));
    }

    private function importCombinations(Product $product, array $sourceCombinationIds, array $stockByCombination)
    {
        $sourceDefaultCombination = isset($product->id_default_combination)
            ? (int) $product->id_default_combination
            : 0;
        $firstDestinationCombination = 0;
        $destinationDefaultCombination = 0;
        foreach ($sourceCombinationIds as $sourceCombinationId) {
            $response = $this->client->getResource('combinations/' . (int) $sourceCombinationId);
            if (!isset($response->combination)) {
                throw new RuntimeException('Could not load source combination ' . (int) $sourceCombinationId . '.');
            }
            $item = $response->combination;
            $attributes = $this->mapCombinationAttributes($item);
            if (!$attributes) {
                throw new RuntimeException('Source combination ' . (int) $sourceCombinationId . ' has no option values.');
            }

            $mapping = $this->getMapping('product_combinations', $sourceCombinationId);
            $destinationCombinationId = $mapping ? (int) $mapping['destination_id'] : 0;
            $combination = $destinationCombinationId > 0 ? new Combination($destinationCombinationId) : new Combination();
            if ($destinationCombinationId > 0 && !Validate::isLoadedObject($combination)) {
                $combination = new Combination();
                $destinationCombinationId = 0;
            }

            if ($destinationCombinationId > 0) {
                $combination->id_product = (int) $product->id;
                $this->setCombinationFields($combination, $item, $product);
                if (!$combination->associateTo($this->shopId) || !$combination->update()) {
                    throw new RuntimeException('Could not update destination combination ' . $destinationCombinationId . '.');
                }
                if (!$combination->setAttributes($attributes)) {
                    throw new RuntimeException('Could not update option values for destination combination ' . $destinationCombinationId . '.');
                }
            } else {
                $this->setCombinationFields($combination, $item, $product);
                $combination->id_product = (int) $product->id;
                $combination->id_shop_list = [$this->shopId];
                if (!$combination->add() || !(int) $combination->id) {
                    throw new RuntimeException(
                        'Could not create a destination combination for source combination ' . (int) $sourceCombinationId . '.'
                    );
                }
                $this->saveMapping('product_combinations', $sourceCombinationId, (int) $combination->id, 'imported');
                if (!$combination->setAttributes($attributes)) {
                    throw new RuntimeException('Could not assign option values to destination combination ' . (int) $combination->id . '.');
                }
            }

            $quantity = $this->sourceQuantity($stockByCombination, $sourceCombinationId);
            StockAvailable::setQuantity(
                (int) $product->id,
                (int) $combination->id,
                $quantity,
                $this->shopId,
                false
            );
            if ($firstDestinationCombination === 0) {
                $firstDestinationCombination = (int) $combination->id;
            }
            if (
                (isset($item->default_on) && (int) $item->default_on === 1)
                || ($sourceDefaultCombination > 0 && $sourceDefaultCombination === $sourceCombinationId)
            ) {
                $destinationDefaultCombination = (int) $combination->id;
            }
        }

        if ($destinationDefaultCombination === 0) {
            $destinationDefaultCombination = $firstDestinationCombination;
        }
        if ($destinationDefaultCombination > 0) {
            $default = new Combination($destinationDefaultCombination);
            $default->default_on = true;
            if (!$default->update()) {
                throw new RuntimeException('Could not set the default destination product combination.');
            }
        }
    }

    private function setCombinationFields(Combination $combination, $item, Product $product)
    {
        $combination->id_product = (int) $product->id;
        $combination->reference = isset($item->reference) ? trim((string) $item->reference) : '';
        $combination->ean13 = isset($item->ean13) ? (string) $item->ean13 : '';
        $combination->isbn = isset($item->isbn) ? (string) $item->isbn : '';
        $combination->upc = isset($item->upc) ? (string) $item->upc : '';
        $combination->mpn = isset($item->mpn) ? (string) $item->mpn : '';
        $combination->price = $this->readDecimal(isset($item->price) ? (string) $item->price : '0');
        $combination->wholesale_price = $this->readDecimal(isset($item->wholesale_price) ? (string) $item->wholesale_price : '0');
        $combination->weight = $this->readDecimal(isset($item->weight) ? (string) $item->weight : '0');
        $combination->minimal_quantity = max(1, isset($item->minimal_quantity) ? (int) $item->minimal_quantity : 1);
        $combination->default_on = isset($item->default_on) && (int) $item->default_on === 1;
    }

    private function mapCombinationAttributes($item)
    {
        $attributes = [];
        if (!isset($item->associations->product_option_values->product_option_value)) {
            return $attributes;
        }

        foreach ($item->associations->product_option_values->product_option_value as $sourceValue) {
            $sourceValueId = $this->getSourceId($sourceValue);
            if ($sourceValueId <= 0) {
                continue;
            }
            $response = $this->client->getResource('product_option_values/' . $sourceValueId);
            if (!isset($response->product_option_value)) {
                throw new RuntimeException('Could not load source product option value ' . $sourceValueId . '.');
            }
            $value = $response->product_option_value;
            $groupId = isset($value->id_attribute_group) ? (int) $value->id_attribute_group : 0;
            if ($groupId <= 0) {
                throw new RuntimeException('Source product option value ' . $sourceValueId . ' has no option group.');
            }
            $group = $this->loadSourceOptionGroup($groupId);
            $destinationGroupId = $this->findOrCreateDestinationGroup($group);
            $destinationAttributeId = $this->findOrCreateDestinationAttribute(
                $destinationGroupId,
                $this->readTranslations(isset($value->name) ? $value->name : null),
                isset($value->color) ? (string) $value->color : ''
            );
            $attributes[] = $destinationAttributeId;
        }

        return array_values(array_unique($attributes));
    }

    private function loadSourceOptionGroup($sourceGroupId)
    {
        $response = $this->client->getResource('product_options/' . (int) $sourceGroupId);
        if (!isset($response->product_option)) {
            throw new RuntimeException('Could not load source product option group ' . (int) $sourceGroupId . '.');
        }

        return $response->product_option;
    }

    private function findOrCreateDestinationGroup($sourceGroup)
    {
        $nameValues = $this->readTranslations(isset($sourceGroup->name) ? $sourceGroup->name : null);
        if (!$nameValues) {
            throw new RuntimeException('A source product option group has no name.');
        }
        $names = $this->mapTranslations($nameValues, true);
        $destinationId = $this->findNamedObjectId(
            'attribute_group',
            'id_attribute_group',
            'attribute_group_lang',
            'id_attribute_group',
            $names
        );
        if ($destinationId > 0) {
            return $destinationId;
        }

        $group = new AttributeGroup();
        $group->name = $names;
        $group->public_name = $this->mapTranslations(
            $this->readTranslations(isset($sourceGroup->public_name) ? $sourceGroup->public_name : null),
            true,
            $names
        );
        foreach ($group->public_name as $idLang => $publicName) {
            $group->public_name[$idLang] = Tools::substr($publicName, 0, 64);
        }
        $sourceType = isset($sourceGroup->group_type) ? (string) $sourceGroup->group_type : 'select';
        $group->group_type = in_array($sourceType, ['color', 'select', 'radio'], true) ? $sourceType : 'select';
        $group->is_color_group = $group->group_type === 'color';
        if (!$group->add() || !(int) $group->id) {
            throw new RuntimeException('Could not create destination product option group "' . reset($names) . '".');
        }

        return (int) $group->id;
    }

    private function findOrCreateDestinationAttribute($destinationGroupId, array $nameValues, $color)
    {
        if (!$nameValues) {
            throw new RuntimeException('A source product option value has no name.');
        }
        $names = $this->mapTranslations($nameValues, true);
        $destinationId = $this->findNamedObjectId(
            'attribute',
            'id_attribute',
            'attribute_lang',
            'id_attribute',
            $names,
            'id_attribute_group',
            $destinationGroupId
        );
        if ($destinationId > 0) {
            return $destinationId;
        }

        $attribute = new ProductAttribute();
        $attribute->id_attribute_group = (int) $destinationGroupId;
        $attribute->name = $names;
        $attribute->color = Validate::isColor($color) ? $color : '';
        if (!$attribute->add() || !(int) $attribute->id) {
            throw new RuntimeException('Could not create destination product option value "' . reset($names) . '".');
        }

        return (int) $attribute->id;
    }

    private function findNamedObjectId($table, $primary, $langTable, $langPrimary, array $names, $parentField = null, $parentId = 0)
    {
        $conditions = [];
        foreach ($names as $idLang => $name) {
            if ($name !== '') {
                $conditions[] = '(l.`id_lang` = ' . (int) $idLang . ' AND l.`name` = \'' . pSQL($name) . '\')';
            }
        }
        if (!$conditions) {
            return 0;
        }

        return (int) Db::getInstance()->getValue(
            'SELECT o.`' . bqSQL($primary) . '`
            FROM `' . _DB_PREFIX_ . bqSQL($table) . '` o
            INNER JOIN `' . _DB_PREFIX_ . bqSQL($langTable) . '` l
                ON l.`' . bqSQL($langPrimary) . '` = o.`' . bqSQL($primary) . '`
            WHERE ' . ($parentField ? 'o.`' . bqSQL($parentField) . '` = ' . (int) $parentId . ' AND ' : '')
            . '(' . implode(' OR ', $conditions) . ')
            ORDER BY o.`' . bqSQL($primary) . '` ASC'
        );
    }

    private function importImages(Product $product, $sourceProductId, $item)
    {
        if (!isset($item->associations->images->image)) {
            return;
        }
        $defaultImageId = isset($item->id_default_image) ? (int) $item->id_default_image : 0;
        $images = [];
        foreach ($item->associations->images->image as $sourceImage) {
            $sourceImageId = $this->getSourceId($sourceImage);
            if ($sourceImageId <= 0) {
                continue;
            }
            $mapping = $this->getMapping('product_images', $sourceImageId);
            if ($mapping) {
                $image = new Image((int) $mapping['destination_id']);
                if (Validate::isLoadedObject($image)) {
                    if (
                        (int) $image->id_product !== (int) $product->id
                        || !(bool) $image->isAssociatedToShop($this->shopId)
                    ) {
                        throw new RuntimeException('A saved image mapping points to a different destination product.');
                    }
                    $images[$sourceImageId] = (int) $image->id;
                    continue;
                }
            }

            $image = $this->createImage(
                $product,
                $sourceProductId,
                $sourceImageId,
                $defaultImageId > 0 && $defaultImageId === $sourceImageId
            );
            if ($mapping) {
                $this->updateMappingDestination('product_images', $sourceImageId, (int) $image->id);
            } else {
                $this->saveMapping('product_images', $sourceImageId, (int) $image->id, 'imported');
            }
            $images[$sourceImageId] = (int) $image->id;
        }

        if ($defaultImageId > 0 && isset($images[$defaultImageId])) {
            if (!Image::deleteCover((int) $product->id)) {
                throw new RuntimeException('Could not clear the previous product cover before assigning the imported cover.');
            }
            $cover = new Image((int) $images[$defaultImageId]);
            $cover->cover = true;
            if (!$cover->update()) {
                throw new RuntimeException('Could not mark the imported product cover image.');
            }
        }

        $this->associateCombinationImages($product, $images);
    }

    private function createImage(Product $product, $sourceProductId, $sourceImageId, $cover)
    {
        $response = $this->client->downloadProductImage((int) $sourceProductId, (int) $sourceImageId);
        if (!$response || strlen($response) > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('Source image ' . (int) $sourceImageId . ' is empty or exceeds the 10 MB import limit.');
        }
        $imageInfo = @getimagesizefromstring($response);
        if (!$imageInfo || empty($imageInfo['mime']) || !in_array($imageInfo['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new RuntimeException('Source image ' . (int) $sourceImageId . ' is not a supported image file.');
        }

        $temporaryFile = tempnam(_PS_TMP_IMG_DIR_, 'kbizmigration_');
        if ($temporaryFile === false || file_put_contents($temporaryFile, $response) === false) {
            if ($temporaryFile !== false && is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
            throw new RuntimeException('Could not prepare source image ' . (int) $sourceImageId . ' for import.');
        }

        $image = new Image();
        $image->id_product = (int) $product->id;
        $image->id_shop_list = [$this->shopId];
        $image->position = (int) Image::getHighestPosition((int) $product->id) + 1;
        $image->cover = (bool) $cover || Image::getImagesTotal((int) $product->id) === 0;
        $image->legend = array_fill_keys(array_column($this->languages, 'id_lang'), '');
        try {
            if (!$image->add() || !(int) $image->id) {
                throw new RuntimeException('Could not create an image record for source image ' . (int) $sourceImageId . '.');
            }

            $basePath = $image->getPathForCreation();
            $extension = $image->image_format;
            if (!$basePath || !ImageManager::resize($temporaryFile, $basePath . '.' . $extension)) {
                throw new RuntimeException('Could not generate the destination file for source image ' . (int) $sourceImageId . '.');
            }
            foreach (ImageType::getImagesTypes('products') as $type) {
                if (!ImageManager::resize(
                    $temporaryFile,
                    $basePath . '-' . $type['name'] . '.' . $extension,
                    (int) $type['width'],
                    (int) $type['height']
                )) {
                    throw new RuntimeException('Could not generate product thumbnails for source image ' . (int) $sourceImageId . '.');
                }
            }
        } catch (Exception $exception) {
            if (Validate::isLoadedObject($image)) {
                $image->delete();
            }
            throw $exception;
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        return $image;
    }

    private function associateCombinationImages(Product $product, array $images)
    {
        if (!$images) {
            return;
        }
        foreach ($this->getMappings('product_combinations', (int) $product->id) as $mapping) {
            $destinationCombination = new Combination((int) $mapping['destination_id']);
            if (!Validate::isLoadedObject($destinationCombination) || (int) $destinationCombination->id_product !== (int) $product->id) {
                continue;
            }
            $source = $this->client->getResource('combinations/' . (int) $mapping['source_id']);
            $sourceImages = [];
            if (isset($source->combination->associations->images->image)) {
                foreach ($source->combination->associations->images->image as $sourceImage) {
                    $sourceImageId = $this->getSourceId($sourceImage);
                    if (isset($images[$sourceImageId])) {
                        $sourceImages[] = (int) $images[$sourceImageId];
                    }
                }
            }
            if ($sourceImages && !$destinationCombination->setImages($sourceImages)) {
                throw new RuntimeException('Could not associate imported images with a destination combination.');
            }
        }
    }

    private function sourceCombinationIds($item)
    {
        $ids = [];
        if (isset($item->associations->combinations->combination)) {
            foreach ($item->associations->combinations->combination as $combination) {
                $id = $this->getSourceId($combination);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function loadSourceStock($item)
    {
        $stocks = [];
        if (!isset($item->associations->stock_availables->stock_available)) {
            throw new RuntimeException(
                'The source product did not include stock records. Check GET permission for stock_availables and retry.'
            );
        }
        foreach ($item->associations->stock_availables->stock_available as $stock) {
            $stockId = $this->getSourceId($stock);
            if ($stockId <= 0) {
                continue;
            }
            $detail = $this->client->getResource('stock_availables/' . $stockId);
            if (!isset($detail->stock_available)) {
                throw new RuntimeException('Could not load source stock record ' . $stockId . '.');
            }
            $record = $detail->stock_available;
            $combinationId = isset($record->id_product_attribute) ? (int) $record->id_product_attribute : 0;
            $quantity = isset($record->quantity) ? trim((string) $record->quantity) : '';
            if (!preg_match('/^-?[0-9]+$/', $quantity)) {
                throw new RuntimeException('Source stock record ' . $stockId . ' has an invalid quantity.');
            }
            $stocks[$combinationId] = (int) $quantity;
        }

        return $stocks;
    }

    private function sourceQuantity(array $stockByCombination, $sourceCombinationId)
    {
        if (!array_key_exists((int) $sourceCombinationId, $stockByCombination)) {
            throw new RuntimeException(
                'The source store did not return stock for product option ' . (int) $sourceCombinationId . '.'
            );
        }

        return (int) $stockByCombination[(int) $sourceCombinationId];
    }

    private function loadSourceProduct($sourceId)
    {
        $response = $this->client->getResource('products/' . (int) $sourceId);
        if (!isset($response->product)) {
            throw new RuntimeException('Source product ' . (int) $sourceId . ' was not returned by the Webservice.');
        }

        return $response->product;
    }

    private function loadSourceLanguages()
    {
        $response = $this->client->getResource('languages', [
            'display' => '[id,iso_code]',
            'limit' => '0,100',
        ]);
        $languages = [];
        if (isset($response->languages->language)) {
            foreach ($response->languages->language as $language) {
                $id = $this->getSourceId($language);
                $isoCode = isset($language->iso_code) ? strtolower((string) $language->iso_code) : '';
                if ($id > 0 && $isoCode !== '') {
                    $languages[$id] = $isoCode;
                }
            }
        }
        if (!$languages) {
            throw new RuntimeException('The source Webservice did not return any languages.');
        }

        return $languages;
    }

    private function readTranslations($node)
    {
        $translations = [];
        if ($node !== null && isset($node->language)) {
            foreach ($node->language as $translation) {
                $id = (int) $translation['id'];
                $value = trim((string) $translation);
                if ($id > 0 && $value !== '') {
                    $translations[$id] = $value;
                }
            }
        }

        return $translations;
    }

    private function mapTranslations(array $sourceValues, $required, array $fallback = [], $rewrite = false)
    {
        $fallbackValue = $sourceValues ? reset($sourceValues) : '';
        $mapped = [];
        foreach ($this->languages as $language) {
            $idLang = (int) $language['id_lang'];
            $isoCode = strtolower($language['iso_code']);
            $value = '';
            foreach ($this->sourceLanguages as $sourceLanguageId => $sourceIsoCode) {
                if ($sourceIsoCode === $isoCode && isset($sourceValues[$sourceLanguageId])) {
                    $value = $sourceValues[$sourceLanguageId];
                    break;
                }
            }
            if ($value === '') {
                $value = isset($fallback[$idLang]) ? $fallback[$idLang] : $fallbackValue;
            }
            if ($required) {
                $value = trim(strip_tags($value));
                if ($value === '') {
                    $value = 'Product';
                }
            } elseif ($rewrite) {
                $value = Tools::str2url($value);
            }
            $mapped[$idLang] = $value;
        }
        if (!$mapped) {
            throw new RuntimeException('The destination store has no languages installed.');
        }

        return $mapped;
    }

    private function getSourceId($node)
    {
        if (isset($node->id) && (int) $node->id > 0) {
            return (int) $node->id;
        }

        return isset($node['id']) ? (int) $node['id'] : 0;
    }

    private function findDestinationProductByReference($reference)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT p.`id_product`
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.`id_product` = p.`id_product`
                AND ps.`id_shop` = ' . (int) $this->shopId . '
            WHERE p.`reference` = \'' . pSQL($reference) . '\'
            ORDER BY p.`id_product` ASC'
        );
    }

    private function getState()
    {
        $state = Db::getInstance()->getRow(
            'SELECT `last_source_id`, `is_complete`, `total_count` FROM `' . _DB_PREFIX_ . 'kbizmigration_state`
            WHERE `source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND `entity` = \'' . self::ENTITY . '\''
        );
        if ($state) {
            return $state;
        }

        if (!Db::getInstance()->insert('kbizmigration_state', [
            'source_key' => pSQL($this->sourceKey),
            'entity' => self::ENTITY,
            'last_source_id' => 0,
            'is_complete' => 0,
            'total_count' => 0,
            'date_upd' => date('Y-m-d H:i:s'),
        ])) {
            throw new RuntimeException('Could not create product migration progress.');
        }

        return ['last_source_id' => 0, 'is_complete' => 0, 'total_count' => 0];
    }

    private function saveTotalCount($totalCount)
    {
        $updated = Db::getInstance()->update(
            'kbizmigration_state',
            ['total_count' => (int) $totalCount, 'date_upd' => date('Y-m-d H:i:s')],
            '`source_key` = \'' . pSQL($this->sourceKey) . '\' AND `entity` = \'' . self::ENTITY . '\''
        );
        if (!$updated && !Db::getInstance()->Affected_Rows()) {
            $state = $this->getState();
            if ((int) $state['total_count'] !== (int) $totalCount) {
                throw new RuntimeException('Could not save the source product total.');
            }
        }
    }

    private function saveState($cursor, $done)
    {
        $updated = Db::getInstance()->update(
            'kbizmigration_state',
            [
                'last_source_id' => (int) $cursor,
                'is_complete' => $done ? 1 : 0,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            '`source_key` = \'' . pSQL($this->sourceKey) . '\' AND `entity` = \'' . self::ENTITY . '\''
        );
        if (!$updated && !Db::getInstance()->Affected_Rows()) {
            $state = $this->getState();
            if ((int) $state['last_source_id'] !== (int) $cursor || (bool) $state['is_complete'] !== (bool) $done) {
                throw new RuntimeException('Could not save product migration progress.');
            }
        }
    }

    private function countMappings($status)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'kbizmigration_map`
            WHERE `source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND `entity` = \'' . self::ENTITY . '\'
                AND `status` = \'' . pSQL($status) . '\''
        );
    }

    private function getMapping($entity, $sourceId)
    {
        return Db::getInstance()->getRow(
            'SELECT `source_id`, `destination_id`, `status` FROM `' . _DB_PREFIX_ . 'kbizmigration_map`
            WHERE `source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND `entity` = \'' . pSQL($entity) . '\'
                AND `source_id` = ' . (int) $sourceId
        );
    }

    private function getMappings($entity, $destinationProductId = 0)
    {
        return Db::getInstance()->executeS(
            'SELECT m.`source_id`, m.`destination_id`, m.`status`
            FROM `' . _DB_PREFIX_ . 'kbizmigration_map` m'
            . ($destinationProductId > 0 ? '
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` pa
                ON pa.`id_product_attribute` = m.`destination_id`
                AND pa.`id_product` = ' . (int) $destinationProductId : '') . '
            WHERE m.`source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND m.`entity` = \'' . pSQL($entity) . '\'
                AND m.`status` = \'imported\''
        );
    }

    private function saveMapping($entity, $sourceId, $destinationId, $status)
    {
        if (!Db::getInstance()->insert('kbizmigration_map', [
            'source_key' => pSQL($this->sourceKey),
            'entity' => pSQL($entity),
            'source_id' => (int) $sourceId,
            'destination_id' => (int) $destinationId,
            'status' => pSQL($status),
        ])) {
            $existing = $this->getMapping($entity, $sourceId);
            if (!$existing || (int) $existing['destination_id'] !== (int) $destinationId) {
                throw new RuntimeException('Could not save a product migration mapping for source ID ' . (int) $sourceId . '.');
            }
        }
    }

    private function updateMappingDestination($entity, $sourceId, $destinationId)
    {
        $updated = Db::getInstance()->update(
            'kbizmigration_map',
            ['destination_id' => (int) $destinationId],
            '`source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND `entity` = \'' . pSQL($entity) . '\'
                AND `source_id` = ' . (int) $sourceId . '
                AND `status` = \'imported\''
        );
        if (!$updated && !Db::getInstance()->Affected_Rows()) {
            $mapping = $this->getMapping($entity, $sourceId);
            if (
                !$mapping
                || (int) $mapping['destination_id'] !== (int) $destinationId
                || $mapping['status'] !== 'imported'
            ) {
                throw new RuntimeException('Could not update a product migration mapping.');
            }
        }
    }

    private function readDecimal($value)
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if ($normalized === '' || !is_numeric($normalized)) {
            throw new RuntimeException('A source product contains an invalid numeric value.');
        }

        return (float) $normalized;
    }

    private function getDefaultTaxRulesGroupId()
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_tax_rules_group`
            FROM `' . _DB_PREFIX_ . 'product_shop`
            WHERE `id_shop` = ' . (int) $this->shopId . '
            GROUP BY `id_tax_rules_group`
            ORDER BY COUNT(*) DESC, `id_tax_rules_group` ASC'
        );
    }

    private function allowedValue($value, array $allowed, $fallback)
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
