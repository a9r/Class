<?php
include 'thumbpic.php';
function thumbpic($src, $width=0, $height=0){
    thumbpic::start(array('src' => $src, 'new_width' => $width, 'new_height' => $height));
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>img</title>
    <style>
        body{margin:0;padding:0;}
    </style>
</head>
<body>
    <img src="/test/ThinkPHP/Uploads/<?php thumbpic('wm/1/55cb1aba04959.jpg', '300'); ?>">
    
</body>
</html>