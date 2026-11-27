<?php
require __DIR__ . '/../../lib/bootstrap.php';

$period = isset($_GET['period']) ? $_GET['period'] : '';
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
	json_out(array('errors' => array('period' => 'Некорректный период')), 400);
}

try {
	json_out(billing_preview_data($period));
} catch (Exception $e) {
	json_out(array('error' => $e->getMessage()), 500);
}
