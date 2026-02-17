<?php
	$show_avg_columns = isset($form) && in_array($form['Period'], array('Quarterly', 'Annually'));
	$avg_by_uniques = isset($form) && !empty($form['AvgByUniques']);
?>
<div class='info-container'>
	<h3>Park Attendance Explorer</h3>

<?php if (isset($no_kingdom)): ?>
	<p>Please navigate to a kingdom first to use this report.</p>
<?php else: ?>
	<form method="POST" action="<?=UIR?>Reports/park_attendance_explorer">
		<table class="search-table">
			<tr>
				<td><label for="StartDate">Start Date</label></td>
				<td><input type="text" id="StartDate" name="StartDate" class="datepicker" value="<?=htmlspecialchars($form['StartDate'] ?? '')?>" /></td>
				<td><label for="EndDate">End Date</label></td>
				<td><input type="text" id="EndDate" name="EndDate" class="datepicker" value="<?=htmlspecialchars($form['EndDate'] ?? '')?>" /></td>
			</tr>
			<tr>
				<td><label for="Period">Period</label></td>
				<td>
					<select id="Period" name="Period">
<?php foreach(array('Weekly','Monthly','Quarterly','Annually') as $p): ?>
						<option value="<?=$p?>"<?=($form['Period'] ?? 'Monthly') == $p ? ' selected' : ''?>><?=$p?></option>
<?php endforeach; ?>
					</select>
				</td>
				<td><label for="ParkId">Park</label></td>
				<td>
					<select id="ParkId" name="ParkId">
						<option value="0">All Parks</option>
<?php if (is_array($parks)): ?>
<?php 	foreach($parks as $park): ?>
<?php 		if ($park['Active'] != 'Active') continue; ?>
						<option value="<?=$park['ParkId']?>"<?=($form['ParkId'] ?? 0) == $park['ParkId'] ? ' selected' : ''?>><?=htmlspecialchars($park['Name'])?></option>
<?php 	endforeach; ?>
<?php endif; ?>
					</select>
				</td>
			</tr>
			<tr>
				<td><label for="MinimumSignIns">Minimum Sign-Ins</label></td>
				<td><input type="number" id="MinimumSignIns" name="MinimumSignIns" min="0" step="1" value="<?=htmlspecialchars($form['MinimumSignIns'] ?? '0')?>" /></td>
				<td><label for="AvgByUniques">Average by Uniques?</label></td>
				<td><input type="checkbox" id="AvgByUniques" name="AvgByUniques" value="1"<?=!empty($form['AvgByUniques']) ? ' checked' : ''?> /></td>
			</tr>
			<tr id="local-players-row" style="<?=!empty($form['ParkId']) ? '' : 'display:none;'?>">
				<td><label for="LocalPlayersOnly">Local Players Only?</label></td>
				<td><input type="checkbox" id="LocalPlayersOnly" name="LocalPlayersOnly" value="1"<?=!empty($form['LocalPlayersOnly']) ? ' checked' : ''?> /></td>
				<td colspan="2"></td>
			</tr>
			<tr>
				<td colspan="4">
					<button type="submit" name="RunReport" value="1" class="button">Run Report</button>
				</td>
			</tr>
		</table>
	</form>
<?php endif; ?>
</div>

<?php if (isset($mode) && $mode == 'all_parks' && is_array($attendance) && count($attendance) > 0): ?>
<div class='info-container'>
	<h3>Kingdom Attendance by Park</h3>
	<div class="actions"><button class="print button">Print</button> <button class="download button">Download CSV</button></div>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Park</th>
				<th>Period</th>
				<th>Total Sign-Ins</th>
				<th>Unique Players</th>
				<th>Unique Members</th>
<?php if ($show_avg_columns): ?>
				<th>Avg Weekly <?=$avg_by_uniques ? 'Uniques' : 'Sign-Ins'?></th>
				<th>Avg Monthly <?=$avg_by_uniques ? 'Uniques' : 'Sign-Ins'?></th>
<?php endif; ?>
				<th>Members 2+</th>
				<th>Members 3+</th>
				<th>Members 4+</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($attendance as $row): ?>
			<tr>
				<td><a href='<?=UIR.'Park/index/'.$row['ParkId']?>'><?=htmlspecialchars($row['ParkName'])?></a></td>
				<td><?=$row['PeriodLabel']?></td>
				<td class='data-column'><?=$row['TotalSignins']?></td>
				<td class='data-column'><?=$row['UniquePlayers']?></td>
				<td class='data-column'><?=$row['UniqueMembers']?></td>
<?php if ($show_avg_columns): ?>
<?php $avg_numerator = $avg_by_uniques ? $row['UniquePlayers'] : $row['TotalSignins']; ?>
				<td class='data-column'><?=$row['WeeksInPeriod'] > 0 ? number_format($avg_numerator / $row['WeeksInPeriod'], 1) : '0'?></td>
				<td class='data-column'><?=$row['MonthsInPeriod'] > 0 ? number_format($avg_numerator / $row['MonthsInPeriod'], 1) : '0'?></td>
<?php endif; ?>
				<td class='data-column'><?=$row['Members2Plus']?></td>
				<td class='data-column'><?=$row['Members3Plus']?></td>
				<td class='data-column'><?=$row['Members4Plus']?></td>
			</tr>
<?php endforeach; ?>
<?php if (isset($summary)): ?>
			<tr class='summary-row' style='font-weight:bold; background-color:#eee;'>
				<td>Kingdom Totals</td>
				<td></td>
				<td class='data-column'><?=$summary['TotalSignins']?></td>
				<td class='data-column'><?=$summary['UniquePlayers']?></td>
				<td class='data-column'><?=$summary['UniqueMembers']?></td>
<?php if ($show_avg_columns): ?>
<?php $avg_summary_numerator = $avg_by_uniques ? $summary['UniquePlayers'] : $summary['TotalSignins']; ?>
				<td class='data-column'><?=$summary['WeeksInPeriod'] > 0 ? number_format($avg_summary_numerator / $summary['WeeksInPeriod'], 1) : '0'?></td>
				<td class='data-column'><?=$summary['MonthsInPeriod'] > 0 ? number_format($avg_summary_numerator / $summary['MonthsInPeriod'], 1) : '0'?></td>
<?php endif; ?>
				<td class='data-column'><?=$summary['Members2Plus']?></td>
				<td class='data-column'><?=$summary['Members3Plus']?></td>
				<td class='data-column'><?=$summary['Members4Plus']?></td>
			</tr>
<?php endif; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>

<?php if (isset($mode) && $mode == 'single_park' && isset($players) && count($players) > 0): ?>
<div class='info-container'>
	<h3>Player Attendance Detail</h3>
	<div class="actions">
		<label for="pageSize">Rows per page:</label>
		<select id="pageSize">
			<option value="10" selected>10</option>
			<option value="25">25</option>
			<option value="50">50</option>
			<option value="100">100</option>
			<option value="0">All</option>
		</select>
		<span id="paginationInfo" style="margin: 0 10px;"></span>
		<button id="prevPage" class="button" disabled>&laquo; Prev</button>
		<button id="nextPage" class="button">Next &raquo;</button>
		<button class="print button">Print</button>
		<button class="download button">Download CSV</button>
	</div>
	<table class='information-table' id='player-detail-table'>
		<thead>
			<tr>
				<th>Player Name</th>
				<th>Waivered</th>
				<th>Dues Paid</th>
<?php foreach ($all_periods as $period): ?>
				<th><?=$period?></th>
<?php endforeach; ?>
				<th>Total</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($players as $player): ?>
			<tr>
				<td><a href='<?=UIR.'Player/index/'.$player['MundaneId']?>'><?=htmlspecialchars($player['Persona'])?></a></td>
				<td class='data-column'><?=$player['Waivered'] ? 'Yes' : 'No'?></td>
				<td class='data-column'><?=$player['DuesPaid'] ? htmlspecialchars($player['DuesPaid']) : ''?></td>
<?php 	foreach ($all_periods as $period): ?>
				<td class='data-column'><?=$player['Periods'][$period] ?? 0?></td>
<?php 	endforeach; ?>
				<td class='data-column' style='font-weight:bold;'><?=$player['Total']?></td>
			</tr>
<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>

<?php if (isset($mode) && (($mode == 'all_parks' && (!is_array($attendance) || count($attendance) == 0)) || ($mode == 'single_park' && (!isset($players) || count($players) == 0)))): ?>
<div class='info-container'>
	<p>No attendance data found for the selected criteria.</p>
</div>
<?php endif; ?>

<div class='info-container'>
	<h3>About This Report</h3>
	<div style="padding: 5px 0;">
		<p>The Park Attendance Explorer provides two views of attendance data for your kingdom's active parks.</p>

		<h4>Controls</h4>
		<ul>
			<li><strong>Start Date / End Date</strong> &mdash; The date range to query. Dates are automatically rounded to encompass full periods.
				For example, if you select Monthly and enter a start date of January 15th, the report will include all of January.
				Similarly, an end date of December 1st will include all of December.</li>
			<li><strong>Period</strong> &mdash; How to group the data: Weekly (ISO weeks, Monday&ndash;Sunday), Monthly, Quarterly (Q1=Jan&ndash;Mar, Q2=Apr&ndash;Jun, Q3=Jul&ndash;Sep, Q4=Oct&ndash;Dec), or Annually.</li>
			<li><strong>Park</strong> &mdash; Select &ldquo;All Parks&rdquo; for the kingdom summary view, or choose a specific park for the player detail view.</li>
			<li><strong>Minimum Sign-Ins</strong> &mdash; In the individual park view, filters out players whose total sign-in count across the entire date range is below this number. Has no effect on the All Parks view.</li>
			<li><strong>Local Players Only?</strong> (individual park view only) &mdash; When checked, restricts the player list to only those whose home park matches the selected park.
				Visitors from other parks are excluded. Useful for reviewing your park's membership attendance specifically.</li>
			<li><strong>Average by Uniques?</strong> &mdash; When checked, the Avg Weekly and Avg Monthly columns use Unique Players as the numerator instead of Total Sign-Ins.
				For example, if a park had 30 total sign-ins in a quarter but only 12 unique players, unchecked shows 30 &divide; weeks, checked shows 12 &divide; weeks.
				This is useful for understanding how many distinct people attend on average rather than total foot traffic.</li>
		</ul>

		<h4>All Parks View</h4>
		<p>Shows one row per park per period with the following columns:</p>
		<ul>
			<li><strong>Total Sign-Ins</strong> &mdash; The total number of attendance records at that park in that period.
				A single player attending 4 times in a month counts as 4 sign-ins. This counts all players, including visitors from other parks.</li>
			<li><strong>Unique Players</strong> &mdash; The number of distinct players who signed in at that park at least once in that period.
				A player who attended 4 times still counts as 1 unique player. This includes visitors from other parks.</li>
			<li><strong>Unique Members</strong> &mdash; Same as Unique Players, but only counts players whose home park matches the park they signed in at.
				Visitors from other parks are excluded. This is useful for determining park status.</li>
			<li><strong>Avg Weekly Sign-Ins / Uniques</strong> (Quarterly/Annually only) &mdash; Total Sign-Ins (or Unique Players if &ldquo;Average by Uniques?&rdquo; is checked)
				divided by the number of distinct calendar weeks that had any attendance in that period. Only counts weeks where at least one sign-in occurred.</li>
			<li><strong>Avg Monthly Sign-Ins / Uniques</strong> (Quarterly/Annually only) &mdash; Total Sign-Ins (or Unique Players if &ldquo;Average by Uniques?&rdquo; is checked)
				divided by the number of distinct calendar months that had any attendance in that period. Only counts months where at least one sign-in occurred.</li>
			<li><strong>Members 2+ / 3+ / 4+</strong> &mdash; The number of park members (home park = sign-in park) who have 2 or more (or 3+, 4+) sign-ins
				within that specific period. These values are calculated per-period, so a member who signed in 3 times in Q1 but only once in Q2
				would count toward 2+ and 3+ in Q1 but not in Q2. These numbers are useful for determining insurance needs and park activity levels.</li>
		</ul>
		<p>A <strong>Kingdom Totals</strong> row appears at the bottom, summing the values across all parks. Note that Unique Players and Unique Members
			in the totals row are sums of per-park counts, so a player who visited multiple parks may be counted more than once in the kingdom total.</p>

		<h4>Individual Park View</h4>
		<p>Shows one row per player with the following columns:</p>
		<ul>
			<li><strong>Player Name</strong> &mdash; The player's persona, linked to their player page. This includes all players who signed in at the park,
				whether they are members of that park or visitors.</li>
			<li><strong>Waivered</strong> &mdash; Whether the player currently has a waiver on file (Yes/No).</li>
			<li><strong>Dues Paid</strong> &mdash; If the player has active, non-revoked dues in this kingdom, shows the expiration date (e.g. 2025-12-31)
				or &ldquo;Life&rdquo; for lifetime dues. Blank if dues are expired or not on file.</li>
			<li><strong>Period columns</strong> &mdash; One column per period (e.g. 2025-01, 2025-02, etc.). Each cell shows the number of times
				that player signed in at this park during that period. A zero means the player had no sign-ins that period.</li>
			<li><strong>Total</strong> &mdash; The sum of all period columns for that player across the entire date range.</li>
		</ul>
		<p>When <strong>Minimum Sign-Ins</strong> is set above zero, only players whose Total meets or exceeds that threshold are shown.
			The table supports pagination (default 10 rows), sorting by any column, and filtering. CSV export always includes all rows regardless of the current page.</p>
	</div>
</div>

<script>
$(function() {
	$('#StartDate, #EndDate').datepicker({dateFormat: 'yy-mm-dd'});

	// Show/hide Local Players Only based on park selection
	function toggleLocalPlayersRow() {
		var parkId = parseInt($('#ParkId').val(), 10);
		if (parkId > 0) {
			$('#local-players-row').show();
		} else {
			$('#local-players-row').hide();
			$('#LocalPlayersOnly').prop('checked', false);
		}
	}
	$('#ParkId').on('change', toggleLocalPlayersRow);

	if ($('.information-table').length) {
		$.tablesorter.language.button_print = "Print";
		$.tablesorter.language.button_close = "Close";
		$(".information-table").tablesorter({
			theme: 'jui',
			widgets: ["zebra", "filter", "print"],
			widgetOptions: {
				zebra: ["normal-row", "alt-row"],
				print_title: '',
				print_dataAttrib: 'data-name',
				print_rows: 'f',
				print_columns: 's',
				print_extraCSS: '',
				print_now: true,
				print_callback: function(config, $table, printStyle) {
					$.tablesorter.printTable.printOutput(config, $table.html(), printStyle);
				}
			}
		});

		$('.print.button').click(function(e) {
			e.preventDefault();
			$('.tablesorter').trigger('printTable');
		});

		$('.download.button').click(function(e) {
			e.preventDefault();
			// Show all rows for CSV export, then restore pagination
			var $table = $('#player-detail-table');
			if ($table.length) {
				var $rows = $table.find('tbody tr');
				var hidden = $rows.filter(':hidden');
				hidden.show();
				$table.table2csv({"filename":"Park Attendance Explorer", "excludeRows":".tablesorter-filter-row"});
				hidden.hide();
			} else {
				$("table.information-table").table2csv({"filename":"Park Attendance Explorer", "excludeRows":".tablesorter-filter-row"});
			}
		});
	}

	// Pagination for the player detail table
	var $detailTable = $('#player-detail-table');
	if ($detailTable.length) {
		var currentPage = 0;
		var pageSize = 10;

		function paginate() {
			var $rows = $detailTable.find('tbody tr');
			var totalRows = $rows.length;

			if (pageSize === 0) {
				// Show all
				$rows.show();
				$('#paginationInfo').text('Showing all ' + totalRows + ' rows');
				$('#prevPage').prop('disabled', true);
				$('#nextPage').prop('disabled', true);
				return;
			}

			var totalPages = Math.ceil(totalRows / pageSize);
			if (currentPage >= totalPages) currentPage = totalPages - 1;
			if (currentPage < 0) currentPage = 0;

			$rows.hide();
			$rows.slice(currentPage * pageSize, (currentPage + 1) * pageSize).show();

			var first = currentPage * pageSize + 1;
			var last = Math.min((currentPage + 1) * pageSize, totalRows);
			$('#paginationInfo').text('Showing ' + first + '-' + last + ' of ' + totalRows);
			$('#prevPage').prop('disabled', currentPage === 0);
			$('#nextPage').prop('disabled', currentPage >= totalPages - 1);
		}

		$('#pageSize').on('change', function() {
			pageSize = parseInt($(this).val(), 10);
			currentPage = 0;
			paginate();
		});

		$('#prevPage').on('click', function(e) {
			e.preventDefault();
			if (currentPage > 0) { currentPage--; paginate(); }
		});

		$('#nextPage').on('click', function(e) {
			e.preventDefault();
			currentPage++;
			paginate();
		});

		// Re-paginate after tablesorter sorts or filters (may reorder/show rows)
		$detailTable.on('sortEnd filterEnd', function() {
			currentPage = 0;
			paginate();
		});

		// Defer initial pagination until after tablesorter finishes async init
		$detailTable.one('tablesorter-initialized', function() {
			paginate();
		});
		setTimeout(paginate, 0);
	}
});
</script>
