<?php

namespace App\Classes;

/**
 * Functionality for parsing an HTML page
 */
class HTMLParser
{
    /**
     * HTML to parse from the current page
     * @var string
     */
    public $html;

    /**
     * The url of the current page
     * @var string
     */
    private $currentURL;

    private $domain;

    /**
     * User agent value used for page requests
     * @var string
     */
    private $userAgent;


    /**
     * Constructor
     * @param   array   class initialization parameters
     * @return  void
     */
    public function __construct($params = [])
    {
        if (isset($params['domain'])) {
            $this->domain = $params['domain'];
        }

        if (isset($params['userAgent'])) {
            $this->userAgent = $params['userAgent'];
        }
    }

    /**
     * 
     */
    public function recursiveFunction($url, &$visitedLinks)
    {
        // Check to see if the url has already been visited
        if (in_array($url, $visitedLinks)) {
            return;
        }

        // Set the current url as being visited
        $visitedLinks[] = $url;

        // Get the html for the current page
        $html = $this->loadPage($url);

        // Get all links in the current page
        $links = $this->getLinks($html);


        // Process/store data for the current page here


        // If there are no links found, no need to continue
        if (!count($links)) {
            return;
        }

        foreach ($links as $link) {
            $skipLink = false;

            // TODO: The code needs to follow files, but not load their html or get their links. So this section should be moved
            $excludeExtensions = [
                'css', 'ashx', 
                'js', 
                'pdf', 'rtf', 
                'gif', 'png', 'jpg', 'jpeg', 'bmp', 'tif', 'tiff', 'ico', 'ps', 
                'wav', 'mp3', 'au', 'aiff', 'mpg', 'mpeg', 'mov', 'qt', 'avi', 
                'zip', 'gz', 'tar', 'exe', 'tgz', 'bz', 'bz2', 'z', 'gzip', 'sit', 
                'vcf', 'arj', 'bin', 'ram', 'ra', 'arc', 'hqx', 'sea', 'uu', 'cl', 'jar', 
            ];

            foreach ($excludeExtensions as $extension) {
                if (strpos($link, '.'.$extension) !== false) {
                    $skipLink = true;
                    break;
                }
            }

            if (in_array($link, $visitedLinks)) {
                $skipLink = true;
            }

            if (count($visitedLinks) > 500) {
                $skipLink = true;
            }

            if (!$skipLink) {
                $this->recursiveFunction($link, $visitedLinks);
            }
        }

        return;
    }

    /**
     * Load an html page
     * @param   string  the url of the page to load
     * @return  bool
     */
    public function loadPage($url)
    {
        // If there is no valid currentURL then no HTML can be parsed
        if (!$url) {
            return false;
        }

        $url = rtrim($url, '/');
        $url .= '/';

        $urlParts = parse_url($url);
        $path = $urlParts['path'];
        $host = $urlParts['host'];

        if (isset($urlParts['query']) && $urlParts['query'] != "") {
            $path .= "?".$urlParts['query'];
        }

        if (isset($urlParts['port'])) {
            $port = (int)$urlParts['port'];
        }
        else {
            if ($urlParts['scheme'] == "http") {
                $port = 80;
            } 
            else {
                if ($urlParts['scheme'] == "https") {
                    $port = 443;
                }
            }
        }

        if ($port == 80) {
            $portString = "";
        } else {
            $portString = ":$port";
        }

        $all = "*/*";

        // Build the request for the page
        $request = "GET $path HTTP/1.0\r\nHost: $host$portString\r\nAccept: $all\r\nUser-Agent: $this->userAgent\r\n\r\n";

        $fsocketTimeout = 30;
        if (substr($url, 0, 5) == "https") {
            $target = "ssl://".$host;
        } else {
            $target = $host;
        }

        $errno = 0;
        $errstr = "";
        $fp = @fsockopen($target, $port, $errno, $errstr, $fsocketTimeout);

        if (!$fp) {
            return false;        
        }
        else {
            if (!fputs($fp, $request)) {
                return false;
            }

            $data = null;
            socket_set_timeout($fp, $fsocketTimeout);
            
            do {
                $status = socket_get_status($fp);
                $data .= fgets($fp, 8192);
            } while (!feof($fp) && !$status['timed_out']) ;

            fclose($fp);

            if ($status['timed_out'] == 1) {
                $contents['state'] = "timeout";
            }
            else {
                $contents['state'] = "ok";
            }

            $contents['file'] = substr($data, strpos($data, "\r\n\r\n") + 4);
        }

        // TODO: more error-handling is needed
        return $contents['file'];
    }

    /**
     * Find all links in the current page
     * @return  array
     */
    public function getLinks($html)
    {
        $pattern = "/href\s*=\s*[\'\"]?([+:%\/\?~=&;\\\(\),._a-zA-Z0-9-]*)(#[.a-zA-Z0-9-]*)?[\'\" ]?(\s*rel\s*=\s*[\'\"]?(nofollow)[\'\"]?)?/i";
        preg_match_all($pattern, $html, $matches);

        $links = array();
        foreach ($matches[1] as $link) {
            if (!$link || $link == '/') {
                continue;
            }

            if (strpos($link, 'http') !== 0 && strpos($link, '/') !== 0) {
                continue;
            }

            if (strpos($link, '/') === 0) {
                $link = rtrim($this->domain, '/') . $link;
            }

            if (strpos($link, 'amnhealthcare.com') === false) {
                continue;
            }

            $link = rtrim($link, '/');
            $link .= '/';
            $link = strtolower($link);

            $links[] = $link;
        }

        return $links;
    }
}
