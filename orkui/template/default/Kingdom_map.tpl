<div class='info-container' style="width: 65%;">
	<h3>Kingdom Map</h3>
	<div style='border: 1px solid #aaa;' ><div id="map" style="width: 100%; height: 100%; min-width: 400px; min-height: 400px;"></div></div>
</div>

<div class='info-container' style="width: 25%;" id='directions-container'>
	<h3>Directions</h3>
	<div id="directions"></div>
</div>

<script type="text/javascript">
//<![CDATA[

  var map;
	function initMap() {
		map = new google.maps.Map(document.getElementById('map'), {
			center: {lat: 0, lng: 0},
			zoom: 2
		});
		$('.info-container:first-child').height($(window).height() * 0.85);
		$('#map').height($(window).height() * 0.75);
		var LatLngList = [];

		var locations = [];
	<?php foreach ($Parks['Parks'] as $k => $Details) : 
			$location = json_decode(stripslashes($Details['Location']));
			$location = ((isset($location->location))?$location->location:$location->bounds->northeast);
			if (is_numeric($location->lat) && is_numeric($location->lng)) :
	?>
  	locations.push(["<?=ucwords($Details['Name']) ?>", <?=$location->lat ?>, <?=$location->lng ?>, <?=$Details['ParkId'] ?>, "<?=($Details['HasHeraldry']?"<img src='" . HTTP_PARK_HERALDRY . sprintf("%05d", $Details['ParkId']) . ".jpg' />":'') . urlencode($Details['Directions'] . "<h4>Description</h4>" . $Details['Description']) ?>", <?=$Details['KingdomId'] ?>, "<?=$Details['KingdomColor'] ?>" ]);
		LatLngList.push(new google.maps.LatLng (<?=$location->lat ?>, <?=$location->lng ?>));
	<?php endif ; ?>
	<?php endforeach ; ?>

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

		var pinColor = "FE7569";
		var pinImage = getMarker(pinColor);
		/*
		var pinShadow = new google.maps.MarkerImage("https://amtgard.com/ork/orkservice/Map/charticon.php?pin=000000",
				new google.maps.Size(40, 37),
				new google.maps.Point(0, 0),
				new google.maps.Point(12, 35));
		*/
		
		for (i = 0; i < locations.length; i++) {
			var pinImage = getMarker("FE7569");  

			marker = new google.maps.Marker({
			position: new google.maps.LatLng(locations[i][1], locations[i][2]),
				map: map,
				title: locations[i][0],
				icon: pinImage
			});

			google.maps.event.addListener(marker, 'click', (function(marker, i) {
				return function() {
				infowindow.setContent("<b><a href='<?=UIR ?>Park/index/" + locations[i][3] + "'>" + locations[i][0] + "</a></b><p style='margin-top: 20px'>" + urldecode(locations[i][4]));
				infowindow.open(map, marker);
				showdirections(locations[i][0], locations[i][4]);
				}
			})(marker, i));
		}

	}
	
  function showdirections(parkname, directions) {
  	$('#directions-container h3').html(urldecode(parkname));
		$('#directions').html(urldecode(directions));
	}
	
	var Markers = {};

	function getMarker(pinColor) {
			if (pinColor in Markers)
					return Markers[pinColor];
			var pinIcon = new google.maps.MarkerImage("https://amtgard.com/ork/orkservice/Map/charticon.php?pin=" + pinColor,
					new google.maps.Size(21, 34),
					new google.maps.Point(0,0),
					new google.maps.Point(10, 34));
			Markers[pinColor] = pinIcon;
			return pinIcon;
	}
    
	function getIcon(color) {
		return MapIconMaker.createMarkerIcon({width: 20, height: 34, primaryColor: color, cornercolor:color});
	}

//]]>
</script>

<script src="//maps.googleapis.com/maps/api/js?key=AIzaSyA8bOLm-eLdymz6DcIE3Q2KGkAVmx-BY4g&callback=initMap" type="text/javascript"></script>
