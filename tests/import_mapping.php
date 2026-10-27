<?php

declare(strict_types=1);

namespace Productsup\CDE\ContainerApi {
    interface ContainerApiInterface
    {
        public function info(string $message): void;
        public function appendManyToOutputFile(array $products): void;
        public function sendNotification(string $type, string $message): void;
    }
}

namespace App\DataSource\Service {
    function curl_init(string $url): string
    {
        return $url;
    }

    function curl_setopt(string $handle, int $option, mixed $value): bool
    {
        return true;
    }

    function curl_exec(string $handle): string
    {
        return str_contains($handle, '/oauth/')
            ? '{"access_token":"test-token"}'
            : json_encode([
                'totalCount' => count($GLOBALS['testProducts']),
                'items' => $GLOBALS['testProducts'],
            ], JSON_THROW_ON_ERROR);
    }

    function curl_error(string $handle): string
    {
        return '';
    }

    function curl_getinfo(string $handle, int $option): int
    {
        return 200;
    }

    function curl_close(string $handle): void
    {
    }
}

namespace {
    require __DIR__ . '/../src/DataSource/Service/ImportService.php';

    use App\DataSource\Service\ImportService;
    use Productsup\CDE\ContainerApi\ContainerApiInterface;

    class OutputCollector implements ContainerApiInterface
    {
        public array $rows = [];
        public string $notification = '';

        public function info(string $message): void
        {
        }

        public function appendManyToOutputFile(array $products): void
        {
            $this->rows = $products;
        }

        public function sendNotification(string $type, string $message): void
        {
            $this->notification = $message;
        }
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    $GLOBALS['testProducts'] = [
        [
            'productId' => 101,
            'statusCode' => 'product-status',
            'technicalDescription' => [['language' => 'nl-NL', 'value' => 'Product description']],
            'featureGroups' => [[
                'features' => [[
                    'etimId' => 'EF000001',
                    'translations' => [['languageCode' => 'nl-NL', 'description' => 'Length']],
                    'type' => 'N',
                    'value' => ['featureValue' => '42'],
                ]],
            ]],
            'tradeProducts' => [
                [
                    'TradeProductId' => 201,
                    'statusCode' => 'first-trade-status',
                    'suppliersPartnumber' => 'first',
                    'orderableIndicator' => false,
                    'grossWeight' => 1.5,
                    'prices' => [['value' => 10]],
                ],
                [
                    'TradeProductId' => 202,
                    'statusCode' => 'second-trade-status',
                    'suppliersPartnumber' => 'second',
                    'discountGroupCode' => null,
                ],
            ],
        ],
        [
            'productId' => 102,
            'tradeProducts' => [['TradeProductId' => 203, 'leadtime' => 3]],
        ],
        ['productId' => 103, 'tradeProducts' => []],
    ];

    $collector = new OutputCollector();
    (new ImportService($collector, 'numbered'))->run();
    check(count($collector->rows) === 3, 'numbered mode should emit one row per product');
    check(str_contains($collector->notification, '3 rows'), 'notification must count output rows');
    $first = $collector->rows[0];
    check($first['product_productId'] === 101, 'product ID must be prefixed');
    check($first['product_statusCode'] === 'product-status', 'product field must be prefixed');
    check($first['product_EF000001_Length'] === '42', 'feature must be prefixed');
    check($first['product_technicalDescription_nl-NL'] === 'Product description', 'description must be prefixed');
    check($first['tradeproduct_1_TradeProductId'] === 201, 'first trade ID is missing');
    check($first['tradeproduct_2_TradeProductId'] === 202, 'second trade ID is missing');
    check($first['tradeproduct_1_statusCode'] === 'first-trade-status', 'overlapping fields must not collide');
    check($first['tradeproduct_2_discountGroupCode'] === null, 'nullable scalar must be preserved');
    check($first['tradeproduct_1_orderableIndicator'] === false, 'boolean must be preserved');
    check($first['tradeproduct_1_grossWeight'] === 1.5, 'number must be preserved');
    check(!array_key_exists('tradeproduct_1_prices', $first), 'nested collections must be excluded');
    check($collector->rows[1]['tradeproduct_1_leadtime'] === 3, 'single trade product must be included');
    check(!array_key_exists('tradeproduct_1_TradeProductId', $collector->rows[2]), 'empty trade list must leave product-only row');

    $collector = new OutputCollector();
    (new ImportService($collector, 'repeated'))->run();
    check(count($collector->rows) === 4, 'repeated mode should emit one row per trade product and preserve empty products');
    check(str_contains($collector->notification, '4 rows'), 'repeated notification must count output rows');
    check($collector->rows[0]['product_productId'] === 101 && $collector->rows[1]['product_productId'] === 101, 'product fields must repeat');
    check($collector->rows[0]['tradeproduct_TradeProductId'] === 201, 'first repeated trade ID is missing');
    check($collector->rows[1]['tradeproduct_TradeProductId'] === 202, 'second repeated trade ID is missing');
    check($collector->rows[1]['product_EF000001_Length'] === '42', 'features must repeat');
    check(!array_key_exists('tradeproduct_1_TradeProductId', $collector->rows[0]), 'repeated mode must not number columns');
    check($collector->rows[2]['tradeproduct_TradeProductId'] === 203, 'single trade product must have its own row');
    check($collector->rows[3]['product_productId'] === 103 && !array_key_exists('tradeproduct_TradeProductId', $collector->rows[3]), 'empty trade list must leave product-only row');

    try {
        (new ImportService(new OutputCollector(), 'invalid'))->run();
        throw new \RuntimeException('invalid mode must fail');
    } catch (\InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), 'TRADE_PRODUCT_MODE'), 'invalid mode error should identify the variable');
    }

    echo "Import mapping checks passed.\n";
}
