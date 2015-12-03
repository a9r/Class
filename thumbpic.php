<?php
if (!defined('MEMORY_LIMIT')) define('MEMORY_LIMIT', '30M');
if (!defined('FILE_CACHE_ENABLED')) define('FILE_CACHE_ENABLED', TRUE);
if (!defined('MAX_FILE_SIZE')) define('MAX_FILE_SIZE', 10485760);
if (!defined('MAX_WIDTH')) define('MAX_WIDTH', 1500);
if (!defined('MAX_HEIGHT')) define('MAX_HEIGHT', 1500);
if (!defined('PNG_IS_TRANSPARENT')) define('PNG_IS_TRANSPARENT', FALSE);
if (!defined('DEFAULT_Q')) define('DEFAULT_Q', 90);
if (!defined('DEFAULT_ZC')) define('DEFAULT_ZC', 1);
if (!defined('DEFAULT_S')) define('DEFAULT_S', 0);
if (!defined('DEFAULT_CC')) define('DEFAULT_CC', 'ffffff');
if (!defined('DEFAULT_WIDTH')) define('DEFAULT_WIDTH', 100);
if (!defined('DEFAULT_HEIGHT')) define('DEFAULT_HEIGHT', 100);
if (!defined('IMG_BASE_DIRECTORY')) define('IMG_BASE_DIRECTORY', 'E:/works/marketing/Uploads/');
// usage
echo thumbpic::start(array('src' => 'wm/1/55cb1aba04959.jpg', 'new_width' => 300));
class thumbpic
{
    protected $src = "";
    protected $docRoot = "";
    protected $lastURLError = false;
    protected $localImage = "";
    protected $localImageMTime = 0;
    protected $url = false;
    protected $myHost = "";
    protected $cachefile = '';
    protected $errors = array();
    protected $cacheDirectory = '';
    protected $startTime = 0;
    protected $lastBenchTime = 0;
    protected $cropTop = false;
    protected $salt = "";
    protected static $curlDataWritten = 0;
    protected static $curlFH = false;
    public static function start($setting = array()) {
        $tim = new thumbpic($setting);
        if (FILE_CACHE_ENABLED) {
            return $tim->tryServerCache();
        }
        $tim->run();
    }
    public function __construct($setting) {
        $default = array('new_width' => 0, 'new_height' => 0, 'zoom_crop' => DEFAULT_ZC, 'quality' => DEFAULT_Q, 'align' => 'c', 'sharpen' => DEFAULT_S, 'canvas_color' => DEFAULT_CC, 'canvas_trans' => 1);
        $this->setting = array_merge($default, $setting);
        $this->pathinfo = pathinfo($this->setting['src']);
        $this->imgType = $this->pathinfo['extension'];
        $this->startTime = microtime(true);
        date_default_timezone_set('UTC');
        $this->calcDocRoot();
        $this->cacheDirectory = IMG_BASE_DIRECTORY;
        $this->myHost = preg_replace('/^www\./i', '', $_SERVER['HTTP_HOST']);
        $this->src = preg_replace('/https?:\/\/(?:www\.)?' . $this->myHost . '/i', '', $this->setting['src']);
        if (strlen($this->src) <= 3) {
            return false;
        }
        $this->localImage = $this->getLocalImagePath($this->src);
        if (!$this->localImage) {
            return false;
        }
        $this->cachefilepath = $this->pathinfo['dirname'] . '/' . $this->pathinfo['filename'] . ($this->setting['new_width'] ? '_w' . $this->setting['new_width'] : '') . ($this->setting['new_height'] ? '_h' . $this->setting['new_height'] : '') . '_thumb.' . $this->imgType;
        $this->cachefile = $this->cacheDirectory . $this->cachefilepath;
        return true;
    }
    public function run() {
        $this->serveInternalImage();
        return true;
    }
    protected function tryServerCache() {
        if (file_exists($this->cachefile)) {
            return $this->serveCacheFile();
        }
    }
    protected function serveInternalImage() {
        if (!$this->localImage) {
            return false;
        }
        $fileSize = filesize($this->localImage);
        if ($fileSize > MAX_FILE_SIZE) {
            return false;
        }
        if ($fileSize <= 0) {
            return false;
        }
        if ($this->processImageAndWriteToCache($this->localImage)) {
            $this->serveCacheFile();
            return true;
        } 
        else {
            return false;
        }
    }
    protected function processImageAndWriteToCache($localImage) {
        $sData = getimagesize($localImage);
        $origType = $sData[2];
        $mimeType = $sData['mime'];
        if (!preg_match('/^image\/(?:gif|jpg|jpeg|png)$/i', $mimeType)) {
            return false;
        }
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }
        if ($this->setting['new_width'] == 0 && $this->setting['new_height'] == 0) {
            $this->setting['new_width'] = (int)DEFAULT_WIDTH;
            $this->setting['new_height'] = (int)DEFAULT_HEIGHT;
        }
        $this->setting['new_width'] = min($this->setting['new_width'], MAX_WIDTH);
        $this->setting['new_height'] = min($this->setting['new_height'], MAX_HEIGHT);
        $this->setMemoryLimit();
        $image = $this->openImage($mimeType, $localImage);
        if ($image === false) {
            return false;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $origin_x = 0;
        $origin_y = 0;
        if ($this->setting['new_width'] && !$this->setting['new_height']) {
            $this->setting['new_height'] = floor($height * ($this->setting['new_width'] / $width));
        } 
        else if ($this->setting['new_height'] && !$this->setting['new_width']) {
            $this->setting['new_width'] = floor($width * ($this->setting['new_height'] / $height));
        }
        if ($this->setting['zoom_crop'] == 3) {
            $final_height = $height * ($this->setting['new_width'] / $width);
            if ($final_height > $this->setting['new_height']) {
                $this->setting['new_width'] = $width * ($this->setting['new_height'] / $height);
            } 
            else {
                $this->setting['new_height'] = $final_height;
            }
        }
        $canvas = imagecreatetruecolor($this->setting['new_width'], $this->setting['new_height']);
        imagealphablending($canvas, false);
        if (strlen($this->setting['canvas_color']) == 3) {
            $this->setting['canvas_color'] = str_repeat(substr($this->setting['canvas_color'], 0, 1), 2) . str_repeat(substr($this->setting['canvas_color'], 1, 1), 2) . str_repeat(substr($this->setting['canvas_color'], 2, 1), 2);
        } 
        else if (strlen($this->setting['canvas_color']) != 6) {
            $this->setting['canvas_color'] = DEFAULT_CC;
        }
        $this->canvas_color_R = hexdec(substr($this->setting['canvas_color'], 0, 2));
        $this->canvas_color_G = hexdec(substr($this->setting['canvas_color'], 2, 2));
        $this->canvas_color_B = hexdec(substr($this->setting['canvas_color'], 4, 2));
        if (preg_match('/^image\/png$/i', $mimeType) && !PNG_IS_TRANSPARENT && $this->setting['canvas_trans']) {
            $color = imagecolorallocatealpha($canvas, $this->canvas_color_R, $this->canvas_color_G, $this->canvas_color_B, 127);
        } 
        else {
            $color = imagecolorallocatealpha($canvas, $this->canvas_color_R, $this->canvas_color_G, $this->canvas_color_B, 0);
        }
        imagefill($canvas, 0, 0, $color);
        if ($this->setting['zoom_crop'] == 2) {
            $final_height = $height * ($this->setting['new_width'] / $width);
            if ($final_height > $this->setting['new_height']) {
                $origin_x = $this->setting['new_width'] / 2;
                $this->setting['new_width'] = $width * ($this->setting['new_height'] / $height);
                $origin_x = round($origin_x - ($this->setting['new_width'] / 2));
            } 
            else {
                $origin_y = $this->setting['new_height'] / 2;
                $this->setting['new_height'] = $final_height;
                $origin_y = round($origin_y - ($this->setting['new_height'] / 2));
            }
        }
        imagesavealpha($canvas, true);
        if ($this->setting['zoom_crop'] > 0) {
            $src_x = $src_y = 0;
            $src_w = $width;
            $src_h = $height;
            $cmp_x = $width / $this->setting['new_width'];
            $cmp_y = $height / $this->setting['new_height'];
            if ($cmp_x > $cmp_y) {
                $src_w = round($width / $cmp_x * $cmp_y);
                $src_x = round(($width - ($width / $cmp_x * $cmp_y)) / 2);
            } 
            else if ($cmp_y > $cmp_x) {
                $src_h = round($height / $cmp_y * $cmp_x);
                $src_y = round(($height - ($height / $cmp_y * $cmp_x)) / 2);
            }
            if ($this->setting['align']) {
                if (strpos($this->setting['align'], 't') !== false) {
                    $src_y = 0;
                }
                if (strpos($this->setting['align'], 'b') !== false) {
                    $src_y = $height - $src_h;
                }
                if (strpos($this->setting['align'], 'l') !== false) {
                    $src_x = 0;
                }
                if (strpos($this->setting['align'], 'r') !== false) {
                    $src_x = $width - $src_w;
                }
            }
            imagecopyresampled($canvas, $image, $origin_x, $origin_y, $src_x, $src_y, $this->setting['new_width'], $this->setting['new_height'], $src_w, $src_h);
        } 
        else {
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $this->setting['new_width'], $this->setting['new_height'], $width, $height);
        }
        if ($this->setting['sharpen'] && function_exists('imageconvolution')) {
            $this->setting['sharpen']['Matrix'] = array(array(-1, -1, -1), array(-1, 16, -1), array(-1, -1, -1),);
            $divisor = 8;
            $offset = 0;
            imageconvolution($canvas, $this->setting['sharpen']['Matrix'], $divisor, $offset);
        }
        if ((IMAGETYPE_PNG == $origType || IMAGETYPE_GIF == $origType) && function_exists('imageistruecolor') && !imageistruecolor($image) && imagecolortransparent($image) > 0) {
            imagetruecolortopalette($canvas, false, imagecolorstotal($image));
        }
        $imgType = "";
        $tempfile = tempnam($this->cacheDirectory, 'thumbpic_tmpimg_');
        if (preg_match('/^image\/(?:jpg|jpeg)$/i', $mimeType)) {
            $imgType = 'jpg';
            imagejpeg($canvas, $tempfile, $this->setting['quality']);
        } 
        else if (preg_match('/^image\/png$/i', $mimeType)) {
            $imgType = 'png';
            imagepng($canvas, $tempfile, floor($this->setting['quality'] * 0.09));
        } 
        else if (preg_match('/^image\/gif$/i', $mimeType)) {
            $imgType = 'gif';
            imagegif($canvas, $tempfile);
        } 
        else {
            return false;
        }
        $tempfile4 = tempnam($this->cacheDirectory, 'thumbpic_tmpimg_');
        $context = stream_context_create();
        $fp = fopen($tempfile, 'r', 0, $context);
        file_put_contents($tempfile4, $fp, FILE_APPEND);
        fclose($fp);
        @unlink($tempfile);
        $lockFile = $this->cachefile . '.lock';
        $fh = fopen($lockFile, 'w');
        if (!$fh) {
            return false;
        }
        if (flock($fh, LOCK_EX)) {
            @unlink($this->cachefile);
            rename($tempfile4, $this->cachefile);
            flock($fh, LOCK_UN);
            fclose($fh);
            @unlink($lockFile);
        } 
        else {
            fclose($fh);
            @unlink($lockFile);
            @unlink($tempfile4);
            return false;
        }
        imagedestroy($canvas);
        imagedestroy($image);
        return true;
    }
    protected function calcDocRoot() {
        $docRoot = @$_SERVER['DOCUMENT_ROOT'];
        if (defined('IMG_BASE_DIRECTORY')) {
            $docRoot = IMG_BASE_DIRECTORY;
        }
        if (!isset($docRoot)) {
            if (isset($_SERVER['SCRIPT_FILENAME'])) {
                $docRoot = str_replace('\\', '/', substr($_SERVER['SCRIPT_FILENAME'], 0, 0 - strlen($_SERVER['PHP_SELF'])));
            }
        }
        if (!isset($docRoot)) {
            if (isset($_SERVER['PATH_TRANSLATED'])) {
                $docRoot = str_replace('\\', '/', substr(str_replace('\\\\', '\\', $_SERVER['PATH_TRANSLATED']), 0, 0 - strlen($_SERVER['PHP_SELF'])));
            }
        }
        if ($docRoot && $_SERVER['DOCUMENT_ROOT'] != '/') {
            $docRoot = preg_replace('/\/$/', '', $docRoot);
        }
        $this->docRoot = $docRoot;
    }
    protected function getLocalImagePath($src) {
        $src = ltrim($src, '/');
        if (!$this->docRoot) {
            $file = preg_replace('/^.*?([^\/\\\\]+)$/', '$1', $src);
            if (is_file($file)) {
                return $this->realpath($file);
            }
            return false;
        } 
        else if (!is_dir($this->docRoot)) {
            return false;
        }
        if (file_exists($this->docRoot . '/' . $src)) {
            $real = $this->realpath($this->docRoot . '/' . $src);
            if (stripos($real, $this->docRoot) === 0) {
                return $real;
            }
        }
        $absolute = $this->realpath('/' . $src);
        if ($absolute && file_exists($absolute)) {
            if (!$this->docRoot) {
                return false;
            }
            if (stripos($absolute, $this->docRoot) === 0) {
                return $absolute;
            }
        }
        $base = $this->docRoot;
        if (strstr($_SERVER['SCRIPT_FILENAME'], ':')) {
            $sub_directories = explode('\\', str_replace($this->docRoot, '', $_SERVER['SCRIPT_FILENAME']));
        } 
        else {
            $sub_directories = explode('/', str_replace($this->docRoot, '', $_SERVER['SCRIPT_FILENAME']));
        }
        foreach ($sub_directories as $sub) {
            $base.= $sub . '/';
            if (file_exists($base . $src)) {
                $real = $this->realpath($base . $src);
                if (stripos($real, $this->realpath($this->docRoot)) === 0) {
                    return $real;
                }
            }
        }
        return false;
    }
    protected function realpath($path) {
        $remove_relatives = '/\w+\/\.\.\//';
        while (preg_match($remove_relatives, $path)) {
            $path = preg_replace($remove_relatives, '', $path);
        }
        return preg_match('#^\.\./|/\.\./#', $path) ? realpath($path) : $path;
    }
    protected function serveCacheFile() {
        if (!is_file($this->cachefile)) {
            return false;
        }
        return $this->cachefilepath;
    }
    protected function openImage($mimeType, $src) {
        switch ($mimeType) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($src);
                break;
            case 'image/png':
                $image = imagecreatefrompng($src);
                imagealphablending($image, true);
                imagesavealpha($image, true);
                break;

            case 'image/gif':
                $image = imagecreatefromgif($src);
                break;

            default:
                return false;
        }
        return $image;
    }
    protected function setMemoryLimit() {
        $inimem = ini_get('memory_limit');
        $inibytes = thumbpic::returnBytes($inimem);
        $ourbytes = thumbpic::returnBytes(MEMORY_LIMIT);
        if ($inibytes < $ourbytes) {
            ini_set('memory_limit', MEMORY_LIMIT);
        }
    }
    protected static function returnBytes($size_str) {
        switch (substr($size_str, -1)) {
            case 'M':
            case 'm':
                return (int)$size_str * 1048576;
            case 'K':
            case 'k':
                return (int)$size_str * 1024;
            case 'G':
            case 'g':
                return (int)$size_str * 1073741824;
            default:
                return $size_str;
        }
    }
}
