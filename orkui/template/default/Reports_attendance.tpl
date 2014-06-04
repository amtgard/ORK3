<script type='text/javascript'>

$(document).ready(function() {
    spc();
});

<?php 
$attendances = array();
foreach ($attendance_periodical['Dates'] as $k => $date): 
    $attendances[(valid_id($date['EventId'])?(date("Y-m-d", strtotime($date['EventStart'])) . ' - ' . date("Y-m-d", strtotime($date['EventEnd']))):date("Y-m-d", strtotime($date['Date'])))] = $date['Attendees'];
endforeach; 
?>
var attendance = [ <?=implode(", ", array_reverse($attendances)) ?> ];
var dates = [ '<?=implode("', '", array_keys(array_reverse($attendances))) ?>' ];

average = function(a) {
    var r = {mean: 0, variance: 0, deviation: 0}, t = a.length;
    for(var m, s = 0, l = t; l--; s += a[l]);
    for(m = r.mean = s / t, l = t, s = 0; l--; s += Math.pow(a[l] - m, 2));
    return r.deviation = Math.sqrt(r.variance = s / t), r;
}

function spc() {
	var stats = average(attendance);
	$('#attendance-graph').highcharts({
            title: {
                text: 'Attendance',
                x: -20 //center
            },
            xAxis: {
                categories: dates
            },
            yAxis: {
                title: {
                    text: 'Attendees'
                },
                plotLines: [{
                    value: 0,
                    width: 1,
                    color: '#808080'
                },
                {
                    value: stats.mean - 2 * stats.deviation,
                    width: 2,
                    color: '#E02810',
                    label: {
                        text: (stats.mean - 2 * stats.deviation).toFixed(2)
                    }
                },
                {
                    value: stats.mean + 2 * stats.deviation,
                    width: 2,
                    color: '#E02810',
                    label: {
                        text: (stats.mean + 2 * stats.deviation).toFixed(2)
                    }
                },
                {
                    value: stats.mean - stats.deviation,
                    width: 2,
                    color: '#FCCA00',
                    label: {
                        text: (stats.mean - stats.deviation).toFixed(2)
                    }
                },
                {
                    value: stats.mean + stats.deviation,
                    width: 2,
                    color: '#FCCA00',
                    label: {
                        text: (stats.mean + stats.deviation).toFixed(2)
                    }
                },
                {
                    value: 0,
                    width: 1,
                    color: '#095EB3'
                },
                {
                    value: stats.mean,
                    width: 2,
                    color: '#333',
                    label: {
                        text: (stats.mean).toFixed(2)
                    }
                }]
            },
            series: [{
                name: 'Attendance',
                data: attendance
            }]
        });
}

</script>

<div class='info-container'>
	<h3>Attendance</h3>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Date</th>
<?php if ( $Type == 'All') : ?>
				<th>Kingdom</th>
				<th>Park</th>
<?php endif; ?>
<?php if ( $Type == 'All' ) : ?>
				<th>Event</th>
<?php endif; ?>
				<th>Count</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($attendance_summary['Dates'])) $attendance_summary['Dates'] = array() ?>
<?php if ('Park' == $Type) : ?>
	<?php foreach ($attendance_summary['Dates'] as $k => $date): ?>
				<tr onClick='javascript:window.location.href="<?=UIR ?>Attendance/park/<?=$date['ParkId'] ?>&AttendanceDate=<?=$date['Date'] ?>"'>
					<td><?=valid_id($date['EventId'])?(date("Y-m-d", strtotime($date['EventStart'])) . ' &mdash; ' . date("Y-m-d", strtotime($date['EventEnd']))):date("Y-m-d", strtotime($date['Date'])) ?></td>
					<td class='data-column'><?=$date['Attendees'] ?></td>
				</tr>
	<?php endforeach; ?>
<?php elseif ('Event' == $Type) : ?>
	<?php foreach ($attendance_summary['Dates'] as $k => $date): ?>
				<tr onClick='javascript:window.location.href="<?=UIR ?>Attendance/event/<?=$date['EventId'] ?>/<?=$date['EventCalendarDetailId'] ?>"'>
					<td><?=date("Y-m-d", strtotime($date['EventStart'])) ?></td>
					<td class='data-column'><?=$date['Attendees'] ?></td>
				</tr>
	<?php endforeach; ?>
<?php elseif ('Kingdom' == $Type) : ?>
	<?php foreach ($attendance_summary['Dates'] as $k => $date): ?>
				<tr onClick='javascript:window.location.href="<?=UIR ?>Attendance/kingdom/<?=$date['KingdomId'] ?>&AttendanceDate=<?=$date['Date'] ?>"'>
					<td><?=valid_id($date['EventId'])?(date("Y-m-d", strtotime($date['EventStart'])) . ' &mdash; ' . date("m-d", strtotime($date['EventEnd']))):date("Y-m-d", strtotime($date['Date'])) ?></td>
					<td class='data-column'><?=$date['Attendees'] ?></td>
				</tr>
	<?php endforeach; ?>
<?php else : ?>
	<?php foreach ($attendance_summary['Dates'] as $k => $date): ?>
				<tr>
					<td>
						<a href='<?=UIR ?>Attendance/<?=$date['ParkId']>0?'park':'event' ?>/<?=(($date['ParkId']>0)?($date['ParkId'].'&AttendanceDate='.date("Y-m-d", strtotime($date['Date']))):($date['EventId'].'/'.$date['EventCalendarDetailId'])) ?>'>
							<?=valid_id($date['EventId'])?(date("Y-m-d", strtotime($date['EventStart'])) . ' &mdash; ' . date("m-d", strtotime($date['EventEnd']))):date("Y-m-d", strtotime($date['Date'])) ?>
						</a>
					</td>
					<td><a href='<?=UIR ?>Kingdom/index/<?=$date['KingdomId'] ?>'><?=$date['KingdomName'] ?></a></td>
					<td><a href='<?=UIR ?>Park/index/<?=$date['ParkId'] ?>'><?=$date['ParkName'] ?></a></td>
					<td><a href='<?=UIR ?>Event/index/<?=$date['EventId'] ?>'><?=$date['EventName'] ?></a></td>
					<td class='data-column'><?=$date['Attendees'] ?></td>
				</tr>
	<?php endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>

<div class='info-container' style="width: 80%">
    <div id='attendance-graph' style="min-width: 310px; width: 100%; height: 500px; margin: 0 auto"></div>
</div>
