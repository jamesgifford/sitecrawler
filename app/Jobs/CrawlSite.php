<?php

namespace App\Jobs;

use App\Classes\HTMLParser;
use App\Jobs\Job;
use App\Models\Email;
use App\Models\Link;
use App\Models\Site;
use Config;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class CrawlSite extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    protected $site;
    protected $userAgent;
    protected $maxDepth;
    protected $maxLinks;

    /**
     * Create a new job instance.
     *
     * return void
     */
    public function __construct(Site $site)
    {
        $this->site = $site;
        $this->userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/48.0.2564.109 Safari/537.36';
        $this->maxDepth = 5;
        $this->maxLinks = 200;
    }

    /**
     * Execute the job.
     *
     * return void
     */
    public function handle()
    {
        $links = $emails = $directories = [];
        $result = $this->recursiveFunction($this->site->url, $links, $emails, $directories);
    }

    /**
     * Recursive function for parsing web pages
     * param   string  the url of the page to parse
     * param   array   links already parsed
     * return  void
     */
    protected function recursiveFunction($url, &$visitedLinks, &$foundEmails, &$readableDirectories, $depth = 0, $isDir = false)
    {
        // Sanitize the url by removing the query string and trailing slashes
        $url = rtrim(strtok($url, '?'), '/');
        
        // Split out parts of the url for easier examination
        $parsedURL = parse_url($url);
        $urlBase = (isset($parsedURL['scheme']) ? $parsedURL['scheme'] : '').'://'.(isset($parsedURL['host']) ? $parsedURL['host'] : '');
        $urlPath = isset($parsedURL['path']) ? $parsedURL['path'] : '';
        $urlExtension = pathinfo($urlPath, PATHINFO_EXTENSION);
        $urlType = $isDir ? 'dir' : ($urlExtension ? 'file' : 'page');

        // Determine if the current url needs to be processed
        //---------------------------------------------------------------------

        // Check if the url has already been processed
        if (in_array($url, $visitedLinks)) {
            return;
        }

        // Check if the current depth is within tolerance
        if ($depth > $this->maxDepth) {
            return;
        }

        // Check if the number of links visited exceeds tolerance
        if (count($visitedLinks) > $this->maxLinks) {
            return;
        }

        // Check if the url contains an excluded extension (eg: css files)
        if (in_array($urlExtension, Config::get('crawl.excludeFileExtensions'))) {
            return;
        }

        // Store information about the current url
        //---------------------------------------------------------------------

        // Set the current url as being visited
        $visitedLinks[] = $url;

        // Store a new link record in the database
        $link = new Link();
        $link->site_id = $this->site->id;
        $link->url = $url;
        $link->type = $urlType;
        $link->extension = $urlExtension;
        $link->save();

        // Check all bases of the url for readable directories
        //---------------------------------------------------------------------

        $dir = $url;
        $nextDir = '';
        $links = [];
        while ($dir && strlen($dir) > 6 && $dir != $nextDir) {
            if ($nextDir) {
                $dir = $nextDir;
            }

            $dir = strtolower(rtrim($dir, '/'));
            $nextDir = strtolower(rtrim(substr($dir, 0, strrpos($dir, '/')), '/'));

            if (!$dir || $dir == 'http:') {
                break;
            }

            $handle = @fopen($dir, "r");

            if ($handle == false) {
                continue;
            }

            if (in_array($dir, $visitedLinks) || in_array($dir, $readableDirectories)) {
                continue;
            }

            $contents = stream_get_contents($handle);

            if (!$contents) {
                continue;
            }

            $links = $this->getDirectories($contents, $dir);

            if ($links) {
                foreach ($links as $link) {
                    $this->recursiveFunction($link, $visitedLinks, $foundEmails, $readableDirectories, $depth + 1, true);
                }
            }
        }

        // Get the page content of the url
        $urlContent = $this->getContent($url);

        // If the url has no content there nothing else to do with it
        if (!$urlContent) {
            return;
        }

        // Parse the page content for email addresses
        //---------------------------------------------------------------------

        // Get all the email addresses in the current page content
        $emails = $this->getEmails($urlContent, $url);

        if ($emails) {
            foreach ($emails as $emailAddress) {
                $emailAddress = strtolower($emailAddress);

                if (in_array($emailAddress, $foundEmails)) {
                    continue;
                }

                // Store a new email record in the database
                $email = new Email();
                $email->site_id = $this->site->id;
                $email->address = $emailAddress;
                $email->save();

                $foundEmails[] = $emailAddress;
            }
        }

        // Parse the page content for additional urls
        //---------------------------------------------------------------------

        // Get all the urls within the current page content
        $links = $this->getLinks($urlContent, $url);

        // If there are no links found, no need to continue with this url
        if (!count($links)) {
            return;
        }

        foreach ($links as $link) {
            $this->recursiveFunction($link, $visitedLinks, $foundEmails, $readableDirectories, $depth + 1);
        }

        return;
    }

    /**
     * Load an html page
     * param   string  the url of the page to load
     * return  bool
     */
    protected function getContent($url)
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
        $fp = fsockopen($target, $port, $errno, $errstr, $fsocketTimeout);

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

            if ($contents['state'] == "ok") {
                return substr($data, strpos($data, "\r\n\r\n") + 4);
            }
        }

        return false;
    }

    /**
     * Find all links in the current page
     * return  array
     */
    protected function getLinks($content, $url = '')
    {
        $urlPattern = "/[href|HREF]\s*=\s*[\'\"]?([+:%\/\?~=&;\\\(\),._a-zA-Z0-9-]*)(#[.a-zA-Z0-9-]*)?[\'\" ]?(\s*rel\s*=\s*[\'\"]?(nofollow)[\'\"]?)?/i";
        //$urlPattern = '/<a href="(.+)">/';
        preg_match_all($urlPattern, $content, $urlMatches);

        $links = array();
        foreach ($urlMatches[1] as $link) {
            if (!$link || $link == '/') {
                continue;
            }

            if ($url) {
                if (strpos($link, '/') === 0) {
                    $link = rtrim($url, '/') . $link;
                }

                if (strrpos($link, $url) !== 0) {
                    continue;
                }
            }

            $link = rtrim($link, '/');
            $link = strtolower($link);

            $links[] = $link;
        }

        return $links;
    }

    /**
     * Find all links in the current page
     * return  array
     */
    protected function getEmails($content, $url = '')
    {
        $emails = array();
        $emailPattern = '`\<a([^>]+)href\=\"mailto\:([^">]+)\"([^>]*)\>(.*?)\<\/a\>`ism';
        preg_match_all($emailPattern, $content, $emailMatches);

        if (!isset($emailMatches[2]) || !is_array($emailMatches[2])) {
            return $emails;
        }

        foreach ($emailMatches[2] as $email) {
            if (!$email || $email == '/') {
                continue;
            }

            if (strpos($email, '@') === false) {
                continue;
            }

            $email = strtolower(rtrim($email, '/'));

            $emails[] = $email;
        }

        return $emails;
    }

    /**
     * Find all directory links in the current page
     * return  array
     */
    protected function getDirectories($content, $url = '')
    {
        //$urlPattern = "/[href|HREF]\s*=\s*[\'\"]?([+:%\/\?~=&;\\\(\),._a-zA-Z0-9-]*)(#[.a-zA-Z0-9-]*)?[\'\" ]?(\s*rel\s*=\s*[\'\"]?(nofollow)[\'\"]?)?/i";
        $urlPattern = '/<a href="([a-zA-Z0-9\-\_\/\.]+)">/';
        preg_match_all($urlPattern, $content, $urlMatches);

        $links = array();
        foreach ($urlMatches[1] as $link) {
            if (!$link || $link == '/') {
                continue;
            }

            $link = strtolower(rtrim(trim($link, '/'), '/'));
            $link = ($url . '/' . $link);

            $links[] = $link;
        }

        return $links;
    }
}
