<?php
$techCards = json_decode(file_get_contents('tech_cards.json'), true);

$newCard = [
    'id' => $_POST['id'] ?: uniqid(),
    'product_name' => $_POST['product_name'],
    'materials' => array_values($_POST['materials'] ?? []),
];

$cardExists = false;
foreach ($techCards as $i => $card) {
    if ($card['id'] === $newCard['id']) {
        $techCards[$i] = $newCard;
        $cardExists = true;
        break;
    }
}

if (!$cardExists) {
    $techCards[] = $newCard;
}

file_put_contents('tech_cards.json', json_encode($techCards, JSON_PRETTY_PRINT));

header('Location: index.php');
?>