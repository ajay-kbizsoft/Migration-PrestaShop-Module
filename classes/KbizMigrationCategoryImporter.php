<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class KbizMigrationCategoryImporter
{
    private const ENTITY = 'categories';
    private const BATCH_SIZE = 10;

    private $client;
    private $sourceKey;
    private $languages;
    private $sourceLanguages;

    public function __construct(KbizMigrationSourceWebserviceClient $client, $sourceUrl)
    {
        $this->client = $client;
        $this->sourceKey = hash('sha256', rtrim($sourceUrl, '/'));
        $this->languages = Language::getLanguages(false);
        $this->sourceLanguages = $this->loadSourceLanguages();
    }

    public static function installDatabase()
    {
        $mapTable = _DB_PREFIX_ . 'kbizmigration_map';
        $stateTable = _DB_PREFIX_ . 'kbizmigration_state';

        $created = Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . bqSQL($mapTable) . '` (
                `id_kbizmigration_map` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `source_key` CHAR(64) NOT NULL,
                `entity` VARCHAR(32) NOT NULL,
                `source_id` INT UNSIGNED NOT NULL,
                `destination_id` INT UNSIGNED NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                PRIMARY KEY (`id_kbizmigration_map`),
                UNIQUE KEY `source_entity` (`source_key`, `entity`, `source_id`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4
        ') && Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . bqSQL($stateTable) . '` (
                `source_key` CHAR(64) NOT NULL,
                `entity` VARCHAR(32) NOT NULL,
                `last_source_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_complete` TINYINT(1) NOT NULL DEFAULT 0,
                `total_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`source_key`, `entity`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4
        ');
        if (!$created) {
            return false;
        }

        $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . bqSQL($stateTable) . '` LIKE \'total_count\'');
        if ($columns === false) {
            return false;
        }
        if (!$columns && !Db::getInstance()->execute(
            'ALTER TABLE `' . bqSQL($stateTable) . '` ADD `total_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_complete`'
        )) {
            return false;
        }

        return true;
    }

    public static function uninstallDatabase()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'kbizmigration_state`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'kbizmigration_map`');
    }

    public function runNextBatch()
    {
        if (!self::installDatabase()) {
            throw new RuntimeException('Could not prepare the migration progress tables.');
        }

        $state = $this->getState();
        $totalCount = (int) $state['total_count'];
        if ($totalCount === 0) {
            $totalCount = $this->client->countResource('categories');
            $this->saveTotalCount($totalCount);
        }
        $cursor = (int) $state['last_source_id'];
        $firstMissingSourceId = $this->getFirstMissingDestinationSourceId();
        if (
            $state['is_complete']
            && $firstMissingSourceId === 0
            && ($cursor > 0 || $this->countMappings('imported') > 0 || $this->countMappings('skipped') > 0)
        ) {
            return [
                'done' => true,
                'processed' => 0,
                'imported' => $this->countMappings('imported'),
                'skipped' => $this->countMappings('skipped'),
            ];
        }
        if ($state['is_complete'] || ($firstMissingSourceId > 0 && $firstMissingSourceId <= $cursor)) {
            $this->saveState(0, false);
            $cursor = 0;
        }

        $categories = $this->client->getResource('categories', [
            'display' => 'full',
            'sort' => '[id_ASC]',
            'filter' => ['id' => '>[' . $cursor . ']'],
            'limit' => '0,' . (self::BATCH_SIZE + 1),
        ]);
        $items = self::extractCategoryItems($categories);
        if (!$items && $cursor === 0) {
            throw new RuntimeException(
                'The source categories endpoint returned no categories. Check that the source API key has GET permission for categories and that the source store has categories available.'
            );
        }

        $hasMore = count($items) > self::BATCH_SIZE;
        if ($hasMore) {
            $items = array_slice($items, 0, self::BATCH_SIZE);
        }

        $processed = 0;
        foreach ($items as $item) {
            $sourceId = $this->getSourceId($item);
            if ($sourceId <= 0) {
                throw new RuntimeException('The source category list contains an invalid category ID.');
            }
            if ($sourceId <= $cursor) {
                continue;
            }

            $this->ensureCategory($sourceId, $item, 0);
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

    public function runNextReimportBatch()
    {
        if (!self::installDatabase()) {
            throw new RuntimeException('Could not prepare the category migration progress tables.');
        }

        $entity = 'categories_refresh';
        $state = $this->getState($entity);
        $cursor = (int) $state['last_source_id'];
        if ($state['is_complete']) {
            $this->saveState(0, false, $entity);
            $cursor = 0;
        }

        $response = $this->client->getResource('categories', [
            'display' => 'full',
            'sort' => '[id_ASC]',
            'filter' => ['id' => '>[' . $cursor . ']'],
            'limit' => '0,' . (self::BATCH_SIZE + 1),
        ]);
        $items = self::extractCategoryItems($response);
        if (!$items && $cursor === 0) {
            throw new RuntimeException('The source categories endpoint returned no categories to re-import.');
        }
        $hasMore = count($items) > self::BATCH_SIZE;
        if ($hasMore) {
            $items = array_slice($items, 0, self::BATCH_SIZE);
        }

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($items as $item) {
            $sourceId = $this->getSourceId($item);
            if ($sourceId <= $cursor) {
                throw new RuntimeException('The source category list is not ordered by increasing ID.');
            }

            $mapping = $this->getMapping($sourceId);
            if ($mapping && $mapping['status'] === 'imported') {
                $this->updateMappedCategory((int) $mapping['destination_id'], $item, $sourceId);
                ++$updated;
            } else {
                ++$skipped;
            }
            $cursor = $sourceId;
            ++$processed;
        }

        $done = !$hasMore;
        $this->saveState($cursor, $done, $entity);

        return [
            'done' => $done,
            'processed' => $processed,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    public function getProgress()
    {
        if (!self::installDatabase()) {
            throw new RuntimeException('Could not prepare the migration progress tables.');
        }
        $state = $this->getState();
        $totalCount = (int) $state['total_count'];
        if ($totalCount === 0) {
            $totalCount = $this->client->countResource('categories');
            $this->saveTotalCount($totalCount);
        }
        $missing = $this->getMissingDestinationCategoryCount();
        $processed = min(
            $totalCount,
            max(0, $this->countMappings('imported') + $this->countMappings('skipped') - $missing)
        );

        return [
            'last_source_id' => (int) $state['last_source_id'],
            'done' => (bool) $state['is_complete'] && $missing === 0 && $processed >= $totalCount,
            'imported' => $this->countMappings('imported'),
            'skipped' => $this->countMappings('skipped'),
            'total' => $totalCount,
            'processed' => $processed,
            'remaining' => max(0, $totalCount - $processed),
        ];
    }

    public function previewCategories($page = 1, $pageSize = 50)
    {
        $page = max(1, (int) $page);
        $pageSize = max(1, min(100, (int) $pageSize));
        $sourceCategories = $this->loadAllSourceCategories();
        $matches = [];
        $visiting = [];
        $rows = [];
        $matchedCount = 0;

        foreach ($sourceCategories as $sourceId => $category) {
            $match = $this->previewCategoryMatch((int) $sourceId, $sourceCategories, $matches, $visiting, 0);
            if ($match['matched']) {
                ++$matchedCount;
            }
            $rows[] = [
                'source_id' => (int) $sourceId,
                'name' => $this->primaryTranslation(
                    $this->readTranslations(isset($category->name) ? $category->name : null)
                ),
                'parent' => $this->sourceParentName((int) (isset($category->id_parent) ? $category->id_parent : 0), $sourceCategories),
                'status' => $match['status'],
                'destination_id' => (int) $match['destination_id'],
            ];
        }

        $total = count($rows);
        $pageCount = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $pageCount);

        return [
            'total' => $total,
            'matched' => $matchedCount,
            'new' => $total - $matchedCount,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'items' => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
        ];
    }

    public static function extractCategoryItems(SimpleXMLElement $response)
    {
        $items = [];
        if (isset($response->categories->category)) {
            foreach ($response->categories->category as $category) {
                $items[] = $category;
            }
        }

        return $items;
    }

    private function loadAllSourceCategories()
    {
        $categoriesById = [];
        $cursor = 0;
        $pageSize = 100;
        do {
            $response = $this->client->getResource('categories', [
                'display' => 'full',
                'sort' => '[id_ASC]',
                'filter' => ['id' => '>[' . $cursor . ']'],
                'limit' => '0,' . ($pageSize + 1),
            ]);
            $pageItems = self::extractCategoryItems($response);
            if (!$pageItems && $cursor === 0) {
                throw new RuntimeException(
                    'The source categories endpoint returned no categories. Check the source API key GET permission and the source store category list.'
                );
            }
            if (count($pageItems) > $pageSize) {
                $pageItems = array_slice($pageItems, 0, $pageSize);
            }

            foreach ($pageItems as $item) {
                $sourceId = $this->getSourceId($item);
                if ($sourceId <= $cursor) {
                    throw new RuntimeException('The source category list is not ordered by increasing ID.');
                }
                $categoriesById[$sourceId] = $item;
                $cursor = $sourceId;
            }
        } while (count($pageItems) === $pageSize);

        ksort($categoriesById, SORT_NUMERIC);

        return $categoriesById;
    }

    private function previewCategoryMatch($sourceId, array $sourceCategories, array &$matches, array &$visiting, $depth)
    {
        if (isset($matches[$sourceId])) {
            return $matches[$sourceId];
        }
        if (isset($visiting[$sourceId]) || $depth > 100) {
            throw new RuntimeException('The source category tree contains a parent cycle or is too deep.');
        }
        $visiting[$sourceId] = true;

        $category = isset($sourceCategories[$sourceId]) ? $sourceCategories[$sourceId] : null;
        if ($category === null) {
            $response = $this->client->getResource('categories/' . (int) $sourceId);
            if (!isset($response->category)) {
                throw new RuntimeException('Could not load source parent category ' . (int) $sourceId . '.');
            }
            $category = $response->category;
            $sourceCategories[$sourceId] = $category;
        }

        $existingMapping = $this->getMapping($sourceId);
        if ($existingMapping) {
            $match = [
                'matched' => true,
                'destination_id' => (int) $existingMapping['destination_id'],
                'status' => $existingMapping['status'] === 'imported' ? 'Previously imported' : 'Mapped / skipped',
            ];
        } else {
            $parentId = isset($category->id_parent) ? (int) $category->id_parent : 0;
            $isRoot = (isset($category->is_root_category) && (int) $category->is_root_category === 1)
                || $parentId === 0;
            if ($isRoot) {
                $match = [
                    'matched' => true,
                    'destination_id' => (int) Configuration::get('PS_ROOT_CATEGORY'),
                    'status' => 'Maps to destination root',
                ];
            } else {
                $parentMatch = $this->previewCategoryMatch(
                    $parentId,
                    $sourceCategories,
                    $matches,
                    $visiting,
                    $depth + 1
                );
                $names = $this->readTranslations(isset($category->name) ? $category->name : null);
                $isHome = $parentMatch['destination_id'] === (int) Configuration::get('PS_ROOT_CATEGORY')
                    && (
                        (isset($category->level_depth) && (int) $category->level_depth === 0)
                        || $this->hasHomeName($names)
                    );
                if ($isHome) {
                    $match = [
                        'matched' => true,
                        'destination_id' => (int) Configuration::get('PS_HOME_CATEGORY'),
                        'status' => 'Maps to destination Home',
                    ];
                } elseif (!$parentMatch['matched'] || $parentMatch['destination_id'] <= 0) {
                    $match = [
                        'matched' => false,
                        'destination_id' => 0,
                        'status' => 'New (parent will be imported)',
                    ];
                } else {
                    $namesByLanguage = $this->mapTranslations($names, true);
                    $destinationId = $this->findExistingCategory($parentMatch['destination_id'], $namesByLanguage);
                    $match = $destinationId > 0
                        ? [
                            'matched' => true,
                            'destination_id' => $destinationId,
                            'status' => 'Already exists',
                        ]
                        : [
                            'matched' => false,
                            'destination_id' => 0,
                            'status' => 'New',
                        ];
                }
            }
        }

        unset($visiting[$sourceId]);
        $matches[$sourceId] = $match;

        return $match;
    }

    private function primaryTranslation(array $translations)
    {
        foreach ($this->languages as $language) {
            $isoCode = strtolower($language['iso_code']);
            foreach ($this->sourceLanguages as $sourceLanguageId => $sourceIsoCode) {
                if ($sourceIsoCode === $isoCode && isset($translations[$sourceLanguageId])) {
                    return $translations[$sourceLanguageId];
                }
            }
        }

        return $translations ? reset($translations) : '';
    }

    private function sourceParentName($sourceParentId, array $sourceCategories)
    {
        if (!isset($sourceCategories[$sourceParentId])) {
            return $sourceParentId > 0 ? 'Category #' . $sourceParentId : '—';
        }

        return $this->primaryTranslation(
            $this->readTranslations(
                isset($sourceCategories[$sourceParentId]->name) ? $sourceCategories[$sourceParentId]->name : null
            )
        );
    }

    public static function getSavedProgress($sourceUrl)
    {
        $sourceKey = hash('sha256', rtrim($sourceUrl, '/'));
        $state = Db::getInstance()->getRow(
            'SELECT `last_source_id`, `is_complete`, `total_count` FROM `' . _DB_PREFIX_ . 'kbizmigration_state`
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
            'total' => (int) $state['total_count'],
            'processed' => max(
                0,
                $counts['imported'] + $counts['skipped'] - self::countMissingDestinationCategoryMappings($sourceKey)
            ),
        ];
    }

    private static function countMissingDestinationCategoryMappings($sourceKey)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*)
            FROM `' . _DB_PREFIX_ . 'kbizmigration_map` m
            LEFT JOIN `' . _DB_PREFIX_ . 'category` c
                ON c.`id_category` = m.`destination_id`
            LEFT JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON cs.`id_category` = m.`destination_id`
                AND cs.`id_shop` = ' . (int) Context::getContext()->shop->id . '
            WHERE m.`source_key` = \'' . pSQL($sourceKey) . '\'
                AND m.`entity` = \'' . self::ENTITY . '\'
                AND m.`status` = \'imported\'
                AND (c.`id_category` IS NULL OR cs.`id_category` IS NULL)'
        );
    }

    private function getMissingDestinationCategoryCount()
    {
        return self::countMissingDestinationCategoryMappings($this->sourceKey);
    }

    public static function getSavedReimportProgress($sourceUrl)
    {
        $sourceKey = hash('sha256', rtrim($sourceUrl, '/'));
        $state = Db::getInstance()->getRow(
            'SELECT `last_source_id`, `is_complete` FROM `' . _DB_PREFIX_ . 'kbizmigration_state`
            WHERE `source_key` = \'' . pSQL($sourceKey) . '\'
            AND `entity` = \'categories_refresh\''
        );

        return $state ? [
            'last_source_id' => (int) $state['last_source_id'],
            'done' => (bool) $state['is_complete'],
        ] : null;
    }

    public static function getImportedCategories($sourceUrl, $limit = 100)
    {
        $sourceKey = hash('sha256', rtrim($sourceUrl, '/'));
        $idShop = (int) Context::getContext()->shop->id;
        $idLang = (int) Context::getContext()->language->id;
        $limit = max(1, min(100, (int) $limit));
        $rows = Db::getInstance()->executeS(
            'SELECT m.`source_id`, m.`destination_id`, c.`id_parent`, c.`active`,
                    COALESCE(
                        (SELECT cl.`name` FROM `' . _DB_PREFIX_ . 'category_lang` cl
                            WHERE cl.`id_category` = c.`id_category`
                                AND cl.`id_lang` = ' . $idLang . '
                                AND cl.`id_shop` = ' . $idShop . ' LIMIT 1),
                        (SELECT cl.`name` FROM `' . _DB_PREFIX_ . 'category_lang` cl
                            WHERE cl.`id_category` = c.`id_category`
                                AND cl.`id_lang` = ' . $idLang . '
                                AND cl.`id_shop` = 0 LIMIT 1),
                        \'\'
                    ) AS `name`,
                    CASE
                        WHEN c.`id_category` IS NULL THEN \'missing\'
                        WHEN cs.`id_category` IS NULL THEN \'not_in_shop\'
                        ELSE \'available\'
                    END AS `visibility`
            FROM `' . _DB_PREFIX_ . 'kbizmigration_map` m
            LEFT JOIN `' . _DB_PREFIX_ . 'category` c
                ON c.`id_category` = m.`destination_id`
            LEFT JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON cs.`id_category` = c.`id_category`
                AND cs.`id_shop` = ' . $idShop . '
            WHERE m.`source_key` = \'' . pSQL($sourceKey) . '\'
                AND m.`entity` = \'' . self::ENTITY . '\'
                AND m.`status` = \'imported\'
            ORDER BY m.`source_id` ASC
            LIMIT ' . $limit
        );
        if ($rows === false) {
            throw new RuntimeException('Could not load imported categories for the current shop.');
        }

        return $rows;
    }

    private function getState($entity = self::ENTITY)
    {
        $table = _DB_PREFIX_ . 'kbizmigration_state';
        $state = Db::getInstance()->getRow(
            'SELECT `last_source_id`, `is_complete`, `total_count` FROM `' . bqSQL($table) . '`
            WHERE `source_key` = \'' . pSQL($this->sourceKey) . '\'
            AND `entity` = \'' . pSQL($entity) . '\''
        );
        if ($state) {
            return $state;
        }

        $inserted = Db::getInstance()->insert('kbizmigration_state', [
            'source_key' => pSQL($this->sourceKey),
            'entity' => pSQL($entity),
            'last_source_id' => 0,
            'is_complete' => 0,
            'total_count' => 0,
            'date_upd' => date('Y-m-d H:i:s'),
        ]);
        if (!$inserted) {
            throw new RuntimeException('Could not create category migration progress.');
        }

        return ['last_source_id' => 0, 'is_complete' => 0, 'total_count' => 0];
    }

    private function saveTotalCount($totalCount, $entity = self::ENTITY)
    {
        $updated = Db::getInstance()->update(
            'kbizmigration_state',
            ['total_count' => (int) $totalCount, 'date_upd' => date('Y-m-d H:i:s')],
            '`source_key` = \'' . pSQL($this->sourceKey) . '\' AND `entity` = \'' . pSQL($entity) . '\''
        );
        if (!$updated && !Db::getInstance()->Affected_Rows()) {
            $state = $this->getState($entity);
            if ((int) $state['total_count'] !== (int) $totalCount) {
                throw new RuntimeException('Could not save the source category total.');
            }
        }
    }

    private function saveState($cursor, $done, $entity = self::ENTITY)
    {
        $updated = Db::getInstance()->update(
            'kbizmigration_state',
            [
                'last_source_id' => (int) $cursor,
                'is_complete' => $done ? 1 : 0,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            '`source_key` = \'' . pSQL($this->sourceKey) . '\' AND `entity` = \'' . pSQL($entity) . '\''
        );
        if (!$updated && !Db::getInstance()->Affected_Rows()) {
            $state = $this->getState($entity);
            if ((int) $state['last_source_id'] !== (int) $cursor || (bool) $state['is_complete'] !== (bool) $done) {
                throw new RuntimeException('Could not save category migration progress.');
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

    private function getFirstMissingDestinationSourceId()
    {
        return (int) Db::getInstance()->getValue(
            'SELECT MIN(m.`source_id`)
            FROM `' . _DB_PREFIX_ . 'kbizmigration_map` m
            LEFT JOIN `' . _DB_PREFIX_ . 'category` c
                ON c.`id_category` = m.`destination_id`
            LEFT JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON cs.`id_category` = m.`destination_id`
                AND cs.`id_shop` = ' . (int) Context::getContext()->shop->id . '
            WHERE m.`source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND m.`entity` = \'' . self::ENTITY . '\'
                AND (c.`id_category` IS NULL OR cs.`id_category` IS NULL)'
        );
    }

    private function getMapping($sourceId)
    {
        return Db::getInstance()->getRow(
            'SELECT `destination_id`, `status` FROM `' . _DB_PREFIX_ . 'kbizmigration_map`
            WHERE `source_key` = \'' . pSQL($this->sourceKey) . '\'
            AND `entity` = \'' . self::ENTITY . '\'
            AND `source_id` = ' . (int) $sourceId
        );
    }

    private function saveMapping($sourceId, $destinationId, $status)
    {
        if (!Db::getInstance()->insert('kbizmigration_map', [
            'source_key' => pSQL($this->sourceKey),
            'entity' => self::ENTITY,
            'source_id' => (int) $sourceId,
            'destination_id' => (int) $destinationId,
            'status' => pSQL($status),
        ])) {
            $existing = $this->getMapping($sourceId);
            if (!$existing || (int) $existing['destination_id'] !== (int) $destinationId) {
                throw new RuntimeException('Could not save the source-to-destination category mapping.');
            }
        }
    }

    private function deleteMapping($sourceId)
    {
        if (!Db::getInstance()->delete(
            'kbizmigration_map',
            '`source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND `entity` = \'' . self::ENTITY . '\'
                AND `source_id` = ' . (int) $sourceId
        )) {
            throw new RuntimeException('Could not remove a stale source-to-destination category mapping.');
        }
    }

    private function updateMappingDestination($sourceId, $destinationId)
    {
        $updated = Db::getInstance()->update(
            'kbizmigration_map',
            ['destination_id' => (int) $destinationId],
            '`source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND `entity` = \'' . self::ENTITY . '\'
                AND `source_id` = ' . (int) $sourceId . '
                AND `status` = \'imported\''
        );
        if (!$updated && !Db::getInstance()->Affected_Rows()) {
            $mapping = $this->getMapping($sourceId);
            if (
                !$mapping
                || (int) $mapping['destination_id'] !== (int) $destinationId
                || $mapping['status'] !== 'imported'
            ) {
                throw new RuntimeException('Could not update the destination category mapping.');
            }
        }
    }

    private function ensureCategory($sourceId, $item, $depth)
    {
        $mapping = $this->getMapping($sourceId);
        if ($mapping) {
            $mappedCategory = new Category((int) $mapping['destination_id']);
            if (Validate::isLoadedObject($mappedCategory)) {
                if (!$mappedCategory->associateTo((int) Context::getContext()->shop->id)) {
                    throw new RuntimeException(
                        'Could not associate destination category ' . (int) $mapping['destination_id'] . ' with the current shop.'
                    );
                }

                return (int) $mapping['destination_id'];
            }

            if ($mapping['status'] === 'imported') {
                if ($item === null) {
                    $resource = $this->client->getResource('categories/' . (int) $sourceId);
                    if (!isset($resource->category)) {
                        throw new RuntimeException('The source category ' . (int) $sourceId . ' was not returned by the Webservice.');
                    }
                    $item = $resource->category;
                }

                $this->updateMappedCategory((int) $mapping['destination_id'], $item, (int) $sourceId);
                $restoredMapping = $this->getMapping($sourceId);
                if (!$restoredMapping || $restoredMapping['status'] !== 'imported') {
                    throw new RuntimeException('Could not restore the saved mapping for source category ' . (int) $sourceId . '.');
                }

                return (int) $restoredMapping['destination_id'];
            }

            $this->deleteMapping($sourceId);
        }
        if ($depth > 100) {
            throw new RuntimeException('The source category tree is too deep or contains a parent cycle.');
        }

        if ($item === null) {
            $resource = $this->client->getResource('categories/' . (int) $sourceId);
            if (!isset($resource->category)) {
                throw new RuntimeException('The source category ' . (int) $sourceId . ' was not returned by the Webservice.');
            }
            $item = $resource->category;
        }

        $sourceParentId = isset($item->id_parent) ? (int) $item->id_parent : 0;
        $isRoot = isset($item->is_root_category) && (int) $item->is_root_category === 1;
        if ($isRoot || $sourceParentId === 0) {
            $destinationId = (int) Configuration::get('PS_ROOT_CATEGORY');
            $this->saveMapping($sourceId, $destinationId, 'skipped');

            return $destinationId;
        }
        if ($sourceParentId < 0) {
            throw new RuntimeException('The source category ' . (int) $sourceId . ' has an invalid parent ID.');
        }

        $nameValues = $this->readTranslations(isset($item->name) ? $item->name : null);
        if (!$nameValues) {
            throw new RuntimeException('The source category ' . (int) $sourceId . ' has no translatable name.');
        }
        $destinationParentId = $this->ensureCategory($sourceParentId, null, $depth + 1);
        if ($destinationParentId <= 0) {
            throw new RuntimeException('Could not map the parent for source category ' . (int) $sourceId . '.');
        }
        $isSourceHome = isset($item->level_depth) && (int) $item->level_depth === 0;
        if (
            $destinationParentId === (int) Configuration::get('PS_ROOT_CATEGORY')
            && ($this->hasHomeName($nameValues) || $isSourceHome)
        ) {
            $destinationId = (int) Configuration::get('PS_HOME_CATEGORY');
            $this->saveMapping($sourceId, $destinationId, 'skipped');

            return $destinationId;
        }
        $destinationParentId = $this->getShopCategoryParent($destinationParentId);

        $namesByLanguage = $this->mapTranslations($nameValues, true);
        $existingId = $this->findExistingCategory($destinationParentId, $namesByLanguage);
        if ($existingId > 0) {
            $this->saveMapping($sourceId, $existingId, 'skipped');

            return $existingId;
        }

        $category = new Category();
        $category->id_shop_default = (int) Context::getContext()->shop->id;
        $category->position = 0;
        $this->setCategoryFields($category, $item, $destinationParentId);

        if (!$category->add() || !(int) $category->id) {
            throw new RuntimeException(
                'PrestaShop could not create source category ' . (int) $sourceId . '. Check destination category validation and shop permissions.'
            );
        }

        $this->saveMapping($sourceId, (int) $category->id, 'imported');

        return (int) $category->id;
    }

    private function updateMappedCategory($destinationId, $item, $sourceId)
    {
        $sourceParentId = isset($item->id_parent) ? (int) $item->id_parent : 0;
        $destinationParentId = $this->getShopCategoryParent(
            $this->resolveMappedDestination($sourceParentId, 0)
        );
        $category = new Category((int) $destinationId);
        $categoryExists = Validate::isLoadedObject($category);
        if (!$categoryExists) {
            $category = new Category();
            $category->id_shop_default = (int) Context::getContext()->shop->id;
            $category->position = 0;
        }

        $this->setCategoryFields($category, $item, $destinationParentId);
        if ($categoryExists) {
            if (!$category->associateTo((int) Context::getContext()->shop->id)) {
                throw new RuntimeException(
                    'Could not associate destination category ' . (int) $destinationId . ' with the current shop.'
                );
            }
            $saved = $category->update();
        } else {
            $saved = $category->add();
        }
        if (!$saved || !(int) $category->id) {
            throw new RuntimeException(
                'PrestaShop could not ' . ($categoryExists ? 'update' : 'restore')
                . ' destination category ' . (int) $destinationId . '.'
            );
        }
        if (!$categoryExists) {
            $this->updateMappingDestination($sourceId, (int) $category->id);
        }
    }

    private function resolveMappedDestination($sourceId, $depth)
    {
        if ($depth > 100) {
            throw new RuntimeException('The source category tree is too deep or contains a parent cycle.');
        }
        $mapping = $this->getMapping((int) $sourceId);
        if (!$mapping) {
            throw new RuntimeException(
                'Could not find the destination mapping for source parent category ' . (int) $sourceId . '.'
            );
        }

        $destinationId = (int) $mapping['destination_id'];
        $category = new Category($destinationId);
        if (Validate::isLoadedObject($category)) {
            if (!$category->associateTo((int) Context::getContext()->shop->id)) {
                throw new RuntimeException(
                    'Could not associate destination parent category ' . $destinationId . ' with the current shop.'
                );
            }

            return $destinationId;
        }
        if ($mapping['status'] !== 'imported') {
            throw new RuntimeException(
                'A mapped root or existing destination parent category ' . $destinationId . ' no longer exists.'
            );
        }

        $resource = $this->client->getResource('categories/' . (int) $sourceId);
        if (!isset($resource->category)) {
            throw new RuntimeException('The source parent category ' . (int) $sourceId . ' was not returned by the Webservice.');
        }
        $item = $resource->category;
        $parentSourceId = isset($item->id_parent) ? (int) $item->id_parent : 0;
        $parentDestinationId = $this->getShopCategoryParent(
            $this->resolveMappedDestination($parentSourceId, $depth + 1)
        );

        $category = new Category();
        $category->id_shop_default = (int) Context::getContext()->shop->id;
        $category->position = 0;
        $this->setCategoryFields($category, $item, $parentDestinationId);
        if (!$category->add() || !(int) $category->id) {
            throw new RuntimeException(
                'PrestaShop could not restore source parent category ' . (int) $sourceId . '.'
            );
        }
        $this->updateMappingDestination((int) $sourceId, (int) $category->id);

        return (int) $category->id;
    }

    private function getShopCategoryParent($destinationParentId)
    {
        if ((int) $destinationParentId === (int) Configuration::get('PS_ROOT_CATEGORY')) {
            return (int) Configuration::get('PS_HOME_CATEGORY');
        }

        return (int) $destinationParentId;
    }

    private function setCategoryFields(Category $category, $item, $destinationParentId)
    {
        $nameValues = $this->readTranslations(isset($item->name) ? $item->name : null);
        if (!$nameValues) {
            throw new RuntimeException('The source category has no translatable name.');
        }
        $namesByLanguage = $this->mapTranslations($nameValues, true);
        $category->id_parent = (int) $destinationParentId;
        $category->active = isset($item->active) ? (int) $item->active : 1;
        $category->is_root_category = false;
        $category->name = $namesByLanguage;
        $category->link_rewrite = $this->mapTranslations(
            $this->readTranslations(isset($item->link_rewrite) ? $item->link_rewrite : null),
            false,
            $namesByLanguage,
            true
        );
        $category->description = $this->mapTranslations(
            $this->readTranslations(isset($item->description) ? $item->description : null),
            false
        );
        $category->meta_title = $this->mapTranslations(
            $this->readTranslations(isset($item->meta_title) ? $item->meta_title : null),
            false
        );
        $category->meta_description = $this->mapTranslations(
            $this->readTranslations(isset($item->meta_description) ? $item->meta_description : null),
            false
        );
    }

    private function hasHomeName(array $names)
    {
        foreach ($names as $name) {
            if (strtolower(trim(strip_tags($name))) === 'home') {
                return true;
            }
        }

        return false;
    }

    private function findExistingCategory($parentId, array $namesByLanguage)
    {
        $conditions = [];
        foreach ($namesByLanguage as $idLang => $name) {
            if ($name === '') {
                continue;
            }
            $conditions[] = '(cl.`id_lang` = ' . (int) $idLang . ' AND cl.`name` = \'' . pSQL($name) . '\')';
        }
        if (!$conditions) {
            return 0;
        }

        return (int) Db::getInstance()->getValue(
            'SELECT c.`id_category`
            FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON cs.`id_category` = c.`id_category`
                AND cs.`id_shop` = ' . (int) Context::getContext()->shop->id . '
            INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON cl.`id_category` = c.`id_category`
                AND cl.`id_shop` = cs.`id_shop`
            WHERE c.`id_parent` = ' . (int) $parentId . '
            AND (' . implode(' OR ', $conditions) . ')
            ORDER BY c.`id_category` ASC'
        );
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
                $sourceLanguageId = (int) $translation['id'];
                $text = trim((string) $translation);
                if ($sourceLanguageId > 0 && $text !== '') {
                    $translations[$sourceLanguageId] = $text;
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
            if ($required && trim($value) === '') {
                $value = 'Category';
            }
            if ($required) {
                $mapped[$idLang] = trim(strip_tags($value));
            } elseif ($rewrite) {
                $mapped[$idLang] = Tools::str2url($value);
            } else {
                $mapped[$idLang] = $value;
            }
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
}
