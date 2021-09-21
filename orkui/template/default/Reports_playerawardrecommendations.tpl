<div class='info-container'>
	<h3>Award Recommendations</h3>
	<div class="actions"><button class="print button">Print</button> <button class="download button">Download CSV</button></div>
	<table class='information-table'>
		<thead>
			<tr>
<?php if (!isset($this->__session->kingdom_id)) : ?>
				<th>Kingdom</th>
<?php endif; ?>
				<th class="filter-select" data-placeholder="Select a Persona" data-priority="2" data-name="Persona">Persona</th>
				<th class="filter-select">Award</th>
				<th class="filter-select">Rank</th>
				<th class="filter-select" style="min-width:80px;">Date</th>
				<th class="filter-select">Sent By</th>
				<th>Reason</th>
				<?php if($this->__session->user_id): ?>
					<th class="sorter-false filter-false">Actions</th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($AwardRecommendations)): ?>
<?php 	foreach ($AwardRecommendations as $k => $recommendation): ?>
			<tr>
				<td><a href='<?=UIR.'Player/index/'.$recommendation['MundaneId'] ?>'><?=$recommendation['Persona'] ?></a></td>
				<td><?=$recommendation['AwardName'] ?></td>
				<td><?=valid_id($recommendation['Rank'])?$recommendation['Rank']:'' ?></td>
				<td><?=$recommendation['DateRecommended'] ?></td>
				<td><a href="<?=UIR.'Player/index/'.$recommendation['RecommendedById'] ?>"><?=$recommendation['RecommendedByName'] ?></a></td>
				<td><?=$recommendation['Reason'] ?></td>
				<?php if($this->__session->user_id): ?>
					<td>
						<?php if ($this->__session->user_id == $recommendation['RecommendedById'] || $this->__session->user_id == $recommendation['MundaneId']): ?>
							<a class="confirm-delete-recommendation" href="<?=UIR.'Player/index/' . $recommendation['MundaneId'] . '/deleterecommendation/'.$recommendation['RecommendationsId'] ?>">Delete</a> 
						<?php endif; ?>
					</td>
				<?php endif; ?>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>

<?php if ($this->__session->user_id): ?>
	<div id="dialogs" style="display: none">
		<div id="delete-recommendation" title="Confirmation Required">
			Are you sure you want to delete this recommendation?
		</div>
	</div>
<?php endif; ?>
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
			$("table.information-table").table2csv({"filename":"Award Recommendations", "excludeRows":".tablesorter-filter-row"});
		});


		<?php if ($this->__session->user_id): ?>
			$(".confirm-delete-recommendation").click(function(e) {
				e.preventDefault();
				var targetUrl = $(this).attr("href");

				$( "#delete-recommendation" ).dialog({ width: 460,
					buttons: { 
						"Cancel": function() { $(this).dialog("close"); }, 
						"Confirm": function() { window.location.href = targetUrl; $(this).dialog("close"); } 
					}
				});
			});
		<?php endif; ?>
	});
</script>
