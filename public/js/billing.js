$(function () {
	// сюда рисуются обе таблицы: <div id="billing_result"></div>
	var $result = $('#billing_result');
	var $apply = $('#apply_billing');
	var $message = $('#billing_message');

	// последний показанный предпросмотр: «Применить» работает именно с ним, а не с тем, что сейчас выбрано в списке
	var shown = null;

	// число со знаком: +4 710,00 / -1 400,25 / 0,00 (знак нужен, чтобы отличать рост от падения)
	function signed(value) {
		var n = Number(value);
		var text = money(Math.abs(n));
		if (Math.round(n * 100) > 0) return '+' + text;
		if (Math.round(n * 100) < 0) return '-' + text;
		return text;
	}

	// ячейка сверки со стоимостью рейсов: 0.00 значит «сходится»
	function checkCell(diff) {
		if (Math.round(Number(diff) * 100) === 0) {
			return '<td class="ok">сходится</td>';
		}
		return '<td class="warn">расхождение: ' + signed(diff) + '</td>';
	}

	// класс строки и подпись статуса определяет action из ответа API
	var ACTIONS = {
		create: { rowClass: 'invoice-new', status: 'Новый <small>(будет создан)</small>' },
		recalculate: { rowClass: '', status: 'Черновик' },
		keep: { rowClass: 'invoice-sent', status: 'Отправлен <small>(не будет изменён)</small>' }
	};

	// строка итогов по группе действий (под таблицей)
	function groupRow(group, label, rowClass) {
		return '<tr class="total-sub ' + rowClass + '">' +
			'<td colspan="2">' + label + ' <small>(' + group.count + ')</small></td>' +
			'<td class="r">' + money(group.before) + '</td>' +
			'<td class="r">' + money(group.after) + '</td>' +
			'<td class="r">' + signed(group.diff) + '</td>' +
			'<td></td>' +
			'</tr>';
	}

	function render(data) {
		if (!data.clients.length) {
			$result.html('<p class="empty">Нет данных за выбранный месяц</p>');
			return;
		}

		var t = data.totals;

		// --- таблица 1: предварительный пересчёт счетов ---
		var html = '<h2>Предварительный пересчёт счетов</h2>' +
			'<table class="grid">' +
			'<thead><tr>' +
			'<th>Счёт</th>' +
			'<th>Клиент</th>' +
			'<th class="r">Сумма сейчас, ₽</th>' +
			'<th class="r">Сумма по новому правилу, ₽</th>' +
			'<th class="r">Разница, ₽</th>' +
			'<th>Статус</th>' +
			'</tr></thead><tbody>';

		$.each(data.clients, function (i, c) {
			var a = ACTIONS[c.action];
			var invoice = c.invoice_id === null
				? '—'
				: '<a href="invoice.php?id=' + c.invoice_id + '">№ ' + c.invoice_id + '</a>';

			html += '<tr class="' + a.rowClass + '">' +
				'<td>' + invoice + '</td>' +
				'<td>' + esc(c.name) + '</td>' +
				'<td class="r">' + money(c.before) + '</td>' +
				'<td class="r">' + money(c.after) + '</td>' +
				'<td class="r">' + signed(c.diff) + '</td>' +
				'<td>' + a.status + '</td>' +
				'</tr>';
		});

		html += '</tbody><tfoot>' +
			// общий итог по всем счетам
			'<tr class="total">' +
			'<td colspan="2">Итого по всем счетам</td>' +
			'<td class="r">' + money(data.total_before) + '</td>' +
			'<td class="r">' + money(data.total_after) + '</td>' +
			'<td class="r">' + signed(t.diff) + '</td>' +
			'<td></td>' +
			'</tr>' +
			// разбивка: что создадим, что пересчитаем, что не тронем
			groupRow(t.by_action.create, 'Будет создано (новые)', 'invoice-new') +
			groupRow(t.by_action.recalculate, 'Будет пересчитано (черновики)', '') +
			groupRow(t.by_action.keep, 'Не будет пересчитано (отправленные)', 'invoice-sent') +
			'</tfoot></table>';

		// --- таблица 2: итоги за месяц со сверкой со стоимостью рейсов ---
		html += '<h2>Итоги за месяц</h2>' +
			'<table class="grid">' +
			'<thead><tr>' +
			'<th>Показатель</th>' +
			'<th class="r">Сумма, ₽</th>' +
			'<th>Сверка с суммой всех рейсов</th>' +
			'</tr></thead><tbody>' +
			'<tr><td>Сумма сейчас (по счетам)</td>' +
			'<td class="r">' + money(data.total_before) + '</td>' + checkCell(t.vs_trips.before) + '</tr>' +
			'<tr><td>Сумма по новому правилу (все счета)</td>' +
			'<td class="r">' + money(data.total_after) + '</td>' + checkCell(t.vs_trips.after) + '</tr>' +
			'<tr><td>Будет записано при применении <small>(черновики и новые по новому правилу + отправленные как есть)</small></td>' +
			'<td class="r">' + money(t.will_write) + '</td>' + checkCell(t.vs_trips.will_write) + '</tr>' +
			'<tr class="total"><td>Сумма всех рейсов за месяц</td>' +
			'<td class="r">' + money(t.trips_cost) + '</td><td></td></tr>' +
			'</tbody></table>';

		$result.html(html);
	}

	function loadPreview(period) {
		// var period = $('#filter select[name="month"]').val();

		// пока грузим: кнопку «Применить» блокируем, показываем «Загрузка...»
		$apply.prop('disabled', true);
		$result.html('<p class="empty">Загрузка...</p>');

		$.getJSON('api/billing_preview.php', { period: period })
			.done(function (data) {
				shown = data;
				render(data);
				// есть что создавать или пересчитывать: включаем «Применить»
				$apply.prop('disabled', !data.changes);
			})
			.fail(function (xhr) {
				shown = null;
				// сервер отвечает JSON-ом: {"errors": {"period": "..."}} или {"error": "..."}
				var json = xhr.responseJSON || {};
				var message = (json.errors && json.errors.period) || json.error || 'Не удалось загрузить предпросмотр счетов';
				$result.html('<p class="empty">' + esc(message) + '</p>');
			});
	}

	$('#show_billing').on('click', function (event) {
		event.preventDefault();
		$message.empty();
		loadPreview($('#filter select[name="month"]').val());
	});

	$apply.on('click', function (event) {
		event.preventDefault();
		if (!shown) return;

		var by = shown.totals.by_action;
		var text = 'Применить пересчёт за ' + shown.period + '?\n' +
			'Будет создано: ' + by.create.count + '\n' +
			'Будет пересчитано: ' + by.recalculate.count + '\n' +
			'Отправленные счета не изменятся: ' + by.keep.count;

		if (!confirm(text)) return;

		var period = shown.period;
		$apply.prop('disabled', true);   // защита от двойного клика

		$.post('api/billing_apply.php', { period: period }, null, 'json')
			.done(function (res) {
				$message.text('Применено за ' + res.period + '. Создано: ' + res.created +
					', пересчитано: ' + res.updated +
					', без изменений: ' + res.unchanged +
					', пропущено отправленных: ' + res.skipped + '.');
				loadPreview(period);   // показать актуальное состояние после записи
			})
			.fail(function (xhr) {
				var json = xhr.responseJSON || {};
				var message = (json.errors && json.errors.period) || json.error || 'Не удалось применить пересчёт';
				$message.text(message);
				$apply.prop('disabled', false);
			});
	});

	// «Применить» неактивна, пока не показан предпросмотр
	$apply.prop('disabled', true);
});
