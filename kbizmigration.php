<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/KbizMigrationSourceWebserviceClient.php';
require_once __DIR__ . '/classes/KbizMigrationCategoryImporter.php';
require_once __DIR__ . '/classes/KbizMigrationProductImporter.php';
require_once __DIR__ . '/classes/KbizMigrationCustomerImporter.php';
require_once __DIR__ . '/classes/KbizMigrationOrderImporter.php';
require_once __DIR__ . '/classes/KbizMigrationCartRuleImporter.php';

class Kbizmigration extends Module
{
    private const CONFIG_SOURCE_URL = 'CUSTOMMIGRATION_SOURCE_URL';
    private const CONFIG_API_KEY = 'CUSTOMMIGRATION_API_KEY';

    public function __construct()
    {
        $this->name = 'kbizmigration';
        $this->tab = 'administration';
        $this->version = '1.8.0';
        $this->author = 'Kbizsoft';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_,
        ];

        parent::__construct();

        $this->displayName = $this->trans('MigrationPro: Migrate & Upgrade', [], 'Modules.Kbizmigration.Admin');
        $this->description = $this->trans(
            'Connect this store to another PrestaShop store using its Webservice API.',
            [],
            'Modules.Kbizmigration.Admin'
        );
    }

    public function install()
    {
        return KbizMigrationCategoryImporter::installDatabase() && parent::install();
    }

    public function uninstall()
    {
        return Configuration::deleteByName(self::CONFIG_SOURCE_URL)
            && Configuration::deleteByName(self::CONFIG_API_KEY)
            && parent::uninstall()
            && KbizMigrationCategoryImporter::uninstallDatabase();
    }

    public function getContent()
    {
        $output = '';
        $categoryPreview = null;
        $categoryProgress = null;
        $productProgress = null;
        $customerProgress = null;
        $orderProgress = null;
        $cartRuleProgress = null;
        $requestedTab = Tools::getValue('active_tab', 'categories');
        $activeTab = is_string($requestedTab) ? $requestedTab : 'categories';
        if (!in_array($activeTab, ['categories', 'products', 'customers', 'orders', 'cart_rules'], true)) {
            $activeTab = 'categories';
        }
        $sourceUrl = (string) Configuration::get(self::CONFIG_SOURCE_URL);
        $apiKey = (string) Configuration::get(self::CONFIG_API_KEY);

        if (!KbizMigrationCategoryImporter::installDatabase()) {
            $output .= $this->displayError(
                $this->trans('Could not prepare category migration storage.', [], 'Modules.Kbizmigration.Admin')
            );
        } elseif (Tools::isSubmit('submitKbizMigrationSave')) {
            $submittedUrl = trim((string) Tools::getValue('source_url'));
            $submittedKey = trim((string) Tools::getValue('api_key'));
            $clearKey = (bool) Tools::getValue('clear_api_key');
            $urlError = KbizMigrationSourceWebserviceClient::validateBaseUrl($submittedUrl);

            if ($urlError !== null) {
                $output .= $this->displayError($this->trans($urlError, [], 'Modules.Kbizmigration.Admin'));
            } elseif ($submittedKey !== '' && !preg_match('/^[A-Za-z0-9]+$/', $submittedKey)) {
                $output .= $this->displayError(
                    $this->trans('The API key must contain only letters and numbers.', [], 'Modules.Kbizmigration.Admin')
                );
            } elseif ($submittedKey === '' && $apiKey === '' && !$clearKey) {
                $output .= $this->displayError(
                    $this->trans('Enter a Webservice API key before saving.', [], 'Modules.Kbizmigration.Admin')
                );
            } elseif (!Configuration::updateValue(self::CONFIG_SOURCE_URL, rtrim($submittedUrl, '/'))) {
                $output .= $this->displayError(
                    $this->trans('Could not save the source store URL.', [], 'Modules.Kbizmigration.Admin')
                );
            } else {
                if ($clearKey) {
                    $keySaved = Configuration::deleteByName(self::CONFIG_API_KEY);
                } elseif ($submittedKey !== '') {
                    $keySaved = Configuration::updateValue(self::CONFIG_API_KEY, $submittedKey);
                } else {
                    $keySaved = true;
                }

                if (!$keySaved) {
                    $output .= $this->displayError(
                        $this->trans('Could not save the API key.', [], 'Modules.Kbizmigration.Admin')
                    );
                } else {
                    $output .= $this->displayConfirmation(
                        $this->trans('Settings saved.', [], 'Modules.Kbizmigration.Admin')
                    );
                    $sourceUrl = rtrim($submittedUrl, '/');
                    $apiKey = $clearKey ? '' : ($submittedKey !== '' ? $submittedKey : $apiKey);
                }
            }
        } elseif (Tools::isSubmit('submitKbizMigrationTest')) {
            $submittedUrl = trim((string) Tools::getValue('source_url'));
            $submittedKey = trim((string) Tools::getValue('api_key'));
            $testUrl = $submittedUrl !== '' ? $submittedUrl : $sourceUrl;
            $testKey = $submittedKey !== '' ? $submittedKey : $apiKey;
            $urlError = KbizMigrationSourceWebserviceClient::validateBaseUrl($testUrl);

            if ($urlError !== null) {
                $output .= $this->displayError($this->trans($urlError, [], 'Modules.Kbizmigration.Admin'));
            } elseif ($testKey === '') {
                $output .= $this->displayError(
                    $this->trans('Enter or save a Webservice API key before testing.', [], 'Modules.Kbizmigration.Admin')
                );
            } else {
                try {
                    (new KbizMigrationSourceWebserviceClient($testUrl, $testKey))->testConnection();
                    $output .= $this->displayConfirmation(
                        $this->trans('Connection successful. The source Webservice API is reachable.', [], 'Modules.Kbizmigration.Admin')
                    );
                } catch (RuntimeException $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
            }

            $sourceUrl = $submittedUrl !== '' ? rtrim($submittedUrl, '/') : $sourceUrl;
        } elseif (Tools::isSubmit('submitKbizMigrationCheckAccess')) {
            $submittedUrl = trim((string) Tools::getValue('source_url'));
            $submittedKey = trim((string) Tools::getValue('api_key'));
            $testUrl = $submittedUrl !== '' ? $submittedUrl : $sourceUrl;
            $testKey = $submittedKey !== '' ? $submittedKey : $apiKey;
            $urlError = KbizMigrationSourceWebserviceClient::validateBaseUrl($testUrl);

            if ($urlError !== null) {
                $output .= $this->displayError($this->trans($urlError, [], 'Modules.Kbizmigration.Admin'));
            } elseif ($testKey === '') {
                $output .= $this->displayError(
                    $this->trans('Enter or save a Webservice API key before checking permissions.', [], 'Modules.Kbizmigration.Admin')
                );
            } else {
                try {
                    $client = new KbizMigrationSourceWebserviceClient($testUrl, $testKey);
                    $client->testConnection();
                    $access = $client->checkReadAccess();
                    $output .= $this->renderReadAccessResults($access);
                } catch (RuntimeException $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
            }

            $sourceUrl = $submittedUrl !== '' ? rtrim($submittedUrl, '/') : $sourceUrl;
        } elseif (Tools::isSubmit('submitKbizMigrationPreviewCategories')) {
            if ($sourceUrl === '' || $apiKey === '') {
                $output .= $this->displayError(
                    $this->trans('Save the source URL and Webservice API key before previewing categories.', [], 'Modules.Kbizmigration.Admin')
                );
            } else {
                try {
                    $client = new KbizMigrationSourceWebserviceClient($sourceUrl, $apiKey);
                    $client->testConnection();
                    $importer = new KbizMigrationCategoryImporter($client, $sourceUrl);
                    $categoryPreview = $importer->previewCategories(
                        Tools::getValue('category_preview_page', 1)
                    );
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
            }
        } elseif (Tools::isSubmit('submitKbizMigrationCategories')) {
            if ($sourceUrl === '' || $apiKey === '') {
                $output .= $this->displayError(
                    $this->trans('Save the source URL and Webservice API key before importing categories.', [], 'Modules.Kbizmigration.Admin')
                );
            } else {
                try {
                    $client = new KbizMigrationSourceWebserviceClient($sourceUrl, $apiKey);
                    $client->testConnection();
                    $importer = new KbizMigrationCategoryImporter($client, $sourceUrl);
                    $result = $importer->runNextBatch();
                    $message = sprintf(
                        $this->trans(
                            'Category batch complete: %1$d processed in this batch, %2$d imported, %3$d skipped. %4$s',
                            [],
                            'Modules.Kbizmigration.Admin'
                        ),
                        $result['processed'],
                        $result['imported'],
                        $result['skipped'],
                        $result['done']
                            ? $this->trans('Category import is complete.', [], 'Modules.Kbizmigration.Admin')
                            : $this->trans('Select the button again to continue with the next batch.', [], 'Modules.Kbizmigration.Admin')
                    );
                    $output .= $this->displayConfirmation($message);
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
            }
        } elseif (Tools::isSubmit('submitKbizMigrationReimportCategories')) {
            if ($sourceUrl === '' || $apiKey === '') {
                $output .= $this->displayError(
                    $this->trans('Save the source URL and Webservice API key before re-importing categories.', [], 'Modules.Kbizmigration.Admin')
                );
            } else {
                try {
                    $client = new KbizMigrationSourceWebserviceClient($sourceUrl, $apiKey);
                    $client->testConnection();
                    $importer = new KbizMigrationCategoryImporter($client, $sourceUrl);
                    $result = $importer->runNextReimportBatch();
                    $message = sprintf(
                        $this->trans(
                            'Category re-import batch complete: %1$d source categories checked, %2$d previously imported categories updated, %3$d categories skipped. %4$s',
                            [],
                            'Modules.Kbizmigration.Admin'
                        ),
                        $result['processed'],
                        $result['updated'],
                        $result['skipped'],
                        $result['done']
                            ? $this->trans('Re-import is complete. Click the re-import button again to run a fresh update.', [], 'Modules.Kbizmigration.Admin')
                            : $this->trans('Select the re-import button again to continue with the next batch.', [], 'Modules.Kbizmigration.Admin')
                    );
                    $output .= $this->displayConfirmation($message);
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
            }
        } elseif (Tools::isSubmit('submitKbizMigrationProducts')) {
            if ($sourceUrl === '' || $apiKey === '') {
                $output .= $this->displayError(
                    $this->trans('Save the source URL and Webservice API key before importing products.', [], 'Modules.Kbizmigration.Admin')
                );
            } else {
                try {
                    $client = new KbizMigrationSourceWebserviceClient($sourceUrl, $apiKey);
                    $client->testConnection();
                    $requiredResources = [
                        'categories',
                        'products',
                        'combinations',
                        'product_options',
                        'product_option_values',
                        'stock_availables',
                        'languages',
                    ];
                    $access = $client->checkReadAccess($requiredResources);
                    $missingResources = [];
                    foreach ($requiredResources as $resource) {
                        if (empty($access[$resource])) {
                            $missingResources[] = $resource;
                        }
                    }
                    if ($missingResources) {
                        $output .= $this->displayError(sprintf(
                            $this->trans(
                                'Product migration requires GET permission for these source Webservice resources: %s.',
                                [],
                                'Modules.Kbizmigration.Admin'
                            ),
                            htmlspecialchars(implode(', ', $missingResources), ENT_QUOTES, 'UTF-8')
                        ));
                    } else {
                        $categoryImporter = new KbizMigrationCategoryImporter($client, $sourceUrl);
                        $categoryResult = $categoryImporter->runNextBatch();
                        if (!$categoryResult['done']) {
                            $output .= $this->displayConfirmation(
                                $this->trans(
                                    'Product import has not started yet. A category batch was processed first; click the product button again after category migration is complete.',
                                    [],
                                    'Modules.Kbizmigration.Admin'
                                )
                            );
                        } else {
                            $productImporter = new KbizMigrationProductImporter($client, $sourceUrl);
                            $result = $productImporter->runNextBatch();
                            $output .= $this->displayConfirmation(sprintf(
                                $this->trans(
                                    'Product batch complete: %1$d processed in this batch, %2$d imported, %3$d skipped. %4$s',
                                    [],
                                    'Modules.Kbizmigration.Admin'
                                ),
                                $result['processed'],
                                $result['imported'],
                                $result['skipped'],
                                $result['done']
                                    ? $this->trans('Product import is complete.', [], 'Modules.Kbizmigration.Admin')
                                    : $this->trans('Select the button again to continue with the next batch.', [], 'Modules.Kbizmigration.Admin')
                            ));
                        }
                    }
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
            }
        } elseif (Tools::isSubmit('submitKbizMigrationCustomers')) {
            if ($sourceUrl === '' || $apiKey === '') {
                $output .= $this->displayError(
                    $this->trans('Save the source URL and Webservice API key before importing customers.', [], 'Modules.Kbizmigration.Admin')
                );
            } else {
                try {
                    $client = new KbizMigrationSourceWebserviceClient($sourceUrl, $apiKey);
                    $client->testConnection();
                    $requiredResources = ['customers', 'addresses', 'countries', 'states'];
                    $access = $client->checkReadAccess($requiredResources);
                    $missingResources = [];
                    foreach ($requiredResources as $resource) {
                        if (empty($access[$resource])) {
                            $missingResources[] = $resource;
                        }
                    }
                    if ($missingResources) {
                        $output .= $this->displayError(sprintf(
                            $this->trans(
                                'Customer migration requires GET permission for these source Webservice resources: %s.',
                                [],
                                'Modules.Kbizmigration.Admin'
                            ),
                            htmlspecialchars(implode(', ', $missingResources), ENT_QUOTES, 'UTF-8')
                        ));
                    } else {
                        $importer = new KbizMigrationCustomerImporter($client, $sourceUrl);
                        $result = $importer->runNextBatch();
                        $output .= $this->displayConfirmation(sprintf(
                            $this->trans(
                                'Customer batch complete: %1$d processed in this batch, %2$d imported, %3$d skipped. %4$s',
                                [],
                                'Modules.Kbizmigration.Admin'
                            ),
                            $result['processed'],
                            $result['imported'],
                            $result['skipped'],
                            $result['done']
                                ? $this->trans('Customer import is complete.', [], 'Modules.Kbizmigration.Admin')
                                : $this->trans('Select the button again to continue with the next batch.', [], 'Modules.Kbizmigration.Admin')
                        ));
                    }
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
            }
        } elseif (Tools::isSubmit('submitKbizMigrationOrders')) {
            if ($sourceUrl === '' || $apiKey === '') {
                $output .= $this->displayError(
                    $this->trans('Save the source URL and Webservice API key before importing orders.', [], 'Modules.Kbizmigration.Admin')
                );
            } else {
                try {
                    $client = new KbizMigrationSourceWebserviceClient($sourceUrl, $apiKey);
                    $client->testConnection();
                    $requiredResources = [
                        'orders',
                        'order_details',
                        'order_histories',
                        'order_states',
                        'currencies',
                        'languages',
                    ];
                    $access = $client->checkReadAccess($requiredResources);
                    $missingResources = [];
                    foreach ($requiredResources as $resource) {
                        if (empty($access[$resource])) {
                            $missingResources[] = $resource;
                        }
                    }
                    if ($missingResources) {
                        $output .= $this->displayError(sprintf(
                            $this->trans(
                                'Order migration requires GET permission for these source Webservice resources: %s.',
                                [],
                                'Modules.Kbizmigration.Admin'
                            ),
                            htmlspecialchars(implode(', ', $missingResources), ENT_QUOTES, 'UTF-8')
                        ));
                    } else {
                        $importer = new KbizMigrationOrderImporter($client, $sourceUrl);
                        $result = $importer->runNextBatch();
                        $output .= $this->displayConfirmation(sprintf(
                            $this->trans(
                                'Order batch complete: %1$d processed in this batch, %2$d imported. %3$s',
                                [],
                                'Modules.Kbizmigration.Admin'
                            ),
                            $result['processed'],
                            $result['imported'],
                            $result['done']
                                ? $this->trans('Order import is complete.', [], 'Modules.Kbizmigration.Admin')
                                : $this->trans('Select the button again to continue with the next batch.', [], 'Modules.Kbizmigration.Admin')
                        ));
                    }
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
            }
        } elseif (Tools::isSubmit('submitKbizMigrationCartRules')) {
            if ($sourceUrl === '' || $apiKey === '') {
                $output .= $this->displayError(
                    $this->trans('Save the source URL and Webservice API key before importing cart rules.', [], 'Modules.Kbizmigration.Admin')
                );
            } else {
                try {
                    $client = new KbizMigrationSourceWebserviceClient($sourceUrl, $apiKey);
                    $client->testConnection();
                    $requiredResources = ['cart_rules', 'currencies', 'languages'];
                    $access = $client->checkReadAccess($requiredResources);
                    $missingResources = [];
                    foreach ($requiredResources as $resource) {
                        if (empty($access[$resource])) {
                            $missingResources[] = $resource;
                        }
                    }
                    if ($missingResources) {
                        $output .= $this->displayError(sprintf(
                            $this->trans(
                                'Cart-rule migration requires GET permission for these source Webservice resources: %s.',
                                [],
                                'Modules.Kbizmigration.Admin'
                            ),
                            htmlspecialchars(implode(', ', $missingResources), ENT_QUOTES, 'UTF-8')
                        ));
                    } else {
                        $importer = new KbizMigrationCartRuleImporter($client, $sourceUrl);
                        $result = $importer->runNextBatch();
                        $output .= $this->displayConfirmation(sprintf(
                            $this->trans(
                                'Cart-rule batch complete: %1$d processed in this batch, %2$d imported, %3$d skipped. %4$s',
                                [],
                                'Modules.Kbizmigration.Admin'
                            ),
                            $result['processed'],
                            $result['imported'],
                            $result['skipped'],
                            $result['done']
                                ? $this->trans('Cart-rule import is complete.', [], 'Modules.Kbizmigration.Admin')
                                : $this->trans('Select the button again to continue with the next batch.', [], 'Modules.Kbizmigration.Admin')
                        ));
                    }
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
            }
        }

        $output .= $this->renderSettingsForm($sourceUrl, $apiKey !== '');
        if ($sourceUrl !== '' && $apiKey !== '') {
            try {
                $client = new KbizMigrationSourceWebserviceClient($sourceUrl, $apiKey);
                try {
                    $categoryProgress = (new KbizMigrationCategoryImporter($client, $sourceUrl))->getProgress();
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
                try {
                    $productProgress = (new KbizMigrationProductImporter($client, $sourceUrl))->getProgress();
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
                try {
                    $customerProgress = (new KbizMigrationCustomerImporter($client, $sourceUrl))->getProgress();
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
                try {
                    $orderProgress = (new KbizMigrationOrderImporter($client, $sourceUrl))->getProgress();
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
                try {
                    $cartRuleProgress = (new KbizMigrationCartRuleImporter($client, $sourceUrl))->getProgress();
                } catch (Exception $exception) {
                    $output .= $this->displayError(
                        $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                    );
                }
            } catch (Exception $exception) {
                $output .= $this->displayError(
                    $this->trans($exception->getMessage(), [], 'Modules.Kbizmigration.Admin')
                );
            }
        }

        $output .= '<ul class="nav nav-tabs" role="tablist">'
            . '<li class="nav-item" role="presentation">'
            . '<a class="nav-link' . ($activeTab === 'categories' ? ' active' : '')
            . '" href="#kbiz-categories" aria-controls="kbiz-categories" aria-selected="'
            . ($activeTab === 'categories' ? 'true' : 'false') . '" role="tab" data-toggle="tab">'
            . $this->trans('Categories', [], 'Modules.Kbizmigration.Admin') . '</a></li>'
            . '<li class="nav-item" role="presentation">'
            . '<a class="nav-link' . ($activeTab === 'products' ? ' active' : '')
            . '" href="#kbiz-products" aria-controls="kbiz-products" aria-selected="'
            . ($activeTab === 'products' ? 'true' : 'false') . '" role="tab" data-toggle="tab">'
            . $this->trans('Products', [], 'Modules.Kbizmigration.Admin') . '</a></li>'
            . '<li class="nav-item" role="presentation">'
            . '<a class="nav-link' . ($activeTab === 'customers' ? ' active' : '')
            . '" href="#kbiz-customers" aria-controls="kbiz-customers" aria-selected="'
            . ($activeTab === 'customers' ? 'true' : 'false') . '" role="tab" data-toggle="tab">'
            . $this->trans('Customers', [], 'Modules.Kbizmigration.Admin') . '</a></li>'
            . '<li class="nav-item" role="presentation">'
            . '<a class="nav-link' . ($activeTab === 'orders' ? ' active' : '')
            . '" href="#kbiz-orders" aria-controls="kbiz-orders" aria-selected="'
            . ($activeTab === 'orders' ? 'true' : 'false') . '" role="tab" data-toggle="tab">'
            . $this->trans('Orders', [], 'Modules.Kbizmigration.Admin') . '</a></li>'
            . '<li class="nav-item" role="presentation">'
            . '<a class="nav-link' . ($activeTab === 'cart_rules' ? ' active' : '')
            . '" href="#kbiz-cart-rules" aria-controls="kbiz-cart-rules" aria-selected="'
            . ($activeTab === 'cart_rules' ? 'true' : 'false') . '" role="tab" data-toggle="tab">'
            . $this->trans('Cart rules', [], 'Modules.Kbizmigration.Admin') . '</a></li></ul>'
            . '<div class="tab-content"><div role="tabpanel" class="tab-pane'
            . ($activeTab === 'categories' ? ' active' : '') . '" id="kbiz-categories">';
        if ($categoryProgress !== null) {
            $output .= $this->renderCategoryProgress($categoryProgress);
            $output .= $this->renderImportedCategories(
                KbizMigrationCategoryImporter::getImportedCategories($sourceUrl)
            );
            $reimportProgress = KbizMigrationCategoryImporter::getSavedReimportProgress($sourceUrl);
            if ($reimportProgress) {
                $output .= $this->renderReimportProgress($reimportProgress);
            }
        }
        $output .= $this->renderCategoryMigrationForm($sourceUrl !== '' && $apiKey !== '');
        if ($categoryPreview !== null) {
            $output .= $this->renderCategoryPreview($categoryPreview);
        }
        $output .= '</div><div role="tabpanel" class="tab-pane'
            . ($activeTab === 'products' ? ' active' : '') . '" id="kbiz-products">';
        if ($productProgress !== null) {
            $output .= $this->renderProductProgress($productProgress);
        }
        $output .= $this->renderProductMigrationForm($sourceUrl !== '' && $apiKey !== '')
            . '</div><div role="tabpanel" class="tab-pane'
            . ($activeTab === 'customers' ? ' active' : '') . '" id="kbiz-customers">';
        if ($customerProgress !== null) {
            $output .= $this->renderCustomerProgress($customerProgress);
        }
        $output .= $this->renderCustomerMigrationForm($sourceUrl !== '' && $apiKey !== '')
            . '</div><div role="tabpanel" class="tab-pane'
            . ($activeTab === 'orders' ? ' active' : '') . '" id="kbiz-orders">';
        if ($orderProgress !== null) {
            $output .= $this->renderOrderProgress($orderProgress);
        }
        $output .= $this->renderOrderMigrationForm($sourceUrl !== '' && $apiKey !== '')
            . '</div><div role="tabpanel" class="tab-pane'
            . ($activeTab === 'cart_rules' ? ' active' : '') . '" id="kbiz-cart-rules">';
        if ($cartRuleProgress !== null) {
            $output .= $this->renderCartRuleProgress($cartRuleProgress);
        }
        $output .= $this->renderCartRuleMigrationForm($sourceUrl !== '' && $apiKey !== '')
            . '</div></div>';

        return $output;
    }

    private function renderSettingsForm($sourceUrl, $hasApiKey)
    {
        $action = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
        $escapedAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
        $escapedUrl = htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8');
        $keyStatus = $hasApiKey
            ? $this->trans('An API key is saved. Leave this field blank to keep it.', [], 'Modules.Kbizmigration.Admin')
            : $this->trans('No API key is saved yet.', [], 'Modules.Kbizmigration.Admin');

        return '
            <form method="post" action="' . $escapedAction . '" class="form-horizontal">
                <div class="panel">
                    <div class="panel-heading">' .
                        $this->trans('Source store connection', [], 'Modules.Kbizmigration.Admin') .
                    '</div>
                    <div class="form-wrapper">
                        <div class="form-group">
                            <label class="control-label col-lg-3" for="source_url">' .
                                $this->trans('Source store URL', [], 'Modules.Kbizmigration.Admin') .
                            '</label>
                            <div class="col-lg-9">
                                <input class="form-control" type="url" id="source_url" name="source_url" required value="' . $escapedUrl . '" placeholder="https://source.example.com">
                                <p class="help-block">' .
                                    $this->trans('Enter the base URL of the source PrestaShop store, without /api.', [], 'Modules.Kbizmigration.Admin') .
                                '</p>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="control-label col-lg-3" for="api_key">' .
                                $this->trans('Webservice API key', [], 'Modules.Kbizmigration.Admin') .
                            '</label>
                            <div class="col-lg-9">
                                <input class="form-control" type="password" id="api_key" name="api_key" autocomplete="new-password">
                                <p class="help-block">' . htmlspecialchars($keyStatus, ENT_QUOTES, 'UTF-8') . '</p>
                            </div>
                        </div>
                        ' . ($hasApiKey ? '
                        <div class="form-group">
                            <label class="control-label col-lg-3" for="clear_api_key">' .
                                $this->trans('Remove saved key', [], 'Modules.Kbizmigration.Admin') .
                            '</label>
                            <div class="col-lg-9">
                                <input type="checkbox" id="clear_api_key" name="clear_api_key" value="1">
                            </div>
                        </div>' : '') . '
                    </div>
                    <div class="panel-footer">
                        <button type="submit" name="submitKbizMigrationTest" class="btn btn-default pull-left">
                            <i class="process-icon-refresh"></i> ' . $this->trans('Test connection', [], 'Modules.Kbizmigration.Admin') . '
                        </button>
                        <button type="submit" name="submitKbizMigrationCheckAccess" class="btn btn-default pull-left">
                            <i class="process-icon-cogs"></i> ' . $this->trans('Check migration permissions', [], 'Modules.Kbizmigration.Admin') . '
                        </button>
                        <button type="submit" name="submitKbizMigrationSave" class="btn btn-default pull-right">
                            <i class="process-icon-save"></i> ' . $this->trans('Save', [], 'Modules.Kbizmigration.Admin') . '
                        </button>
                    </div>
                </div>
            </form>';
    }

    private function renderReadAccessResults($access)
    {
        $allAllowed = !in_array(false, $access, true);
        $html = $allAllowed
            ? $this->displayConfirmation($this->trans('All checked read permissions are available.', [], 'Modules.Kbizmigration.Admin'))
            : $this->displayError($this->trans('Some read permissions are missing. Enable GET access for the listed resources on the source store.', [], 'Modules.Kbizmigration.Admin'));
        $html .= '<ul class="list-unstyled">';

        foreach ($access as $resource => $allowed) {
            $label = htmlspecialchars($resource, ENT_QUOTES, 'UTF-8');
            $status = $allowed
                ? $this->trans('GET access available', [], 'Modules.Kbizmigration.Admin')
                : $this->trans('GET access missing', [], 'Modules.Kbizmigration.Admin');
            $class = $allowed ? 'text-success' : 'text-danger';
            $html .= '<li class="' . $class . '"><strong>' . $label . ':</strong> '
                . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        return $html . '</ul>';
    }

    private function renderCategoryMigrationForm($isConfigured)
    {
        if (!$isConfigured) {
            return '';
        }

        $action = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
        $escapedAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');

        return '
            <form method="post" action="' . $escapedAction . '" class="form-horizontal">
                <input type="hidden" name="active_tab" value="categories">
                <div class="panel">
                    <div class="panel-heading">' .
                        $this->trans('Category migration', [], 'Modules.Kbizmigration.Admin') .
                    '</div>
                    <div class="panel-body">
                        <p>' .
                            $this->trans('Import categories in batches of 10. Existing matching categories are skipped. If a previously mapped destination category was deleted or is missing from the current shop, the import resumes or rewinds its scan as needed and restores that category before continuing. Root and Home categories are mapped to the destination store’s own categories. No products, customers, images, or orders are changed in this phase.', [], 'Modules.Kbizmigration.Admin') .
                        '</p>
                        <p>' .
                            $this->trans('Re-import updates only categories previously created and mapped by this module. It refreshes their names, descriptions, URL slugs, active status, and parent from the source. It does not delete categories or update destination categories that were skipped as existing matches.', [], 'Modules.Kbizmigration.Admin') .
                        '</p>
                        </div>
                        <div class="panel-footer">
                        <button type="submit" name="submitKbizMigrationPreviewCategories" class="btn btn-default">
                            <i class="process-icon-search"></i> ' . $this->trans('Preview and compare categories', [], 'Modules.Kbizmigration.Admin') . '
                        </button>
                        <button type="submit" name="submitKbizMigrationCategories" class="btn btn-primary">
                            <i class="process-icon-import"></i> ' . $this->trans('Import next category batch', [], 'Modules.Kbizmigration.Admin') . '
                        </button>
                        <button type="submit" name="submitKbizMigrationReimportCategories" class="btn btn-default">
                            <i class="process-icon-refresh"></i> ' . $this->trans('Re-import/update mapped categories', [], 'Modules.Kbizmigration.Admin') . '
                        </button>
                    </div>
                </div>
            </form>';
    }

    private function renderProductMigrationForm($isConfigured)
    {
        if (!$isConfigured) {
            return '';
        }

        $action = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
        $escapedAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');

        return '
            <form method="post" action="' . $escapedAction . '" class="form-horizontal">
                <input type="hidden" name="active_tab" value="products">
                <div class="panel">
                    <div class="panel-heading">' .
                        $this->trans('Product migration', [], 'Modules.Kbizmigration.Admin') .
                    '</div>
                    <div class="panel-body">
                        <p>' .
                            $this->trans('Import products in batches of 5 after category migration. Product names, descriptions, reference, price, options, combinations, images, and stock are copied. Source and destination stores must use the same currency. The destination store’s most-used tax rule group is applied; source tax rules are not copied.', [], 'Modules.Kbizmigration.Admin') .
                        '</p>
                        <p>' .
                            $this->trans('Products with a reference already present in the destination are skipped. Only products previously mapped by this module are updated on retry. Imported stock replaces the destination quantity for each imported product or combination.', [], 'Modules.Kbizmigration.Admin') .
                        '</p>
                    </div>
                    <div class="panel-footer">
                        <button type="submit" name="submitKbizMigrationProducts" class="btn btn-primary">
                            <i class="process-icon-import"></i> ' . $this->trans('Import next product batch', [], 'Modules.Kbizmigration.Admin') . '
                        </button>
                    </div>
                </div>
            </form>';
    }

    private function renderCustomerMigrationForm($isConfigured)
    {
        if (!$isConfigured) {
            return '';
        }

        $action = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
        $escapedAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');

        return '
            <form method="post" action="' . $escapedAction . '" class="form-horizontal">
                <input type="hidden" name="active_tab" value="customers">
                <div class="panel">
                    <div class="panel-heading">' .
                        $this->trans('Customer migration', [], 'Modules.Kbizmigration.Admin') .
                    '</div>
                    <div class="panel-body">
                        <p>' .
                            $this->trans('Import customers in batches of 5 with their addresses. A customer with an email already in the destination is skipped unless it was previously mapped by this module. Imported customers receive a generated password they do not know; they must use “Forgot password” to set their own password. Customer group membership is assigned to the destination store’s default customer group.', [], 'Modules.Kbizmigration.Admin') .
                        '</p>
                        <p>' .
                            $this->trans('Source addresses require matching destination countries and states. Add any missing country/state to the destination and retry; customer and address mappings make the batch resumable.', [], 'Modules.Kbizmigration.Admin') .
                        '</p>
                    </div>
                    <div class="panel-footer">
                        <button type="submit" name="submitKbizMigrationCustomers" class="btn btn-primary">
                            <i class="process-icon-import"></i> ' . $this->trans('Import next customer batch', [], 'Modules.Kbizmigration.Admin') . '
                        </button>
                    </div>
                </div>
            </form>';
    }

    private function renderCustomerProgress(array $progress)
    {
        return $this->renderImportProgress(
            $this->trans('Customer migration progress', [], 'Modules.Kbizmigration.Admin'),
            $progress
        ) . '<p>' . (int) $progress['imported'] . ' customers imported, '
            . (int) $progress['skipped'] . ' skipped; '
            . (int) $progress['addresses'] . ' addresses imported; source ID cursor '
            . (int) $progress['last_source_id'] . '.</p>';
    }

    private function renderOrderMigrationForm($isConfigured)
    {
        if (!$isConfigured) {
            return '';
        }

        $action = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
        $escapedAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');

        return '
            <form method="post" action="' . $escapedAction . '" class="form-horizontal">
                <input type="hidden" name="active_tab" value="orders">
                <div class="panel">
                    <div class="panel-heading">' .
                        $this->trans('Order migration', [], 'Modules.Kbizmigration.Admin') .
                    '</div>
                    <div class="panel-body">
                        <p>' .
                            $this->trans('Import orders in batches of 3 after customer and product migration. Order lines use the customer, address, product, and combination mappings saved by this module. Source currencies must also be enabled in the destination; totals are copied without currency conversion.', [], 'Modules.Kbizmigration.Admin') .
                        '</p>
                        <p>' .
                            $this->trans('Order statuses are matched by translated status name. Source payment-method text, reference, totals, and status history are retained, but payment records, credentials, capture/refund actions, invoices, and shipping labels are not created. No order emails or status-change notifications are sent.', [], 'Modules.Kbizmigration.Admin') .
                        '</p>
                    </div>
                    <div class="panel-footer">
                        <button type="submit" name="submitKbizMigrationOrders" class="btn btn-primary">
                            <i class="process-icon-import"></i> ' . $this->trans('Import next order batch', [], 'Modules.Kbizmigration.Admin') . '
                        </button>
                    </div>
                </div>
            </form>';
    }

    private function renderOrderProgress(array $progress)
    {
        return $this->renderImportProgress(
            $this->trans('Order migration progress', [], 'Modules.Kbizmigration.Admin'),
            $progress
        ) . '<p>' . (int) $progress['imported'] . ' orders imported, '
            . (int) $progress['skipped'] . ' skipped; source ID cursor '
            . (int) $progress['last_source_id'] . '.</p>';
    }

    private function renderCartRuleMigrationForm($isConfigured)
    {
        if (!$isConfigured) {
            return '';
        }
        $action = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
        $escapedAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');

        return '
            <form method="post" action="' . $escapedAction . '" class="form-horizontal">
                <input type="hidden" name="active_tab" value="cart_rules">
                <div class="panel">
                    <div class="panel-heading">'
                        . $this->trans('Cart-rule migration', [], 'Modules.Kbizmigration.Admin') .
                    '</div>
                    <div class="panel-body">
                        <p>'
                            . $this->trans('Import voucher-code cart rules in batches of 5. Names, validity dates, quantities, minimum amounts, reductions, free shipping, and active status are copied. Currency values are kept as-is with no conversion.', [], 'Modules.Kbizmigration.Admin') .
                        '</p>
                        <p>'
                            . $this->trans('Rules without a code, customer-specific rules, rules with country/carrier/group/product/shop/combination restrictions, and rules using custom discount types are skipped because their linked restrictions cannot be safely mapped through the source Webservice. A voucher code already present in the destination is skipped without being overwritten.', [], 'Modules.Kbizmigration.Admin') .
                        '</p>
                    </div>
                    <div class="panel-footer">
                        <button type="submit" name="submitKbizMigrationCartRules" class="btn btn-primary">
                            <i class="process-icon-import"></i> ' . $this->trans('Import next cart-rule batch', [], 'Modules.Kbizmigration.Admin') . '
                        </button>
                    </div>
                </div>
            </form>';
    }

    private function renderCartRuleProgress(array $progress)
    {
        return $this->renderImportProgress(
            $this->trans('Cart-rule migration progress', [], 'Modules.Kbizmigration.Admin'),
            $progress
        ) . '<p>' . (int) $progress['imported'] . ' cart rules imported, '
            . (int) $progress['skipped'] . ' skipped; source ID cursor '
            . (int) $progress['last_source_id'] . '.</p>';
    }

    private function renderProductProgress(array $progress)
    {
        return $this->renderImportProgress(
            $this->trans('Product migration progress', [], 'Modules.Kbizmigration.Admin'),
            $progress
        ) . '<p>' . (int) $progress['imported'] . ' imported, '
            . (int) $progress['skipped'] . ' skipped; source ID cursor '
            . (int) $progress['last_source_id'] . '.</p>';
    }

    private function renderCategoryProgress(array $progress)
    {
        return $this->renderImportProgress(
            $this->trans('Category migration progress', [], 'Modules.Kbizmigration.Admin'),
            $progress
        ) . '<p>' . (int) $progress['imported'] . ' imported, '
            . (int) $progress['skipped'] . ' skipped; source ID cursor '
            . (int) $progress['last_source_id'] . '.</p>';
    }

    private function renderImportProgress($label, array $progress)
    {
        $total = max(0, (int) $progress['total']);
        $processed = min($total, max(0, (int) $progress['processed']));
        $remaining = max(0, (int) $progress['remaining']);
        $percentage = $total === 0 ? 100 : (int) floor($processed * 100 / $total);
        $complete = !empty($progress['done']) || $total === 0;
        $status = $complete
            ? $this->trans('Complete', [], 'Modules.Kbizmigration.Admin')
            : $this->trans('In progress', [], 'Modules.Kbizmigration.Admin');
        $summary = sprintf(
            $this->trans(
                '%1$d of %2$d processed; %3$d remaining.',
                [],
                'Modules.Kbizmigration.Admin'
            ),
            $processed,
            $total,
            $remaining
        );

        return '<div class="panel"><div class="panel-heading">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . ' — ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8')
            . '</div><div class="panel-body"><div class="progress" role="progressbar"'
            . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
            . ' aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $percentage . '">'
            . '<div class="progress-bar ' . ($complete ? 'progress-bar-success' : 'progress-bar-info') . '"'
            . ' style="width: ' . $percentage . '%"></div></div><p>'
            . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8')
            . '</p></div></div>';
    }

    private function renderImportedCategories(array $categories)
    {
        if (!$categories) {
            return '<div class="alert alert-warning">'
                . $this->trans('No categories are currently recorded as imported from this source.', [], 'Modules.Kbizmigration.Admin')
                . '</div>';
        }

        $html = '<div class="panel"><div class="panel-heading">'
            . $this->trans('Categories imported from this source', [], 'Modules.Kbizmigration.Admin')
            . '</div><div class="panel-body"><p>'
            . $this->trans('Showing up to 100 saved category mappings. Categories missing from the selected shop are listed with their status so they are not hidden.', [], 'Modules.Kbizmigration.Admin')
            . '</p></div><div class="table-responsive"><table class="table"><thead><tr><th>'
            . $this->trans('Source ID', [], 'Modules.Kbizmigration.Admin')
            . '</th><th>' . $this->trans('Destination category', [], 'Modules.Kbizmigration.Admin')
            . '</th><th>' . $this->trans('Destination ID', [], 'Modules.Kbizmigration.Admin')
            . '</th><th>' . $this->trans('Parent ID', [], 'Modules.Kbizmigration.Admin')
            . '</th><th>' . $this->trans('Active', [], 'Modules.Kbizmigration.Admin')
            . '</th><th>' . $this->trans('Shop status', [], 'Modules.Kbizmigration.Admin')
            . '</th></tr></thead><tbody>';

        foreach ($categories as $category) {
            $categoryId = (int) $category['destination_id'];
            $name = (string) $category['name'];
            if ($name === '') {
                $name = $category['visibility'] === 'missing'
                    ? $this->trans('Destination category is missing', [], 'Modules.Kbizmigration.Admin')
                    : $this->trans('Category name unavailable in this language', [], 'Modules.Kbizmigration.Admin');
            }
            $escapedName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            if ($category['visibility'] === 'available') {
                $editUrl = $this->context->link->getAdminLink(
                    'AdminCategories',
                    true,
                    [],
                    ['id_category' => $categoryId, 'updatecategory' => 1]
                );
                $escapedName = '<a href="' . htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') . '">'
                    . $escapedName . '</a>';
            }
            $visibility = $category['visibility'] === 'available'
                ? $this->trans('Available in selected shop', [], 'Modules.Kbizmigration.Admin')
                : ($category['visibility'] === 'missing'
                    ? $this->trans('Missing; re-import will restore it', [], 'Modules.Kbizmigration.Admin')
                    : $this->trans('Not associated with selected shop; re-import will associate it', [], 'Modules.Kbizmigration.Admin'));
            $parentId = $category['id_parent'] === null ? '—' : (int) $category['id_parent'];
            $active = $category['active'] === null
                ? '—'
                : ((int) $category['active']
                    ? $this->trans('Yes', [], 'Modules.Kbizmigration.Admin')
                    : $this->trans('No', [], 'Modules.Kbizmigration.Admin'));
            $html .= '<tr><td>' . (int) $category['source_id'] . '</td><td>'
                . $escapedName . '</td><td>' . $categoryId . '</td><td>'
                . $parentId . '</td><td>' . $active . '</td><td>'
                . htmlspecialchars($visibility, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        return $html . '</tbody></table></div></div>';
    }

    private function renderReimportProgress(array $progress)
    {
        $status = $progress['done']
            ? $this->trans('Complete', [], 'Modules.Kbizmigration.Admin')
            : $this->trans('In progress', [], 'Modules.Kbizmigration.Admin');

        return '<div class="alert alert-info">'
            . $this->trans('Category re-import progress', [], 'Modules.Kbizmigration.Admin')
            . ': ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8')
            . ' — source ID cursor ' . (int) $progress['last_source_id'] . '.</div>';
    }

    private function renderCategoryPreview(array $preview)
    {
        $html = '<div class="panel"><div class="panel-heading">'
            . $this->trans('Source category preview and comparison', [], 'Modules.Kbizmigration.Admin')
            . '</div><div class="panel-body"><p>'
            . sprintf(
                $this->trans(
                    'Source categories: %1$d. Matching destination categories: %2$d. New categories to import: %3$d.',
                    [],
                    'Modules.Kbizmigration.Admin'
                ),
                (int) $preview['total'],
                (int) $preview['matched'],
                (int) $preview['new']
            )
            . '</p><form method="post" class="form-inline">'
            . '<input type="hidden" name="active_tab" value="categories">'
            . '<label for="category_preview_page">'
            . $this->trans('Page', [], 'Modules.Kbizmigration.Admin')
            . '</label> <select class="form-control" id="category_preview_page" name="category_preview_page">';

        for ($page = 1; $page <= $preview['page_count']; ++$page) {
            $selected = $page === $preview['page'] ? ' selected' : '';
            $html .= '<option value="' . (int) $page . '"' . $selected . '>'
                . (int) $page . ' / ' . (int) $preview['page_count'] . '</option>';
        }

        $html .= '</select> <button type="submit" name="submitKbizMigrationPreviewCategories" class="btn btn-default">'
            . $this->trans('Show page', [], 'Modules.Kbizmigration.Admin')
            . '</button></form></div><div class="table-responsive"><table class="table">'
            . '<thead><tr><th>' . $this->trans('Source ID', [], 'Modules.Kbizmigration.Admin')
            . '</th><th>' . $this->trans('Category', [], 'Modules.Kbizmigration.Admin')
            . '</th><th>' . $this->trans('Source parent', [], 'Modules.Kbizmigration.Admin')
            . '</th><th>' . $this->trans('Comparison', [], 'Modules.Kbizmigration.Admin')
            . '</th><th>' . $this->trans('Destination ID', [], 'Modules.Kbizmigration.Admin')
            . '</th></tr></thead><tbody>';

        foreach ($preview['items'] as $item) {
            $html .= '<tr><td>' . (int) $item['source_id'] . '</td><td>'
                . htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') . '</td><td>'
                . htmlspecialchars($item['parent'], ENT_QUOTES, 'UTF-8') . '</td><td>'
                . htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8') . '</td><td>'
                . ((int) $item['destination_id'] > 0 ? (int) $item['destination_id'] : '—')
                . '</td></tr>';
        }

        return $html . '</tbody></table></div></div>';
    }
}
