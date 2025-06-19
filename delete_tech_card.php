<?php
if (isset($_GET['id'])) {
    $techCards = json_decode(file_get_contents('tech_cards.json'), true);

    $techCards = array_filter($techCards, function($card) {
        return $card['id'] !== $_GET['id'];
    });

    file_put_contents('tech_cards.json', json_encode(array_values($techCards), JSON_PRETTY_PRINT));
}
?>