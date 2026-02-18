<style type='text/css'>
	@media (max-width: 425px) {
		.kingdom-summary td:nth-child(n+3), .kingdom-summary th:nth-child(n+3) {
			display: none;
		}
		#events td:nth-child(2n), #events th:nth-child(2n) {
			display: none;	
		}
		#tournaments td {
			white-space: normal;
			overflow: hidden;
			text-overflow: ellipsis;
		}
		#tournaments td:nth-child(n+2), #tournaments th:nth-child(n+2) {
			display: none;	
		}
		#tournaments td:last-child, #tournaments th:last-child {
			display: table-cell;
			white-space: nowrap;
		}
	}
</style>
<div class='info-container kingdom-summary'>
	<h3>Kingdoms</h3>
  <a href='https://play.amtgard.com' style='padding: 16px 0 12px 8px; display: block;'><b>Find a Chapter Near You!</b></a>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Kingdom</th>
				<th>Parks</th>
				<th>Part.</th>
				<th>Weekly</th>
				<th>Monthly</th>
				<th>Total</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($ActiveKingdomSummary['ActiveKingdomsSummaryList'])): ?>
    <?php $parks = 0; $part = 0; $total = 0; $month_total = 0; ?>
	<?php foreach ($ActiveKingdomSummary['ActiveKingdomsSummaryList'] as $k => $report) : ?>
		<?php if ($report['ParentKingdomId'] == 0) : ?>
            <?php $parks += $report['ParkCount']; $part += $report['Participation']; $ave += $report['Attendance']/26.0; $total += $report['Attendance'];  $month_total += $report['Monthly'];?>
			<tr onclick='javascript:window.location.href="<?=UIR;?>Kingdom/index/<?=$report['KingdomId']; ?>&kingdom_name=<?=$report['KingdomName']; ?>";'>
				<td>
				    <div class='tiny-heraldry'><img src='<?=HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf('%04d',$report['KingdomId'])) ?>'></div>
				    <?=stripslashes($report['KingdomName'] ?? ''); ?>
				</td>
				<td class='data-column'><?=$report['ParkCount']; ?></td>
				<td class='data-column'><?=$report['Participation']; ?>/<?=$report['ParkCount']; ?></td>
				<td class='data-column' style='text-align: right;'><?=sprintf("%0.01f",($report['Attendance']/26.0)); ?></td>
				<td class='data-column' style='text-align: right;'><?=sprintf("%0.01f",($report['Monthly']/12.0)); ?></td>
				<td class='data-column' style='text-align: right;'><?=$report['Attendance']; ?></td>
			</tr>
		<?php endif; ?>
	<?php endforeach; ?>
            <tr>
                <td></td>
                <td class='data-column'><?=$parks; ?></td>
                <td class='data-column'><?=$part; ?>/<?=$parks ?></td>
                <td class='data-column' style='text-align: right;'><?=sprintf("%0.01f",$total/26.0); ?></td>
                <td class='data-column' style='text-align: right;'><?=sprintf("%0.01f",$month_total/12.0); ?></td>
                <td class='data-column' style='text-align: right;'><?=$total; ?></td>
            </tr>
<?php endif; ?>
		</tbody>
	</table>
</div>
<div class='info-container kingdom-summary'>
	<h3>Principalities</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Principality</th>
				<th>Parks</th>
				<th>Part.</th>
				<th>Weekly</th>
				<th>Monthly</th>
				<th>Total</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($ActiveKingdomSummary['ActiveKingdomsSummaryList'])): ?>
    <?php $parks = 0; $part = 0; $total = 0; $month_total = 0; ?>
	<?php foreach ($ActiveKingdomSummary['ActiveKingdomsSummaryList'] as $k => $report) : ?>
		<?php if ($report['ParentKingdomId'] > 0) : ?>
            <?php $parks += $report['ParkCount']; $part += $report['Participation']; $ave += $report['Attendance']/26.0; $total += $report['Attendance'];  $month_total += $report['Monthly'];?>
			<tr onclick='javascript:window.location.href="<?=UIR;?>Kingdom/index/<?=$report['KingdomId']; ?>";'>
				<td>
				    <div class='tiny-heraldry'><img src='<?=HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf('%04d',$report['KingdomId'])) ?>'></div>
				    <?=stripslashes($report['KingdomName']); ?>
				</td>
				<td class='data-column'><?=$report['ParkCount']; ?></td>
				<td class='data-column'><?=$report['Participation']; ?>/<?=$report['ParkCount']; ?></td>
				<td class='data-column' style='text-align: right;'><?=sprintf("%0.01f",($report['Attendance']/26.0)); ?></td>
				<td class='data-column' style='text-align: right;'><?=sprintf("%0.01f",($report['Monthly']/12.0)); ?></td>
				<td class='data-column'><?=$report['Attendance']; ?></td>
			</tr>
		<?php endif; ?>
    <?php endforeach; ?>
            <tr>
                <td></td>
                <td class='data-column'><?=$parks; ?></td>
                <td class='data-column'><?=$part; ?>/<?=$parks ?></td>
                <td class='data-column' style='text-align: right;'><?=sprintf("%0.01f",$total/26.0); ?></td>
                <td class='data-column' style='text-align: right;'><?=sprintf("%0.01f",$month_total/12.0); ?></td>
                <td class='data-column' style='text-align: right;'><?=$total; ?></td>
            </tr>
<?php endif; ?>
		</tbody>
	</table>
</div>
<div class='info-container'>
    <h3>Find a Chapter!</h3>
    <ul>
        <li><a href='https://play.amtgard.com'><b>Find a Chapter - Play Amtgard Today!</b></a></li>
        <li>
            <a href='<?=UIR ?>Atlas'>
                <img style='display: block; padding: 4px; margin: 6px 0; border-radius: 4px; border: 1px solid #ccc;' src='<?=HTTP_UI . '/template/default/img/map.jpg' ?>' />
                Amtgard Atlas Map 
            </a>
        </li>
    </ul>
</div>
<div class='info-container' id='events'>
	<h3>Events</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Event</th>
				<th>Next Date</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($EventSummary as $k => $event): ?>
			<tr onclick='javascript:window.location.href="<?=UIR;?>Event/index/<?=$event['EventId'];?>"'>
				<td><?=$event['KingdomName'] ?></td>
				<td><?=$event['ParkName'] ?></td>
				<td>
					<div class='tiny-heraldry'>
						<?php if ($event['HasHeraldry']==1): ?>
							<img src="<?=HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf("%05d", $event['EventId'])) ?>" onerror="this.src='<?=HTTP_EVENT_HERALDRY ?>00000.jpg';">
						<?php else: ?>
							<img src="<?=HTTP_EVENT_HERALDRY ?>00000.jpg">
						<?php endif; ?>
					</div>
					<?=$event['Name'] ?>
				</td>
				<td><?=0 == $event['NextDate']?"":date("M. j, Y", strtotime($event['NextDate'])) ?></td>
			</tr>
<?php endforeach; ?>
		</tbody>
	</table>
</div>
<div class='info-container'>
	<h3>Find</h3>
	<ul>
		<li><a href='<?=UIR ?>Search/index'>Players</a></li>
		<li><a href='<?=UIR ?>Search/unit'>Companies &amp; Households</a></li>
		<li><a href='<?=UIR ?>Search/event'>Events</a></li>
	</ul>
</div>
