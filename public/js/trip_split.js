$(function () {
	var $tbody = $('#trip_split tbody');
	var tripId = $('#trip_split').data('trip-id');

	function load() {
		$.getJSON('api/trip_split.php', { trip_id: tripId }, function (data) {
			$tbody.empty();
			if (!data.clients.length) {
				$tbody.append('<tr><td colspan="7" class="empty">Нет данных по клиентам</td></tr>');
				return;
			}

			$.each(data.clients, function (i, client) {
				var basis = client.basis === 'volume'
					? 'Объём'
					: client.basis === 'weight' ? 'Вес' : '';
				var volume = client.volume_m3 === null ? '—' : num(client.volume_m3);

				$tbody.append(
					'<tr>' +
					'<td>' + esc(client.name) + '</td>' +
					'<td class="r">' + client.cargo_count + '</td>' +
					'<td class="r">' + num(client.weight_kg) + '</td>' +
					'<td class="r">' + volume + '</td>' +
					'<td>' + esc(basis) + '</td>' +
					'<td class="r">' + num(client.share * 100) + '</td>' +
					'<td class="r">' + money(client.amount) + '</td>' +
					'</tr>'
				);
			});

			$tbody.append(
				'<tr class="total">' +
				'<td colspan="6">Итого по клиентам</td>' +
				'<td class="r">' + money(data.summary) + '</td>' +
				'</tr>' +
				'<tr class="total">' +
				'<td colspan="6">Стоимость рейса</td>' +
				'<td class="r">' + money(data.cost) + '</td>' +
				'</tr>'
			);
		}).fail(function (xhr) {
			var message = xhr.responseJSON && xhr.responseJSON.error
				? xhr.responseJSON.error
				: 'Не удалось загрузить распределение стоимости';
			$tbody.empty();
			$tbody.append('<tr><td colspan="7" class="empty">' + esc(message) + '</td></tr>');
		});
	}

	load();
});
