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

                    // Fetch products with pagination and map to {id, name}
                    $productsUrlBase = 'https://api.etimix.com/api/v2/products';
                    $offset = 0;
                    $limit = 100; // adjust as needed
                    $totalCount = null;

                    do {
                        $url = $productsUrlBase . '?limit=' . $limit . '&offset=' . $offset . '&excludeNulls=true';
                        $ch = curl_init($url);
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
                            break;
                        }

                        $data = json_decode($resp, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $this->containerApi->info('Invalid JSON response when fetching products: ' . json_last_error_msg());
                            break;
                        }

                        if ($totalCount === null && isset($data['totalCount'])) {
                            $totalCount = (int) $data['totalCount'];
                        }

                        if (!empty($data['items']) && is_array($data['items'])) {
                            foreach ($data['items'] as $item) {
                                if (!is_array($item)) {
                                    continue;
                                }

                                // map productId -> id and partnumberManufacturer -> name
                                if (isset($item['productId'])) {
                                    $products[] = [
                                        'id' => (int) $item['productId'],
                                        'productId' => (int) $item['productId'],
                                        'relationManufacturer' => isset($item['relationManufacturer']) ? (string) $item['relationManufacturer'] : '',
                                        'partnumberManufacturer' => isset($item['partnumberManufacturer']) ? (string) $item['partnumberManufacturer'] : '',
                                        'name' => isset($item['partnumberManufacturer']) ? (string) $item['partnumberManufacturer'] : '',
                                    ];
                                }
                            }

                            // for now always break when offset is higher than 0, to avoid fetching all products in this example
                            if ($offset > 0){
                                break;
                            }

                            // advance offset
                            $offset += $limit;
                        } else {
                            // no items returned
                            break;
                        }

                        // stop if we've fetched all
                        if ($totalCount !== null && $offset >= $totalCount) {
                            break;
                        }

                    } while (true);
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