<div class='info-container'>
	<h3><?=$page_title ?></h3>
	<div class="actions"><button class="print button">Print</button> <button class="download button">Download CSV</button></div>
	<table class='information-table'>
		<thead>
			<tr>
<?php if (!isset($this->__session->park_id)) : ?>
				<th>Kingdom</th>
<?php endif; ?>
				<th>Park</th>
				<th>Persona</th>
				<th>Custom Award</th>
				<th>Date</th>
				<th>Given By</th>
				<th>Note</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Awards)): ?>
<?php 	foreach ($Awards as $k => $award): ?>
			<tr>
<?php 		if (!isset($this->__session->park_id)) : ?>
				<td><a href='<?=UIR.'Kingdom/index/'.$award['KingdomId'] ?>'><?=$award['KingdomName'] ?></a></td>
<?php 		endif; ?>
				<td><a href='<?=UIR.'Park/index/'.$award['ParkId'] ?>'><?=$award['ParkName'] ?></a></td>
				<td><a href='<?=UIR.'Player/index/'.$award['MundaneId'] ?>'><?=$award['Persona'] ?></a></td>
				<td><?=$award['CustomAwardName'] ?></td>
				<td><?=$award['Date'] ?></td>
				<td><?php if (valid_id($award['GivenById'])) : ?><a href='<?=UIR.'Player/index/'.$award['GivenById'] ?>'><?=$award['GivenBy'] ?></a><?php endif; ?></td>
				<td><?=$award['Note'] ?></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
	<div class="pager">
		<button class="first">&laquo;</button>
		<button class="prev">&lsaquo;</button>
		<span class="pagedisplay"></span>
		<button class="next">&rsaquo;</button>
		<button class="last">&raquo;</button>
		<select class="pagesize">
			<option value="25">25</option>
			<option value="50">50</option>
			<option value="100">100</option>
			<option value="<?=is_array($Awards)?count($Awards):9999 ?>">All</option>
		</select>
	</div>
</div>

<script src="<?=HTTP_TEMPLATE;?>default/script/js/jquery-tablesorter/widgets/widget-pager.js"></script>
<script>
	$(function() {
		$.tablesorter.language.button_print = "Print";
		$.tablesorter.language.button_close = "Close";
		$(".information-table").tablesorter({
			theme: 'jui',
			widgets: ["zebra", "filter", "print", "pager"],
    widgetOptions : {
		zebra : [ "normal-row", "alt-row" ],
      columnSelector_container : $('#columnSelector'),
      columnSelector_name : 'data-name',

      pager_size : 25,
      pager_output : '{startRow} to {endRow} of {totalRows} rows',
      pager_removeRows : false,
      pager_savePages : false,

      print_title      : '',
      print_dataAttrib : 'data-name',
      print_rows       : 'f',
      print_columns    : 's',
      print_extraCSS   : '',
      print_now        : true,
      print_callback   : function(config, $table, printStyle) {
        $.tablesorter.printTable.printOutput( config, $table.html(), printStyle );
      }
    }
		});

		$('.print.button').click(function(e) {
			e.preventDefault();
			$('.tablesorter').trigger('printTable');
		});

		$('.download.button').click(function(e) {
			e.preventDefault();
			var $table = $("table.information-table");
			var $hiddenRows = $table.find('tbody tr:hidden');
			$hiddenRows.show();
			$table.table2csv({"filename":"Custom Awards", "excludeRows":".tablesorter-filter-row"});
			$hiddenRows.hide();
		});
	});
</script>
