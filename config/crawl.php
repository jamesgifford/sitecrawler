<?php

return [

// Extensions used by valid webpages (that might contain links to index)
'pageExtensions' => [
    'html', 'htm', 'xhtml', 
    'php', 
    'aspx', 'asp'
],

// File extensions to record (path will be recorded, but contents will not be indexed)
'fileExtensions' => [
    'pdf', 'xls', 'rtf', 'txt', 'xml'
],

// File extensions to skip completely (will not be recorded or searched for links)
'excludeExtensions' => [
    'css', 'ashx', 
    'js', 
    'gif', 'png', 'jpg', 'jpeg', 'bmp', 'tif', 'tiff', 'ico', 'ps', 
    'wav', 'mp3', 'au', 'aiff', 'mpg', 'mpeg', 'mov', 'qt', 'avi', 
    'zip', 'gz', 'tar', 'exe', 'tgz', 'bz', 'bz2', 'z', 'gzip', 'sit', 'jar', 
    'vcf', 'arj', 'bin', 'ram', 'ra', 'arc', 'hqx', 'sea', 'uu', 'cl',  
],

];
