<?php

?>
<div class='info-container'>
	<h3>Kingdoms</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Kingdom</th>
				<th>Parks</th>
				<th>Part.</th>
				<th>Ave.</th>
				<th>Total</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($ActiveKingdomSummary['ActiveKingdomsSummaryList'])): ?>
    <?php $parks = 0; $part = 0; $total = 0; ?>
	<?php foreach ($ActiveKingdomSummary['ActiveKingdomsSummaryList'] as $k => $report) : ?>
		<?php if ($report['ParentKingdomId'] == 0) : ?>
            <?php $parks += $report['ParkCount']; $part += $report['Participation']; $ave += $report['Attendance']/26.0; $total += $report['Attendance']; ?>
			<tr onclick='javascript:window.location.href="<?=UIR;?>Kingdom/index/<?=$report['KingdomId']; ?>&kingdom_name=<?=$report['KingdomName']; ?>";'>
				<td>
				    <div class='tiny-heraldry'><img src='<?=HTTP_KINGDOM_HERALDRY . sprintf('%04d.jpg',$report['KingdomId']) ?>' onerror="this.src='<?=HTTP_PLAYER_HERALDRY ?>000000.jpg'" /></div>
				    <?=stripslashes($report['KingdomName']); ?>
				</td>
				<td class='data-column'><?=$report['ParkCount']; ?></td>
				<td class='data-column'><?=$report['Participation']; ?>/<?=$report['ParkCount']; ?></td>
				<td class='data-column' style='text-align: right;'><?=sprintf("%0.02f",($report['Attendance']/26.0)); ?></td>
				<td class='data-column' style='text-align: right;'><?=$report['Attendance']; ?></td>
			</tr>
		<?php endif; ?>
	<?php endforeach; ?>
            <tr>
                <td></td>
                <td class='data-column'><?=$parks; ?></td>
                <td class='data-column'><?=$part; ?>/<?=$parks ?></td>
                <td class='data-column' style='text-align: right;'><?=sprintf("%0.02f",$total/26.0); ?></td>
                <td class='data-column' style='text-align: right;'><?=$total; ?></td>
            </tr>
<?php endif; ?>
		</tbody>
	</table>
</div>
<div class='info-container'>
	<h3>Principalities</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Principality</th>
				<th>Parks</th>
				<th>Part.</th>
				<th>Ave.</th>
				<th>Total</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($ActiveKingdomSummary['ActiveKingdomsSummaryList'])): ?>
    <?php $parks = 0; $part = 0; $total = 0; ?>
	<?php foreach ($ActiveKingdomSummary['ActiveKingdomsSummaryList'] as $k => $report) : ?>
		<?php if ($report['ParentKingdomId'] > 0) : ?>
            <?php $parks += $report['ParkCount']; $part += $report['Participation']; $ave += $report['Attendance']/26.0; $total += $report['Attendance']; ?>
			<tr onclick='javascript:window.location.href="<?=UIR;?>Kingdom/index/<?=$report['KingdomId']; ?>";'>
				<td>
				    <div class='tiny-heraldry'><img src='<?=HTTP_KINGDOM_HERALDRY . sprintf('%04d.jpg',$report['KingdomId']) ?>' onerror="this.src='<?=HTTP_PLAYER_HERALDRY ?>000000.jpg'" /></div>
				    <?=stripslashes($report['KingdomName']); ?>
				</td>
				<td class='data-column'><?=$report['ParkCount']; ?></td>
				<td class='data-column'><?=$report['Participation']; ?>/<?=$report['ParkCount']; ?></td>
				<td class='data-column'><?=sprintf("%0.02f",($report['Attendance']/26.0)); ?></td>
				<td class='data-column'><?=$report['Attendance']; ?></td>
			</tr>
		<?php endif; ?>
    <?php endforeach; ?>
            <tr>
                <td></td>
                <td class='data-column'><?=$parks; ?></td>
                <td class='data-column'><?=$part; ?>/<?=$parks ?></td>
                <td class='data-column' style='text-align: right;'><?=sprintf("%0.02f",$total/26.0); ?></td>
                <td class='data-column' style='text-align: right;'><?=$total; ?></td>
            </tr>
<?php endif; ?>
		</tbody>
	</table>
</div>
<div class='info-container'>
    <h3>Reports</h3>
    <ul>
        <li>
            <a href='<?=UIR ?>Atlas'>
                <img style='display: block; padding: 4px; margin: 6px 0; border-radius: 4px; border: 1px solid #ccc;' src='/ork/orkui/template/default/img/map.jpg' />
                Amtgard Atlas Map 
            </a>
        </li>
    </ul>
</div>
<div class='info-container'>
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
						<img src="<?=HTTP_EVENT_HERALDRY . sprintf("%05d", $event['EventId']) ?>.jpg" onerror="this.src='//www.esdraelon.amtgard.com/ork/assets/heraldry/player/000000.jpg'">
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
	<h3>Tournaments</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Tournament</th>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Event</th>
				<th>Date</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ((array)$Tournaments['Tournaments'] as $k => $tournament) : ?>
			<tr onClick='javascript:window.location.href="<?=UIR ?>Tournament/create&<?=valid_id($tournament['EventCalendarDetailId'])?('EventCalendarDetailId='.$tournament['EventCalendarDetailId']):(valid_id($tournament['ParkId'])?'ParkId='.$tournament['ParkId']:('KingdomId='.$tournament['KingdomId'])) ?>"'>
				<td><?=$tournament['Name'] ?></td>
				<td><?=$tournament['KingdomName'] ?></a></td>
				<td><?=$tournament['ParkName'] ?></a></td>
				<td><?=$tournament['EventName'] ?></a></td>
				<td><?=date("M. j, Y", strtotime($tournament['DateTime'])) ?></td>
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
