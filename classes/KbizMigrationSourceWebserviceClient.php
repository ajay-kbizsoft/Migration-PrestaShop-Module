<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class KbizMigrationSourceWebserviceClient
{
    private $baseUrl;
    private $apiKey;

    public function __construct($baseUrl, $apiKey)
    {
        $urlError = self::validateBaseUrl($baseUrl);
        if ($urlError !== null) {
            throw new InvalidArgumentException($urlError);
        }

        if (!is_string($apiKey) || $apiKey === '') {
            throw new InvalidArgumentException('A Webservice API key is required.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required to connect to the source store.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    public static function validateBaseUrl($baseUrl)
    {
        if (!is_string($baseUrl) || $baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            return 'Enter a valid source store URL.';
        }

        $parts = parse_url($baseUrl);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('~/(?:api)(?:/)?$~i', isset($parts['path']) ? $parts['path'] : '')
        ) {
            return 'Use an http or https store base URL without credentials, query parameters, fragments, or a trailing /api path.';
        }

        return null;
    }

    public function testConnection()
    {
        $result = $this->requestUrl($this->baseUrl . '/api/');
        if ($result['status'] === 401 || $result['status'] === 403) {
            throw new RuntimeException('The source store rejected the API key or it lacks Webservice access.');
        }

        if ($result['status'] === 503) {
            throw new RuntimeException(
                'The source server returned HTTP 503 (Service Unavailable). The API endpoint is reachable, but the source host or its proxy is not serving this authenticated request. Check the source store status, hosting firewall/WAF, and Webservice configuration, then retry.'
            );
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new RuntimeException('The source Webservice returned HTTP status ' . $result['status'] . '.');
        }

        $baseParts = parse_url($this->baseUrl);
        if (
            !$result['secure']
            && (!is_array($baseParts) || !$this->isLocalHost(strtolower($baseParts['host'])))
        ) {
            throw new RuntimeException(
                'The source store must use HTTPS. Enter its https:// URL; the API key was not sent over HTTP.'
            );
        }

        if (strpos($result['body'], '<prestashop') === false) {
            throw new RuntimeException('The source URL responded, but it does not appear to be the PrestaShop Webservice API.');
        }
    }

    public function getResource($resource, array $query = [])
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\/[1-9][0-9]*)?$/', $resource)) {
            throw new InvalidArgumentException('Invalid Webservice resource name.');
        }

        $url = $this->baseUrl . '/api/' . $resource;
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $result = $this->requestUrl($url);

        if ($result['status'] === 401 || $result['status'] === 403) {
            throw new RuntimeException('The API key is not allowed to read the source resource "' . $resource . '".');
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new RuntimeException(
                'The source resource "' . $resource . '" returned HTTP status ' . $result['status'] . '.'
            );
        }

        $requestParts = parse_url($url);
        if (
            !$result['secure']
            && (!is_array($requestParts) || !$this->isLocalHost(strtolower($requestParts['host'])))
        ) {
            throw new RuntimeException('The source store must use HTTPS before data can be read.');
        }

        $xml = @simplexml_load_string($result['body']);
        if ($xml === false || $xml->getName() !== 'prestashop') {
            throw new RuntimeException('The source resource "' . $resource . '" returned invalid Webservice XML.');
        }

        return $xml;
    }

    public function countResource($resource)
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $resource)) {
            throw new InvalidArgumentException('Invalid Webservice resource name.');
        }

        $count = 0;
        $cursor = 0;
        $pageSize = 100;
        $pageCount = 0;
        do {
            if (++$pageCount > 10000) {
                throw new RuntimeException('Could not finish counting source resource "' . $resource . '".');
            }

            $response = $this->getResource($resource, [
                'display' => '[id]',
                'sort' => '[id_ASC]',
                'filter' => ['id' => '>[' . $cursor . ']'],
                'limit' => '0,' . $pageSize,
            ]);
            if (!isset($response->{$resource})) {
                throw new RuntimeException('The source Webservice did not return the "' . $resource . '" resource list.');
            }

            $items = [];
            foreach ($response->{$resource}->children() as $item) {
                $items[] = $item;
            }
            if (!$items) {
                break;
            }

            foreach ($items as $item) {
                $id = isset($item['id']) ? (int) $item['id'] : (isset($item->id) ? (int) $item->id : 0);
                if ($id <= $cursor) {
                    throw new RuntimeException('The source "' . $resource . '" list is not ordered by increasing ID.');
                }
                $cursor = $id;
                ++$count;
            }
        } while (count($items) === $pageSize);

        return $count;
    }

    public function downloadProductImage($sourceProductId, $sourceImageId)
    {
        $sourceProductId = (int) $sourceProductId;
        $sourceImageId = (int) $sourceImageId;
        if ($sourceProductId <= 0 || $sourceImageId <= 0) {
            throw new InvalidArgumentException('Invalid source product image identifiers.');
        }

        $url = $this->baseUrl . '/api/images/products/' . $sourceProductId . '/' . $sourceImageId;
        $result = $this->requestUrl($url);
        if ($result['status'] === 401 || $result['status'] === 403) {
            throw new RuntimeException('The API key is not allowed to read source product images.');
        }
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new RuntimeException('The source product image returned HTTP status ' . $result['status'] . '.');
        }

        $requestParts = parse_url($url);
        if (
            !$result['secure']
            && (!is_array($requestParts) || !$this->isLocalHost(strtolower($requestParts['host'])))
        ) {
            throw new RuntimeException('The source store must use HTTPS before product images can be downloaded.');
        }

        return $result['body'];
    }

    private function requestUrl($url)
    {
        for ($redirects = 0; $redirects <= 3; ++$redirects) {
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                throw new RuntimeException('The source Webservice request URL is invalid.');
            }

            $host = strtolower($parts['host']);
            $isHttps = strtolower($parts['scheme']) === 'https';
            $port = isset($parts['port']) ? (int) $parts['port'] : ($isHttps ? 443 : 80);
            $useAuthentication = $isHttps || $this->isLocalHost($host);
            $location = null;
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('Could not initialize the source connection.');
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Accept: application/xml'],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => function ($curlHandle, $header) use (&$location) {
                    if (stripos($header, 'Location:') === 0) {
                        $location = trim(substr($header, strlen('Location:')));
                    }

                    return strlen($header);
                },
            ];
            if ($useAuthentication) {
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                $options[CURLOPT_USERPWD] = $this->apiKey . ':';
            }
            curl_setopt_array($handle, $options);

            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $curlError = curl_error($handle);
            curl_close($handle);

            if ($body === false) {
                throw new RuntimeException('Could not connect to the source store: ' . $curlError);
            }

            if ($status >= 300 && $status < 400 && $location !== null) {
                $redirectUrl = $this->resolveRedirectUrl($url, $location);
                $redirectParts = parse_url($redirectUrl);
                $redirectPort = is_array($redirectParts) && isset($redirectParts['port'])
                    ? (int) $redirectParts['port']
                    : 443;
                $isStandardHttpsUpgrade = !$isHttps && $port === 80 && $redirectPort === 443;
                if (
                    !is_array($redirectParts)
                    || !isset($redirectParts['scheme'], $redirectParts['host'])
                    || strtolower($redirectParts['scheme']) !== 'https'
                    || strtolower($redirectParts['host']) !== $host
                    || (!$isStandardHttpsUpgrade && $redirectPort !== $port)
                    || isset($redirectParts['user'])
                    || isset($redirectParts['pass'])
                    || isset($redirectParts['fragment'])
                ) {
                    throw new RuntimeException(
                        'The source Webservice redirects to a different host or to an insecure URL. Enter the final HTTPS source store URL directly.'
                    );
                }

                if ($redirects === 3) {
                    throw new RuntimeException('The source Webservice redirected too many times.');
                }

                $url = $redirectUrl;
                continue;
            }

            return [
                'body' => $body,
                'status' => $status,
                'secure' => $isHttps,
            ];
        }

        throw new RuntimeException('The source Webservice redirected too many times.');
    }

    public function checkReadAccess(?array $resources = null)
    {
        if ($resources === null) {
            $resources = [
                'categories',
                'products',
                'combinations',
                'product_options',
                'product_option_values',
                'stock_availables',
                'customers',
                'addresses',
                'countries',
                'states',
                'orders',
                'order_details',
                'order_histories',
                'order_states',
                'cart_rules',
                'currencies',
                'languages',
            ];
        }
        $baseParts = parse_url($this->baseUrl);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            throw new RuntimeException('The source store URL is invalid.');
        }

        if (
            strtolower($baseParts['scheme']) === 'https'
            || $this->isLocalHost(strtolower($baseParts['host']))
        ) {
            $apiBase = $this->baseUrl;
        } elseif (!isset($baseParts['port']) || (int) $baseParts['port'] === 80) {
            $apiBase = 'https://' . $baseParts['host']
                . (isset($baseParts['path']) ? $baseParts['path'] : '');
        } else {
            throw new RuntimeException(
                'Enter the source store HTTPS URL before checking resource permissions.'
            );
        }

        $results = [];
        foreach ($resources as $resource) {
            $url = rtrim($apiBase, '/') . '/api/' . $resource . '?display=%5Bid%5D&limit=0%2C1';
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('Could not initialize the source connection.');
            }

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $this->apiKey . ':',
                CURLOPT_HTTPHEADER => ['Accept: application/xml'],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($handle);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $curlError = curl_error($handle);
            curl_close($handle);

            if ($response === false) {
                throw new RuntimeException(
                    'Could not check access to the source resource "' . $resource . '": ' . $curlError
                );
            }

            if ($statusCode === 200 && strpos($response, '<prestashop') !== false) {
                $results[$resource] = true;
            } elseif ($statusCode === 401 || $statusCode === 403) {
                $results[$resource] = false;
            } else {
                throw new RuntimeException(
                    'Could not verify source resource "' . $resource . '" (HTTP ' . $statusCode . '). Check the source Webservice and hosting configuration.'
                );
            }
        }

        return $results;
    }

    private function isLocalHost($host)
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function resolveRedirectUrl($baseUrl, $location)
    {
        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }

        $base = parse_url($baseUrl);
        $origin = $base['scheme'] . '://' . $base['host'];
        if (isset($base['port'])) {
            $origin .= ':' . $base['port'];
        }

        if (strpos($location, '//') === 0) {
            return $base['scheme'] . ':' . $location;
        }

        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }

        $path = isset($base['path']) ? $base['path'] : '/';

        return $origin . substr($path, 0, strrpos($path, '/') + 1) . $location;
    }
}
