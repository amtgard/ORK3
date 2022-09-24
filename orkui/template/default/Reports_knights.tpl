<?php
    $total = 0;
    $dues_paid = 0;
?>

<div class='info-container'>
<?php if (isset($activewaivereduespaid)) : ?>
    <h3>Knights</h3>
<?php else: ?>
	<h3>Active Players</h3>
<?php endif; ?>
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
    			<th>Weeks</th>
    			<th>Park Weeks</th>
    			<th>Attendances</th>
				<th>Credits</th>
<?php if (isset($activewaivereduespaid)) : ?>
				<th>Dues Paid</th>
<?php endif; ?>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($active_players)): ?>
<?php 	foreach ($active_players as $k => $player): ?>
<?php       $total++; ?>
			<tr>
<?php 		if (!isset($this->__session->kingdom_id)) : ?>
				<td><a href='<?=UIR.'Kingdom/index/'.$player['KingdomId'] ?>'><?=$player['KingdomName'] ?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->park_id)) : ?>
				<td><a href='<?=UIR.'Park/index/'.$player['ParkId'] ?>'><?=$player['ParkName'] ?></a></td>
<?php 		endif; ?>
				<td><a href='<?=UIR.'Player/index/'.$player['MundaneId'] ?>'><?=$player['Persona'] ?></a></td>
    			<td class='data-column'><?=$player['WeeksAttended'] ?></td>
    			<td class='data-column'><?=$player['ParkDaysAttended'] ?></td>
    			<td class='data-column'><?=$player['DaysAttended'] ?></td>
				<td class='data-column'><?=$player['TotalMonthlyCredits'] ?></td>
<?php if (isset($activewaivereduespaid)) : ?>
<?php       $dues_paid += $player['DuesPaid']; ?>
				<td><?=$player['DuesPaid']?"Dues Paid":"" ?></td>
<?php endif; ?>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
            <tr>
                <td colspan='<?=(4 + (!isset($this->__session->kingdom_id)?1:0) + (!isset($this->__session->park_id)?1:0)) ?></td>'></td>
                <td>Total: <?=$total ?></td>
<?php if (isset($activewaivereduespaid)) : ?>
                <td>Dues Paid: <?=$dues_paid ?></td>
<?php endif; ?>
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
			$("table.information-table").table2csv({"filename":"Knights Report", "excludeRows":".tablesorter-filter-row"});
		});
	});
</script>