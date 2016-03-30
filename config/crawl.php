<?php

return [

// File extensions to record (path will be recorded, but contents will not be indexed)
'includeFileExtensions' => [
    'pdf', 'xls', 'rtf', 'txt', 'xml'
],

// File extensions to skip completely (will not be recorded or searched for links)
'excludeFileExtensions' => [
    'css', 'ashx', 
    'js', 
    'gif', 'png', 'jpg', 'jpeg', 'bmp', 'tif', 'tiff', 'ico', 'ps', 
    'wav', 'mp3', 'au', 'aiff', 'mpg', 'mpeg', 'mov', 'qt', 'avi', 
    'zip', 'gz', 'tar', 'exe', 'tgz', 'bz', 'bz2', 'z', 'gzip', 'sit', 'jar', 
    'vcf', 'arj', 'bin', 'ram', 'ra', 'arc', 'hqx', 'sea', 'uu', 'cl', 
],

// Which user agent to use when requesting info from sites
'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/48.0.2564.109 Safari/537.36',

'maxDepth' => 10,

];
