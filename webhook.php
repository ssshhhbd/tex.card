<?php
/**
 * Обработчик вебхуков для автоматического запуска производства
 * при изменении этапа сделки в Bitrix24
 */

header('Content-Type: application/json; charset=utf-8');

// Логирование для отладки
function writeLog($message) {
    $logFile = __DIR__ . '/logs/webhook.log';
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

try {
    // Получаем данные от Bitrix24
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    writeLog('Получен вебхук: ' . $input);
    
    if (!$data) {
        throw new Exception('Некорректные данные вебхука');
    }
    
    // Проверяем, что это событие изменения сделки
    if (!isset($data['event']) || $data['event'] !== 'ONCRMDEALADD' && $data['event'] !== 'ONCRMDEALUPDATE') {
        writeLog('Игнорируем событие: ' . ($data['event'] ?? 'неизвестно'));
        echo json_encode(['status' => 'ignored']);
        exit;
    }
    
    // Получаем ID сделки
    $dealId = $data['data']['FIELDS']['ID'] ?? null;
    if (!$dealId) {
        throw new Exception('Не найден ID сделки');
    }
    
    // Получаем новый этап сделки
    $newStageId = $data['data']['FIELDS']['STAGE_ID'] ?? null;
    if (!$newStageId) {
        writeLog('Этап сделки не изменился');
        echo json_encode(['status' => 'no_stage_change']);
        exit;
    }
    
    writeLog("Сделка {$dealId} перешла на этап {$newStageId}");
    
    // Инициализируем обработчик технических карт
    $techCardProcessor = new TechCardProcessor($data['auth']);
    
    // Обрабатываем изменение этапа
    $result = $techCardProcessor->processDealStageChange($dealId, $newStageId);
    
    writeLog('Результат обработки: ' . json_encode($result));
    
    echo json_encode([
        'status' => 'success',
        'result' => $result
    ]);
    
} catch (Exception $e) {
    writeLog('Ошибка: ' . $e->getMessage());
    
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Класс для обработки технических карт
 */
class TechCardProcessor {
    private $auth;
    private $bitrix24;
    
    public function __construct($auth) {
        $this->auth = $auth;
        $this->bitrix24 = new Bitrix24API($auth);
    }
    
    /**
     * Обработка изменения этапа сделки
     */
    public function processDealStageChange($dealId, $newStageId) {
        $results = [];
        
        try {
            // Получаем все технические карты
            $techCards = $this->getTechCards();
            
            // Фильтруем карты по этапу
            $triggeredCards = array_filter($techCards, function($card) use ($newStageId) {
                return isset($card['ufTriggerStage']) && $card['ufTriggerStage'] === $newStageId;
            });
            
            if (empty($triggeredCards)) {
                return ['message' => 'Нет технических карт для этапа ' . $newStageId];
            }
            
            // Получаем информацию о сделке
            $deal = $this->bitrix24->call('crm.deal.get', ['id' => $dealId]);
            
            // Обрабатываем каждую техническую карту
            foreach ($triggeredCards as $card) {
                $result = $this->executeProduction($card, $deal);
                $results[] = $result;
            }
            
            return $results;
            
        } catch (Exception $e) {
            throw new Exception('Ошибка обработки технических карт: ' . $e->getMessage());
        }
    }
    
    /**
     * Получение списка технических карт
     */
    private function getTechCards() {
        try {
            // Получаем технические карты из пользовательского объекта CRM
            $result = $this->bitrix24->call('crm.item.list', [
                'entityTypeId' => 1000, // ID типа объекта для технических карт
                'select' => ['*']
            ]);
            
            return $result['items'] ?? [];
            
        } catch (Exception $e) {
            // Если пользовательский объект не настроен, возвращаем пустой массив
            return [];
        }
    }
    
    /**
     * Выполнение производства по технической карте
     */
    private function executeProduction($techCard, $deal) {
        try {
            $ingredients = json_decode($techCard['ufIngredients'] ?? '[]', true);
            $outputQuantity = intval($techCard['ufOutputQuantity'] ?? 1);
            $productName = $techCard['title'] ?? 'Неизвестный товар';
            
            $result = [
                'techCard' => $productName,
                'dealId' => $deal['ID'],
                'actions' => []
            ];
            
            // Списываем материалы
            foreach ($ingredients as $ingredient) {
                $writeOffResult = $this->writeOffMaterial($ingredient, $outputQuantity);
                $result['actions'][] = $writeOffResult;
            }
            
            // Добавляем готовый товар
            $addProductResult = $this->addFinishedProduct($techCard, $outputQuantity);
            $result['actions'][] = $addProductResult;
            
            // Добавляем комментарий к сделке
            $this->addDealComment($deal['ID'], $productName, $outputQuantity, $ingredients);
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'error' => 'Ошибка выполнения производства: ' . $e->getMessage(),
                'techCard' => $techCard['title'] ?? 'Неизвестная карта'
            ];
        }
    }
    
    /**
     * Списание материала со склада
     */
    private function writeOffMaterial($ingredient, $multiplier = 1) {
        try {
            $quantity = floatval($ingredient['quantity']) * $multiplier;
            
            // Ищем товар по коду
            $products = $this->bitrix24->call('catalog.product.list', [
                'filter' => ['code' => $ingredient['code']],
                'select' => ['id', 'name', 'quantity']
            ]);
            
            if (empty($products['products'])) {
                return [
                    'action' => 'writeOff',
                    'material' => $ingredient['name'],
                    'status' => 'error',
                    'message' => 'Материал не найден в каталоге'
                ];
            }
            
            $product = $products['products'][0];
            $currentQuantity = floatval($product['quantity']);
            
            if ($currentQuantity < $quantity) {
                return [
                    'action' => 'writeOff',
                    'material' => $ingredient['name'],
                    'status' => 'error',
                    'message' => "Недостаточно материала на складе. Требуется: {$quantity}, доступно: {$currentQuantity}"
                ];
            }
            
            // Обновляем количество товара
            $newQuantity = $currentQuantity - $quantity;
            $this->bitrix24->call('catalog.product.update', [
                'id' => $product['id'],
                'fields' => ['quantity' => $newQuantity]
            ]);
            
            return [
                'action' => 'writeOff',
                'material' => $ingredient['name'],
                'status' => 'success',
                'quantity' => $quantity,
                'unit' => $ingredient['unit'],
                'remainingQuantity' => $newQuantity
            ];
            
        } catch (Exception $e) {
            return [
                'action' => 'writeOff',
                'material' => $ingredient['name'],
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Добавление готового товара на склад
     */
    private function addFinishedProduct($techCard, $quantity) {
        try {
            $productCode = $techCard['ufProductCode'] ?? 'FINISHED_' . $techCard['id'];
            $productName = $techCard['title'];
            
            // Ищем существующий товар
            $products = $this->bitrix24->call('catalog.product.list', [
                'filter' => ['code' => $productCode],
                'select' => ['id', 'name', 'quantity']
            ]);
            
            if (!empty($products['products'])) {
                // Обновляем количество существующего товара
                $product = $products['products'][0];
                $currentQuantity = floatval($product['quantity']);
                $newQuantity = $currentQuantity + $quantity;
                
                $this->bitrix24->call('catalog.product.update', [
                    'id' => $product['id'],
                    'fields' => ['quantity' => $newQuantity]
                ]);
                
                return [
                    'action' => 'addProduct',
                    'product' => $productName,
                    'status' => 'updated',
                    'quantity' => $quantity,
                    'totalQuantity' => $newQuantity
                ];
            } else {
                // Создаем новый товар
                $result = $this->bitrix24->call('catalog.product.add', [
                    'fields' => [
                        'iblockId' => 23, // ID каталога товаров
                        'name' => $productName,
                        'code' => $productCode,
                        'active' => 'Y',
                        'quantity' => $quantity,
                        'canBuyZero' => 'N',
                        'quantityTrace' => 'Y'
                    ]
                ]);
                
                return [
                    'action' => 'addProduct',
                    'product' => $productName,
                    'status' => 'created',
                    'quantity' => $quantity,
                    'productId' => $result
                ];
            }
            
        } catch (Exception $e) {
            return [
                'action' => 'addProduct',
                'product' => $techCard['title'],
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Добавление комментария к сделке
     */
    private function addDealComment($dealId, $productName, $quantity, $ingredients) {
        try {
            $ingredientsList = array_map(function($ing) {
                return "• {$ing['name']}: {$ing['quantity']} {$ing['unit']}";
            }, $ingredients);
            
            $comment = "🏭 Автоматическое производство\n\n";
            $comment .= "📦 Произведено: {$productName} x{$quantity}\n\n";
            $comment .= "📋 Использованные материалы:\n" . implode("\n", $ingredientsList);
            
            $this->bitrix24->call('crm.timeline.comment.add', [
                'fields' => [
                    'ENTITY_ID' => $dealId,
                    'ENTITY_TYPE' => 'deal',
                    'COMMENT' => $comment
                ]
            ]);
            
        } catch (Exception $e) {
            // Игнорируем ошибки комментариев
        }
    }
}

/**
 * Простой класс для работы с Bitrix24 API
 */
class Bitrix24API {
    private $auth;
    
    public function __construct($auth) {
        $this->auth = $auth;
    }
    
    public function call($method, $params = []) {
        $url = $this->auth['domain'] . '/rest/' . $method . '.json';
        
        $params['auth'] = $this->auth['access_token'];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: {$httpCode}");
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            throw new Exception("API Error: {$data['error_description']}");
        }
        
        return $data['result'] ?? $data;
    }
}

?>