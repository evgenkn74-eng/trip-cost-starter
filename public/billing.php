<?php
require __DIR__ . '/../lib/bootstrap.php';

$months = db()->query("SELECT DISTINCT substr(departure_date, 1, 7) FROM trips ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);

page_header('Счета за месяц');
?>
<h1>Счета за месяц</h1>
<div id="billing_message" class="msg" style="display: none;"></div>
<form id="filter" class="filter">
    <select name="month">
        <option value="">Все месяцы</option>
        <?php foreach ($months as $m): ?>
            <option value="<?= h($m) ?>"><?= h($m) ?></option>
        <?php endforeach; ?>
    </select>
	<button id="show_billing">Показать</button>
	<button id="apply_billing" disabled>Применить</button>
</form>

<div id="billing_result"></div>

<!-- <h2>Предварительный пересчёт счетов</h2>
<table class="grid">
    <thead>
    <tr>
        <th>Счёт</th>
        <th>Клиент</th>
        <th class="r">Сумма сейчас, ₽</th>
        <th class="r">Сумма по новому правилу, ₽</th>
        <th class="r">Разница, ₽</th>
        <th>Статус</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td><a href="invoice.php?id=101">№ 101</a></td>
        <td>ООО «Вектор»</td>
        <td class="r">48 500,00</td>
        <td class="r">53 210,00</td>
        <td class="r">+4 710,00</td>
        <td>Черновик</td>
    </tr>
    <tr class="invoice-sent">
        <td><a href="invoice.php?id=102">№ 102</a></td>
        <td>ООО «Север»</td>
        <td class="r">26 300,50</td>
        <td class="r">24 900,25</td>
        <td class="r">-1 400,25</td>
        <td>Отправлен <small>(не будет изменён)</small></td>
    </tr>
    <tr class="invoice-sent">
        <td><a href="invoice.php?id=103">№ 103</a></td>
        <td>ООО «Маяк»</td>
        <td class="r">11 750,00</td>
        <td class="r">11 750,00</td>
        <td class="r">0,00</td>
        <td>Отправлен <small>(не будет изменён)</small></td>
    </tr>
    <tr class="invoice-new">
        <td>—</td>
        <td>ООО «Маяк 2»</td>
        <td class="r">0,00</td>
        <td class="r">11 750,00</td>
        <td class="r">+11 750,00</td>
        <td>Новый <small>(будет создан)</small></td>
    </tr>
    </tbody>
    <tfoot>
    <tr class="total">
        <td colspan="2">Итого по всем счетам</td>
        <td class="r">86 550,50</td>
        <td class="r">101 610,25</td>
        <td class="r">+15 059,75</td>
        <td></td>
    </tr>
    <tr class="total-sub invoice-new">
        <td colspan="2">Будет создано (новые)</td>
        <td class="r">0,00</td>
        <td class="r">11 750,00</td>
        <td class="r">+11 750,00</td>
        <td></td>
    </tr>
    <tr class="total-sub">
        <td colspan="2">Будет пересчитано (черновики)</td>
        <td class="r">48 500,00</td>
        <td class="r">53 210,00</td>
        <td class="r">+4 710,00</td>
        <td></td>
    </tr>
    <tr class="total-sub invoice-sent">
        <td colspan="2">Не будет пересчитано (отправленные)</td>
        <td class="r">38 050,50</td>
        <td class="r">36 650,25</td>
        <td class="r">-1 400,25</td>
        <td></td>
    </tr>
    </tfoot>
</table>

<h2>Итоги за месяц</h2>
<table class="grid">
    <thead>
    <tr>
        <th>Показатель</th>
        <th class="r">Сумма, ₽</th>
        <th>Сверка с суммой всех рейсов</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td>Сумма сейчас (по счетам)</td>
        <td class="r">86 550,50</td>
        <td class="warn">расхождение: -15 059,75</td>
    </tr>
    <tr>
        <td>Сумма по новому правилу (все счета)</td>
        <td class="r">101 610,25</td>
        <td class="ok">сходится</td>
    </tr>
    <tr>
        <td>Будет записано при применении <small>(черновики и новые по новому правилу + отправленные как есть)</small></td>
        <td class="r">103 010,50</td>
        <td class="warn">расхождение: +1 400,25</td>
    </tr>
    <tr class="total">
        <td>Сумма всех рейсов за месяц</td>
        <td class="r">101 610,25</td>
        <td></td>
    </tr>
    </tbody>
</table> -->

<script src="js/billing.js"></script>
<?php page_footer();
