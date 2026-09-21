<?php
$html = file_get_contents(__DIR__ . '/../staff/customizations.php');
$start = strpos($html, 'function createJoManager');
$end = strpos($html, 'window.joManager = createJoManager');
$js = substr($html, $start, $end - $start);
$js = preg_replace('/<\?php.*?\?>/s', 'false', $js);
$js = "window.printflowStaffServiceOrderModalMixin = function(){return{};};\n" . $js . "\nwindow.joManager = createJoManager;\ncreateJoManager('ALL');\n";
file_put_contents(__DIR__ . '/tmp_jo_manager.js', $js);
echo "Wrote " . strlen($js) . " bytes\n";
