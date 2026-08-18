<?php

declare(strict_types=1);

namespace App\DataSource\Service;

use Productsup\CDE\ContainerApi\ContainerApiInterface;

readonly class ImportService
{
    public function __construct(
        private ContainerApiInterface $containerApi,
    ) {}

    public function run(): void
    {
        $this->containerApi->info('Starting product import.');

        // Fetch OAuth token from Etimix and then fetch products
        // TODO: fill these with your credentials
        $clientId = 'ProPlanet Sales Joey'; // e.g. 'your_client_id'
        $clientSecret = 'c396b1d6-bc85-4bc8-8241-f8a66c3a4a5a'; // e.g. 'your_client_secret' using now created test key for products up

        $products = [];

        // Only attempt call when credentials are provided
        if ($clientId !== '' && $clientSecret !== '') {
            // Get access token (client_credentials grant)
            $tokenUrl = 'https://api.etimix.com/oauth/access_token';
            $postFields = http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

            $ch = curl_init($tokenUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded',
            ]);
            $resp = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false || $curlErr !== '') {
                $this->containerApi->info('Failed to request access token: ' . $curlErr);
            } else {
                $tokenData = json_decode($resp, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->containerApi->info('Invalid JSON response when requesting access token.');
                } elseif (!empty($tokenData['access_token'])) {
                    $accessToken = $tokenData['access_token'];

                    // Fetch products
                    $productsUrl = 'https://api.etimix.com/api/v2/products?limit=0&offset=2&excludeNulls=true';
                    $ch = curl_init($productsUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $accessToken,
                        'Accept: application/json',
                    ]);
                    $resp = curl_exec($ch);
                    $curlErr = curl_error($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($resp === false || $curlErr !== '') {
                        $this->containerApi->info('Failed to fetch products: ' . $curlErr);
                    } else {
                        $data = json_decode($resp, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $this->containerApi->info('Invalid JSON response when fetching products.');
                        } elseif (!empty($data['items']) && is_array($data['items'])) {
                            // items is expected to be an array of product objects
                            foreach ($data['items'] as $item) {
                                // Ensure each item is an associative array
                                if (is_array($item)) {
                                    $products[] = $item;
                                } else {
                                    $products[] = (array) $item;
                                }
                            }
                        } else {
                            // No items found — notify and leave products empty
                            $this->containerApi->info('No products found in API response.');
                        }
                    }
                } else {
                    $this->containerApi->info('Access token not present in OAuth response. HTTP code: ' . $httpCode);
                }
            }
        } else {
            $this->containerApi->info('OAuth client_id / client_secret not set. Skipping remote import.');
        }

        $this->containerApi->appendManyToOutputFile($products);

        $this->containerApi->info('Imported ' . \count($products) . ' products.');

        // Notify the end-user in the Productsup notification panel
        $this->containerApi->sendNotification('success', 'Import completed: ' . \count($products) . ' products imported.');
    }
}