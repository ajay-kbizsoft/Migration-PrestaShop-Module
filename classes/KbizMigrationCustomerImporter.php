<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class KbizMigrationCustomerImporter
{
    private const ENTITY = 'customers';
    private const ADDRESS_ENTITY = 'customer_addresses';
    private const BATCH_SIZE = 5;

    private $client;
    private $sourceKey;
    private $shopId;

    public function __construct(KbizMigrationSourceWebserviceClient $client, $sourceUrl)
    {
        $this->client = $client;
        $this->sourceKey = hash('sha256', rtrim($sourceUrl, '/'));
        $this->shopId = (int) Context::getContext()->shop->id;
    }

    public function getProgress()
    {
        $this->prepareDatabase();
        $state = $this->getState();
        $total = (int) $state['total_count'];
        if ($total === 0) {
            $total = $this->client->countResource('customers');
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
            'addresses' => $this->countMappings('imported', self::ADDRESS_ENTITY),
            'total' => $total,
            'processed' => $processed,
            'remaining' => max(0, $total - $processed),
        ];
    }

    public function ensureAddressesForSourceCustomer($sourceCustomerId)
    {
        $mapping = $this->getMapping(self::ENTITY, (int) $sourceCustomerId);
        if (!$mapping || (int) $mapping['destination_id'] <= 0) {
            throw new RuntimeException(
                'Source customer ' . (int) $sourceCustomerId . ' must be migrated before its order addresses can be imported.'
            );
        }
        $customer = new Customer((int) $mapping['destination_id']);
        if (!Validate::isLoadedObject($customer)) {
            throw new RuntimeException(
                'Destination customer for source customer ' . (int) $sourceCustomerId . ' is missing.'
            );
        }
        $this->importAddresses((int) $sourceCustomerId, $customer);
    }

    public function runNextBatch()
    {
        $this->prepareDatabase();
        $state = $this->getState();
        $total = (int) $state['total_count'];
        if ($total === 0) {
            $total = $this->client->countResource('customers');
            $this->saveTotalCount($total);
        }
        if ($total === 0) {
            $this->saveState(0, true);

            return ['done' => true, 'processed' => 0, 'imported' => 0, 'skipped' => 0, 'addresses' => 0];
        }
        if ($state['is_complete']) {
            return [
                'done' => true,
                'processed' => 0,
                'imported' => $this->countMappings('imported'),
                'skipped' => $this->countMappings('skipped'),
                'addresses' => $this->countMappings('imported', self::ADDRESS_ENTITY),
            ];
        }

        $cursor = (int) $state['last_source_id'];
        $response = $this->client->getResource('customers', [
            'display' => '[id]',
            'sort' => '[id_ASC]',
            'filter' => ['id' => '>[' . $cursor . ']'],
            'limit' => '0,' . (self::BATCH_SIZE + 1),
        ]);
        $items = self::extractItems($response, 'customers', 'customer');
        $hasMore = count($items) > self::BATCH_SIZE;
        if ($hasMore) {
            $items = array_slice($items, 0, self::BATCH_SIZE);
        }

        $processed = 0;
        foreach ($items as $item) {
            $sourceId = self::getId($item);
            if ($sourceId <= $cursor) {
                throw new RuntimeException('The source customer list is not ordered by increasing ID.');
            }
            $this->importCustomer($sourceId);
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
            'addresses' => $this->countMappings('imported', self::ADDRESS_ENTITY),
        ];
    }

    private function importCustomer($sourceId)
    {
        $source = $this->loadResource('customers', $sourceId, 'customer');
        $mapping = $this->getMapping(self::ENTITY, $sourceId);
        if ($mapping && $mapping['status'] === 'skipped') {
            $destinationCustomer = new Customer((int) $mapping['destination_id']);
            if (Validate::isLoadedObject($destinationCustomer)) {
                $this->importAddresses($sourceId, $destinationCustomer);
            }

            return;
        }

        if (!$mapping) {
            $email = trim((string) $source->email);
            if (!Validate::isEmail($email)) {
                throw new RuntimeException('Source customer ' . $sourceId . ' has an invalid email address.');
            }
            $existingCustomerId = (int) Customer::customerExists($email, true);
            if ($existingCustomerId > 0) {
                $this->saveMapping(self::ENTITY, $sourceId, $existingCustomerId, 'skipped');
                $destinationCustomer = new Customer($existingCustomerId);
                if (Validate::isLoadedObject($destinationCustomer)) {
                    $this->importAddresses($sourceId, $destinationCustomer);
                }

                return;
            }
        }

        $customer = $mapping ? new Customer((int) $mapping['destination_id']) : new Customer();
        if ($mapping && !Validate::isLoadedObject($customer)) {
            $customer = new Customer();
        }
        $this->populateCustomer($customer, $source);
        if ($mapping && Validate::isLoadedObject($customer)) {
            $saved = $customer->update();
        } else {
            $customer->id_shop = $this->shopId;
            $customer->id_shop_group = (int) Context::getContext()->shop->id_shop_group;
            $customer->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
            $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
            $customer->newsletter = false;
            $customer->optin = false;
            $customer->passwd = Tools::hash(Tools::passwdGen(32));
            $saved = $customer->add();
        }
        if (!$saved || (int) $customer->id <= 0) {
            throw new RuntimeException('Could not save source customer ' . $sourceId . '. Check the customer data and destination email uniqueness.');
        }

        $this->saveMapping(self::ENTITY, $sourceId, (int) $customer->id, 'imported');
        $this->importAddresses($sourceId, $customer);
    }

    private function populateCustomer(Customer $customer, SimpleXMLElement $source)
    {
        $customer->deleted = false;
        foreach (['firstname', 'lastname', 'email'] as $field) {
            $customer->{$field} = trim((string) $source->{$field});
        }
        if (!Validate::isEmail($customer->email)) {
            throw new RuntimeException('A source customer has an invalid email address.');
        }

        foreach (['company', 'website', 'siret', 'ape'] as $field) {
            if (isset($source->{$field})) {
                $customer->{$field} = trim((string) $source->{$field});
            }
        }
        if (isset($source->active)) {
            $customer->active = (int) $source->active === 1;
        }
        if (isset($source->birthday)) {
            $birthday = trim((string) $source->birthday);
            if ($birthday !== '' && $birthday !== '0000-00-00' && Validate::isBirthDate($birthday)) {
                $customer->birthday = $birthday;
            }
        }
    }

    private function importAddresses($sourceCustomerId, Customer $destinationCustomer)
    {
        $cursor = 0;
        for ($page = 0; $page < 10000; ++$page) {
            $response = $this->client->getResource('addresses', [
                'display' => '[id]',
                'sort' => '[id_ASC]',
                'filter' => [
                    'id_customer' => '[' . (int) $sourceCustomerId . ']',
                    'id' => '>[' . $cursor . ']',
                ],
                'limit' => '0,100',
            ]);
            $items = self::extractItems($response, 'addresses', 'address');
            foreach ($items as $item) {
                $sourceAddressId = self::getId($item);
                if ($sourceAddressId <= $cursor) {
                    throw new RuntimeException('The source customer address list is not ordered by increasing ID.');
                }
                $sourceAddress = $this->loadResource('addresses', $sourceAddressId, 'address');
                if ((int) $sourceAddress->id_customer !== (int) $sourceCustomerId) {
                    throw new RuntimeException('Source address ' . $sourceAddressId . ' does not belong to the expected customer.');
                }
                $this->importAddress($sourceAddressId, $sourceAddress, $destinationCustomer);
                $cursor = $sourceAddressId;
            }
            if (count($items) < 100) {
                return;
            }
        }

        throw new RuntimeException('Could not finish reading addresses for source customer ' . (int) $sourceCustomerId . '.');
    }

    private function importAddress($sourceAddressId, SimpleXMLElement $source, Customer $customer)
    {
        $mapping = $this->getMapping(self::ADDRESS_ENTITY, $sourceAddressId);
        $address = $mapping ? new Address((int) $mapping['destination_id']) : new Address();
        if ($mapping && !Validate::isLoadedObject($address)) {
            $mapping = null;
            $address = new Address();
        }

        $address->id_customer = (int) $customer->id;
        $address->deleted = false;
        $address->id_country = $this->mapCountry(isset($source->id_country) ? (int) $source->id_country : 0);
        $address->id_state = $this->mapState(
            isset($source->id_state) ? (int) $source->id_state : 0,
            (int) $address->id_country,
            isset($source->id_country) ? (int) $source->id_country : 0
        );
        $address->alias = $this->addressValue($source, 'alias', 'Migrated address');
        $address->firstname = $this->addressValue($source, 'firstname', (string) $customer->firstname);
        $address->lastname = $this->addressValue($source, 'lastname', (string) $customer->lastname);
        foreach ([
            'company',
            'address1',
            'address2',
            'postcode',
            'city',
            'other',
            'phone',
            'phone_mobile',
            'vat_number',
            'dni',
        ] as $field) {
            $address->{$field} = isset($source->{$field}) ? trim((string) $source->{$field}) : '';
        }

        if ($mapping && Validate::isLoadedObject($address)) {
            $saved = $address->update();
        } else {
            $saved = $address->add();
        }
        if (!$saved || (int) $address->id <= 0) {
            throw new RuntimeException(
                'Could not save source address ' . (int) $sourceAddressId
                . '. Check that its required fields and country/state are valid in the destination.'
            );
        }
        $this->saveMapping(self::ADDRESS_ENTITY, $sourceAddressId, (int) $address->id, 'imported');
    }

    private function mapCountry($sourceCountryId)
    {
        if ($sourceCountryId <= 0) {
            throw new RuntimeException('A source customer address has no country.');
        }
        $country = $this->loadResource('countries', $sourceCountryId, 'country');
        $isoCode = strtoupper(trim((string) $country->iso_code));
        if ($isoCode === '' || !Validate::isLanguageIsoCode($isoCode)) {
            throw new RuntimeException('Could not determine the ISO country code for source country ' . $sourceCountryId . '.');
        }
        $destinationCountryId = (int) Country::getByIso($isoCode);
        if ($destinationCountryId <= 0) {
            throw new RuntimeException(
                'Destination country ' . $isoCode . ' is not configured. Add it before retrying customer migration.'
            );
        }

        return $destinationCountryId;
    }

    private function mapState($sourceStateId, $destinationCountryId, $sourceCountryId)
    {
        if ($sourceStateId <= 0) {
            return 0;
        }
        $state = $this->loadResource('states', $sourceStateId, 'state');
        if (
            isset($state->id_country)
            && (int) $state->id_country > 0
            && (int) $state->id_country !== (int) $sourceCountryId
        ) {
            throw new RuntimeException(
                'Source state ' . (int) $sourceStateId . ' does not belong to source address country '
                . (int) $sourceCountryId . '.'
            );
        }
        $isoCode = strtoupper(trim((string) $state->iso_code));
        $destinationStateId = $isoCode !== ''
            ? (int) State::getIdByIso($isoCode, $destinationCountryId)
            : 0;
        if ($destinationStateId > 0) {
            return $destinationStateId;
        }
        if ($isoCode === '' || !Validate::isStateIsoCode($isoCode) || strlen($isoCode) > 7) {
            throw new RuntimeException(
                'Source state ' . (int) $sourceStateId
                . ' has no valid ISO code and cannot be created in the destination.'
            );
        }

        $country = new Country((int) $destinationCountryId);
        $stateName = trim((string) $state->name);
        if (
            !Validate::isLoadedObject($country)
            || (int) $country->id_zone <= 0
            || $stateName === ''
            || !Validate::isGenericName($stateName)
            || Tools::strlen($stateName) > 80
        ) {
            throw new RuntimeException(
                'Source state "' . $isoCode
                . '" cannot be created because its name or destination country zone is invalid.'
            );
        }

        $destinationState = new State();
        $destinationState->id_country = (int) $destinationCountryId;
        $destinationState->id_zone = (int) $country->id_zone;
        $destinationState->iso_code = $isoCode;
        $destinationState->name = $stateName;
        $destinationState->active = !isset($state->active) || (bool) (int) $state->active;
        if (!$destinationState->add()) {
            $destinationStateId = (int) State::getIdByIso($isoCode, $destinationCountryId);
            if ($destinationStateId <= 0) {
                throw new RuntimeException(
                    'Could not create destination state "' . $isoCode . '" for the mapped address country.'
                );
            }

            return $destinationStateId;
        }

        return (int) $destinationState->id;
    }

    private function addressValue(SimpleXMLElement $source, $field, $fallback)
    {
        $value = isset($source->{$field}) ? trim((string) $source->{$field}) : '';

        return $value !== '' ? $value : $fallback;
    }

    private function loadResource($resource, $id, $nodeName)
    {
        $response = $this->client->getResource($resource . '/' . (int) $id);
        if (!isset($response->{$nodeName})) {
            throw new RuntimeException('Source ' . $nodeName . ' ' . (int) $id . ' was not returned by the Webservice.');
        }

        return $response->{$nodeName};
    }

    private function prepareDatabase()
    {
        if (!KbizMigrationCategoryImporter::installDatabase()) {
            throw new RuntimeException('Could not prepare the customer migration progress tables.');
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
            throw new RuntimeException('Could not create customer migration progress.');
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
                throw new RuntimeException('Could not save the source customer total.');
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
                throw new RuntimeException('Could not save customer migration progress.');
            }
        }
    }

    private function countMappings($status, $entity = self::ENTITY)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'kbizmigration_map`
            WHERE `source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND `entity` = \'' . pSQL($entity) . '\'
                AND `status` = \'' . pSQL($status) . '\''
        );
    }

    private function getMapping($entity, $sourceId)
    {
        return Db::getInstance()->getRow(
            'SELECT `destination_id`, `status` FROM `' . _DB_PREFIX_ . 'kbizmigration_map`
            WHERE `source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND `entity` = \'' . pSQL($entity) . '\'
                AND `source_id` = ' . (int) $sourceId
        );
    }

    private function saveMapping($entity, $sourceId, $destinationId, $status)
    {
        $existing = $this->getMapping($entity, $sourceId);
        if ($existing) {
            if (!Db::getInstance()->update(
                'kbizmigration_map',
                ['destination_id' => (int) $destinationId, 'status' => pSQL($status)],
                '`source_key` = \'' . pSQL($this->sourceKey) . '\'
                    AND `entity` = \'' . pSQL($entity) . '\'
                    AND `source_id` = ' . (int) $sourceId
            ) && ((int) $existing['destination_id'] !== (int) $destinationId || $existing['status'] !== $status)) {
                throw new RuntimeException('Could not update the customer migration mapping.');
            }

            return;
        }
        if (!Db::getInstance()->insert('kbizmigration_map', [
            'source_key' => pSQL($this->sourceKey),
            'entity' => pSQL($entity),
            'source_id' => (int) $sourceId,
            'destination_id' => (int) $destinationId,
            'status' => pSQL($status),
        ])) {
            throw new RuntimeException('Could not save a customer migration mapping.');
        }
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
