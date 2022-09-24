<?php
    $total = 0;
?>
<div class='info-container'>
	<h3>Active Players</h3>
	<div class="actions"><button class="print button">Print</button> <button class="download button">Download CSV</button></div>
	<table class='information-table'>
		<thead>
			<tr>
<?php if (!isset($this->__session->kingdom_id)) : ?>
				<th>Kingdom</th>
<?php endif; ?>
<?php if (!isset($this->__session->park_id)) : ?>
				<th>Park</th>
<?php endif; ?>
				
				<th>Persona</th>
				<th>Attendance</th>
				<th>Credits</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($active_players as $k => $player): ?>
<?php       $total++; ?>
			<tr>
<?php if (!isset($this->__session->kingdom_id)) : ?>
				<td><?=$player['KingdomName'] ?></td>
<?php endif; ?>
<?php if (!isset($this->__session->park_id)) : ?>
				<td><?=$player['ParkName'] ?></td>
<?php endif; ?>
				<td><?=$player['Persona'] ?></td>
				<td class='data-column'><?=$player['WeeksAttended'] ?></td>
				<td class='data-column'><?=$player['TotalCredits'] ?></td>
			</tr>
<?php endforeach; ?>
            <tr>
                <td colspan='<?=(3 + (!isset($this->__session->kingdom_id)?1:0) + (!isset($this->__session->park_id)?1:0)) ?></td>'></td>
                <td>Total: <?=$total ?></td>
            </tr>
		</tbody>
	</table>
</div>

<script>
	$(function() {
		$.tablesorter.language.button_print = "Print";
		$.tablesorter.language.button_close = "Close";
		$(".information-table").tablesorter({
			theme: 'jui',
			widgets: ["zebra", "filter", "print"],
    widgetOptions : {
		zebra : [ "normal-row", "alt-row" ],
      columnSelector_container : $('#columnSelector'),
      columnSelector_name : 'data-name',

      print_title      : '',          // this option > caption > table id > "table"
      print_dataAttrib : 'data-name', // header attrib containing modified header name
      print_rows       : 'f',         // (a)ll, (v)isible, (f)iltered, or custom css selector
      print_columns    : 's',         // (a)ll, (v)isible or (s)elected (columnSelector widget)
      print_extraCSS   : '',          // add any extra css definitions for the popup window here
      //print_styleSheet : '../css/theme.blue.css', // add the url of your print stylesheet
      print_now        : true,        // Open the print dialog immediately if true
      // callback executed when processing completes - default setting is null
      print_callback   : function(config, $table, printStyle) {
        // do something to the $table (jQuery object of table wrapped in a div)
        // or add to the printStyle string, then...
        // print the table using the following code
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
			$("table.information-table").table2csv({"filename":"Active Players Report", "excludeRows":".tablesorter-filter-row"});
		});
	});
</script>