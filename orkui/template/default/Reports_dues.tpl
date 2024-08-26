<style>
	#dues-filter-box label {
		padding: 3px 3px;
		border: 1px dashed #666;
		margin: 0 10px 5px 0;
	}
</style>

<div class='info-container'>
	<h3>Dues Paid List</h3>
	<div class="actions"><button class="print button">Print</button> <button class="download button">Download CSV</button></div>
	<div id="dues-filter-box" style="border: 1px solid #ccc; margin-bottom:15px; padding-bottom: 15px;">
		<h4 style="width: 90%; margin: 5px 4px; margin: 5px auto 10px;">Filters</h4>
		<div style="width: 90%; margin: 0 auto;">
			<label>Unwaivered <input type="checkbox" value="unwaivered" name="filter" checked/></label>
			<label>Dues For Life <input type="checkbox" value="duesforlife" name="filter" checked/></label>
			<label>Suspended <input type="checkbox" value="suspended" name="filter" checked/></label>
		</div>
	</div>
	<strong>Total:</strong> <?= count($roster['DuesPaidList']); ?> <strong>Filtered Total:</strong> <span id="dues-filtered-total"><?= count($roster['DuesPaidList']); ?></span> (Hidden: <span id="dues-hidden-total">0</span>)
	<table id="dues-paid-list-table" class='information-table'>
		<thead>
			<tr>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Persona</th>
				<?php if (!$roster['RestrictAccess']): ?>
					<th>Mundane</th>
				<?php endif; ?>
				<th>Waivered</th>
				<th>Suspended</th>
				<th>Date Paid</th>
				<th>Expires</th>
				<th>Dues For Life</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($roster['DuesPaidList'])) : ?>
<?php 	foreach ($roster['DuesPaidList'] as $k => $player): ?>
			<tr class="<?= (!empty($player['DuesForLife']))?' duesforlife':'' ?><?= (!empty($player['Suspended']))?' suspended penalty-box':'' ?><?= (empty($player['Waivered']))?' unwaivered':'' ?>">
				<td><a href='<?=UIR.'Kingdom/index/'.$player['KingdomId'] ?>'><?=$player['KingdomName'] ?></a></td>
				<td><a href='<?=UIR.'Park/index/'.$player['ParkId'] ?>'><?=$player['ParkName'] ?></a></td>
				<td><a href='<?=UIR.'Player/index/'.$player['MundaneId'] ?>'><?= $player['Persona'] ?></a></td>
				<?php if (!$roster['RestrictAccess']): ?>
					<td><?= $player['GivenName'] . ' ' . $player['Surname'] ?></td>
				<?php endif; ?>
				<td><?= ($player['Waivered'])?'Yes':'' ?></td>
				<td><?= ($player['Suspended'])?'Yes':'' ?></td>
				<td><?=($player['DuesFrom']?$player['DuesFrom']:'') ?></td>
				<td style="border: 2px dashed green; background-color: #ccf0cd;"><?=($player['DuesUntil'] && $player['DuesForLife'] == 0?$player['DuesUntil']:'') ?></td>
				<td style="<?= ($player['DuesForLife'] == 1) ? 'border: 2px dashed green; background-color: #ccf0cd;' : '' ?>"><?=($player['DuesForLife'] == 1?'Yes':'') ?></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>

<script type='text/javascript'>
	$(document).ready(function() {
		$('#dues-filter-box input[name=filter]').on('click', function (e) {
			var filterclass = $(e.target).val();
			var ttl = parseInt(<?= count($roster['DuesPaidList']); ?>);
			var visible;
			if (this.checked) {
				$('#dues-paid-list-table .' + filterclass).show();

			} else {
				$('#dues-paid-list-table .' + filterclass).hide();
			}
			visible = parseInt($('#dues-paid-list-table tr:visible').length - 1);
			$('#dues-filtered-total').html(visible);
			$('#dues-hidden-total').html(ttl - visible);
		})

	});
</script>


<script>
	$(function() {
		$.tablesorter.language.button_print = "Print";
		$.tablesorter.language.button_close = "Close";
		$(".information-table").on('filterEnd', function () {
			var visible;
			var ttl = parseInt(<?= count($roster['DuesPaidList']); ?>);
			visible = parseInt($('#dues-paid-list-table tr:visible').length - 2);
			$('#dues-filtered-total').html(visible);
			$('#dues-hidden-total').html(ttl - visible);
    }).tablesorter({
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
			$("table.information-table").table2csv({"filename":"Dues Paid List", "excludeRows":".tablesorter-filter-row"});
		});
	});
</script>
<?php
