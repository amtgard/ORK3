<div class='info-container'>
	<h3>Player Roster</h3>
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
				<th>Mundane</th>
				<th>Waivered</th>
<?php if (isset($show_duespaid)) : ?>
				<th>Dues Paid</th>
				<th>Dues Through</th>
<?php endif; ?>
				<th>Last Sign-in</th>
			<th>Suspended Until</th>
<?php if (isset($show_suspension)) : ?>
				<th>Suspended At</th>
				<th>Suspendator</th>
				<th>Suspension</th>
<?php endif; ?>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($roster)) : ?>
<?php 	foreach ($roster as $k => $player): ?>
			<tr <?=$player['Suspended']==1?"class='penalty-box'":"" ?>>
<?php 		if (!isset($this->__session->kingdom_id)) : ?>
				<td><a href='<?=UIR.'Kingdom/index/'.$player['KingdomId'] ?>'><?=$player['KingdomName'] ?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->park_id)) : ?>
				<td><a href='<?=UIR.'Park/index/'.$player['ParkId'] ?>'><?=$player['ParkName'] ?></a></td>
<?php 		endif; ?>
				<td><a href='<?=UIR.'Player/index/'.$player['MundaneId'] ?>'><?=trimlen($player['Persona'])>0?$player['Persona']:"<i>No Persona</i>" ?></a></td>
				<td><?=($player['Displayable']==0?"<span class='restricted-player-display'>Restricted</span>":$player['Surname'].', '.$player['GivenName']) ?></td>
				<td><?=($player['Waivered']==1?"Waiver":"") ?></td>
<?php if (isset($show_duespaid)) : ?>
				<td><?=($player['DuesPaid']?"Paid":"") ?></td>
				<td><?=($player['DuesThrough']?$player['DuesThrough']:"") ?></td>
<?php endif; ?>
				<td><?=$player['LastSignIn'] ?></td>
			<td><?=$player['SuspendedUntil'] ?></td>
<?php if (isset($show_suspension)) : ?>
				<td><?=$player['SuspendedAt'] ?></td>
				<td><?=$player['Suspendator'] ?></td>
				<td><?=$player['Suspension'] ?></td>
<?php endif; ?>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
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
			$("table.information-table").table2csv({"filename":"Player Roster", "excludeRows":".tablesorter-filter-row"});
		});
	});
</script>
<?php
