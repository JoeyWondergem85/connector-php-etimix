<?php

declare(strict_types=1);

namespace App\DataSource\Service;

use Productsup\CDE\ContainerApi\ContainerApiInterface;

readonly class ImportService
{
    public function __construct(
        private ContainerApiInterface $containerApi,
        private string $tradeProductMode,
    ) {}

    public function run(): void
    {
        if (!in_array($this->tradeProductMode, ['numbered', 'repeated'], true)) {
            throw new \InvalidArgumentException('TRADE_PRODUCT_MODE must be "numbered" or "repeated".');
        }

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
                    $limit = 10; // adjust as needed
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
                                    // extract technical descriptions by language
                                    $techNl = null;
                                    $techEn = null;
                                    if (!empty($item['technicalDescription']) && is_array($item['technicalDescription'])) {
                                        foreach ($item['technicalDescription'] as $td) {
                                            if (!is_array($td)) {
                                                continue;
                                            }
                                            if (isset($td['language'], $td['value'])) {
                                                if ($td['language'] === 'nl-NL') {
                                                    $techNl = (string) $td['value'];
                                                }
                                                if ($td['language'] === 'en-GB') {
                                                    $techEn = (string) $td['value'];
                                                }
                                            }
                                        }
                                    }

                                    // extract features from featureGroups
                                    $featuresArr = [];
                                    if (!empty($item['featureGroups']) && is_array($item['featureGroups'])) {
                                        foreach ($item['featureGroups'] as $fg) {
                                            if (empty($fg['features']) || !is_array($fg['features'])) {
                                                continue;
                                            }
                                            foreach ($fg['features'] as $feat) {
                                                if (!is_array($feat) || empty($feat['etimId'])) {
                                                    continue;
                                                }

                                                // determine feature label: etimId + translation description (prefer nl-NL)
                                                $labelDesc = null;
                                                if (!empty($feat['translations']) && is_array($feat['translations'])) {
                                                    foreach ($feat['translations'] as $tr) {
                                                        if (isset($tr['languageCode']) && $tr['languageCode'] === 'nl-NL' && !empty($tr['description'])) {
                                                            $labelDesc = $tr['description'];
                                                            break;
                                                        }
                                                    }
                                                    if ($labelDesc === null) {
                                                        foreach ($feat['translations'] as $tr) {
                                                            if (!empty($tr['description'])) {
                                                                $labelDesc = $tr['description'];
                                                                break;
                                                            }
                                                        }
                                                    }
                                                }
                                                $label = $feat['etimId'] . ($labelDesc ? ' ' . $labelDesc : '');

                                                // determine value based on type
                                                $value = null;
                                                $type = isset($feat['type']) ? (string) $feat['type'] : '';
                                                if ($type === 'A') {
                                                    // for A use valueDescription etimId + description if available, otherwise featureValue
                                                    if (!empty($feat['value']['valueDescription']) && is_array($feat['value']['valueDescription'])) {
                                                        $vd = $feat['value']['valueDescription'];
                                                        $vdId = isset($vd['etimId']) ? $vd['etimId'] : null;
                                                        $vdDesc = null;
                                                        if (!empty($vd['translations']) && is_array($vd['translations'])) {
                                                            foreach ($vd['translations'] as $vtr) {
                                                                if (isset($vtr['languageCode']) && $vtr['languageCode'] === 'nl-NL' && !empty($vtr['description'])) {
                                                                    $vdDesc = $vtr['description'];
                                                                    break;
                                                                }
                                                            }
                                                            if ($vdDesc === null) {
                                                                foreach ($vd['translations'] as $vtr) {
                                                                    if (!empty($vtr['description'])) {
                                                                        $vdDesc = $vtr['description'];
                                                                        break;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if ($vdId !== null) {
                                                            $value = $vdId . ($vdDesc ? ' ' . $vdDesc : '');
                                                        } elseif (isset($feat['value']['featureValue'])) {
                                                            $value = (string) $feat['value']['featureValue'];
                                                        }
                                                    } else {
                                                        if (isset($feat['value']['featureValue'])) {
                                                            $value = (string) $feat['value']['featureValue'];
                                                        }
                                                    }
                                                } else {
                                                    // types N, R, L -> use featureValue
                                                    if (isset($feat['value']['featureValue'])) {
                                                        $value = (string) $feat['value']['featureValue'];
                                                    }
                                                }

                                                if ($label !== '') {
                                                    $featuresArr[$label] = $value;
                                                }
                                            }
                                        }
                                    }

                                    $mapped = [
                                        'product_productId' => (int) $item['productId'],
                                        'product_relationManufacturer' => isset($item['relationManufacturer']) ? (string) $item['relationManufacturer'] : null,
                                        'product_partnumberManufacturer' => isset($item['partnumberManufacturer']) ? (string) $item['partnumberManufacturer'] : null,
                                        'product_eanCodePartnumber' => isset($item['eanCodePartnumber']) ? (string) $item['eanCodePartnumber'] : null,
                                        'product_brand' => isset($item['brand']) ? (string) $item['brand'] : null,
                                        'product_productDefines' => isset($item['productDefines']) ? (float) $item['productDefines'] : null,
                                        'product_smallestUnit' => isset($item['smallestUnit']) ? (bool) $item['smallestUnit'] : false,
                                        'product_netWeight' => isset($item['netWeight']) ? (float) $item['netWeight'] : null,
                                        'product_netWeightUnit' => isset($item['netWeightUnit']) ? (string) $item['netWeightUnit'] : null,
                                        'product_partNumberSuccessor' => isset($item['partNumberSuccessor']) ? (string) $item['partNumberSuccessor'] : null,
                                        'product_ean-CodeSuccessor' => isset($item['ean-CodeSuccessor']) ? (string) $item['ean-CodeSuccessor'] : null,
                                        'product_partnumberPredecessor' => isset($item['partnumberPredecessor']) ? (string) $item['partnumberPredecessor'] : null,
                                        'product_ean-CodePredecessor' => isset($item['ean-CodePredecessor']) ? (string) $item['ean-CodePredecessor'] : null,
                                        'product_dinNumber' => isset($item['dinNumber']) ? (string) $item['dinNumber'] : null,
                                        'product_isoNumber' => isset($item['isoNumber']) ? (string) $item['isoNumber'] : null,
                                        'product_statusCode' => isset($item['statusCode']) ? (string) $item['statusCode'] : null,
                                        'product_sdsRevisionDate' => isset($item['sdsRevisionDate']) ? (string) $item['sdsRevisionDate'] : null,
                                        'product_countryOfProductionOrigin' => isset($item['countryOfProductionOrigin']) ? (string) $item['countryOfProductionOrigin'] : null,
                                        'product_eccnNumber' => isset($item['eccnNumber']) ? (string) $item['eccnNumber'] : null,
                                        'product_sdsIndication' => isset($item['sdsIndication']) ? (bool) $item['sdsIndication'] : false,
                                        'product_unCode' => isset($item['unCode']) ? (string) $item['unCode'] : null,
                                        'product_reachListDate' => isset($item['reachListDate']) ? (string) $item['reachListDate'] : null,
                                        'product_reachIndicator' => isset($item['reachIndicator']) ? (bool) $item['reachIndicator'] : false,
                                        'product_technicalDescription_nl-NL' => $techNl,
                                        'product_technicalDescription_en-GB' => $techEn,
                                        'product_created' => isset($item['created']) ? (string) $item['created'] : null,
                                        'product_modified' => isset($item['modified']) ? (string) $item['modified'] : null,
                                    ];

                                    // merge feature key/value pairs as individual columns
                                    if (!empty($featuresArr) && is_array($featuresArr)) {
                                        foreach ($featuresArr as $fKey => $fValue) {
                                            // replace spaces with underscores to form valid array keys
                                            $safeKey = str_replace(' ', '_', $fKey);
                                            $mapped['product_' . $safeKey] = $fValue;
                                        }
                                    }

                                    $tradeProducts = $item['tradeProducts'] ?? [];
                                    if (!is_array($tradeProducts)) {
                                        throw new \UnexpectedValueException('Expected tradeProducts to be an array.');
                                    }

                                    if ($tradeProducts === []) {
                                        $products[] = $mapped;
                                    }

                                    foreach ($tradeProducts as $index => $tradeProduct) {
                                        if (!is_array($tradeProduct)) {
                                            throw new \UnexpectedValueException('Expected every tradeProduct to be an object.');
                                        }

                                        $prefix = $this->tradeProductMode === 'numbered'
                                            ? 'tradeproduct_' . ($index + 1) . '_'
                                            : 'tradeproduct_';
                                        $tradeColumns = [];
                                        foreach ($tradeProduct as $key => $value) {
                                            if (is_scalar($value) || $value === null) {
                                                $tradeColumns[$prefix . $key] = $value;
                                            }
                                        }

                                        if ($this->tradeProductMode === 'repeated') {
                                            $products[] = array_merge($mapped, $tradeColumns);
                                        } else {
                                            $mapped = array_merge($mapped, $tradeColumns);
                                        }
                                    }

                                    if ($tradeProducts !== [] && $this->tradeProductMode === 'numbered') {
                                        $products[] = $mapped;
                                    }
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

        $this->containerApi->info('Imported ' . \count($products) . ' rows.');

        // Notify the end-user in the Productsup notification panel
        $this->containerApi->sendNotification('success', 'Import completed: ' . \count($products) . ' rows imported.');
    }
}