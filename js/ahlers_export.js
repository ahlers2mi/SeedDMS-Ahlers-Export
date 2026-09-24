/*
 * Ahlers Export – Übernahme der markierten Suchtreffer ins Export-Formular.
 *
 * Die Checkboxen der Trefferliste (marks[D<id>]) liegen außerhalb des
 * Formulars. Beim Absenden werden die markierten Einträge als versteckte
 * Felder angehängt. Ist nichts markiert, exportiert der Server alle Treffer.
 */
$(document).ready(function () {
	function checkedMarks() {
		return $('input[name^="marks"]').filter(function () {
			return this.checked && this.name.indexOf('marks[D') === 0;
		});
	}

	function updateSelectionInfo() {
		var info = $('#ahlers-export-selection');
		if (!info.length)
			return;
		var n = checkedMarks().length;
		info.text(n > 0 ? String(info.data('text') || '').replace('[count]', n) : '');
	}

	$('body').on('change', 'input[name^="marks"]', updateSelectionInfo);
	/* Die Trefferliste schaltet Checkboxen auch per Klick auf die Zeile um */
	$('body').on('click', 'table', function () {
		window.setTimeout(updateSelectionInfo, 0);
	});
	updateSelectionInfo();

	$('body').on('submit', '#ahlers-export-form', function () {
		var form = $(this);
		form.find('input.ahlers-export-mark').remove();
		checkedMarks().each(function () {
			$('<input type="hidden" class="ahlers-export-mark" value="1" />')
				.attr('name', this.name)
				.appendTo(form);
		});
		return true;
	});
});
