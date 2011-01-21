<?php

## index.php
## 
##  Created by Andreas Linde on 8/17/10.
##             Stanley Rost on 8/17/10.
##  Copyright 2010 Andreas Linde. All rights reserved.
##
##  Permission is hereby granted, free of charge, to any person obtaining a copy
##  of this software and associated documentation files (the "Software"), to deal
##  in the Software without restriction, including without limitation the rights
##  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
##  copies of the Software, and to permit persons to whom the Software is
##  furnished to do so, subject to the following conditions:
##
##  The above copyright notice and this permission notice shall be included in
##  all copies or substantial portions of the Software.
##
##  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
##  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
##  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
##  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
##  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
##  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
##  THE SOFTWARE.

require('json.inc');
require('plist.inc');
require_once('config.inc');

define('CHUNK_SIZE', 1024*1024); // Size (in bytes) of tiles chunk

  // Read a file and display its content chunk by chunk
  function readfile_chunked($filename, $retbytes = TRUE) {
    $buffer = '';
    $cnt =0;
    // $handle = fopen($filename, 'rb');
    $handle = fopen($filename, 'rb');
    if ($handle === false) {
      return false;
    }
    while (!feof($handle)) {
      $buffer = fread($handle, CHUNK_SIZE);
      echo $buffer;
      ob_flush();
      flush();
      if ($retbytes) {
        $cnt += strlen($buffer);
      }
    }
    $status = fclose($handle);
    if ($retbytes && $status) {
      return $cnt; // return num. bytes delivered like readfile() does.
    }
    return $status;
}

function nl2br_skip_html($string)
{
	// remove any carriage returns (Windows)
	$string = str_replace("\r", '', $string);

	// replace any newlines that aren't preceded by a > with a <br />
	$string = preg_replace('/(?<!>)\n/', "<br />\n", $string);

	return $string;
}

class AppUpdater
{
    // define URL type parameter values
    const TYPE_PROFILE  = 'profile';
    const TYPE_APP      = 'app';
    const TYPE_IPA      = 'ipa';
    const TYPE_APK      = 'apk';
    const TYPE_AUTH     = 'authorize';

    // define the json response format version
    const API_V1 = '1';
    const API_V2 = '2';
    
    // define support app platforms
    const APP_PLATFORM_IOS      = "iOS";
    const APP_PLATFORM_ANDROID  = "Android";
    
    // define keys for the returning json string api version 1
    const RETURN_RESULT   = 'result';
    const RETURN_NOTES    = 'notes';
    const RETURN_TITLE    = 'title';
    const RETURN_SUBTITLE = 'subtitle';

    // define keys for the returning json string api version 2
    const RETURN_V2_VERSION         = 'version';
    const RETURN_V2_SHORTVERSION    = 'shortversion';
    const RETURN_V2_NOTES           = 'notes';
    const RETURN_V2_TITLE           = 'title';
    const RETURN_V2_TIMESTAMP       = 'timestamp';
    const RETURN_V2_AUTHCODE        = 'authcode';

    const RETURN_V2_AUTH_FAILED     = 'FAILED';

    // define keys for the array to keep a list of available beta apps to be displayed in the web interface
    const INDEX_APP             = 'app';
    const INDEX_VERSION         = 'version';
    const INDEX_SUBTITLE        = 'subtitle';
    const INDEX_DATE            = 'date';
    const INDEX_NOTES           = 'notes';
    const INDEX_PROFILE         = 'profile';
    const INDEX_PROFILE_UPDATE  = 'profileupdate';
    const INDEX_DIR             = 'dir';
    const INDEX_IMAGE           = 'image';
    const INDEX_STATS           = 'stats';
    const INDEX_PLATFORM        = 'platform';


    // define keys for the array to keep a list of devices installed this app
    const DEVICE_USER       = 'user';
    const DEVICE_PLATFORM   = 'platform';
    const DEVICE_OSVERSION  = 'osversion';
    const DEVICE_APPVERSION = 'appversion';
    const DEVICE_LASTCHECK  = 'lastcheck';

    protected $appDirectory;
    protected $json = array();
    public $applications = array();

    
    function __construct($dir) {
        
        date_default_timezone_set('UTC');

        $this->appDirectory = $dir;

        $bundleidentifier = isset($_GET['bundleidentifier']) ?
            $this->validateDir($_GET['bundleidentifier']) : null;

        $type = isset($_GET['type']) ? $this->validateType($_GET['type']) : null;
        $api = isset($_GET['api']) ? $this->validateAPIVersion($_GET['api']) : self::API_V1;
        
        // if (!$bundleidentifier)
        // {
        //     $this->json = array(self::RETURN_RESULT => -1);
        //     return $this->sendJSONAndExit();
        // }
        
        // if a bundleidentifier is submitted and request coming from a client, return JSON
        if ($bundleidentifier && 
            (
                strpos($_SERVER["HTTP_USER_AGENT"], 'CFNetwork') !== false ||       // iOS network requests, which means the client is calling, old versions don't add a custom user agent
                strpos($_SERVER["HTTP_USER_AGENT"], 'Hockey/iOS') !== false ||      // iOS hockey client is calling
                strpos($_SERVER["HTTP_USER_AGENT"], 'Hockey/Android') !== false ||  // Android hockey client is calling
                $type
            ))
        {
            return $this->deliver($bundleidentifier, $api, $type);
        }
        
        // if a bundleidentifier is provided, only show that app
        $this->show($bundleidentifier);
    }
    
    protected function array_orderby()
    {
        $args = func_get_args();
        $data = array_shift($args);
        foreach ($args as $n => $field) {
            if (is_string($field)) {
                $tmp = array();
                foreach ($data as $key => $row)
                    $tmp[$key] = $row[$field];
                $args[$n] = $tmp;
                }
        }
        $args[] = &$data;
        call_user_func_array('array_multisort', $args);
        return array_pop($args);
    }


    protected function validateDir($dir)
    {
        // do not allow .. or / in the name and check if that path actually exists
        if (
            $dir &&
            !preg_match('#(/|\.\.)#u', $dir) &&
            file_exists($this->appDirectory.$dir))
        {
            return $dir;
        }
        return null;
    }
    
    protected function validateType($type)
    {
        if (in_array($type, array(self::TYPE_PROFILE, self::TYPE_APP, self::TYPE_IPA, self::TYPE_AUTH, self::TYPE_APK)))
        {
            return $type;
        }
        return null;
    }

    protected function validateAPIVersion($api)
    {
        if (in_array($api, array(self::API_V1, self::API_V2)))
        {
            return $api;
        }
        return self::API_V1;
    }
    
    // map a device UDID into a username
    protected function mapUser($user, $userlist)
    {
        $username = $user;
        $lines = explode("\n", $userlist);

        foreach ($lines as $i => $line) :
            if ($line == "") continue;
            
            $userelement = explode(";", $line);

            if (count($userelement) == 2) {
                if ($userelement[0] == $user) {
                    $username = $userelement[1];
                    break;
                }
            }
        endforeach;

        return $username;
    }
    
    // map a device code into readable name
    protected function mapPlatform($device)
    {
        $platform = $device;
        
        switch ($device) {
            case "i386":
                $platform = "iPhone Simulator";
                break;
            case "iPhone1,1":
                $platform = "iPhone";
                break;
            case "iPhone1,2":
                $platform = "iPhone 3G";
                break;
            case "iPhone2,1":
                $platform = "iPhone 3GS";
                break;
            case "iPhone3,1":
                $platform = "iPhone 4";
                break;
            case "iPad1,1":
                $platform = "iPad";
                break;
            case "iPod1,1":
                $platform = "iPod Touch";
                break;
            case "iPod2,1":
                $platform = "iPod Touch 2nd Gen";
                break;
            case "iPod3,1":
                $platform = "iPod Touch 3rd Gen";
                break;
            case "iPod4,1":
                $platform = "iPod Touch 4th Gen";
                break;
        }
	
        return $platform;
    }
    
    protected function deliver($bundleidentifier, $api, $type)
    {
        // iOS
        $plist                  = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*.plist'));
        $ipa                    = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*.ipa'));
        $provisioningProfile    = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*.mobileprovision'));

        // Android
        $json                   = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*.json'));
        $apk                    = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*.apk'));

        // Common
        $note                   = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*.html'));
        $image                  = @array_shift(glob($this->appDirectory.$bundleidentifier . '/*.png'));
        
        // did we get any user data?
        $udid = isset($_GET['udid']) ? $_GET['udid'] : null;
        $appversion = isset($_GET['version']) ? $_GET['version'] : "";
        $osversion = isset($_GET['ios']) ? $_GET['ios'] : "";
        $platform = isset($_GET['platform']) ? $_GET['platform'] : "";
        
        if ($udid && $type != self::TYPE_AUTH) {
            $thisdevice = $udid.";;".$platform.";;".$osversion.";;".$appversion.";;".date("m/d/Y H:i:s");
            $content =  "";

            $filename = $this->appDirectory."stats/".$bundleidentifier;

            if (is_dir($this->appDirectory."stats/")) {
                $content = @file_get_contents($filename);
            
                $lines = explode("\n", $content);
                $content = "";
                $found = false;
                foreach ($lines as $i => $line) :
                    if ($line == "") continue;
                    $device = explode( ";;", $line);

                    $newline = $line;
                
                    if (count($device) > 0) {
                        // is this the same device?
                        if ($device[0] == $udid) {
                            $newline = $thisdevice;
                            $found = true;
                        }
                    }
                
                    $content .= $newline."\n";
                endforeach;
            
                if (!$found) {
                    $content .= $thisdevice;
                }
            
                // write back the updated stats
                file_put_contents($filename, $content);
            }
        }

        // notes file is optional, other files are required
        if ((!$ipa || !$plist) && (!$apk || !$json)) {
            $this->json = array(self::RETURN_RESULT => -1);
            return $this->sendJSONAndExit();
        }

        if (!$type) {
            // check for available updates for the given bundleidentifier
            // and return a JSON string with the result values

            if ($plist && $ipa) {
                
                // this is an iOS app
                
                // parse the plist file
                $plistDocument = new DOMDocument();
                $plistDocument->load($plist);
                $parsed_plist = parsePlist($plistDocument);

                // get the bundle_version which we treat as build number
                $latestversion = $parsed_plist['items'][0]['metadata']['bundle-version'];
            
                if ($api == self::API_V1) {
                    // this is API Version 1
                
                    // add the latest release notes if available
                    if ($note) {
                        $this->json[self::RETURN_NOTES] = nl2br_skip_html(file_get_contents($appDirectory . $note));
                    }

                    $this->json[self::RETURN_TITLE]   = $parsed_plist['items'][0]['metadata']['title'];

                    if ($parsed_plist['items'][0]['metadata']['subtitle'])
        	            $this->json[self::RETURN_SUBTITLE]   = $parsed_plist['items'][0]['metadata']['subtitle'];

                    $this->json[self::RETURN_RESULT]  = $latestversion;

                    return $this->sendJSONAndExit();
                } else {
                    // this is API Version 2
                
                    $newAppVersion = array();
                    // add the latest release notes if available
                    if ($note) {
                        $newAppVersion[self::RETURN_V2_NOTES] = nl2br_skip_html(file_get_contents($appDirectory . $note));
                    }

                    $newAppVersion[self::RETURN_V2_TITLE]   = $parsed_plist['items'][0]['metadata']['title'];

                    if ($parsed_plist['items'][0]['metadata']['subtitle'])
        	            $newAppVersion[self::RETURN_V2_SHORTVERSION]   = $parsed_plist['items'][0]['metadata']['subtitle'];

                    $newAppVersion[self::RETURN_V2_VERSION]  = $latestversion;
                
                    $newAppVersion[self::RETURN_V2_TIMESTAMP]  = filectime($appDirectory . $ipa);;

                    $this->json[] = $newAppVersion;
                    return $this->sendJSONAndExit();
                }
            } else if ($json && $apk) {
                
                // this is an Android app
                
                // API version is V2 by default, even if the client provides V1
                
                // parse the json file
                $parsed_json = json_decode(file_get_contents($appDirectory . $json), true);
                
                $newAppVersion = array();
                // add the latest release notes if available
                if ($note) {
                    $newAppVersion[self::RETURN_V2_NOTES] = nl2br_skip_html(file_get_contents($appDirectory . $note));
                }

                $newAppVersion[self::RETURN_V2_TITLE]   = $parsed_json['title'];

                $newAppVersion[self::RETURN_V2_SHORTVERSION]  = $parsed_json['versionName'];
                $newAppVersion[self::RETURN_V2_VERSION]  = $parsed_json['versionCode'];
            
                $newAppVersion[self::RETURN_V2_TIMESTAMP]  = filectime($appDirectory . $apk);;

                $this->json[] = $newAppVersion;
                return $this->sendJSONAndExit();
            }

        } else if ($type == self::TYPE_PROFILE) {

            // send latest profile for the given bundleidentifier
            $filename = $appDirectory  . $provisioningProfile;
            header('Content-Disposition: attachment; filename=' . urlencode(basename($filename)));
            header('Content-Type: application/octet-stream;');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: '.filesize($filename).";\n");
            readfile($filename);

        } else if ($type == self::TYPE_APP) {

            // send XML with url to app binary file
            $ipa_url =
                dirname("http://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI']) . '/' .
                $bundleidentifier . '/' . basename($ipa);

            $plist_content = file_get_contents($plist);
            $plist_content = str_replace('__URL__', $ipa_url, $plist_content);
            if ($image) {
                $image_url =
                    dirname("http://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI']) . '/' .
                    $bundleidentifier . '/' . basename($image);
                $imagedict = "<dict><key>kind</key><string>display-image</string><key>needs-shine</key><false/><key>url</key><string>".$image_url."</string></dict></array>";
                $insertpos = strpos($plist_content, '</array>');
                $plist_content = substr_replace($plist_content, $imagedict, $insertpos, 8);
            }
            header('content-type: application/xml');
            echo $plist_content;

        } else if ($type == self::TYPE_IPA) {
            
            // send the ipa iOS application file
            $filename = $appDirectory  . $ipa;
            header('Content-Disposition: attachment; filename=' . urlencode(basename($filename)));
            header('Content-Type: application/octet-stream;');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: '.filesize($filename).";\n");
            readfile_chunked($filename);
            
        } else if ($type == self::TYPE_APK) {
 
            // send apk android application file
            $filename = $appDirectory  . $apk;
            header('Content-Disposition: attachment; filename=' . urlencode(basename($filename)));
            header('Content-Type: application/vnd.android.package-archive apk;');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: '.filesize($filename).";\n");
            readfile_chunked($filename);
            
        } else if ($type == self::TYPE_AUTH && $api != self::API_V1 && $udid && $appversion) {
            // check if the UDID is allowed to be used
            $filename = $this->appDirectory."stats/".$bundleidentifier;

            $this->json[self::RETURN_V2_AUTHCODE] = self::RETURN_V2_AUTH_FAILED;

            $userlistfilename = $this->appDirectory."stats/userlist.txt";
        
            if (file_exists($filename)) {
                $userlist = @file_get_contents($userlistfilename);
                
                $lines = explode("\n", $userlist);

                foreach ($lines as $i => $line) :
                    if ($line == "") continue;
                    
                    $device = explode(";", $line);
                    
                    if (count($device) > 0) {
                        // is this the same device?
                        if ($device[0] == $udid) {
                            $this->json[self::RETURN_V2_AUTHCODE] = md5(HOCKEY_AUTH_SECRET . $appversion. $bundleidentifier . $udid);
                            break;
                        }
                    }
                endforeach;                
            }
            
            return $this->sendJSONAndExit();
        }

        exit();
    }
    
    protected function sendJSONAndExit()
    {
        
        ob_end_clean();
        header('Content-type: application/json');
        print json_encode($this->json);
        exit();
    }
    
    protected function show($appBundleIdentifier)
    {
        // first get all the subdirectories, which do not have a file named "private" present
        if ($handle = opendir($this->appDirectory)) {
            while (($file = readdir($handle)) !== false) {
                if (in_array($file, array('.', '..')) || !is_dir($this->appDirectory . $file) || (glob($this->appDirectory . $file . '/private') && !$appBundleIdentifier)) {
                    // skip if not a directory or has `private` file
                    // but only if no bundle identifier is provided to this function
                    continue;
                }
                
                // if a bundle identifier is provided and the directory does not match, continue
                if ($appBundleIdentifier && $file != $appBundleIdentifier) {
                    continue;
                }

                // now check if this directory has the 3 mandatory files
                
                // iOS
                $ipa                    = @array_shift(glob($this->appDirectory.$file . '/*.ipa'));
                $provisioningProfile    = @array_shift(glob($this->appDirectory.$file . '/*.mobileprovision'));
                $plist                  = @array_shift(glob($this->appDirectory.$file . '/*.plist'));
                
                // Android
                $apk                    = @array_shift(glob($this->appDirectory.$file . '/*.apk'));
                $json                   = @array_shift(glob($this->appDirectory.$file . '/*.json'));
                
                // Common
                $note                   = @array_shift(glob($this->appDirectory.$file . '/*.html'));
                $image                  = @array_shift(glob($this->appDirectory.$file . '/*.png'));

                if ((!$ipa || !$plist) && (!$apk || !$json)) {
                    continue;
                }


                $newApp = array();

                $newApp[self::INDEX_DIR]            = $file;
                $newApp[self::INDEX_IMAGE]          = substr($image, strpos($image, $file));
                $newApp[self::INDEX_NOTES]          = $note ? nl2br_skip_html(file_get_contents($note)) : '';
                $newApp[self::INDEX_STATS]          = array();

                if ($ipa) {
                    // iOS application
                    $plistDocument = new DOMDocument();
                    $plistDocument->load($plist);
                    $parsed_plist = parsePlist($plistDocument);

                    // now get the application name from the plist
                    $newApp[self::INDEX_APP]            = $parsed_plist['items'][0]['metadata']['title'];
                    if ($parsed_plist['items'][0]['metadata']['subtitle'])
                        $newApp[self::INDEX_SUBTITLE]   = $parsed_plist['items'][0]['metadata']['subtitle'];
                    $newApp[self::INDEX_VERSION]        = $parsed_plist['items'][0]['metadata']['bundle-version'];
                    $newApp[self::INDEX_DATE]           = filectime($ipa);
                
                    if ($provisioningProfile) {
                        $newApp[self::INDEX_PROFILE]        = $provisioningProfile;
                        $newApp[self::INDEX_PROFILE_UPDATE] = filectime($provisioningProfile);
                    }
                    $newApp[self::INDEX_PLATFORM]       = self::APP_PLATFORM_IOS;
                    
                } else if ($apk) {
                    // Android Application
                    
                    // parse the json file
                    $parsed_json = json_decode(file_get_contents($json), true);

                    // now get the application name from the json file
                    $newApp[self::INDEX_APP]        = $parsed_json['title'];
                    $newApp[self::INDEX_SUBTITLE]   = $parsed_json['versionName'];
                    $newApp[self::INDEX_VERSION]    = $parsed_json['versionCode'];                    
                    $newApp[self::INDEX_DATE]       = filectime($apk);                
                    $newApp[self::INDEX_PLATFORM]   = self::APP_PLATFORM_ANDROID;
                }
                
                // now get the current user statistics
                $userlist =  "";

                $filename = $this->appDirectory."stats/".$file;
                $userlistfilename = $this->appDirectory."stats/userlist.txt";
            
                if (file_exists($filename)) {
                    $userlist = @file_get_contents($userlistfilename);
                    
                    $content = file_get_contents($filename);
                    $lines = explode("\n", $content);

                    foreach ($lines as $i => $line) :
                        if ($line == "") continue;
                        
                        $device = explode(";;", $line);
                        
                        $newdevice = array();

                        $newdevice[self::DEVICE_USER] = $this->mapUser($device[0], $userlist);
                        $newdevice[self::DEVICE_PLATFORM] = $this->mapPlatform($device[1]);
                        $newdevice[self::DEVICE_OSVERSION] = $device[2];
                        $newdevice[self::DEVICE_APPVERSION] = $device[3];
                        $newdevice[self::DEVICE_LASTCHECK] = $device[4];
                        
                        $newApp[self::INDEX_STATS][] = $newdevice;
                    endforeach;
                    
                    // sort by app version
                    $newApp[self::INDEX_STATS] = self::array_orderby($newApp[self::INDEX_STATS], self::DEVICE_APPVERSION, SORT_DESC, self::DEVICE_OSVERSION, SORT_DESC, self::DEVICE_PLATFORM, SORT_ASC, self::DEVICE_LASTCHECK, SORT_DESC);
                }
                
                // add it to the array
                $this->applications[] = $newApp;
            }
            closedir($handle);
        }
    }
}


?>