<?php global $Session ?>

<script type='text/javascript'>
  $(document).ready(function() {
    $( '#AttendanceDate' ).datepicker({dateFormat: 'yy-mm-dd'});
    //var facedata = <?=json_encode($FaceData) ?>;
    $('a[mundaneid]').on('click', function() {
      console.log($(this).attr('mundaneid'));
      $('tr[mundaneid="' + $(this).attr('mundaneid') + '"]').remove();
    });
  });
</script>

<div class='info-container'>
	<h3><?=$AttendanceDate ?></h3>
  <div style='padding: 0.5em;'>
  <p>The Amtgard ORK Beholder will gaze upon your faces, and if you are known to it, you shall be counted!</p>
  <p>You or your PM should go to your ORK persona page and upload a front-facing image of your face, with good neutral lighting.</p>
  <p>The ORK does not keep your image, but merely makes a note of your winsome features for future reference.</p>
  <p>On this page, take a group selfie in good lighting, with as many cheery and forward-facing people as reasonable fit. Upload your selfie and let the Eye of the ORK gaze upon it!</p>
  </div>
  <p>
  <form class='form-container' method='post' action='<?=UIR ?>Attendance/behold/gaze' enctype="multipart/form-data">
		<div>
			<span>Date:</span>
			<span><input type='text' class='required-field' value='<?=trimlen($Attendance_index['AttendanceDate'])?$Attendance_index['AttendanceDate']:$AttendanceDate ?>' name='AttendanceDate' id='AttendanceDate' /></span>
		</div>
		<div>
			<span>Group Selfie:</span>
			<span><input type='file' class='restricted-image-type' name='GroupSelfie' id='GroupSelfie' /></span>
		</div>
		<div>
			<span></span>
			<span><input value='Behold!' type='submit' /></span>
		</div>
  </form>
</div>
<div class='info-container'>
	<h3>Those Who Are Known</h3>
  <?php if (isset($FaceLocations)) : ?>
  <img src='data:image/png;base64,<?=$QueryImage ?>' style='max-width: 100%; border: 1px solid #ccc; margin: 8px 0; border-radius: 4px;' />
  <?php endif; ?>
  <form class='form-container' method='post' action='<?=UIR ?>Attendance/behold/behold'>
    <table class='information-table form-container' id='EventListTable'>
      <thead>
        <tr>
          <th>Kingdom</th>
          <th>Park</th>
          <th>Player</th>
          <th>Class</th>
          <th class='deletion'>&times;</th>
        </tr>
      </thead>
      <tbody>
  <?php if (!is_array($FaceLocations)) $FaceLocations = array(); ?>
  <?php foreach ($FaceLocations as $key => $detail) : ?>
  <?php if ($detail[0]['id'] > 0) : $detail = $detail[0]; ?>
        <tr mundaneid='<?=$detail['MundaneId'] ?>'>
          <td><a href='<?=UIR ?>Kingdom/index/<?=$detail['KingdomId'] ?>'><?=$detail['Kingdom'] ?></a></td>
          <td><a href='<?=UIR ?>Park/index/<?=$detail['ParkId'] ?>'><?=$detail['Park'] ?></a></td>
          <td><a href='<?=UIR ?>Player/index/<?=$detail['MundaneId'] ?>'><?=$detail['Persona'] ?></a></td>
          <td class='data-column'>
            <select name='class[<?=$detail['MundaneId'] ?>]'>
              <option value=0> -</option>
              <?php foreach ($Classes['Classes'] as $k => $class) : ?>
              <option value='<?=$class['ClassId'] ?>'><?=$class['Name'] ?></option>
              <?php endforeach ?>
            </select>
          </td>
          <td class='deletion'><a mundaneid='<?=$detail['MundaneId'] ?>'>&times;</a></td>
        </tr>
  <?php endif; ?>
  <?php endforeach ?>
      </tbody>
    </table>
  	<span><input value='Let them be counted!' type='submit' /></span>
    <input type='hidden' class='required-field' value='<?=trimlen($Attendance_index['AttendanceDate'])?$Attendance_index['AttendanceDate']:$AttendanceDate ?>' name='attendance_date' id='attendance_date' />
  </form>
</div>
