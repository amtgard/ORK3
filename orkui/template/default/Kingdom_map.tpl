<div class='info-container' style="width: 65%;">
	<h3>Kingdom Map</h3>
	<div style='border: 1px solid #aaa;' ><div id="map" style="width: 100%; height: 100%; min-width: 400px; min-height: 400px;"></div></div>
</div>

<div class='info-container' style="width: 25%;" id='directions-container'>
	<h3>Directions</h3>
	<div id="directions"></div>
</div>

<script src="http://maps.google.com/maps/api/js?sensor=false" type="text/javascript"></script>

<script type="text/javascript">
//<![CDATA[

	$(document).ready(function() {
		$('.info-container:first-child').height($(window).height() * 0.85);
		$('#map').height($(window).height() * 0.75);

		var LatLngList = [];

		var locations = [];
	<?php foreach ($Parks['Parks'] as $k => $Details) : 
			$location = json_decode(stripslashes($Details['Location']));
			$location = ((isset($location->location))?$location->location:$location->bounds->northeast);
			if (is_numeric($location->lat) && is_numeric($location->lng)) :
	?>
		locations.push(["<?=ucwords($Details['Name']) ?>", <?=$location->lat ?>, <?=$location->lng ?>, <?=$Details['ParkId'] ?>, "<?=($Details['HasHeraldry']?"<img src='" . HTTP_PARK_HERALDRY . sprintf("%05d", $Details['ParkId']) . ".jpg' />":'') . urlencode($Details['Directions'] . "<h4>Description</h4>" . $Details['Description']) ?>" ]);
		LatLngList.push(new google.maps.LatLng (<?=$location->lat ?>, <?=$location->lng ?>));
	<?php endif ; ?>
	<?php endforeach ; ?>

		var map = new google.maps.Map(document.getElementById('map'), {
		  zoom: 10,
		  center: new google.maps.LatLng(0, 0),
		  mapTypeId: google.maps.MapTypeId.ROADMAP
		});

		var bounds = new google.maps.LatLngBounds ();
		//  Go through each...
		for (var i = 0, LtLgLen = LatLngList.length; i < LtLgLen; i++) {
		  //  And increase the bounds to take this point
			bounds.extend (LatLngList[i]);
		}
		//  Fit these bounds to the map
		map.fitBounds (bounds);
		var listener = google.maps.event.addListener(map, "idle", function() { 
			map.setZoom(map.getZoom() - 1); 
			google.maps.event.removeListener(listener); 
		});
		
		var infowindow = new google.maps.InfoWindow();

		var marker, i;

		for (i = 0; i < locations.length; i++) {  
		  marker = new google.maps.Marker({
			position: new google.maps.LatLng(locations[i][1], locations[i][2]),
			map: map,
			title: locations[i][0],
		  });

		  google.maps.event.addListener(marker, 'click', (function(marker, i) {
			return function() {
			  infowindow.setContent("<b><a href='<?=UIR ?>Park/index/" + locations[i][3] + "'>" + locations[i][0] + "</a></b><p style='margin-top: 20px'>" + urldecode(locations[i][4]));
			  infowindow.open(map, marker);
			  showdirections(locations[i][0], locations[i][4]);
			}
		  })(marker, i));
		}
	});
	
	function showdirections(parkname, directions) {
        $('#directions-container h3').html(urldecode(parkname));
		$('#directions').html(urldecode(directions));
	}
	
	function getIcon(color) {
		return MapIconMaker.createMarkerIcon({width: 20, height: 34, primaryColor: color, cornercolor:color});
	}
//]]>
</script>
