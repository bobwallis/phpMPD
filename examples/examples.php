<?php
require( '../MPD.php' );

$mpd = new MPD();

print_r( $mpd->status() );