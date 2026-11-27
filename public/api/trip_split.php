<?php
require __DIR__ . '/../../lib/bootstrap.php';

$trip_id = isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : 0;
// пример ['id' => 7, 'number' => 'СПБ-1107', 'cost' => 51614.07]
$st = db()->prepare('SELECT id, number, cost FROM trips WHERE id = ?');
$st->execute(array($trip_id));
$trip = $st->fetch();
if (!$trip) {
    json_out(array('error' => 'Рейс не найден'), 404);
}

$st = db()->prepare('SELECT client_id, COUNT(*) AS cargo_count
    FROM cargo
    WHERE trip_id = ?
    GROUP BY client_id');
$st->execute(array($trip_id));
// fetchAll() [['client_id' => 1, 'cargo_count' => 2], ['client_id' => 4, 'cargo_count' => 1]...
// array_column() ['1' => 2, '4' => 1]
$cargo_counts = array_column($st->fetchAll(), 'cargo_count', 'client_id');

// расчитываю доли клиентов рейса и их суммы по одному из способов (по весу или по объёму)
$shares = calc_share($trip_id);

$clients = array();
$summary = 0;
foreach ($shares as $client_id => $share) {
    $summary += (int)round((float)$share['amount'] * 100);
    $clients[] = array(
        'client_id' => (int)$client_id,
        'name' => $share['name'],
        'cargo_count' => (int)($cargo_counts[$client_id] ?? 0),
        'weight_kg' => (float)$share['weight_kg'],
        'volume_m3' => $share['volume_m3'] !== null && (float)$share['volume_m3'] > 0
            ? (float)$share['volume_m3']
            : null,
        'share' => round((float)$share['share'], 4),
        'amount' => number_format((float)$share['amount'], 2, '.', ''),
        'basis' => $share['basis'],
    );
}

json_out(array(
    'trip_id' => (int)$trip['id'],
    'number' => $trip['number'],
    'cost' => number_format((float)$trip['cost'], 2, '.', ''),
    'clients' => $clients,
    'summary' => number_format($summary / 100, 2, '.', ''),
));
