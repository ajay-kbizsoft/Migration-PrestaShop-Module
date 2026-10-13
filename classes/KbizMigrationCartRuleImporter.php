<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class KbizMigrationCartRuleImporter
{
    private const ENTITY = 'cart_rules';
    private const BATCH_SIZE = 5;

    private $client;
    private $sourceKey;
    private $shopId;
    private $languages;
    private $sourceLanguages;

    public function __construct(KbizMigrationSourceWebserviceClient $client, $sourceUrl)
    {
        $this->client = $client;
        $this->sourceKey = hash('sha256', rtrim($sourceUrl, '/'));
        $this->shopId = (int) Context::getContext()->shop->id;
        $this->languages = Language::getLanguages(false);
        $this->sourceLanguages = $this->loadSourceLanguages();
    }

    public function getProgress()
    {
        $this->prepareDatabase();
        $state = $this->getState();
        $total = (int) $state['total_count'];
        if ($total === 0) {
            $total = $this->client->countResource(self::ENTITY);
            $this->saveTotalCount($total);
        }

        $imported = $this->countMappings('imported');
        $skipped = $this->countMappings('skipped');
        $processed = min($total, $imported + $skipped);

        return [
            'last_source_id' => (int) $state['last_source_id'],
            'done' => (bool) $state['is_complete'] && $processed >= $total,
            'imported' => $imported,
            'skipped' => $skipped,
            'total' => $total,
            'processed' => $processed,
            'remaining' => max(0, $total - $processed),
        ];
    }

    public function runNextBatch()
    {
        $this->prepareDatabase();
        $state = $this->getState();
        $total = (int) $state['total_count'];
        if ($total === 0) {
            $total = $this->client->countResource(self::ENTITY);
            $this->saveTotalCount($total);
        }
        if ($total === 0) {
            $this->saveState(0, true);

            return ['done' => true, 'processed' => 0, 'imported' => 0, 'skipped' => 0];
        }
        if ($state['is_complete']) {
            return [
                'done' => true,
                'processed' => 0,
                'imported' => $this->countMappings('imported'),
                'skipped' => $this->countMappings('skipped'),
            ];
        }

        $cursor = (int) $state['last_source_id'];
        $response = $this->client->getResource(self::ENTITY, [
            'display' => '[id]',
            'sort' => '[id_ASC]',
            'filter' => ['id' => '>[' . $cursor . ']'],
            'limit' => '0,' . (self::BATCH_SIZE + 1),
        ]);
        $items = self::extractItems($response);
        $hasMore = count($items) > self::BATCH_SIZE;
        if ($hasMore) {
            $items = array_slice($items, 0, self::BATCH_SIZE);
        }

        $processed = 0;
        foreach ($items as $item) {
            $sourceId = self::getId($item);
            if ($sourceId <= $cursor) {
                throw new RuntimeException('The source cart-rule list is not ordered by increasing ID.');
            }
            $this->importCartRule($sourceId);
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

    private function importCartRule($sourceId)
    {
        $mapping = $this->getMapping($sourceId);
        if ($mapping && $mapping['status'] === 'skipped') {
            return;
        }
        if (
            $mapping
            && $mapping['status'] === 'imported'
            && Validate::isLoadedObject(new CartRule((int) $mapping['destination_id']))
        ) {
            $destination = new CartRule((int) $mapping['destination_id']);
        } else {
            $destination = new CartRule();
        }

        $source = $this->loadResource($sourceId);
        if ($this->hasUnsupportedRequirements($source)) {
            if (!$this->saveMapping($sourceId, 0, 'skipped')) {
                throw new RuntimeException('Could not record skipped source cart rule ' . $sourceId . '.');
            }

            return;
        }

        if (!$mapping || $mapping['status'] !== 'imported') {
            $existingId = (int) CartRule::getIdByCode(trim((string) $source->code));
            if ($existingId > 0) {
                if (!$this->saveMapping($sourceId, $existingId, 'skipped')) {
                    throw new RuntimeException('Could not record the existing destination voucher code for source cart rule ' . $sourceId . '.');
                }

                return;
            }
        }

        $this->populateCartRule($destination, $source);
        if (!Db::getInstance()->execute('START TRANSACTION')) {
            throw new RuntimeException('Could not start a transaction for source cart rule ' . $sourceId . '.');
        }
        try {
            $saved = $destination->id ? $destination->update() : $destination->add();
            if (!$saved || (int) $destination->id <= 0) {
                throw new RuntimeException('Could not save source cart rule ' . $sourceId . ' in the destination.');
            }
            if (!$this->saveMapping($sourceId, (int) $destination->id, 'imported')) {
                throw new RuntimeException('Could not save the mapping for source cart rule ' . $sourceId . '.');
            }
            if (!Db::getInstance()->execute('COMMIT')) {
                throw new RuntimeException('Could not commit destination cart rule ' . $sourceId . '.');
            }
        } catch (Exception $exception) {
            Db::getInstance()->execute('ROLLBACK');
            throw $exception;
        }
    }

    private function hasUnsupportedRequirements(SimpleXMLElement $source)
    {
        if (trim(isset($source->code) ? (string) $source->code : '') === '') {
            return true;
        }
        foreach ([
            'country_restriction',
            'carrier_restriction',
            'group_restriction',
            'cart_rule_restriction',
            'product_restriction',
            'shop_restriction',
        ] as $restriction) {
            if (isset($source->{$restriction}) && (int) $source->{$restriction} !== 0) {
                return true;
            }
        }
        if (isset($source->id_customer) && (int) $source->id_customer > 0) {
            return true;
        }
        if (isset($source->id_cart_rule_type) && (int) $source->id_cart_rule_type > 0) {
            return true;
        }

        return false;
    }

    private function populateCartRule(CartRule $destination, SimpleXMLElement $source)
    {
        $destination->id_shop_list = [$this->shopId];
        $destination->id_customer = 0;
        foreach ([
            'date_from',
            'date_to',
            'description',
            'quantity',
            'quantity_per_user',
            'priority',
            'partial_use',
            'code',
            'minimum_amount',
            'minimum_amount_tax',
            'minimum_amount_shipping',
            'minimum_product_quantity',
            'free_shipping',
            'reduction_percent',
            'reduction_amount',
            'reduction_tax',
            'reduction_exclude_special',
            'highlight',
            'active',
        ] as $field) {
            $destination->{$field} = isset($source->{$field}) ? (string) $source->{$field} : '';
        }
        $destination->name = $this->mapNames(isset($source->name) ? $source->name : null);
        $destination->minimum_amount_currency = isset($source->minimum_amount_currency)
            && (int) $source->minimum_amount_currency > 0
            ? $this->mapCurrency((int) $source->minimum_amount_currency)
            : 0;
        $destination->reduction_currency = isset($source->reduction_currency)
            && (int) $source->reduction_currency > 0
            ? $this->mapCurrency((int) $source->reduction_currency)
            : (int) Currency::getDefaultCurrencyId();

        $sourceReductionProduct = isset($source->reduction_product) ? (int) $source->reduction_product : 0;
        $destination->reduction_product = $sourceReductionProduct > 0
            ? $this->mappedDestinationId('products', $sourceReductionProduct, 'discount product')
            : $sourceReductionProduct;
        $sourceGiftProduct = isset($source->gift_product) ? (int) $source->gift_product : 0;
        $destination->gift_product = $sourceGiftProduct > 0
            ? $this->mappedDestinationId('products', $sourceGiftProduct, 'gift product')
            : 0;
        $sourceGiftCombination = isset($source->gift_product_attribute) ? (int) $source->gift_product_attribute : 0;
        $destination->gift_product_attribute = $sourceGiftCombination > 0
            ? $this->mappedDestinationId('product_combinations', $sourceGiftCombination, 'gift combination')
            : 0;
    }

    private function mapNames($sourceNames)
    {
        $sourceTranslations = [];
        if ($sourceNames !== null && isset($sourceNames->language)) {
            foreach ($sourceNames->language as $translation) {
                $sourceLanguageId = (int) $translation['id'];
                if ($sourceLanguageId > 0 && trim((string) $translation) !== '') {
                    $sourceTranslations[$sourceLanguageId] = trim((string) $translation);
                }
            }
        }
        if (!$sourceTranslations) {
            throw new RuntimeException('A source cart rule has no readable name.');
        }

        $fallback = reset($sourceTranslations);
        $names = [];
        foreach ($this->languages as $language) {
            $isoCode = strtolower($language['iso_code']);
            foreach ($this->sourceLanguages as $sourceLanguageId => $sourceIsoCode) {
                if ($isoCode === $sourceIsoCode && isset($sourceTranslations[$sourceLanguageId])) {
                    $names[(int) $language['id_lang']] = $sourceTranslations[$sourceLanguageId];
                    break;
                }
            }
            if (!isset($names[(int) $language['id_lang']])) {
                $names[(int) $language['id_lang']] = $fallback;
            }
        }

        return $names;
    }

    private function mapCurrency($sourceCurrencyId)
    {
        $source = $this->client->getResource('currencies/' . (int) $sourceCurrencyId);
        if (!isset($source->currency->iso_code)) {
            throw new RuntimeException('Could not read the ISO code for source currency ' . $sourceCurrencyId . '.');
        }
        $isoCode = strtoupper(trim((string) $source->currency->iso_code));
        if (strlen($isoCode) !== 3 || !Validate::isLanguageIsoCode($isoCode)) {
            throw new RuntimeException('The source cart rule references a currency with an invalid ISO code.');
        }
        $destinationId = (int) Currency::getIdByIsoCode($isoCode);
        $currency = $destinationId > 0 ? new Currency($destinationId) : null;
        if (
            !$currency
            || !Validate::isLoadedObject($currency)
            || !(bool) $currency->active
        ) {
            throw new RuntimeException(
                'Destination currency ' . $isoCode . ' must exist and be active before importing cart rules.'
            );
        }
        if (Shop::isFeatureActive()) {
            $associated = (bool) Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'currency_shop`
                WHERE `id_currency` = ' . (int) $destinationId . ' AND `id_shop` = ' . (int) $this->shopId
            );
            if (!$associated && !Db::getInstance()->insert('currency_shop', [
                    'id_currency' => $destinationId,
                    'id_shop' => $this->shopId,
                    'conversion_rate' => (float) $currency->conversion_rate,
                ])) {
                throw new RuntimeException('Could not enable destination currency ' . $isoCode . ' for this shop.');
            }
        }

        return $destinationId;
    }

    private function mappedDestinationId($entity, $sourceId, $label)
    {
        if ($sourceId <= 0) {
            throw new RuntimeException('The source cart rule has an invalid ' . $label . ' reference.');
        }
        $row = Db::getInstance()->getRow(
            'SELECT `destination_id` FROM `' . _DB_PREFIX_ . 'kbizmigration_map`
            WHERE `source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND `entity` = \'' . pSQL($entity) . '\'
                AND `source_id` = ' . (int) $sourceId
        );
        if (!$row || (int) $row['destination_id'] <= 0) {
            throw new RuntimeException(
                'The ' . $label . ' referenced by this cart rule must be migrated first.'
            );
        }

        return (int) $row['destination_id'];
    }

    private function loadSourceLanguages()
    {
        $response = $this->client->getResource('languages', [
            'display' => '[id,iso_code]',
            'limit' => '0,100',
        ]);
        $languages = [];
        foreach (self::extractItems($response, 'languages', 'language') as $item) {
            $id = self::getId($item);
            $isoCode = isset($item->iso_code) ? strtolower(trim((string) $item->iso_code)) : '';
            if ($id > 0 && $isoCode !== '') {
                $languages[$id] = $isoCode;
            }
        }
        if (!$languages) {
            throw new RuntimeException('The source Webservice did not return any languages.');
        }

        return $languages;
    }

    private function loadResource($sourceId)
    {
        $response = $this->client->getResource(self::ENTITY . '/' . (int) $sourceId);
        if (!isset($response->cart_rule)) {
            throw new RuntimeException('Source cart rule ' . (int) $sourceId . ' was not returned by the Webservice.');
        }

        return $response->cart_rule;
    }

    private function prepareDatabase()
    {
        if (!KbizMigrationCategoryImporter::installDatabase()) {
            throw new RuntimeException('Could not prepare cart-rule migration storage.');
        }
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
            throw new RuntimeException('Could not create cart-rule migration progress.');
        }

        return ['last_source_id' => 0, 'is_complete' => 0, 'total_count' => 0];
    }

    private function saveTotalCount($total)
    {
        $updated = Db::getInstance()->update(
            'kbizmigration_state',
            ['total_count' => (int) $total, 'date_upd' => date('Y-m-d H:i:s')],
            '`source_key` = \'' . pSQL($this->sourceKey) . '\' AND `entity` = \'' . self::ENTITY . '\''
        );
        if (!$updated && !Db::getInstance()->Affected_Rows()) {
            $state = $this->getState();
            if ((int) $state['total_count'] !== (int) $total) {
                throw new RuntimeException('Could not save the source cart-rule total.');
            }
        }
    }

    private function saveState($cursor, $done)
    {
        if (!Db::getInstance()->update(
            'kbizmigration_state',
            [
                'last_source_id' => (int) $cursor,
                'is_complete' => $done ? 1 : 0,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            '`source_key` = \'' . pSQL($this->sourceKey) . '\' AND `entity` = \'' . self::ENTITY . '\''
        ) && !Db::getInstance()->Affected_Rows()) {
            $state = $this->getState();
            if ((int) $state['last_source_id'] !== (int) $cursor || (bool) $state['is_complete'] !== (bool) $done) {
                throw new RuntimeException('Could not save cart-rule migration progress.');
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
        $existing = $this->getMapping($sourceId);
        if ($existing) {
            $updated = Db::getInstance()->update(
                'kbizmigration_map',
                ['destination_id' => (int) $destinationId, 'status' => pSQL($status)],
                '`source_key` = \'' . pSQL($this->sourceKey) . '\'
                    AND `entity` = \'' . self::ENTITY . '\'
                    AND `source_id` = ' . (int) $sourceId
            );

            return (bool) $updated || (
                (int) $existing['destination_id'] === (int) $destinationId
                && $existing['status'] === $status
            );
        }

        return (bool) Db::getInstance()->insert('kbizmigration_map', [
            'source_key' => pSQL($this->sourceKey),
            'entity' => self::ENTITY,
            'source_id' => (int) $sourceId,
            'destination_id' => (int) $destinationId,
            'status' => pSQL($status),
        ]);
    }

    private static function extractItems(SimpleXMLElement $response, $resource, $itemName)
    {
        $items = [];
        if (isset($response->{$resource}->{$itemName})) {
            foreach ($response->{$resource}->{$itemName} as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function getId(SimpleXMLElement $node)
    {
        if (isset($node->id) && (int) $node->id > 0) {
            return (int) $node->id;
        }

        return isset($node['id']) ? (int) $node['id'] : 0;
    }
}
