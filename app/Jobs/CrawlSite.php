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
     * @return void
     */
    public function __construct(Site $site)
    {
        $this->site = $site;
        $this->userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/48.0.2564.109 Safari/537.36';
        $this->maxDepth = 5;
        $this->maxLinks = 500;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $links = $emails = $directories = [];
        $result = $this->recursiveFunction($this->site->url, $links, $emails, $directories);
    }

    /**
     * Recursive function for parsing web pages
     * @param   string  the url of the page to parse
     * @param   array   links already parsed
     * @return  void
     */
    protected function recursiveFunction($url, &$visitedLinks, &$foundEmails, &$readableDirectories, $depth = 0)
    {
        // Check to see if the url has already been visited
        if (in_array($url, $visitedLinks)) {
            return;
        }

        if ($depth > $this->maxDepth) {
            return;
        }

        // Set the current url as being visited
        $visitedLinks[] = $url;

        $urlType = 'page';
        $urlExtension = '';

        // Check if the url is to a valid web page
        $pageExtensions = Config::get('crawl.pageExtensions');
        foreach ($pageExtensions as $extension) {
            if (strpos($url, '.'.$extension) !== false) {
                $urlExtension = $extension;
                break;
            }
        }

        // Check if the url is a desired file
        $fileExtensions = Config::get('crawl.fileExtensions');
        foreach ($fileExtensions as $extension) {
            if (strpos($url, '.'.$extension) !== false) {
                $urlType = 'file';
                $urlExtension = $extension;
                break;
            }
        }

        // Process/store data for the current url here
        $link = new Link();
        $link->site_id = $this->site->id;
        $link->url = $url;
        $link->type = $urlType;
        $link->extension = $urlExtension;
        $link->save();

        // If the url is to a file, don't search it
        if ($urlType == 'file') {
            return;
        }

        // 
        $idDir = is_dir($url);

        // Get the html for the current page
        $html = $this->loadPage($url);

        // Get all the email addresses in the current page
        $emails = $this->getEmails($url, $html);

        if ($emails) {
            foreach ($emails as $emailAddress) {
                $emailAddress = strtolower($emailAddress);

                if (in_array($emailAddress, $foundEmails)) {
                    continue;
                }

                $email = new Email();
                $email->site_id = $this->site->id;
                $email->address = $emailAddress;
                $email->save();

                $foundEmails[] = $emailAddress;
            }
        }

        // Get all links in the current page
        $links = $this->getLinks($url, $html);

        // If there are no links found, no need to continue with this url
        if (!count($links)) {
            return;
        }

        foreach ($links as $link) {
            $skipLink = false;

            $excludeExtensions = Config::get('crawl.excludeExtensions');
            foreach ($excludeExtensions as $extension) {
                if (strpos($link, '.'.$extension) !== false) {
                    $skipLink = true;
                    break;
                }
            }

            if (in_array($link, $visitedLinks)) {
                $skipLink = true;
            }

            if (count($visitedLinks) > $this->maxLinks) {
                $skipLink = true;
            }

            if (!$skipLink) {
                $this->recursiveFunction($link, $visitedLinks, $foundEmails, $readableDirectories, $depth + 1);
            }
        }

        return;
    }

    /**
     * Load an html page
     * @param   string  the url of the page to load
     * @return  bool
     */
    protected function loadPage($url)
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
    protected function getLinks($url, $html)
    {
        $urlPattern = "/[href|HREF]\s*=\s*[\'\"]?([+:%\/\?~=&;\\\(\),._a-zA-Z0-9-]*)(#[.a-zA-Z0-9-]*)?[\'\" ]?(\s*rel\s*=\s*[\'\"]?(nofollow)[\'\"]?)?/i";
        preg_match_all($urlPattern, $html, $urlMatches);

        $links = array();
        foreach ($urlMatches[1] as $link) {
            if (!$link || $link == '/') {
                continue;
            }

            if (strpos($link, 'http') !== 0 && strpos($link, '/') !== 0) {
                continue;
            }

            if (strpos($link, '/') === 0) {
                $link = rtrim($url, '/') . $link;
            }

            if (strpos($link, $url) !== 0) {
                continue;
            }

            $link = rtrim($link, '/');
            $link .= '/';
            $link = strtolower($link);

            $links[] = $link;
        }

        return $links;
    }

    /**
     * Find all links in the current page
     * @return  array
     */
    protected function getEmails($url, $html)
    {
        $emailPattern = '`\<a([^>]+)href\=\"mailto\:([^">]+)\"([^>]*)\>(.*?)\<\/a\>`ism';
        preg_match_all($emailPattern, $html, $emailMatches);

        $links = array();
        foreach ($emailMatches[2] as $link) {
            if (!$link || $link == '/') {
                continue;
            }

            if (strpos($link, '@') === false) {
                continue;
            }

            $link = rtrim($link, '/');

            $links[] = $link;
        }

        return $links;
    }
}
