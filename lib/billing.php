<?php

// Выбирает способ расчёта: по объёму, если он > 0 у всех грузов рейса, иначе по весу
function calc_share($trip_id)
{
    $trip_id = (int)$trip_id;

    // total — сколько грузов в рейсе, bad — у скольких объём не указан или не больше нуля
    $check = db()->query("SELECT COUNT(*) AS total,
		SUM(volume_m3 IS NULL OR volume_m3 <= 0) AS bad
		FROM cargo WHERE trip_id = $trip_id")->fetch();

    // по объёму считаем, только если грузы есть и ни у одного нет проблемы с объёмом
    if ($check['total'] > 0 && (int)$check['bad'] === 0) {
        return calc_share_by_volume($trip_id);
    }
    return calc_share_by_weight($trip_id);
}

/**
 * Доли клиентов в стоимости рейса по ОБЪЁМУ.
 *
 * Вызывать только если объём указан у всех грузов рейса
 * (эту проверку делает диспетчер calc_share).
 */
function calc_share_by_volume($trip_id)
{
    // Стоимость рейса в копейках; null — рейса нет, считать нечего.
    $cost_kop = get_trip_cost_kop($trip_id);
    if ($cost_kop === null) return array();

    // Клиенты рейса с суммами веса и объёма.
    $rows = get_trip_client_rows($trip_id);

    // Единицы клиента — тысячные доли м³: м³ * 1000, округлённые до целого.
    // Так расчёт остаётся целочисленным, как и для веса.
    $units = array();
    foreach ($rows as $row) {
        $units[$row['client_id']] = (int)round($row['volume_m3'] * 1000);
    }

    // Делим копейки по тысячным долям м³ и собираем результат с пометкой 'volume'.
    return build_share_result($rows, $units, distribute_cost($cost_kop, $units), 'volume');
}

/**
 * Доли клиентов в стоимости рейса по ВЕСУ.
 */
function calc_share_by_weight($trip_id)
{
    // Стоимость рейса в копейках; null — рейса нет, считать нечего.
    $cost_kop = get_trip_cost_kop($trip_id);
    if ($cost_kop === null) return array();

    // Клиенты рейса с суммами веса и объёма.
    $rows = get_trip_client_rows($trip_id);

    // Единицы клиента — граммы: килограммы * 1000, округлённые до целого.
    $units = array();
    foreach ($rows as $row) {
        $units[$row['client_id']] = (int)round($row['weight_kg'] * 1000);   // граммы
    }

    // Делим копейки по граммам и собираем результат с пометкой 'weight'.
    return build_share_result($rows, $units, distribute_cost($cost_kop, $units), 'weight');
}



/**
 * Стоимость рейса в копейках.
 *
 * Возвращает целое число копеек или null, если рейса с таким id нет.
 * null нужен, чтобы вызывающий код отличил «рейса нет» от «рейс стоит 0».
 */
function get_trip_cost_kop($trip_id)
{
    // Приводим id к целому числу: так его безопасно вставлять в SQL строкой.
    $trip_id = (int)$trip_id;

    // Берём стоимость рейса из таблицы trips (в рублях, например 51614.07).
    $trip = db()->query("SELECT cost FROM trips WHERE id = $trip_id")->fetch();

    // Рейс найден: переводим рубли в копейки.
    // round() нужен, потому что 51614.07 * 100 в дробных числах может дать
    // 5161406.999999..., а нам нужно ровно 5161407. (int) делает число целым.
    // Рейс не найден: fetch() вернул false, отдаём null.
    return $trip ? (int)round($trip['cost'] * 100) : null;
}

/**
 * Клиенты рейса с суммарным весом и объёмом их грузов.
 *
 * Одна строка на клиента: у клиента может быть несколько грузов в рейсе,
 * поэтому вес и объём суммируем.
 */
function get_trip_client_rows($trip_id)
{
    // Приводим id к целому числу (защита SQL-строки от лишних символов).
    $trip_id = (int)$trip_id;

    // clients = Имя клиента
	// cargo = ID клиента, вес груза (сумма разных видов грузов)
    return db()->query("SELECT c.client_id, cl.name, SUM(c.weight_kg) AS weight_kg, SUM(c.volume_m3) AS volume_m3
        FROM cargo c
        JOIN clients cl ON cl.id = c.client_id
        WHERE c.trip_id = $trip_id
        GROUP BY c.client_id, cl.name
        ORDER BY c.client_id")->fetchAll();
}

/**
 * Делит копейки между клиентами пропорционально их целым «единицам».
 *
 * Единицы — это граммы (расчёт по весу) или тысячные доли м³ (по объёму).
 * Вход:  $cost_kop — стоимость в копейках, $units — [client_id => единицы].
 * Выход: [client_id => копейки]. Сумма копеек ВСЕГДА равна $cost_kop.
 * Функция не ходит в базу, поэтому её легко тестировать отдельно.
 */
function distribute_cost($cost_kop, array $units)
{
    // Общее количество единиц у всех клиентов рейса.
    $total = array_sum($units);

    // $kop — копейки каждого клиента, $rem — остаток от деления, $given — сколько роздано.
    $kop = array();
    $rem = array();
    $given = 0;

    // Шаг 1: каждому клиенту целая часть его доли и остаток от деления.
    foreach ($units as $client_id => $u) {
        // Числитель: стоимость * единицы клиента. Целый, поэтому деление можно сделать нацело.
        // Сначала умножаем, потом делим: так не теряется точность.
        $num = $cost_kop * $u;

        // intdiv — деление нацело, дробная часть отбрасывается: «базовая» сумма клиента.
        // Если общих единиц нет (total = 0), делить нельзя: даём ноль.
        $kop[$client_id] = $total > 0 ? intdiv($num, $total) : 0;

        // Остаток от деления: насколько клиента «недоплатили» при отбрасывании.
        // По нему решаем, кому достанутся недостающие копейки.
        $rem[$client_id] = $total > 0 ? $num % $total : 0;

        // Запоминаем, сколько копеек уже роздано.
        $given += $kop[$client_id];
    }

    // Сколько копеек осталось раздать: стоимость минус то, что уже роздано.
    // Всегда от 0 до (число клиентов - 1), потому что у каждого отбросили меньше копейки.
    // Если единиц нет вообще, раздавать нечего.
    $left = $total > 0 ? $cost_kop - $given : 0;

    // Шаг 2: порядок раздачи. Берём список client_id и сортируем его.
    $order = array_keys($units);
    usort($order, function ($a, $b) use ($rem) {
        // Остатки разные: у кого остаток больше, тот раньше (поэтому $b сравниваем с $a).
        if ($rem[$a] !== $rem[$b]) {
            return $rem[$b] <=> $rem[$a];
        }
        // Остатки равны: решаем по client_id, чтобы результат не менялся от запуска к запуску.
        return $a <=> $b;
    });

    // Шаг 3: раздаём недостающие копейки по одной первым $left клиентам из списка.
    for ($k = 0; $k < $left; $k++) {
        $kop[$order[$k]]++;
    }

    return $kop;
}

/**
 * Собирает результат в едином формате для обоих способов расчёта.
 *
 * $rows  — строки клиентов из get_trip_client_rows,
 * $units — единицы клиентов (по ним считается доля),
 * $kop   — копейки клиентов из distribute_cost,
 * $basis — чем считали: 'weight' или 'volume'.
 */
function build_share_result(array $rows, array $units, array $kop, $basis)
{
    // Общие единицы нужны для доли каждого клиента.
    $total = array_sum($units);

    $result = array();
    foreach ($rows as $row) {
        // Короткое имя для ключа клиента, чтобы не повторять длинное выражение.
        $id = $row['client_id'];

        $result[$id] = array(
            // Название клиента для таблиц.
            'name'      => $row['name'],
            // Суммарный вес в килограммах (его показывают страницы).
            'weight_kg' => $row['weight_kg'],
            // Суммарный объём в м³; у клиента может быть null или частичная сумма.
            'volume_m3' => $row['volume_m3'],
            // Чем считали: по этому ключу видно, на какой базе получена сумма.
            'basis'     => $basis,
            // Доля от 0 до 1: единицы клиента / общие единицы (0, если единиц нет).
            'share'     => $total > 0 ? $units[$id] / $total : 0,
            // Сумма в рублях: копейки делим на 100 только здесь, на выходе.
            'amount'    => $kop[$id] / 100,
        );
    }

    return $result;
}

/**
 * Сформировать счета клиентов за месяц.
 *
 * Для каждого клиента берутся рейсы месяца (по дате отправления, кроме отменённых) и его доля в каждом из них.
 * Если счёт за месяц уже есть, его строки пересчитываются заново.
 */
function billing_run_period($period)
{
    $db = db();
    $created = 0;
    $updated = 0;

    $db->beginTransaction();
    foreach (get_clients() as $client) {
        $trips = $db->query("SELECT DISTINCT t.id FROM trips t
            JOIN cargo c ON c.trip_id = t.id
            WHERE c.client_id = {$client['id']}
              AND t.departure_date LIKE '{$period}%'
              AND t.status <> 'cancelled'
            ORDER BY t.departure_date")->fetchAll(PDO::FETCH_COLUMN);
        if (!$trips) {
            continue;
        }

        $invoice = $db->query("SELECT * FROM invoices WHERE client_id = {$client['id']} AND period = '{$period}'")->fetch();
        if ($invoice) {
            $invoice_id = $invoice['id'];
            $db->exec("DELETE FROM invoice_lines WHERE invoice_id = $invoice_id");
            $updated++;
        } else {
            $db->exec("INSERT INTO invoices (client_id, period, status, total, created_at)
                VALUES ({$client['id']}, '{$period}', 'draft', 0, datetime('now', 'localtime'))");
            $invoice_id = $db->lastInsertId();
            $created++;
        }

        $total = 0;
        foreach ($trips as $trip_id) {
            $shares = calc_share($trip_id);
            $amount = $shares[$client['id']]['amount'];
            $db->exec("INSERT INTO invoice_lines (invoice_id, trip_id, amount) VALUES ($invoice_id, $trip_id, $amount)");
            $total += $amount;
        }
        $db->exec("UPDATE invoices SET total = $total WHERE id = $invoice_id");
    }
    $db->commit();

    return array('created' => $created, 'updated' => $updated);
}

/**
 * Копейки (целое число) -> строка рублей с двумя знаками: 5161407 -> '51614.07', -140025 -> '-1400.25'.
 * Целые числа не дают ошибок округления, поэтому деньги считаем в копейках до самого вывода.
 */
function kop_to_str($kop)
{
    $kop = (int)$kop;
    $sign = $kop < 0 ? '-' : '';
    $kop = abs($kop);
	// str_pad() - дополняет строку слева (STR_PAD_LEFT) символами '0', пока её длина не станет равна 2
    return $sign . intdiv($kop, 100) . '.' . str_pad((string)($kop % 100), 2, '0', STR_PAD_LEFT);
}

/**
 * Предпросмотр формирования счетов за месяц. Ничего не пишет в базу.
 *
 * Для каждого клиента сравнивает сумму текущего счёта («до») с суммой по новому правилу («после»)
 * и определяет, что сделает применение: create / recalculate / keep.
 * Возвращает массив, готовый для json_out (формат описан в billing_preview_structure.php).
 */
function billing_preview_data($period)
{
    $db = db();

    // 1. Рейсы месяца: по дате отправления, не отменённые, с грузами (те же условия, что у формирования счетов).
    $st = $db->prepare("SELECT t.id, t.cost FROM trips t
        WHERE t.departure_date LIKE ?
          AND t.status <> 'cancelled'
          AND EXISTS (SELECT 1 FROM cargo c WHERE c.trip_id = t.id)
        ORDER BY t.departure_date");
    $st->execute(array($period . '%'));
    $trips = $st->fetchAll();

    // Общая стоимость всех рейсов за выбранный период (в копейках)
    $trips_cost = 0;
	// Сумма «по новому правилу» по каждому клиенту (в копейках) и общая стоимость рейсов месяца.
    $after = array();
    foreach ($trips as $trip) {
        $trips_cost += (int)round((float)$trip['cost'] * 100);
        foreach (calc_share($trip['id']) as $client_id => $share) {
            // amount обратно в целые копейки: round убирает дробный шум
            $kop = (int)round($share['amount'] * 100);
			// [client_id => копейки]
            $after[$client_id] = (isset($after[$client_id]) ? $after[$client_id] : 0) + $kop;
        }
    }

    // 3. Существующие счета за этот месяц.
    $st = $db->prepare("SELECT id, client_id, status, total FROM invoices WHERE period = ?");
    $st->execute(array($period));
    $invoices = array();
	foreach ($st->fetchAll() as $inv) {
		if (isset($invoices[$inv['client_id']])) {
			throw new Exception('У клиента ' . $inv['client_id'] . ' несколько счетов за ' . $period);
		}
		// [client_id => строка счёта]
		$invoices[$inv['client_id']] = $inv;
	}

    // 4. Итоги по действиям (в копейках).
    $zero = array('count' => 0, 'before' => 0, 'after' => 0);
    $by_action = array('create' => $zero, 'recalculate' => $zero, 'keep' => $zero);
    $total_before = 0;
    $total_after = 0;
    $total_will_write = 0;

    // 5. Одна строка на клиента, у которого есть рейсы в месяце или уже есть счёт.
    //    get_clients() уже отсортирован по именам.
    $clients_out = array();
	$changes = 0;
    foreach (get_clients() as $client) {
        $id = $client['id'];
        $has_invoice = isset($invoices[$id]);
        // у клиента нет ни счёта, ни рейсов в этом месяце
		if (!$has_invoice && !isset($after[$id])) {
            continue;
        }

        $invoice = $has_invoice ? $invoices[$id] : null;
        $before_kop = $has_invoice ? (int)round((float)$invoice['total'] * 100) : 0;
        $after_kop = isset($after[$id]) ? $after[$id] : 0;

        // Что сделает «Применить»: нет счёта - создаст, отправленный - не тронет, черновик - пересчитает.
        if (!$has_invoice) {
            $action = 'create';
        } elseif ($invoice['status'] === 'sent') {
            $action = 'keep';
        } else {
            $action = 'recalculate';
        }

		// считаем только то, что применение реально изменит: новые счета и черновики с другой суммой
		if ($action === 'create' || ($action === 'recalculate' && $after_kop !== $before_kop)) {
			$changes++;
		}

        // Что будет лежать в счёте после применения: у отправленных остаётся старая сумма.
        $will_write_kop = $action === 'keep' ? $before_kop : $after_kop;

        $by_action[$action]['count']++;
        $by_action[$action]['before'] += $before_kop;
        $by_action[$action]['after'] += $after_kop;
        $total_before += $before_kop;
        $total_after += $after_kop;
        // нужен только для итога «Будет записано при применении» (totals.will_write)
        $total_will_write += $will_write_kop;

        $clients_out[] = array(
            'client_id' => (int)$id,
            'name' => $client['name'],
            'invoice_id' => $has_invoice ? (int)$invoice['id'] : null,
            'invoice_status' => $has_invoice ? $invoice['status'] : null,
            // нужен экрану (цвет строки и подпись статуса) и billing_apply: возвращён
            'action' => $action,
            'before' => kop_to_str($before_kop),
            'after' => kop_to_str($after_kop),
            'diff' => kop_to_str($after_kop - $before_kop),
        );
    }

    // 6. Итоги по группам в строковом виде.
    $by_action_out = array();
    foreach ($by_action as $action => $g) {
        $by_action_out[$action] = array(
            'count' => $g['count'],
            'before' => kop_to_str($g['before']),
            'after' => kop_to_str($g['after']),
            'diff' => kop_to_str($g['after'] - $g['before']),
        );
    }

    return array(
        'period' => $period,
        'clients' => $clients_out,
        'total_before' => kop_to_str($total_before),
        'total_after' => kop_to_str($total_after),
        'totals' => array(
            'diff' => kop_to_str($total_after - $total_before),
            'will_write' => kop_to_str($total_will_write),
			// Общая стоимость всех рейсов за выбранный период (в копейках)
            'trips_cost' => kop_to_str($trips_cost),
            'by_action' => $by_action_out,
            // расхождение каждой суммы со стоимостью рейсов месяца; '0.00' значит «сходится»
            'vs_trips' => array(
                'before' => kop_to_str($total_before - $trips_cost),
                'after' => kop_to_str($total_after - $trips_cost),
                'will_write' => kop_to_str($total_will_write - $trips_cost),
            ),
        ),
        // если нечего создавать или пересчитывать, то нет смысла включать кнопку «Применить»
        // 'changes' => $by_action['create']['count'] + $by_action['recalculate']['count'],
		'changes' => $changes
    );
}

/**
 * Строки счетов за месяц по новому правилу: [client_id => [trip_id => копейки]].
 *
 * Рейсы те же, что в предпросмотре: по дате отправления, не отменённые, с грузами.
 */
function billing_period_lines($period)
{
    $st = db()->prepare("SELECT t.id FROM trips t
        WHERE t.departure_date LIKE ?
          AND t.status <> 'cancelled'
          AND EXISTS (SELECT 1 FROM cargo c WHERE c.trip_id = t.id)
        ORDER BY t.departure_date, t.id");
    $st->execute(array($period . '%'));

    $lines = array();
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $trip_id) {
        foreach (calc_share($trip_id) as $client_id => $share) {
            // amount обратно в целые копейки: round убирает дробный шум
            $lines[$client_id][(int)$trip_id] = (int)round($share['amount'] * 100);
        }
    }
    return $lines;
}

/**
 * Применяет пересчёт за месяц: создаёт недостающие счета и пересчитывает черновики.
 * Отправленные счета не трогает. Всё в одной транзакции: либо записано всё, либо ничего.
 * Повторный вызов с тем же периодом ничего не меняет: совпавшие счета пропускаются без записи.
 *
 * Возвращает отчёт: сколько создано / обновлено / не изменилось / пропущено и список счетов с действием.
 */
function billing_apply_period($period)
{
    $db = db();
    $lines = billing_period_lines($period);

    $result = array(
        'period' => $period,
        'created' => 0,     // новых счетов (клиент был без счёта)
        'updated' => 0,     // черновиков, у которых изменились суммы
        'unchanged' => 0,   // черновиков, которые уже совпадают с новым правилом
        'skipped' => 0,     // отправленных счетов: не пересчитываются
        'invoices' => array(),
    );
	// на сколько в сумме изменились записанные счета
    $diff_kop = 0;

    $db->beginTransaction();
    try {
        // Существующие счета месяца по клиенту
        $st = $db->prepare("SELECT id, client_id, status, total FROM invoices WHERE period = ?");
        $st->execute(array($period));
        $invoices = array();

		foreach ($st->fetchAll() as $inv) {
			if (isset($invoices[$inv['client_id']])) {
				throw new Exception('У клиента ' . $inv['client_id'] . ' несколько счетов за ' . $period);
			}
			// [client_id => строка счёта]
			$invoices[$inv['client_id']] = $inv;
		}

        $insert_invoice = $db->prepare("INSERT INTO invoices (client_id, period, status, total, created_at)
            VALUES (?, ?, 'draft', ?, datetime('now', 'localtime'))");
        $insert_line = $db->prepare("INSERT INTO invoice_lines (invoice_id, trip_id, amount) VALUES (?, ?, ?)");
        $select_lines = $db->prepare("SELECT trip_id, amount FROM invoice_lines WHERE invoice_id = ?");
        $delete_lines = $db->prepare("DELETE FROM invoice_lines WHERE invoice_id = ?");
        $update_total = $db->prepare("UPDATE invoices SET total = ? WHERE id = ?");

        foreach (get_clients() as $client) {
            $id = $client['id'];
            $new_lines = isset($lines[$id]) ? $lines[$id] : array();
            $invoice = isset($invoices[$id]) ? $invoices[$id] : null;

            // у клиента нет ни счёта, ни рейсов в этом месяце
            if (!$invoice && !$new_lines) {
                continue;
            }
			// сумма по новому правилу для клиента в копейках
            $after_kop = array_sum($new_lines);
            $before_kop = $invoice ? (int)round((float)$invoice['total'] * 100) : 0;
            $invoice_id = $invoice ? (int)$invoice['id'] : null;

			// Счёта нет: создаём черновик со строками
            if (!$invoice) {
                $insert_invoice->execute(array($id, $period, kop_to_str($after_kop)));
                $invoice_id = (int)$db->lastInsertId();
                foreach ($new_lines as $trip_id => $kop) {
                    $insert_line->execute(array($invoice_id, $trip_id, kop_to_str($kop)));
                }
                $action = 'created';
                $result['created']++;
                $diff_kop += $after_kop - $before_kop;
            }
			// Отправленный: не трогаем (клиент его уже получил)
			elseif ($invoice['status'] === 'sent') {
                $action = 'skipped';
                $result['skipped']++;
            }
			// Черновик: сравниваем текущие строки с новыми
			else {
                $select_lines->execute(array($invoice_id));
                $old_lines = array();
                foreach ($select_lines->fetchAll() as $line) {
                    $old_lines[(int)$line['trip_id']] = (int)round((float)$line['amount'] * 100);
                }
                $old_sorted = $old_lines;
                $new_sorted = $new_lines;
                ksort($old_sorted);
                ksort($new_sorted);
				// сравниваю массивы на предмет полного совпадения ключей, значений, их типов и порядка следования
                if ($old_sorted === $new_sorted && $before_kop === $after_kop) {
                    // Уже совпадает: ничего не пишем, повторный вызов не меняет данные
                    $action = 'unchanged';
                    $result['unchanged']++;
                } else {
                    $delete_lines->execute(array($invoice_id));
                    foreach ($new_lines as $trip_id => $kop) {
                        $insert_line->execute(array($invoice_id, $trip_id, kop_to_str($kop)));
                    }
                    $update_total->execute(array(kop_to_str($after_kop), $invoice_id));
                    $action = 'updated';
                    $result['updated']++;
                    $diff_kop += $after_kop - $before_kop;
                }
            }

            $result['invoices'][] = array(
                'client_id' => (int)$id,
                'name' => $client['name'],
                'invoice_id' => $invoice_id,
                'action' => $action,
                'before' => kop_to_str($before_kop),
                // для skipped это сумма «по новому правилу» справочно: в базу она не записана
                'after' => kop_to_str($after_kop),
                'diff' => kop_to_str($after_kop - $before_kop),
            );
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    $result['changes'] = $result['created'] + $result['updated'];
    $result['total_diff'] = kop_to_str($diff_kop);
    return $result;
}
