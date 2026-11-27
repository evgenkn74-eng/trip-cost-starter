<?php
require __DIR__ . '/../../lib/bootstrap.php';

// запись в базу только по POST: GET могут случайно открыть или обновить
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Allow: POST');
	json_out(array('error' => 'Метод не поддерживается, используйте POST'), 405);
}

$period = isset($_POST['period']) ? $_POST['period'] : '';
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
	json_out(array('errors' => array('period' => 'Некорректный период')), 400);
}

try {
	json_out(billing_apply_period($period));
} catch (Exception $e) {
	// транзакция уже откатана внутри billing_apply_period
	json_out(array('error' => $e->getMessage()), 500);
}
