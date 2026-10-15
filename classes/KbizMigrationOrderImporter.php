<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class KbizMigrationOrderImporter
{
    private const ENTITY = 'orders';
    private const BATCH_SIZE = 3;
    private const PAGE_SIZE = 100;

    private $client;
    private $sourceUrl;
    private $sourceKey;
    private $shopId;
    private $shopGroupId;
    private $defaultLanguageId;
    private $languages;
    private $sourceLanguages;
    private $stateMappings = [];

    public function __construct(KbizMigrationSourceWebserviceClient $client, $sourceUrl)
    {
        $this->client = $client;
        $this->sourceUrl = rtrim($sourceUrl, '/');
        $this->sourceKey = hash('sha256', rtrim($sourceUrl, '/'));
        $context = Context::getContext();
        $this->shopId = (int) $context->shop->id;
        $this->shopGroupId = (int) $context->shop->id_shop_group;
        $this->defaultLanguageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $this->languages = Language::getLanguages(false);
        $this->sourceLanguages = $this->loadSourceLanguages();
    }

    public function getProgress()
    {
        $this->prepareDatabase();
        $state = $this->getState();
        $total = (int) $state['total_count'];
        if ($total === 0) {
            $total = $this->client->countResource('orders');
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
            $total = $this->client->countResource('orders');
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
        $response = $this->client->getResource('orders', [
            'display' => '[id]',
            'sort' => '[id_ASC]',
            'filter' => ['id' => '>[' . $cursor . ']'],
            'limit' => '0,' . (self::BATCH_SIZE + 1),
        ]);
        $items = self::extractItems($response, 'orders', 'order');
        $hasMore = count($items) > self::BATCH_SIZE;
        if ($hasMore) {
            $items = array_slice($items, 0, self::BATCH_SIZE);
        }

        $processed = 0;
        foreach ($items as $item) {
            $sourceId = self::getId($item);
            if ($sourceId <= $cursor) {
                throw new RuntimeException('The source order list is not ordered by increasing ID.');
            }
            $this->importOrder($sourceId);
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

    public function getInvoiceAndDiscountProgress()
    {
        $this->prepareDatabase();
        $state = $this->getState('order_financials');
        $total = (int) $state['total_count'];
        if ($total === 0) {
            $total = $this->client->countResource('orders');
            $this->saveTotalCount($total, 'order_financials');
        }

        return [
            'last_source_id' => (int) $state['last_source_id'],
            'done' => (bool) $state['is_complete']
                && $this->countEntityMappings('order_financials', 'imported') >= $total,
            'imported' => $this->countEntityMappings('order_invoices', 'imported'),
            'attached' => $this->countEntityMappings('order_cart_rules', 'imported'),
            'skipped' => $this->countEntityMappings('order_cart_rules', 'skipped'),
            'total' => $total,
            'processed' => min($total, $this->countEntityMappings('order_financials', 'imported')),
            'remaining' => max(0, $total - $this->countEntityMappings('order_financials', 'imported')),
        ];
    }

    public function runNextInvoiceAndDiscountBatch()
    {
        $this->prepareDatabase();
        $entity = 'order_financials';
        $state = $this->getState($entity);
        $total = (int) $state['total_count'];
        if ($total === 0) {
            $total = $this->client->countResource('orders');
            $this->saveTotalCount($total, $entity);
        }
        if ($total === 0) {
            $this->saveState(0, true, $entity);

            return [
                'done' => true,
                'processed' => 0,
                'invoices' => 0,
                'attached' => 0,
                'skipped' => 0,
            ];
        }
        if ($state['is_complete']) {
            return [
                'done' => true,
                'processed' => 0,
                'invoices' => $this->countEntityMappings('order_invoices', 'imported'),
                'attached' => $this->countEntityMappings('order_cart_rules', 'imported'),
                'skipped' => $this->countEntityMappings('order_cart_rules', 'skipped'),
            ];
        }

        $cursor = (int) $state['last_source_id'];
        $response = $this->client->getResource('orders', [
            'display' => '[id]',
            'sort' => '[id_ASC]',
            'filter' => ['id' => '>[' . $cursor . ']'],
            'limit' => '0,' . (self::BATCH_SIZE + 1),
        ]);
        $items = self::extractItems($response, 'orders', 'order');
        $hasMore = count($items) > self::BATCH_SIZE;
        if ($hasMore) {
            $items = array_slice($items, 0, self::BATCH_SIZE);
        }

        $processed = 0;
        foreach ($items as $item) {
            $sourceOrderId = self::getId($item);
            if ($sourceOrderId <= $cursor) {
                throw new RuntimeException('The source order list is not ordered by increasing ID.');
            }
            $this->importOrderInvoicesAndDiscounts($sourceOrderId);
            $cursor = $sourceOrderId;
            ++$processed;
        }

        $done = !$hasMore;
        $this->saveState($cursor, $done, $entity);

        return [
            'done' => $done,
            'processed' => $processed,
            'invoices' => $this->countEntityMappings('order_invoices', 'imported'),
            'attached' => $this->countEntityMappings('order_cart_rules', 'imported'),
            'skipped' => $this->countEntityMappings('order_cart_rules', 'skipped'),
        ];
    }

    private function importOrderInvoicesAndDiscounts($sourceOrderId)
    {
        $progressMapping = $this->getMapping('order_financials', $sourceOrderId);
        if ($progressMapping && $progressMapping['status'] === 'imported') {
            return;
        }
        $orderMapping = $this->getMapping(self::ENTITY, $sourceOrderId);
        if (!$orderMapping || $orderMapping['status'] !== 'imported') {
            throw new RuntimeException(
                'Import source order ' . (int) $sourceOrderId . ' before migrating its invoices and cart-rule history.'
            );
        }
        $destinationOrder = new Order((int) $orderMapping['destination_id']);
        if (!Validate::isLoadedObject($destinationOrder)) {
            throw new RuntimeException(
                'Destination order mapped from source order ' . (int) $sourceOrderId . ' no longer exists.'
            );
        }

        $sourceInvoices = $this->loadRelatedResources(
            'order_invoices',
            'order_invoice',
            $sourceOrderId
        );
        $sourceOrderCartRules = $this->loadRelatedResources(
            'order_cart_rules',
            'order_cart_rule',
            $sourceOrderId
        );
        $sourceDetails = $this->loadOrderDetails($sourceOrderId);
        $destinationDetails = $this->loadDestinationOrderDetails((int) $destinationOrder->id);
        if (count($sourceDetails) !== count($destinationDetails)) {
            throw new RuntimeException(
                'Source and destination order ' . (int) $sourceOrderId
                . ' have different line counts; invoice lines cannot be safely matched.'
            );
        }

        if (!Db::getInstance()->execute('START TRANSACTION')) {
            throw new RuntimeException(
                'Could not start a transaction for invoice and cart-rule history of source order '
                . (int) $sourceOrderId . '.'
            );
        }
        try {
            $invoiceIds = [];
            $latestInvoice = null;
            foreach ($sourceInvoices as $sourceInvoiceSummary) {
                $sourceInvoiceId = self::getId($sourceInvoiceSummary);
                $sourceInvoice = $this->loadResource(
                    'order_invoices',
                    $sourceInvoiceId,
                    'order_invoice'
                );
                if ((int) $sourceInvoice->id_order !== (int) $sourceOrderId) {
                    throw new RuntimeException(
                        'Source invoice ' . $sourceInvoiceId . ' does not belong to source order ' . (int) $sourceOrderId . '.'
                    );
                }
                if (!isset($sourceInvoice->number) || (int) $sourceInvoice->number <= 0) {
                    $invoiceIds[$sourceInvoiceId] = 0;
                    continue;
                }
                $destinationInvoiceId = $this->ensureDestinationInvoice(
                    $sourceInvoiceId,
                    $sourceInvoice,
                    $destinationOrder
                );
                $invoiceIds[$sourceInvoiceId] = $destinationInvoiceId;
                $invoiceDate = $this->sourceDate($sourceInvoice, 'date_add', $this->sourceDate($sourceInvoice, 'delivery_date'));
                $invoice = new OrderInvoice($destinationInvoiceId);
                if (
                    $latestInvoice === null
                    || strcmp($invoiceDate, $latestInvoice['date_add']) > 0
                    || (
                        $invoiceDate === $latestInvoice['date_add']
                        && (int) $invoice->number > (int) $latestInvoice['number']
                    )
                ) {
                    $latestInvoice = [
                        'date_add' => $invoiceDate,
                        'number' => (int) $invoice->number,
                    ];
                }
            }

            $this->mapSourceOrderDetails(
                $sourceDetails,
                $destinationDetails,
                $invoiceIds,
                (int) $destinationOrder->id
            );
            foreach ($sourceOrderCartRules as $sourceOrderCartRule) {
                $this->importOrderCartRule(
                    $sourceOrderId,
                    (int) $destinationOrder->id,
                    $sourceOrderCartRule,
                    $invoiceIds
                );
            }
            if ($latestInvoice !== null) {
                $destinationOrder->invoice_number = $latestInvoice['number'];
                $destinationOrder->invoice_date = $latestInvoice['date_add'];
                if (!$destinationOrder->update()) {
                    throw new RuntimeException(
                        'Could not update invoice details for destination order ' . (int) $destinationOrder->id . '.'
                    );
                }
            }
            if (!$this->saveMapping('order_financials', $sourceOrderId, (int) $destinationOrder->id, 'imported')) {
                throw new RuntimeException('Could not save invoice and discount migration progress for source order.');
            }
            if (!Db::getInstance()->execute('COMMIT')) {
                throw new RuntimeException(
                    'Could not commit invoice and discount history for source order ' . (int) $sourceOrderId . '.'
                );
            }
        } catch (Throwable $exception) {
            Db::getInstance()->execute('ROLLBACK');
            throw $exception;
        }
    }

    private function ensureDestinationInvoice($sourceInvoiceId, SimpleXMLElement $source, Order $order)
    {
        $mapping = $this->getMapping('order_invoices', $sourceInvoiceId);
        if ($mapping && Validate::isLoadedObject(new OrderInvoice((int) $mapping['destination_id']))) {
            $invoice = new OrderInvoice((int) $mapping['destination_id']);
            if ((int) $invoice->id_order !== (int) $order->id) {
                throw new RuntimeException('A mapped destination invoice belongs to a different order.');
            }
            $invoice->number = (int) $invoice->number;
        } else {
            $invoice = new OrderInvoice();
            $invoice->id_order = (int) $order->id;
            $invoice->number = 0;
            $invoice->delivery_number = 0;
            $invoice->delivery_date = '0000-00-00 00:00:00';
        }

        $invoice->id_order = (int) $order->id;
        foreach ([
            'total_discount_tax_excl',
            'total_discount_tax_incl',
            'total_paid_tax_excl',
            'total_paid_tax_incl',
            'total_products',
            'total_products_wt',
            'total_shipping_tax_excl',
            'total_shipping_tax_incl',
            'shipping_tax_computation_method',
            'total_wrapping_tax_excl',
            'total_wrapping_tax_incl',
        ] as $field) {
            if (isset($source->{$field})) {
                $invoice->{$field} = (string) $source->{$field};
            }
        }
        $sourceNumber = isset($source->number) ? (int) $source->number : 0;
        $sourceDate = $this->sourceDate($source, 'date_add', $this->sourceDate($source, 'delivery_date'));
        $sourceNote = isset($source->note) ? trim(strip_tags((string) $source->note)) : '';
        $migrationNote = 'Migrated source invoice #' . $sourceNumber;
        $invoice->note = trim($sourceNote . ($sourceNote !== '' ? "\n" : '') . $migrationNote);

        if (!(int) $invoice->id) {
            if (!$invoice->add()) {
                throw new RuntimeException('Could not create a destination invoice for source invoice ' . $sourceInvoiceId . '.');
            }
            $invoice->number = $this->nextInvoiceNumber($sourceDate);
        }
        $invoice->date_add = $sourceDate;
        $invoice->delivery_number = 0;
        $invoice->delivery_date = '0000-00-00 00:00:00';
        if (!$invoice->update()) {
            throw new RuntimeException('Could not save destination invoice for source invoice ' . $sourceInvoiceId . '.');
        }
        if (!$this->saveMapping('order_invoices', $sourceInvoiceId, (int) $invoice->id, 'imported')) {
            throw new RuntimeException('Could not save the mapping for source invoice ' . $sourceInvoiceId . '.');
        }

        return (int) $invoice->id;
    }

    private function nextInvoiceNumber($invoiceDate)
    {
        $sql = 'SELECT MAX(`number`) FROM `' . _DB_PREFIX_ . 'order_invoice`';
        if (Configuration::get('PS_INVOICE_RESET')) {
            $sql .= ' WHERE YEAR(`date_add`) = ' . (int) date('Y', strtotime($invoiceDate));
        }
        $lastNumber = Db::getInstance()->getValue($sql);
        if ($lastNumber === false) {
            throw new RuntimeException('Could not determine the next destination invoice number.');
        }

        return (int) $lastNumber + 1;
    }

    private function mapSourceOrderDetails(array $sourceDetails, array $destinationDetails, array $invoiceIds, $destinationOrderId)
    {
        foreach ($sourceDetails as $index => $sourceDetail) {
            $sourceDetailId = self::getId($sourceDetail);
            if ($sourceDetailId <= 0) {
                throw new RuntimeException('A source order line has an invalid ID.');
            }
            $mapping = $this->getMapping('order_details', $sourceDetailId);
            $destinationDetail = $mapping
                ? $this->loadDestinationOrderDetail((int) $mapping['destination_id'])
                : $destinationDetails[$index];
            if (
                !$destinationDetail
                || (int) $destinationDetail['id_order'] !== (int) $destinationOrderId
                || (int) $destinationDetail['product_id'] !== $this->mappedDestinationId(
                    'products',
                    (int) $sourceDetail->product_id,
                    'product in source order line'
                )
                || (int) $destinationDetail['product_attribute_id'] !== $this->mappedOptionalCombinationId(
                    isset($sourceDetail->product_attribute_id) ? (int) $sourceDetail->product_attribute_id : 0
                )
                || (int) $destinationDetail['product_quantity'] !== (int) $sourceDetail->product_quantity
            ) {
                throw new RuntimeException(
                    'Could not safely match source order line ' . $sourceDetailId . ' to its destination line.'
                );
            }

            $destinationInvoiceId = 0;
            $sourceInvoiceId = isset($sourceDetail->id_order_invoice) ? (int) $sourceDetail->id_order_invoice : 0;
            if ($sourceInvoiceId > 0) {
                if (!array_key_exists($sourceInvoiceId, $invoiceIds)) {
                    throw new RuntimeException(
                        'Source order line ' . $sourceDetailId . ' references an invoice that was not returned.'
                    );
                }
                $destinationInvoiceId = $invoiceIds[$sourceInvoiceId];
            }
            if (!Db::getInstance()->update(
                'order_detail',
                ['id_order_invoice' => $destinationInvoiceId],
                '`id_order_detail` = ' . (int) $destinationDetail['id_order_detail']
                    . ' AND `id_order` = ' . (int) $destinationOrderId
            )) {
                throw new RuntimeException('Could not link a destination order line to its invoice.');
            }
            if (!$this->saveMapping(
                'order_details',
                $sourceDetailId,
                (int) $destinationDetail['id_order_detail'],
                'imported'
            )) {
                throw new RuntimeException('Could not save the source order-line mapping.');
            }
        }
    }

    private function mappedOptionalCombinationId($sourceCombinationId)
    {
        return $sourceCombinationId > 0
            ? $this->mappedDestinationId('product_combinations', $sourceCombinationId, 'product combination')
            : 0;
    }

    private function loadDestinationOrderDetails($destinationOrderId)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_order_detail`, `id_order`, `product_id`, `product_attribute_id`, `product_quantity`
            FROM `' . _DB_PREFIX_ . 'order_detail`
            WHERE `id_order` = ' . (int) $destinationOrderId . '
            ORDER BY `id_order_detail` ASC'
        );
        if ($rows === false) {
            throw new RuntimeException('Could not read destination order lines for invoice mapping.');
        }

        return $rows;
    }

    private function loadDestinationOrderDetail($destinationDetailId)
    {
        $row = Db::getInstance()->getRow(
            'SELECT `id_order_detail`, `id_order`, `product_id`, `product_attribute_id`, `product_quantity`
            FROM `' . _DB_PREFIX_ . 'order_detail`
            WHERE `id_order_detail` = ' . (int) $destinationDetailId
        );

        return $row ?: null;
    }

    private function importOrderCartRule($sourceOrderId, $destinationOrderId, SimpleXMLElement $source, array $invoiceIds)
    {
        $sourceAssociationId = self::getId($source);
        if ($sourceAssociationId <= 0 || (int) $source->id_order !== (int) $sourceOrderId) {
            throw new RuntimeException('The source order-cart-rule list contains an invalid association.');
        }
        $mapping = $this->getMapping('order_cart_rules', $sourceAssociationId);
        if ($mapping && in_array($mapping['status'], ['imported', 'skipped'], true)) {
            return;
        }
        $sourceCartRuleId = isset($source->id_cart_rule) ? (int) $source->id_cart_rule : 0;
        $cartRuleMapping = $this->getMapping('cart_rules', $sourceCartRuleId);
        if (!$cartRuleMapping) {
            throw new RuntimeException(
                'Migrate cart rules before importing order discounts; source cart rule '
                . (int) $sourceCartRuleId . ' has no migration mapping.'
            );
        }
        if ($cartRuleMapping['status'] !== 'imported') {
            if (!$this->saveMapping('order_cart_rules', $sourceAssociationId, 0, 'skipped')) {
                throw new RuntimeException('Could not record an order discount without an imported destination cart rule.');
            }

            return;
        }
        if (!Validate::isLoadedObject(new CartRule((int) $cartRuleMapping['destination_id']))) {
            throw new RuntimeException(
                'The destination cart rule mapped from source cart rule ' . $sourceCartRuleId . ' is missing.'
            );
        }

        $sourceInvoiceId = isset($source->id_order_invoice) ? (int) $source->id_order_invoice : 0;
        $destinationInvoiceId = 0;
        if ($sourceInvoiceId > 0) {
            if (!array_key_exists($sourceInvoiceId, $invoiceIds)) {
                $invoiceMapping = $this->getMapping('order_invoices', $sourceInvoiceId);
                if (!$invoiceMapping || $invoiceMapping['status'] !== 'imported') {
                    throw new RuntimeException('An order discount references an invoice that has not been migrated.');
                }
                $destinationInvoiceId = (int) $invoiceMapping['destination_id'];
            } else {
                $destinationInvoiceId = $invoiceIds[$sourceInvoiceId];
            }
        }

        $orderCartRule = new OrderCartRule();
        $orderCartRule->id_order = (int) $destinationOrderId;
        $orderCartRule->id_cart_rule = (int) $cartRuleMapping['destination_id'];
        $orderCartRule->id_order_invoice = $destinationInvoiceId;
        $orderCartRule->name = isset($source->name) ? trim(strip_tags((string) $source->name)) : '';
        $orderCartRule->value = isset($source->value) ? (float) $source->value : 0;
        $orderCartRule->value_tax_excl = isset($source->value_tax_excl) ? (float) $source->value_tax_excl : 0;
        $orderCartRule->free_shipping = isset($source->free_shipping) && (bool) (int) $source->free_shipping;
        $orderCartRule->deleted = isset($source->deleted) && (bool) (int) $source->deleted;
        if (!$orderCartRule->add()) {
            throw new RuntimeException('Could not attach a migrated discount to destination order ' . (int) $destinationOrderId . '.');
        }
        if (!$this->saveMapping(
            'order_cart_rules',
            $sourceAssociationId,
            (int) $orderCartRule->id,
            'imported'
        )) {
            throw new RuntimeException('Could not save the source order-discount mapping.');
        }
    }

    private function loadRelatedResources($resource, $itemNode, $sourceOrderId)
    {
        $items = [];
        $cursor = 0;
        for ($page = 0; $page < 10000; ++$page) {
            $response = $this->client->getResource($resource, [
                'display' => 'full',
                'sort' => '[id_ASC]',
                'filter' => [
                    'id_order' => '[' . (int) $sourceOrderId . ']',
                    'id' => '>[' . $cursor . ']',
                ],
                'limit' => '0,' . self::PAGE_SIZE,
            ]);
            $pageItems = self::extractItems($response, $resource, $itemNode);
            foreach ($pageItems as $item) {
                $id = self::getId($item);
                if ($id <= $cursor || (int) $item->id_order !== (int) $sourceOrderId) {
                    throw new RuntimeException(
                        'The source ' . $resource . ' list for order ' . (int) $sourceOrderId . ' is invalid or unordered.'
                    );
                }
                $items[] = $item;
                $cursor = $id;
            }
            if (count($pageItems) < self::PAGE_SIZE) {
                return $items;
            }
        }

        throw new RuntimeException('Could not finish reading source ' . $resource . ' for order ' . (int) $sourceOrderId . '.');
    }

    private function importOrder($sourceOrderId)
    {
        $source = $this->loadResource('orders', $sourceOrderId, 'order');
        $mapping = $this->getMapping(self::ENTITY, $sourceOrderId);
        if ($mapping && Validate::isLoadedObject(new Order((int) $mapping['destination_id']))) {
            return;
        }

        $sourceCustomerId = isset($source->id_customer) ? (int) $source->id_customer : 0;
        $customerId = $this->mappedDestinationId('customers', $sourceCustomerId, 'customer');
        $customer = new Customer($customerId);
        if (!Validate::isLoadedObject($customer)) {
            throw new RuntimeException('The destination customer for source order ' . $sourceOrderId . ' is missing.');
        }
        (new KbizMigrationCustomerImporter($this->client, $this->sourceUrl))
            ->ensureAddressesForSourceCustomer($sourceCustomerId);

        $deliveryAddressId = $this->mappedDestinationId(
            'customer_addresses',
            isset($source->id_address_delivery) ? (int) $source->id_address_delivery : 0,
            'delivery address'
        );
        $invoiceAddressId = $this->mappedDestinationId(
            'customer_addresses',
            isset($source->id_address_invoice) ? (int) $source->id_address_invoice : 0,
            'invoice address'
        );
        $this->assertAddressOwner($deliveryAddressId, $customerId, $sourceOrderId);
        $this->assertAddressOwner($invoiceAddressId, $customerId, $sourceOrderId);

        $currencyId = $this->mapCurrency(isset($source->id_currency) ? (int) $source->id_currency : 0);
        $languageId = $this->mapLanguage(isset($source->id_lang) ? (int) $source->id_lang : 0);
        $details = $this->loadOrderDetails($sourceOrderId);
        $history = $this->loadOrderHistory($sourceOrderId, $source);
        $this->assertMappedProducts($details, $sourceOrderId);

        if (!Db::getInstance()->execute('START TRANSACTION')) {
            throw new RuntimeException('Could not start a transaction for source order ' . $sourceOrderId . '.');
        }
        try {
            $cart = new Cart();
            $cart->id_shop = $this->shopId;
            $cart->id_shop_group = $this->shopGroupId;
            $cart->id_customer = $customerId;
            $cart->id_address_delivery = $deliveryAddressId;
            $cart->id_address_invoice = $invoiceAddressId;
            $cart->id_currency = $currencyId;
            $cart->id_lang = $languageId;
            $cart->secure_key = (string) $customer->secure_key;
            if (!$cart->add()) {
                throw new RuntimeException('Could not create a destination cart for source order ' . $sourceOrderId . '.');
            }

            $order = new Order();
            $this->populateOrder($order, $source, $customer, $cart, $deliveryAddressId, $invoiceAddressId, $currencyId, $languageId);
            if (!$order->add()) {
                throw new RuntimeException('Could not create destination order for source order ' . $sourceOrderId . '.');
            }
            $this->importOrderDetails($order, $details);
            $this->importHistory($order, $history);
            if (!$this->saveMapping(self::ENTITY, $sourceOrderId, (int) $order->id, 'imported')) {
                throw new RuntimeException('Could not save the source-to-destination order mapping.');
            }
            if (!Db::getInstance()->execute('COMMIT')) {
                throw new RuntimeException('Could not commit source order ' . $sourceOrderId . '.');
            }
        } catch (Throwable $exception) {
            Db::getInstance()->execute('ROLLBACK');
            throw $exception;
        }
    }

    private function populateOrder(
        Order $order,
        SimpleXMLElement $source,
        Customer $customer,
        Cart $cart,
        $deliveryAddressId,
        $invoiceAddressId,
        $currencyId,
        $languageId
    ) {
        $order->id_shop = $this->shopId;
        $order->id_shop_group = $this->shopGroupId;
        $order->id_cart = (int) $cart->id;
        $order->id_customer = (int) $customer->id;
        $order->id_address_delivery = (int) $deliveryAddressId;
        $order->id_address_invoice = (int) $invoiceAddressId;
        $order->id_currency = (int) $currencyId;
        $order->id_lang = (int) $languageId;
        $order->id_carrier = 0;
        $order->secure_key = (string) $customer->secure_key;
        $order->payment = 'Migrated order - payment not reprocessed';
        $order->module = 'kbizmigration';
        $order->conversion_rate = $this->readDecimal(
            isset($source->conversion_rate) ? $source->conversion_rate : 1,
            'conversion rate'
        );
        if ($order->conversion_rate <= 0) {
            throw new RuntimeException('Source order has an invalid currency conversion rate.');
        }

        foreach ([
            'recyclable',
            'gift',
            'total_discounts',
            'total_discounts_tax_incl',
            'total_discounts_tax_excl',
            'total_paid',
            'total_paid_tax_incl',
            'total_paid_tax_excl',
            'total_paid_real',
            'total_products',
            'total_products_wt',
            'total_shipping',
            'total_shipping_tax_incl',
            'total_shipping_tax_excl',
            'carrier_tax_rate',
            'total_wrapping',
            'total_wrapping_tax_incl',
            'total_wrapping_tax_excl',
            'round_mode',
            'round_type',
        ] as $field) {
            if (isset($source->{$field})) {
                $order->{$field} = $this->readDecimal($source->{$field}, $field);
            }
        }

        $order->gift_message = isset($source->gift_message) ? (string) $source->gift_message : '';
        $sourceNote = strip_tags($this->sourceString($source, 'note', ''));
        $sourcePayment = strip_tags($this->sourceString($source, 'payment', ''));
        $order->note = trim($sourceNote . ($sourcePayment !== '' ? "\nSource payment method: " . $sourcePayment : ''));
        $order->reference = $this->sourceString($source, 'reference', '');
        $order->date_add = $this->sourceDate($source, 'date_add');
        $order->date_upd = $this->sourceDate($source, 'date_upd', $order->date_add);
        $order->invoice_number = 0;
        $order->delivery_number = 0;
        $order->invoice_date = '0000-00-00 00:00:00';
        $order->delivery_date = '0000-00-00 00:00:00';
        $order->valid = 0;
        $order->current_state = 0;
    }

    private function loadOrderDetails($sourceOrderId)
    {
        $details = [];
        $cursor = 0;
        for ($page = 0; $page < 10000; ++$page) {
            $response = $this->client->getResource('order_details', [
                'display' => 'full',
                'sort' => '[id_ASC]',
                'filter' => [
                    'id_order' => '[' . (int) $sourceOrderId . ']',
                    'id' => '>[' . $cursor . ']',
                ],
                'limit' => '0,' . self::PAGE_SIZE,
            ]);
            $items = self::extractItems($response, 'order_details', 'order_detail');
            foreach ($items as $item) {
                $detailId = self::getId($item);
                if ($detailId <= $cursor || (int) $item->id_order !== (int) $sourceOrderId) {
                    throw new RuntimeException('The source order detail list is invalid or not ordered.');
                }
                $details[] = $item;
                $cursor = $detailId;
            }
            if (count($items) < self::PAGE_SIZE) {
                return $details;
            }
        }

        throw new RuntimeException('Could not finish reading line items for source order ' . (int) $sourceOrderId . '.');
    }

    private function assertMappedProducts(array $details, $sourceOrderId)
    {
        foreach ($details as $detail) {
            $sourceProductId = isset($detail->product_id) ? (int) $detail->product_id : 0;
            $this->mappedDestinationId('products', $sourceProductId, 'product in order ' . (int) $sourceOrderId);
            $sourceCombinationId = isset($detail->product_attribute_id) ? (int) $detail->product_attribute_id : 0;
            if ($sourceCombinationId > 0) {
                $this->mappedDestinationId(
                    'product_combinations',
                    $sourceCombinationId,
                    'product combination in order ' . (int) $sourceOrderId
                );
            }
        }
    }

    private function importOrderDetails(Order $order, array $details)
    {
        foreach ($details as $source) {
            $detail = new OrderDetail();
            $detail->id_order = (int) $order->id;
            $detail->id_shop = $this->shopId;
            $detail->product_id = $this->mappedDestinationId('products', (int) $source->product_id, 'product');
            $sourceCombinationId = isset($source->product_attribute_id) ? (int) $source->product_attribute_id : 0;
            $detail->product_attribute_id = $sourceCombinationId > 0
                ? $this->mappedDestinationId('product_combinations', $sourceCombinationId, 'product combination')
                : 0;

            foreach ([
                'product_name',
                'product_quantity',
                'product_quantity_in_stock',
                'product_quantity_return',
                'product_quantity_refunded',
                'product_quantity_reinjected',
                'product_price',
                'reduction_percent',
                'reduction_amount',
                'reduction_amount_tax_excl',
                'reduction_amount_tax_incl',
                'group_reduction',
                'product_quantity_discount',
                'product_ean13',
                'product_isbn',
                'product_upc',
                'product_mpn',
                'product_reference',
                'product_supplier_reference',
                'product_weight',
                'tax_computation_method',
                'tax_name',
                'tax_rate',
                'ecotax',
                'ecotax_tax_rate',
                'discount_quantity_applied',
                'download_nb',
                'download_deadline',
                'unit_price_tax_incl',
                'unit_price_tax_excl',
                'total_price_tax_incl',
                'total_price_tax_excl',
                'total_shipping_price_tax_excl',
                'total_shipping_price_tax_incl',
                'purchase_supplier_price',
                'original_product_price',
                'total_refunded_tax_excl',
                'total_refunded_tax_incl',
            ] as $field) {
                if (isset($source->{$field})) {
                    $detail->{$field} = (string) $source->{$field};
                }
            }
            $detail->id_warehouse = 0;
            $detail->id_order_invoice = 0;
            $detail->id_customization = 0;
            $detail->download_hash = '';
            $product = new Product((int) $detail->product_id);
            if (!(int) $detail->product_quantity || !Validate::isLoadedObject($product)) {
                throw new RuntimeException('A source order line has an invalid quantity or unmapped destination product.');
            }
            $detail->id_tax_rules_group = (int) $product->id_tax_rules_group;
            if (
                (int) $detail->product_attribute_id > 0
                && !Validate::isLoadedObject(new Combination((int) $detail->product_attribute_id))
            ) {
                throw new RuntimeException('A source order line references a missing destination product combination.');
            }
            if (!$detail->add()) {
                throw new RuntimeException('Could not save a line item for destination order ' . (int) $order->id . '.');
            }
        }
    }

    private function loadOrderHistory($sourceOrderId, SimpleXMLElement $sourceOrder)
    {
        $histories = [];
        $cursor = 0;
        for ($page = 0; $page < 10000; ++$page) {
            $response = $this->client->getResource('order_histories', [
                'display' => 'full',
                'sort' => '[id_ASC]',
                'filter' => [
                    'id_order' => '[' . (int) $sourceOrderId . ']',
                    'id' => '>[' . $cursor . ']',
                ],
                'limit' => '0,' . self::PAGE_SIZE,
            ]);
            $items = self::extractItems($response, 'order_histories', 'order_history');
            foreach ($items as $item) {
                $historyId = self::getId($item);
                if ($historyId <= $cursor || (int) $item->id_order !== (int) $sourceOrderId) {
                    throw new RuntimeException('The source order history list is invalid or not ordered.');
                }
                $histories[] = [
                    'source_id' => $historyId,
                    'state_id' => isset($item->id_order_state) ? (int) $item->id_order_state : 0,
                    'date_add' => $this->sourceDate($item, 'date_add', $this->sourceDate($sourceOrder, 'date_add')),
                ];
                $cursor = $historyId;
            }
            if (count($items) < self::PAGE_SIZE) {
                if (!$histories && isset($sourceOrder->current_state) && (int) $sourceOrder->current_state > 0) {
                    $histories[] = [
                        'source_id' => 0,
                        'state_id' => (int) $sourceOrder->current_state,
                        'date_add' => $this->sourceDate($sourceOrder, 'date_add'),
                    ];
                }
                usort($histories, static function ($left, $right) {
                    $dateOrder = strcmp($left['date_add'], $right['date_add']);

                    return $dateOrder !== 0 ? $dateOrder : ($left['source_id'] <=> $right['source_id']);
                });

                return $histories;
            }
        }

        throw new RuntimeException('Could not finish reading history for source order ' . (int) $sourceOrderId . '.');
    }

    private function importHistory(Order $order, array $histories)
    {
        $currentState = 0;
        foreach ($histories as $history) {
            $destinationStateId = $this->mapOrderState((int) $history['state_id']);
            if (!Db::getInstance()->insert('order_history', [
                'id_order' => (int) $order->id,
                'id_order_state' => $destinationStateId,
                'id_employee' => 0,
                'date_add' => pSQL($history['date_add']),
            ])) {
                throw new RuntimeException('Could not save order history for destination order ' . (int) $order->id . '.');
            }
            $currentState = $destinationStateId;
        }
        if ($currentState <= 0) {
            throw new RuntimeException('The source order has no readable order-status history.');
        }

        $order->current_state = $currentState;
        $destinationState = new OrderState($currentState, (int) $order->id_lang);
        $order->valid = (bool) $destinationState->logable;
        if (!$order->update()) {
            throw new RuntimeException('Could not save the final status for destination order ' . (int) $order->id . '.');
        }
    }

    private function mapOrderState($sourceStateId)
    {
        if ($sourceStateId <= 0) {
            throw new RuntimeException('A source order history entry has no order status.');
        }
        if (isset($this->stateMappings[$sourceStateId])) {
            return $this->stateMappings[$sourceStateId];
        }

        $sourceState = $this->loadResource('order_states', $sourceStateId, 'order_state');
        $sourceNames = $this->readTranslations(isset($sourceState->name) ? $sourceState->name : null);
        $destinationNames = [];
        foreach ($this->languages as $language) {
            $iso = strtolower($language['iso_code']);
            foreach ($this->sourceLanguages as $sourceLanguageId => $sourceIso) {
                if ($sourceIso === $iso && isset($sourceNames[$sourceLanguageId])) {
                    $destinationNames[(int) $language['id_lang']] = $sourceNames[$sourceLanguageId];
                }
            }
        }
        if (!$destinationNames && $sourceNames) {
            $destinationNames[$this->defaultLanguageId] = reset($sourceNames);
        }

        foreach ($destinationNames as $languageId => $name) {
            $id = (int) Db::getInstance()->getValue(
                'SELECT os.`id_order_state`
                FROM `' . _DB_PREFIX_ . 'order_state` os
                INNER JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl
                    ON osl.`id_order_state` = os.`id_order_state`
                    AND osl.`id_lang` = ' . (int) $languageId . '
                WHERE os.`deleted` = 0
                    AND LOWER(TRIM(osl.`name`)) = \'' . pSQL(Tools::strtolower(trim($name))) . '\'
                ORDER BY os.`id_order_state` ASC'
            );
            if ($id > 0) {
                $this->stateMappings[$sourceStateId] = $id;

                return $id;
            }
        }

        throw new RuntimeException(
            'No destination order status matches a source status name. Add the matching order status to the destination, then retry.'
        );
    }

    private function mappedDestinationId($entity, $sourceId, $description)
    {
        if ($sourceId <= 0) {
            throw new RuntimeException('Source order is missing a valid ' . $description . ' ID.');
        }
        $mapping = $this->getMapping($entity, $sourceId);
        if (!$mapping || (int) $mapping['destination_id'] <= 0) {
            throw new RuntimeException(
                'Source ' . $description . ' ' . (int) $sourceId
                . ' has not been migrated yet. Complete the customer and product stages, then retry this order.'
            );
        }

        return (int) $mapping['destination_id'];
    }

    private function assertAddressOwner($addressId, $customerId, $sourceOrderId)
    {
        $address = new Address((int) $addressId);
        if (!Validate::isLoadedObject($address) || (int) $address->id_customer !== (int) $customerId) {
            throw new RuntimeException(
                'An address for source order ' . (int) $sourceOrderId
                . ' is missing or does not belong to its mapped destination customer. Re-run customer migration first.'
            );
        }
    }

    private function mapCurrency($sourceCurrencyId)
    {
        if ($sourceCurrencyId <= 0) {
            throw new RuntimeException('A source order has no currency.');
        }
        $currency = $this->loadResource('currencies', $sourceCurrencyId, 'currency');
        $isoCode = strtoupper(trim((string) $currency->iso_code));
        if ($isoCode === '' || !Validate::isLanguageIsoCode($isoCode)) {
            throw new RuntimeException('Could not determine the ISO currency code for source currency ' . $sourceCurrencyId . '.');
        }
        $destinationCurrencyId = (int) Currency::getIdByIsoCode($isoCode);
        if ($destinationCurrencyId <= 0) {
            throw new RuntimeException(
                'Destination currency ' . $isoCode
                . ' does not exist in this store. Add it under International > Localization > Currencies before retrying order migration.'
            );
        }

        $destinationCurrency = new Currency($destinationCurrencyId);
        if (!Validate::isLoadedObject($destinationCurrency) || !(bool) $destinationCurrency->active) {
            throw new RuntimeException(
                'Destination currency ' . $isoCode
                . ' exists but is disabled. Enable it under International > Localization > Currencies before retrying order migration.'
            );
        }

        if (Shop::isFeatureActive()) {
            $isAssociated = (bool) Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'currency_shop`
                WHERE `id_currency` = ' . (int) $destinationCurrencyId . '
                    AND `id_shop` = ' . (int) $this->shopId
            );
            if (!$isAssociated) {
                $conversionRate = (float) Db::getInstance()->getValue(
                    'SELECT `conversion_rate` FROM `' . _DB_PREFIX_ . 'currency`
                    WHERE `id_currency` = ' . (int) $destinationCurrencyId
                        . ' AND `deleted` = 0'
                );
                if ($conversionRate <= 0 || !Db::getInstance()->insert('currency_shop', [
                    'id_currency' => $destinationCurrencyId,
                    'id_shop' => $this->shopId,
                    'conversion_rate' => $conversionRate,
                ])) {
                    throw new RuntimeException(
                        'Could not enable destination currency ' . $isoCode . ' for the current shop.'
                    );
                }
            }
        }

        return $destinationCurrencyId;
    }

    private function mapLanguage($sourceLanguageId)
    {
        $iso = isset($this->sourceLanguages[$sourceLanguageId]) ? $this->sourceLanguages[$sourceLanguageId] : '';
        foreach ($this->languages as $language) {
            if (strtolower($language['iso_code']) === $iso) {
                return (int) $language['id_lang'];
            }
        }

        if (Language::getLanguage($this->defaultLanguageId) !== false) {
            return $this->defaultLanguageId;
        }

        throw new RuntimeException('The destination store has no default language for imported orders.');
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
            $iso = isset($item->iso_code) ? strtolower(trim((string) $item->iso_code)) : '';
            if ($id > 0 && $iso !== '') {
                $languages[$id] = $iso;
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

    private function loadResource($resource, $id, $nodeName)
    {
        $response = $this->client->getResource($resource . '/' . (int) $id);
        if (!isset($response->{$nodeName})) {
            throw new RuntimeException('Source ' . $resource . ' ' . (int) $id . ' was not returned by the Webservice.');
        }

        return $response->{$nodeName};
    }

    private function sourceString(SimpleXMLElement $source, $field, $fallback)
    {
        return isset($source->{$field}) ? trim((string) $source->{$field}) : $fallback;
    }

    private function sourceDate(SimpleXMLElement $source, $field, $fallback = '')
    {
        $value = $this->sourceString($source, $field, '');
        if ($value !== '' && Validate::isDate($value)) {
            return $value;
        }

        return $fallback !== '' ? $fallback : date('Y-m-d H:i:s');
    }

    private function readDecimal($value, $field)
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if ($normalized === '' || !is_numeric($normalized)) {
            throw new RuntimeException('Source order contains an invalid ' . $field . '.');
        }

        return (float) $normalized;
    }

    private function prepareDatabase()
    {
        if (!KbizMigrationCategoryImporter::installDatabase()) {
            throw new RuntimeException('Could not prepare the order migration progress tables.');
        }
    }

    private function getState($entity = self::ENTITY)
    {
        $state = Db::getInstance()->getRow(
            'SELECT `last_source_id`, `is_complete`, `total_count` FROM `' . _DB_PREFIX_ . 'kbizmigration_state`
            WHERE `source_key` = \'' . pSQL($this->sourceKey) . '\'
                AND `entity` = \'' . pSQL($entity) . '\''
        );
        if ($state) {
            return $state;
        }
        if (!Db::getInstance()->insert('kbizmigration_state', [
            'source_key' => pSQL($this->sourceKey),
            'entity' => pSQL($entity),
            'last_source_id' => 0,
            'is_complete' => 0,
            'total_count' => 0,
            'date_upd' => date('Y-m-d H:i:s'),
        ])) {
            throw new RuntimeException('Could not create ' . pSQL($entity) . ' migration progress.');
        }

        return ['last_source_id' => 0, 'is_complete' => 0, 'total_count' => 0];
    }

    private function saveTotalCount($total, $entity = self::ENTITY)
    {
        $updated = Db::getInstance()->update(
            'kbizmigration_state',
            ['total_count' => (int) $total, 'date_upd' => date('Y-m-d H:i:s')],
            '`source_key` = \'' . pSQL($this->sourceKey) . '\' AND `entity` = \'' . pSQL($entity) . '\''
        );
        if (!$updated && !Db::getInstance()->Affected_Rows()) {
            $state = $this->getState($entity);
            if ((int) $state['total_count'] !== (int) $total) {
                throw new RuntimeException('Could not save the source ' . pSQL($entity) . ' total.');
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
                throw new RuntimeException('Could not save ' . pSQL($entity) . ' migration progress.');
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

    private function countEntityMappings($entity, $status)
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
            return (bool) Db::getInstance()->update(
                'kbizmigration_map',
                ['destination_id' => (int) $destinationId, 'status' => pSQL($status)],
                '`source_key` = \'' . pSQL($this->sourceKey) . '\'
                    AND `entity` = \'' . pSQL($entity) . '\'
                    AND `source_id` = ' . (int) $sourceId
            ) || (
                (int) $existing['destination_id'] === (int) $destinationId
                && $existing['status'] === $status
            );
        }

        return (bool) Db::getInstance()->insert('kbizmigration_map', [
            'source_key' => pSQL($this->sourceKey),
            'entity' => pSQL($entity),
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
