<?php
// Логируем входящие запросы для отладки
file_put_contents('webhook_log.txt', file_get_contents('php://input') . PHP_EOL, FILE_APPEND);

$config = require 'config.php';
$techCards = json_decode(file_get_contents('tech_cards.json'), true);

$request = json_decode(file_get_contents('php://input'), true);

if ($request['event'] === 'ONCRMDEALUPDATE' && isset($request['data']['FIELDS']['ID'])) {
    $dealId = $request['data']['FIELDS']['ID'];

    // Получаем данные о сделке
    $dealInfo = callBitrixApi('crm.deal.get', ['id' => $dealId]);

    if ($dealInfo && $dealInfo['result']['STAGE_ID'] === $config['deal_stage_for_production']) {
        // Получаем товары в сделке
        $products = callBitrixApi('crm.deal.productrows.get', ['id' => $dealId]);

        if ($products && !empty($products['result'])) {
            foreach ($products['result'] as $product) {
                $productName = $product['PRODUCT_NAME'];
                $quantity = $product['QUANTITY'];

                // Ищем тех. карту для товара
                $techCard = findTechCard($productName, $techCards);

                if ($techCard) {
                    // Списываем материалы
                    foreach ($techCard['materials'] as $material) {
                        $materialName = $material['name'];
                        $materialQuantity = $material['quantity'] * $quantity;

                        // 1. Найти товар (материал) на складе по названию
                        $materialProduct = callBitrixApi('catalog.product.list', [
                            'filter' => ['NAME' => $materialName],
                            'select' => ['ID', 'QUANTITY']
                        ]);

                        if ($materialProduct && !empty($materialProduct['result']['products'])) {
                            $productId = $materialProduct['result']['products'][0]['id'];
                            $availableQuantity = $materialProduct['result']['products'][0]['quantity'];

                            if ($availableQuantity >= $materialQuantity) {
                                // 2. Создать документ списания
                                callBitrixApi('catalog.store.document.add', [
                                    'fields' => [
                                        'DOC_TYPE' => 'W',
                                        'ITEMS' => [
                                            [
                                                'ELEMENT_ID' => $productId,
                                                'AMOUNT' => $materialQuantity,
                                            ]
                                        ]
                                    ]
                                ]);
                            } else {
                                // Логика для случая, если материала не хватает
                                // Например, отправить уведомление ответственному
                            }
                        }
                    }
                }
            }
        }
    }
}

function callBitrixApi($method, $params) {
    $config = require 'config.php';
    $url = $config['bitrix_webhook_url'] . $method;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_POST => 1,
        CURLOPT_HEADER => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => $url,
        CURLOPT_POSTFIELDS => http_build_query($params),
    ]);
    $result = curl_exec($curl);
    curl_close($curl);

    return json_decode($result, true);
}

function findTechCard($productName, $techCards) {
    foreach ($techCards as $card) {
        if (mb_strtolower($card['product_name']) === mb_strtolower($productName)) {
            return $card;
        }
    }
    return null;
}
?>