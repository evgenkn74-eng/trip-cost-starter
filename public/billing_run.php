<?php

// Старое формирование счетов заменено экраном «Счета за месяц» (billing.php).
// Оно пересчитывало и отправленные счета, что противоречит ТЗ, поэтому здесь только переход.
header('Location: billing.php');
exit;

// старый код, оставлен для справки:
// require __DIR__ . '/../lib/bootstrap.php';

// $period = isset($_POST['period']) ? $_POST['period'] : '';
// if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
//     die('Неверный месяц');
// }

// $result = billing_run_period($period);

// $msg = 'Счета за ' . $period . ': создано ' . $result['created'] . ', обновлено ' . $result['updated'];
// header('Location: invoices.php?period=' . urlencode($period) . '&msg=' . urlencode($msg));
