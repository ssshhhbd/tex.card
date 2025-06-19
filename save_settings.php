<?php
$config = [
    'bitrix_webhook_url' => $_POST['bitrix_webhook_url'],
    'deal_stage_for_production' => $_POST['deal_stage_for_production'],
];

file_put_contents('config.php', '<?php return ' . var_export($config, true) . ';');

header('Location: index.php');
?>